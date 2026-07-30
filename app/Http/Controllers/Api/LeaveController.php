<?php

namespace App\Http\Controllers\Api;

use App\Models\LeaveRequest;
use App\Services\LeaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The employee's own leave: what they have left, what they have asked for, and
 * withdrawing something they no longer need.
 *
 * Every rule — overlap, balance, working days, whether a type needs sign-off —
 * comes from LeaveService, the same one the web form posts to. A rule enforced
 * only in a form is a rule the API would not have.
 */
class LeaveController extends ApiController
{
    public function __construct(
        protected LeaveService $leave,
    ) {}

    /**
     * Balances for every type still open for booking.
     *
     * Inactive types keep their history but cannot be booked again, so they are
     * not offered here — the app renders this list as the apply form.
     */
    public function balances(Request $request): JsonResponse
    {
        $employee = $this->employee()->load('company');
        $year     = (int) ($request->query('year') ?: date('Y'));

        return $this->ok([
            'year'     => $year,
            'balances' => $this->leave->balanceSummary($employee, $year)
                ->map(fn (array $row) => [
                    'leave_type_id'     => $row['type']->id,
                    'name'              => $row['type']->name,
                    'code'              => $row['type']->code,
                    'color'             => $row['type']->color,
                    'is_paid'           => $row['type']->is_paid,
                    'allow_half_day'    => $row['type']->allow_half_day,
                    'requires_approval' => $row['type']->requires_approval,
                    'entitled_days'     => (float) $row['balance']->entitled_days,
                    'carried_forward'   => (float) $row['balance']->carried_forward,
                    'used_days'         => (float) $row['balance']->used_days,
                    'available_days'    => $row['balance']->available,
                    // A type granting zero days draws down nothing — that is how
                    // unpaid leave is set up, and the app must not show it as a
                    // person with no days left.
                    'is_capped' => $this->leave->isCapped($row['balance']),
                ])->values(),
        ]);
    }

    /** The employee's own requests, newest first. */
    public function index(Request $request): JsonResponse
    {
        $employee = $this->employee();

        $data = $request->validate([
            'status' => 'nullable|in:pending,approved,rejected,cancelled',
            'year'   => 'nullable|integer|min:2000|max:2100',
            'page'   => 'nullable|integer|min:1',
        ]);

        $query = $employee->leaveRequests()
            // The stage label reads the employee's manager_id, so without this
            // every row in the page would fire its own query for it.
            ->with('leaveType', 'employee', 'approver')
            ->latest('start_date');

        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }

        if (isset($data['year'])) {
            $query->whereYear('start_date', $data['year']);
        }

        $page = $query->paginate(15);

        return $this->ok([
            'requests' => collect($page->items())
                ->map(fn (LeaveRequest $r) => $this->requestPayload($r))
                ->values(),
            'meta' => $this->pageMeta($page),
        ]);
    }

    /** One request in full, including the decision and who made it. */
    public function show(LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authoriseOwner($leaveRequest);

        $leaveRequest->load('leaveType', 'employee', 'approver', 'managerApprover');

        return $this->ok(['request' => $this->requestPayload($leaveRequest, detailed: true)]);
    }

    /**
     * Book leave.
     *
     * The service decides how many days it actually costs — weekends and company
     * holidays inside the range are free — so the app does not compute a number
     * that could disagree with the one recorded.
     */
    public function store(Request $request): JsonResponse
    {
        $employee = $this->employee()->load('company');

        $data = $request->validate([
            'leave_type_id' => 'required|integer|exists:leave_types,id',
            'start_date'    => 'required|date_format:Y-m-d',
            // Bounded so a typo like the year 2999 cannot walk the day loop a
            // million times before the balance check refuses it.
            'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date|before_or_equal:'
                . now()->addYears(2)->toDateString(),
            'half_day_period' => 'nullable|in:first_half,second_half',
            'reason'          => 'nullable|string|max:1000',
        ], [
            'end_date.before_or_equal' => 'Leave cannot be booked more than two years ahead.',
        ]);

        $data['is_half_day'] = $request->boolean('is_half_day');

        // Business-rule failures come back as ValidationException, so they reach
        // the client in the same per-field shape as a bad date would.
        $leaveRequest = $this->leave->submit($employee, $data);

        return $this->ok([
            'request' => $this->requestPayload($leaveRequest->load('leaveType', 'employee')),
            'message' => $leaveRequest->status === 'approved'
                ? 'Leave approved — this type does not need sign-off.'
                : 'Leave request submitted. You will be notified once it is reviewed.',
        ], 201);
    }

    /** Withdraw a request. Approved leave gives its days back; pending never spent any. */
    public function cancel(LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authoriseOwner($leaveRequest);

        $this->leave->cancel($leaveRequest);

        return $this->ok([
            'request' => $this->requestPayload($leaveRequest->fresh()->load('leaveType', 'employee')),
            'message' => 'Leave request withdrawn.',
        ]);
    }

    /**
     * Ownership, not just authentication.
     *
     * Every employee can reach this route, so the record has to be checked
     * against the caller — a token is not permission to touch somebody else's
     * leave.
     */
    protected function authoriseOwner(LeaveRequest $leaveRequest): void
    {
        abort_unless(
            $leaveRequest->employee_id === $this->employee()->id,
            403,
            'That leave request is not yours.',
        );
    }

    protected function requestPayload(LeaveRequest $r, bool $detailed = false): array
    {
        $payload = [
            'id'          => $r->id,
            'leave_type'  => $r->leaveType?->name,
            'start_date'  => $r->start_date->toDateString(),
            'end_date'    => $r->end_date->toDateString(),
            'days'        => (float) $r->days,
            'is_half_day' => (bool) $r->is_half_day,
            'status'      => $r->status,
            // What it is waiting on — "Awaiting Manager" and "Awaiting HR" are
            // both pending, and an employee chasing a decision needs to know
            // which desk it is sitting on.
            'stage'         => $r->stage_label,
            'can_cancel'    => $r->isCancellable(),
            'submitted_at'  => $r->created_at?->toIso8601String(),
        ];

        if (! $detailed) {
            return $payload;
        }

        return $payload + [
            'half_day_period'     => $r->half_day_period,
            'reason'              => $r->reason,
            'manager_note'        => $r->manager_note,
            'manager_approved_by' => $r->managerApprover?->name,
            'manager_approved_at' => $r->manager_approved_at?->toIso8601String(),
            'decision_note'       => $r->decision_note,
            'decided_by'          => $r->approver?->name,
            'decided_at'          => $r->approved_at?->toIso8601String(),
        ];
    }
}
