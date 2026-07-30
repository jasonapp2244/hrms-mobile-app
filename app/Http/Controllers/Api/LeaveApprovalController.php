<?php

namespace App\Http\Controllers\Api;

use App\Models\LeaveRequest;
use App\Services\LeaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A line manager's leave inbox on the phone.
 *
 * The approve-leave permission gets a manager through the door; it grants
 * nothing on its own. Every request is checked against this manager's own
 * direct reports, so holding the permission is not access to anyone else's
 * team — the same rule the portal applies.
 */
class LeaveApprovalController extends ApiController
{
    public function __construct(
        protected LeaveService $leave,
    ) {}

    /** What this manager still has to decide, soonest first. */
    public function index(): JsonResponse
    {
        $manager = $this->employee();
        $teamIds = $manager->subordinates()->pluck('id');

        $pending = LeaveRequest::with(['employee', 'leaveType'])
            ->whereIn('employee_id', $teamIds)
            ->pending()
            ->orderBy('start_date')
            ->get()
            // Anything already passed up is with HR, not with this manager.
            ->filter(fn (LeaveRequest $r) => $r->isAwaitingManager())
            ->values();

        return $this->ok([
            'pending' => $pending->map(fn (LeaveRequest $r) => [
                'id'           => $r->id,
                'employee'     => $r->employee?->full_name,
                'employee_id'  => $r->employee_id,
                'leave_type'   => $r->leaveType?->name,
                'start_date'   => $r->start_date->toDateString(),
                'end_date'     => $r->end_date->toDateString(),
                'days'         => (float) $r->days,
                'is_half_day'  => (bool) $r->is_half_day,
                'reason'       => $r->reason,
                'submitted_at' => $r->created_at?->toIso8601String(),
                // Who else on the team is already off over the same dates. A
                // manager approving cover has to know that before saying yes,
                // not after.
                'clashes' => $this->clashesFor($r, $teamIds),
            ])->values(),
            'pending_count' => $pending->count(),
        ]);
    }

    /**
     * Pass a request up to HR.
     *
     * Nothing is spent here — the days are only committed by the final
     * decision, so a request between the two steps holds no balance.
     */
    public function approve(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authoriseTeamMember($leaveRequest);

        $data = $request->validate(['manager_note' => 'nullable|string|max:1000']);

        $this->leave->managerApprove($leaveRequest, auth()->id(), $data['manager_note'] ?? null);

        return $this->ok([
            'message' => sprintf(
                "%s's request has been passed to HR for final approval.",
                $leaveRequest->employee->first_name,
            ),
            'status' => $leaveRequest->fresh()->stage_label,
        ]);
    }

    public function reject(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authoriseTeamMember($leaveRequest);

        $data = $request->validate(
            ['decision_note' => 'required|string|max:1000'],
            ['decision_note.required' => 'Please give a reason — the employee sees this.'],
        );

        $this->leave->reject($leaveRequest, auth()->id(), $data['decision_note']);

        return $this->ok(['message' => 'Request rejected.', 'status' => 'rejected']);
    }

    /** Approved leave already covering the same dates, elsewhere in the team. */
    protected function clashesFor(LeaveRequest $request, $teamIds): array
    {
        return LeaveRequest::with('employee')
            ->whereIn('employee_id', $teamIds)
            ->where('employee_id', '!=', $request->employee_id)
            ->approved()
            ->overlapping($request->start_date->toDateString(), $request->end_date->toDateString())
            ->get()
            ->map(fn (LeaveRequest $clash) => [
                'employee'   => $clash->employee?->full_name,
                'start_date' => $clash->start_date->toDateString(),
                'end_date'   => $clash->end_date->toDateString(),
            ])->values()->all();
    }

    /**
     * A manager may only act on their own direct reports, and never on their
     * own request — that decision belongs to their manager or to HR.
     */
    protected function authoriseTeamMember(LeaveRequest $leaveRequest): void
    {
        $manager = $this->employee();

        abort_if($leaveRequest->employee_id === $manager->id, 403,
            'You cannot decide on your own leave request.');

        abort_unless($leaveRequest->employee?->manager_id === $manager->id, 403,
            'That request belongs to someone outside your team.');
    }
}
