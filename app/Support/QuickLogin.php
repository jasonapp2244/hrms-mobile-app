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
            ->values();
    }

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

        return [
            'email'    => $user->email,
            'password' => $pair['password'],
            'name'     => $user->name,
            'roles'    => $roles->map(fn (string $role) => self::label($role))->join(' + '),
            'is_admin' => $roles->contains('admin'),
        ];
    }

    private static function label(string $role): string
    {
        return match ($role) {
            'hr'    => 'HR',
            default => ucfirst($role),
        };
    }
}
