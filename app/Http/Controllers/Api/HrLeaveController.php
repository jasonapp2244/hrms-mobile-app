<?php

namespace App\Http\Controllers\Api;

use App\Models\LeaveRequest;
use App\Services\LeaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HR's leave desk, on the phone (client requirement, 2026-09-22).
 *
 * **This is the second step of the approval chain, not the first.** The manager
 * inbox next door (`Api\LeaveApprovalController`) is scoped to one manager's
 * own reports and its approve button calls `managerApprove()`, which passes a
 * request up and spends nothing. This is where the days are actually committed.
 *
 * The gate is **`manage-leave` to look and `approve-leave` as well to decide**,
 * which is not a new rule — it is exactly what `routes/web.php` applies to the
 * company-wide register and the final approve/reject inside the `role:admin|hr`
 * group. A line manager holds `approve-leave` and **not** `manage-leave`, so
 * the same permission that keeps them out of the register on the web keeps them
 * out of here, and there is one definition of "may decide for the company"
 * rather than two free to drift.
 *
 * Everything below is company-scoped on top of the permission. `manage-leave`
 * says what kind of work somebody may do; it never says whose data.
 */
class HrLeaveController extends ApiController
{
    public function __construct(
        protected LeaveService $leave,
    ) {}

    /**
     * What HR still has to decide, soonest first.
     *
     * Paginated, unlike the manager's inbox: a team is small enough to send in
     * one document and a company is not. The queue is ordered by start date
     * because the request that begins on Monday is the one that has to be
     * answered, not the one that was typed first.
     */
    public function index(): JsonResponse
    {
        $companyId = $this->companyScope();

        $page = LeaveRequest::with(['employee.department', 'employee.office', 'leaveType', 'managerApprover'])
            ->where('company_id', $companyId)
            ->pending()
            ->orderBy('start_date')
            ->paginate($this->perPage('hr_leave'));

        // Filtered in PHP rather than SQL because "awaiting HR" is a state the
        // model computes from two columns, and duplicating that as a where
        // clause is how the two come to disagree. The page is already bounded,
        // so the cost is a pass over at most per_page rows.
        $pending = collect($page->items())
            ->filter(fn (LeaveRequest $r) => $r->isAwaitingHr())
            ->values();

        return $this->ok([
            'pending'       => $pending->map(fn (LeaveRequest $r) => $this->payload($r))->values(),
            'pending_count' => $pending->count(),
            'meta'          => $this->pageMeta($page),
        ]);
    }

    /**
     * What has been decided lately, so a phone is not a write-only surface.
     *
     * HR's most common question after "what is waiting" is "what did I do with
     * the one yesterday", and without this the app could only answer it by
     * sending somebody to a desk.
     */
    public function decided(): JsonResponse
    {
        $companyId = $this->companyScope();

        $page = LeaveRequest::with(['employee.department', 'leaveType', 'approver'])
            ->where('company_id', $companyId)
            ->where('status', '!=', 'pending')
            ->latest('updated_at')
            ->paginate($this->perPage('hr_leave'));

        return $this->ok([
            'requests' => collect($page->items())
                ->map(fn (LeaveRequest $r) => $this->payload($r, withContext: false))
                ->values(),
            'meta' => $this->pageMeta($page),
        ]);
    }

    /**
     * Grant it, and spend the days.
     *
     * `LeaveService::approve()` re-checks the balance at the moment of granting
     * — days can be spent between submission and this call by an auto-approved
     * request or by another approval earlier in the same queue. That check
     * throws, and the throw is **caught and reported as a refusal the app can
     * read**, not left to surface as a validation error shaped like a form post.
     */
    public function approve(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authoriseCompany($leaveRequest);

        $data = $request->validate(['decision_note' => 'nullable|string|max:1000']);

        try {
            $this->leave->approve($leaveRequest, auth()->id(), $data['decision_note'] ?? null);
        } catch (ValidationException $e) {
            return $this->refusal($e);
        }

        return $this->ok([
            'message' => __('api.leave_approved'),
            'request' => $this->payload($leaveRequest->fresh(['employee', 'leaveType']), withContext: false),
        ]);
    }

    /** Refuse it. Nothing was spent, so nothing is returned. */
    public function reject(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authoriseCompany($leaveRequest);

        // A reason is required, exactly as it is on the web: a refusal with no
        // words is the one outcome somebody will come and ask about, and the
        // person who has to answer will not remember.
        $data = $request->validate(['decision_note' => 'required|string|max:1000']);

        try {
            $this->leave->reject($leaveRequest, auth()->id(), $data['decision_note']);
        } catch (ValidationException $e) {
            return $this->refusal($e);
        }

        return $this->ok([
            'message' => __('api.leave_rejected'),
            'request' => $this->payload($leaveRequest->fresh(['employee', 'leaveType']), withContext: false),
        ]);
    }

    /**
     * The file attached to a request (B4.1).
     *
     * HR's own route to it, for the same reason the manager has one: the
     * employee's route checks ownership and the manager's checks the reporting
     * line, and neither of those describes somebody deciding for the whole
     * company. Behind `manage-leave` like the register it is read from.
     */
    public function attachment(LeaveRequest $leaveRequest): StreamedResponse|JsonResponse
    {
        $this->authoriseCompany($leaveRequest);

        if (! $leaveRequest->hasAttachment()) {
            return $this->fail('not_found', __('api.leave_attachment_missing'), 404);
        }

        return Storage::disk(LeaveRequest::ATTACHMENT_DISK)
            ->download($leaveRequest->attachment, $leaveRequest->attachmentDownloadName());
    }

