<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\LeaveRequestDecided;
use App\Notifications\LeaveRequestSubmitted;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Who gets told, and when.
 *
 * Kept out of LeaveService so the rules about leave stay about leave. This
 * answers a different question — the routing one — and it is the only place
 * that decides it, so the web portal and the mobile API cannot notify
 * different people for the same event.
 *
 * Nothing here throws. A notification that cannot be delivered must never undo
 * a decision that has already been made: leave is granted whether or not the
 * mail server was reachable.
 */
class NotificationService
{
    /**
     * The people who can act on a request at its current stage.
     *
     * A request waiting on a manager goes to that one manager. One waiting on
     * HR goes to everyone who could decide it — HR is a desk, not a person, and
     * sending to whoever happens to be named would leave requests sitting
     * behind somebody on holiday.
     *
     * @return Collection<int, User>
     */
    public function approversFor(LeaveRequest $request): Collection
    {
        if ($request->isAwaitingManager()) {
            $manager = $request->employee?->manager?->user;

            return $manager ? collect([$manager]) : collect();
        }

        return $this->deciders($request->company_id);
    }

    /**
     * Staff who may make the final decision on leave for a company.
     *
     * By permission rather than by role name: whoever the roles editor has
     * granted approve-leave is who should hear about it, without this having to
     * be updated when a role is renamed or added.
     *
     * @return Collection<int, User>
     */
    public function deciders(?int $companyId): Collection
    {
        if (! $companyId) {
            return collect();
        }

        return User::where('company_id', $companyId)
            ->where('is_active', '!=', false)
            ->get()
            // Employees who manage a team also hold approve-leave, but they act
            // on their own reports through the manager step — including them
            // here would copy every request in the company to every manager.
            ->filter(fn (User $user) => $user->can('approve-leave') && ! $user->employee?->isManager())
            ->values();
    }

    /** A new request has been raised: tell whoever it is waiting on. */
    public function leaveSubmitted(LeaveRequest $request): void
    {
        $this->send(
            $this->approversFor($request),
            new LeaveRequestSubmitted($request),
        );
    }

    /**
     * A manager has passed a request up: tell HR it has arrived, and the
     * employee that it moved.
     *
     * The employee hears about the step even though nothing is granted yet —
     * silence between submitting and a decision is what makes people chase.
     */
    public function leavePassedToHr(LeaveRequest $request): void
    {
        $this->send($this->deciders($request->company_id), new LeaveRequestSubmitted($request));
        $this->send($this->employeeUser($request), new LeaveRequestDecided($request, 'manager_approved'));
    }

    /** A final decision has been made: tell the person who asked. */
    public function leaveDecided(LeaveRequest $request): void
    {
        $this->send($this->employeeUser($request), new LeaveRequestDecided($request, $request->status));
    }

    /**
     * Deliver, swallowing anything that goes wrong.
     *
     * A notification failure is not a reason to fail the request that caused
     * it. The decision is already recorded; losing the message is bad, losing
     * the decision would be worse.
     */
    protected function send(Collection $recipients, $notification): void
    {
        if ($recipients->isEmpty()) {
            return;
        }

        try {
            Notification::send($recipients, $notification);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @return Collection<int, User> */
    protected function employeeUser(LeaveRequest $request): Collection
    {
        $user = $request->employee?->user;

        return $user ? collect([$user]) : collect();
    }
}
