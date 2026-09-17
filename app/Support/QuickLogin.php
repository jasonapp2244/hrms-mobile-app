<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * The accounts offered by the one-click panel on the login page.
 *
 * A hard-coded demo box used to sit on that page and was removed, for two
 * reasons worth not repeating. It named accounts that only the demo seeder
 * creates, so on a real install it advertised logins that did not work. And it
 * printed a fixed password that `emp:preflight` fails a deploy over, on a page
 * anyone can reach.
 *
 * So nothing here is taken on trust. The environment file supplies candidates;
 * every one is looked up, its password verified against the stored hash, and
 * its roles read from the database. A row that would not actually sign in is
 * never rendered. Roles shown are the real ones, so the panel cannot drift out
 * of date the way a hand-written list does.
 *
 * @see config/demo.php for when this is allowed to be on at all.
 */
final class QuickLogin
{
    private const CACHE_KEY = 'demo.quick_login.accounts';

    /**
     * Short enough that a password change or a deactivation drops out of the
     * panel while someone is still looking at it, long enough that the bcrypt
     * checks below do not run on every page load. They are the expensive part —
     * deliberately so, which is why the result is not recomputed per request.
     */
    private const CACHE_TTL = 60;

    public static function enabled(): bool
    {
        // Two locks, because they fail differently. The config flag can be
        // frozen in by `config:cache` on a developer machine and shipped in the
        // build; the environment check is read at runtime and cannot be.
        return (bool) config('demo.quick_login') && ! app()->environment('production');
    }

    /**
     * @return Collection<int, array{email: string, password: string, name: string, roles: string, is_admin: bool}>
     */
    public static function accounts(): Collection
    {
        if (! self::enabled()) {
            return collect();
        }

        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn () => self::resolve());
    }

    /** Drop the memoised list — for tests, and after seeding or a password change. */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return Collection<int, array<string, mixed>> */
    private static function resolve(): Collection
    {
        $candidates = collect(explode(',', (string) config('demo.quick_login_accounts')))
            ->map(fn (string $entry) => trim($entry))
            ->filter()
            ->map(function (string $entry) {
                // Split on the first colon only: a password may contain others.
                [$email, $password] = array_pad(explode(':', $entry, 2), 2, '');

                return ['email' => strtolower(trim($email)), 'password' => $password];
            })
            ->filter(fn (array $pair) => $pair['email'] !== '' && $pair['password'] !== '')
            ->unique('email')
            ->values();

        if ($candidates->isEmpty()) {
            return collect();
        }

        $users = User::with('roles')
            ->whereIn('email', $candidates->pluck('email')->all())
            ->get()
            ->keyBy(fn (User $user) => strtolower($user->email));

        return $candidates
            ->map(fn (array $pair) => self::row($pair, $users->get($pair['email'])))
            ->filter()
            // **Every account named, grouped by role, strongest role first.**
            //
            // This used to be `unique('role_key')` — one button per role, four
            // at most. That was solving a real problem badly: the env file on
            // the live box named two employees and no manager, so the area a
            // client most wants to see was unreachable and the one they had
            // already seen was offered twice. Collapsing to one per role hid
            // the symptom; the cause was the env file, and the panel could not
            // fix it by showing less.
            //
            // Showing all of them is strictly more information and reintroduces
            // nothing: a role nobody is listed for is still missing, which is
            // now visible rather than disguised as a complete set of four. The
            // ordering does the work the de-duplication was credited with —
            // admin, HR, manager, employee — so the areas are still reachable
            // top-down, and a name now distinguishes the five employees the
            // demo company has from each other.
            ->sortBy(fn (array $row) => sprintf(
                '%d %s',
                array_search($row['role_key'], self::ROLE_ORDER, true),
                $row['name'],
            ))
            ->values();
    }

    /**
     * Descending reach, the same order as the Roles & Permissions screen and
     * as `User::homeRoute()`'s precedence. All three answer a version of the
     * same question and should not disagree.
     */
    private const ROLE_ORDER = ['admin', 'hr', 'manager', 'employee'];

    /** @return array<string, mixed>|null */
    private static function row(array $pair, ?User $user): ?array
    {
        // Every reason this row would not sign in, checked before it is shown:
        // no such account, a password that has since changed, a deactivated
        // user, or a role LoginController turns away. A button that fails is
        // worse than no button — the client reports it as the app being broken.
        if (! $user || ! Hash::check($pair['password'], $user->password)) {
            return null;
        }

        if (! ($user->is_active ?? true)) {
            return null;
        }

        $roles = $user->roles->pluck('name');

        if ($roles->intersect(['admin', 'hr', 'employee', 'manager'])->isEmpty()) {
            return null;
        }

        $primary = self::primaryRole($roles);

        return [
            'email'    => $user->email,
            'password' => $pair['password'],
            'name'     => $user->name,
            // The raw role, for ordering and de-duplication. `roles` below is
            // the display string and must not be used for either — 'HR' and
            // 'hr' would sort and group as two different things.
            'role_key' => $primary,
            'roles'    => self::label($primary),
            'is_admin' => $roles->contains('admin'),
        ];
    }

    /**
     * The one role this button is worth naming.
     *
     * Every role used to be joined with ' + ', which turned the manager account
     * into "Employee + Manager" — accurate about the row and wrong about the
     * button, because the panel is answering "what do I get if I click this",
     * and what you get is the manager area. Managers hold the employee role as
     * well by design, so the joined label would have said that about every one
     * of them.
     *
     * The order is deliberately the same as User::homeRoute()'s, because it is
     * answering the same question — which of somebody's roles decides where
     * they land. If that precedence ever changes, this has to change with it,
     * or the panel will promise one area and open another.
     *
     * @param  Collection<int, string>  $roles
     */
    private static function primaryRole(Collection $roles): string
    {
        foreach (['admin', 'hr', 'manager', 'employee'] as $role) {
            if ($roles->contains($role)) {
                return $role;
            }
        }

        // Unreachable while row() keeps refusing anything outside those four,
        // but a guess is better than an empty button if that ever loosens.
        return (string) $roles->first();
    }

    private static function label(string $role): string
    {
        return match ($role) {
            'hr'    => 'HR',
            default => ucfirst($role),
        };
    }
}