    /**
     * The company this HR user may act for.
     *
     * `companyId()` on the base controller fails closed for an account with no
     * company. An HR login always has one; saying so here rather than assuming
     * it is what keeps this endpoint from becoming the exception.
     */
    protected function companyScope(): int
    {
        return $this->companyId();
    }

    /**
     * A request belonging to somebody else's company is a 404-shaped 403.
     *
     * The route takes a bound model, which is the leak: the permission is
     * company-blind, so without this an id from another client would be
     * approved by this client's HR.
     */
    protected function authoriseCompany(LeaveRequest $leaveRequest): void
    {
        abort_unless(
            $leaveRequest->company_id === $this->companyScope(),
            403,
            __('api.leave_not_yours'),
        );
    }

    /**
     * Turn a service refusal into an answer the app can show.
     *
     * The service speaks in validation messages because the web posts forms at
     * it. The app posts JSON and has no field to hang an error on, so the first
     * message becomes the sentence and the code says what kind of refusal it
     * was — an over-spent balance and an already-decided request need different
     * words on screen and the app cannot tell them apart from prose.
     */
    protected function refusal(ValidationException $e): JsonResponse
    {
        $message = collect($e->errors())->flatten()->first() ?? $e->getMessage();

        return $this->fail('leave_decision_refused', (string) $message, 422);
    }

    /**
     * One request, as HR reads it.
     *
     * `$withContext` carries the parts that only matter while deciding — the
     * balance and the clashes. They cost a query each, so the decided list
     * leaves them out: nobody re-checks entitlement on a request they settled
     * last week.
     *
     * @return array<string, mixed>
     */
    protected function payload(LeaveRequest $r, bool $withContext = true): array
    {
        $employee = $r->employee;

        $payload = [
            'id'             => $r->id,
            'employee'       => $employee?->full_name,
            'employee_id'    => $r->employee_id,
            'employee_code'  => $employee?->employee_code,
            'department'     => $employee?->department?->name,
            'office'         => $employee?->office?->name,
            'leave_type'     => $r->leaveType?->name,
            'start_date'     => $r->start_date->toDateString(),
            'end_date'       => $r->end_date->toDateString(),
            'days'           => (float) $r->days,
            'is_half_day'    => (bool) $r->is_half_day,
            'reason'         => $r->reason,
            'status'         => $r->status,
            'submitted_at'   => $r->created_at?->toIso8601String(),
            'has_attachment'  => $r->hasAttachment(),
            'attachment_name' => $r->attachment_name,
            // The manager step, so HR can see a request was seconded rather
            // than arriving unread. An auto-approved type or an employee with
            // no manager leaves these null, which is an answer too.
            'manager_approved_by' => $r->managerApprover?->name,
            'manager_approved_at' => $r->manager_approved_at?->toIso8601String(),
            'manager_note'        => $r->manager_note,
        ];

        if (! $withContext) {
            $payload['decided_by']    = $r->approver?->name;
            $payload['decision_note'] = $r->decision_note;

            return $payload;
        }

        $payload['balance']  = $this->balance($r);
        $payload['clashes']  = $this->clashes($r);

        return $payload;
    }

    /**
     * What this person has left of this leave type, this year.
     *
     * The single most important number on the screen and the one a phone is
     * most likely to omit: approving without it is how `used_days` ends up past
     * the entitlement and somebody has to unpick it afterwards. `capped` is
     * sent separately because an uncapped type has no "available" to speak of
     * and a zero there would read as "none left".
     *
     * @return array<string, mixed>|null
     */
    protected function balance(LeaveRequest $r): ?array
    {
        if (! $r->employee || ! $r->leaveType) {
            return null;
        }

        $balance = $this->leave->balanceFor($r->employee, $r->leaveType, $r->start_date->year);

        return [
            'entitled'  => (float) $balance->entitled_days,
            'used'      => (float) $balance->used_days,
            'available' => (float) $balance->available,
            'capped'    => $this->leave->isCapped($balance),
            // Said plainly rather than left for the app to work out, because
            // getting it wrong on a phone means granting leave that does not
            // exist. This is the same comparison `approve()` will make.
            'would_exceed' => $this->leave->isCapped($balance)
                && (float) $r->days > $balance->available,
        ];
    }

    /**
     * Who else is already off over the same dates.
     *
     * **Scoped to the department, not the company.** A manager's clash list is
     * their team because their team is who covers for each other; HR's
     * equivalent is the department for the same reason. Company-wide would be
     * every approved day off in the business over that week — true, unreadable,
     * and no help in deciding whether this one can be covered.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function clashes(LeaveRequest $r): Collection
    {
        $employee = $r->employee;

        if (! $employee?->department_id) {
            return collect();
        }

        return LeaveRequest::with('employee')
            ->whereHas('employee', fn ($q) => $q
                ->where('company_id', $employee->company_id)
                ->where('department_id', $employee->department_id))
            ->where('employee_id', '!=', $r->employee_id)
            ->approved()
            ->overlapping($r->start_date->toDateString(), $r->end_date->toDateString())
            ->get()
            ->map(fn (LeaveRequest $clash) => [
                'employee'   => $clash->employee?->full_name,
                'start_date' => $clash->start_date->toDateString(),
                'end_date'   => $clash->end_date->toDateString(),
            ])
            ->values();
    }
}
