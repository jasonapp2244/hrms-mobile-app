<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Join logins that have no employee record to the record that is plainly theirs.
 *
 * An HR or admin login set up before its employee row existed signs in to the
 * phone app and then finds Clock, History, Leave and Schedule empty, because
 * every one of them is "this person's" and the server finds the person through
 * `employees.user_id`. This fixes the ones that can be fixed without judgement.
 *
 * **A match is the same email, in the same company, with nobody else claiming
 * it — and nothing else.** Not a name, not a partial address: linking the wrong
 * login to a record lets one person punch as another. Anything ambiguous or
 * unmatched is listed for a person to settle on the employee page.
 *
 * Changes nothing unless given --apply. Read the dry run first.
 */
class LinkEmployeeAccounts extends Command
{
    protected $signature = 'emp:link-accounts
                            {--apply : Write the links. Without it, only report what would happen}';

    protected $description = 'Link logins with no employee record to the employee with the same email in the same company';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $unlinked = User::query()
            ->whereNotIn('id', Employee::whereNotNull('user_id')->select('user_id'))
            ->with('roles')
            ->orderBy('id')
            ->get();

        if ($unlinked->isEmpty()) {
            $this->info('Every login is already linked to an employee record.');

            return self::SUCCESS;
        }

        $rows = [];
        $linked = 0;

        foreach ($unlinked as $user) {
            [$outcome, $employee] = $this->resolve($user, $apply);

            if ($outcome === 'linked' || $outcome === 'would link') {
                $linked++;
            }

            $rows[] = [
                $user->id,
                $user->email,
                $user->roles->pluck('name')->implode(', ') ?: '—',
                $employee ? "{$employee->employee_code} {$employee->full_name}" : '—',
                $outcome,
            ];
        }

        $this->table(['User', 'Email', 'Role', 'Employee', 'Result'], $rows);

        if ($apply) {
            $this->info("Linked {$linked} login(s).");
        } else {
            $this->warn("Dry run: {$linked} login(s) would be linked. Run again with --apply to write them.");
        }

        $this->line('<fg=gray>Anything else: create the employee in Employees → Add (same email), or open the</>');
        $this->line('<fg=gray>employee and use "Or link an existing login", then sign out and back in on the app.</>');

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: ?Employee}
     */
    private function resolve(User $user, bool $apply): array
    {
        if ($user->company_id === null) {
            return ['skipped: login has no company', null];
        }

        $email = mb_strtolower(trim((string) $user->email));

        $matches = Employee::query()
            ->where('company_id', $user->company_id)
            ->whereNull('user_id')
            ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->get();

        if ($matches->isEmpty()) {
            return ['skipped: no employee with this email', null];
        }

        if ($matches->count() > 1) {
            return ["skipped: {$matches->count()} employees share this email", null];
        }

        $employee = $matches->first();

        if (! $apply) {
            return ['would link', $employee];
        }

        $done = DB::transaction(function () use ($user, $employee) {
            // Re-read under lock: the web form may have linked either side since
            // the query above.
            $fresh = Employee::whereKey($employee->id)->lockForUpdate()->first();

            if ($fresh->user_id || Employee::where('user_id', $user->id)->exists()) {
                return false;
            }

            $fresh->update(['user_id' => $user->id]);

            ActivityLog::record(
                event: ActivityLog::ACCOUNT_CHANGED,
                description: "Linked the existing login {$user->email} to {$fresh->full_name} (emp:link-accounts)",
                subject: $user,
                actorLabel: 'System (emp:link-accounts)',
                companyId: $user->company_id,
            );

            return true;
        });

        return [$done ? 'linked' : 'skipped: linked elsewhere meanwhile', $employee];
    }
}
