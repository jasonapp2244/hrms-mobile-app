<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who a manager is allowed to see, in one place.
 *
 * Every manager-facing query in the application asks this and nothing else, so
 * there is a single answer to "whose data is this person entitled to" rather
 * than one per controller. A second definition living in a controller is how a
 * scope leak ships: the screen that forgot the filter looks exactly like the
 * ones that did not.
 *
 * ## The scope is direct reports, and only direct reports
 *
 * `employees.manager_id` already carries the reporting line, so nothing new was
 * introduced to express ownership — a manager↔office or manager↔department
 * pivot would be a second claim on the same fact, and the two would eventually
 * disagree about who owns whom.
 *
 * The scope deliberately does **not** recurse down the tree. Leave approval is
 * a single hop — manager, then HR — which is what `LeaveService::managerApprove`
 * models and what `LeaveApprovalController` has always enforced; a manager two
 * levels up is not in that chain and granting them sight of those records would
 * hand them data they can never act on. Visibility across a whole reporting
 * subtree is a real feature, but it is a different one, and it belongs to
 * whoever also changes the approval chain to match.
 *
 * Company is asserted on every query alongside the manager id. `manager_id`
 * alone would be enough today because the schema is single-company, but the
 * scope is the wrong place to depend on that: A2.10 (multi-company tenancy) is
 * an open item, and a cross-company reporting line created by a future import
 * would silently widen every manager's view.
 */
class ManagerScope
{
    /**
     * The employee record behind a manager's login.
     *
     * A manager with no employee row has no reporting line and therefore no
     * scope — the same refusal `ApiController::employee()` and the portal
     * already give, worded the same way so one cause reads as one problem.
     */
    public function employeeFor(?\App\Models\User $user): Employee
    {
        $employee = $user?->employee;

        abort_unless(
            $employee,
            403,
            'No employee record is linked to this account. Contact HR.',
        );

        return $employee;
    }

    /**
     * A query already narrowed to this manager's team.
     *
     * Returned as a builder rather than a result so callers can add their own
     * conditions without ever being able to remove the two that matter.
     *
     * @return Builder<Employee>
     */
    public function query(Employee $manager): Builder
    {
        return Employee::query()
            ->where('manager_id', $manager->id)
            ->where('company_id', $manager->company_id);
    }

    /**
     * The manager's active team, ordered for display.
     *
     * Active only: somebody who has left still carries the reporting line — the
     * column is nulled on delete, not on termination — and listing them as
     * absent every morning would be a permanent false alarm.
     *
     * @return Collection<int, Employee>
     */
    public function team(Employee $manager, array $with = []): Collection
    {
        return $this->query($manager)
            ->with($with)
            ->active()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();
    }

    /**
     * Just the ids, for the `whereIn` that most callers actually want.
     *
     * @return array<int, int>
     */
    public function teamIds(Employee $manager): array
    {
        return $this->query($manager)->active()->pluck('id')->all();
    }

    /**
     * Is this employee inside the manager's scope?
     *
     * Status is not consulted. A manager must still be able to open the
     * attendance of somebody who was terminated last week — the records are the
     * reason the refusal to delete an employee exists — and hiding them here
     * would turn a historical question into a 403.
     */
    public function manages(Employee $manager, Employee $subject): bool
    {
        return $subject->manager_id === $manager->id
            && $subject->company_id === $manager->company_id;
    }

    /**
     * Refuse anything outside the scope.
     *
     * 403 rather than 404. The distinction leaks nothing here: the ids are
     * sequential and a manager can already tell a real employee from a missing
     * one by asking for one of their own, so pretending the record does not
     * exist would buy no secrecy and cost the caller a truthful error.
     */
    public function assertManages(Employee $manager, Employee $subject): void
    {
        abort_unless(
            $this->manages($manager, $subject),
            403,
            'That employee does not report to you.',
        );
    }
}
