<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The rules behind a leave request: how many days it actually costs, whether the
 * employee may take it, and how the balance moves as it is granted or given back.
 *
 * Business rules live here rather than in the controller because the mobile API
 * (Phase 3) and the approval screens (Phase 4.6) have to apply exactly the same
 * ones. A rule enforced in a form is a rule the API will not have.
 */
class LeaveService
{
    public function __construct(
        protected NotificationService $notifications,
    ) {}

    /**
     * Non-working days when the company has not configured its own.
     * Carbon numbers the week Sunday=0 … Saturday=6.
     */
    public const DEFAULT_WEEKEND = [0, 6];

    /**
     * The company's weekend, from `companies.settings.weekend_days`.
     *
     * There is no UI for this yet (it is planned as A2.8, working-days per
     * office). Reading it from settings now means a company on a Fri/Sat weekend
     * can be corrected with one row update instead of a code change.
     */
    public function weekendDays(?Company $company): array
    {
        $configured = $company?->settings['weekend_days'] ?? null;

        if (! is_array($configured) || $configured === []) {
            return self::DEFAULT_WEEKEND;
        }

        return array_map('intval', $configured);
    }

    /**
     * The dates in a range the company actually works, as 'Y-m-d' strings.
     *
     * The single definition of a working day. Leave charges against it and
     * attendance measures absence against it, so the two cannot disagree about
     * whether a Saturday counts.
     *
     * @return array<int, string>
     */
    public function workingDatesBetween(?Company $company, string $from, string $to): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end   = Carbon::parse($to)->startOfDay();

        if ($end->lt($start)) {
            return [];
        }

        $weekend  = $this->weekendDays($company);
        $holidays = $company
            ? Holiday::datesBetween($company->id, $start->toDateString(), $end->toDateString())
            : [];

        $dates = [];

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            if (in_array($day->dayOfWeek, $weekend, true)) {
                continue;
            }
            if (in_array($day->toDateString(), $holidays, true)) {
                continue;
            }
            $dates[] = $day->toDateString();
        }

        return $dates;
    }

    /**
     * Days actually charged for a leave range.
     *
     * Weekends and company holidays are free — an employee who books Friday to
     * Monday over a two-day weekend spends two days, not four. A half day is
     * always a single date and always costs 0.5.
     */
    public function chargeableDays(?Company $company, string $from, string $to, bool $halfDay = false): float
    {
        $days = (float) count($this->workingDatesBetween($company, $from, $to));

        // A half day is validated to a single date, so this only ever halves a
        // one-day request — and stays 0 if that date was a weekend or holiday.
        if ($halfDay) {
            return $days > 0 ? 0.5 : 0.0;
        }

        return $days;
    }

    /**
     * Who is on approved leave on a given date, keyed by employee id.
     *
     * The request itself is returned, not just the id, so callers can name the
     * leave type on screen without a second lookup.
     *
     * @return \Illuminate\Support\Collection<int, LeaveRequest>
     */
    public function onLeaveOn(int $companyId, ?string $date = null): \Illuminate\Support\Collection
    {
        $date ??= now()->toDateString();

        return LeaveRequest::with('leaveType', 'employee')
            ->where('company_id', $companyId)
            ->approved()
            ->overlapping($date, $date)
            ->get()
            ->keyBy('employee_id');
    }

    /**
     * Working dates covered by approved leave in a range, per employee.
     *
     * Weekends and holidays inside a leave range are excluded, because they were
     * never charged for either — a week off does not make somebody "on leave" on
     * the Sunday in the middle of it.
     *
     * A half day appears as a full date here. The employee was legitimately away
     * for part of it, and the only question this answers is whether to hold their
     * absence against them.
     *
     * @return array<int, array<int, string>> employee_id => ['Y-m-d', …]
     */
    public function leaveDatesByEmployee(int $companyId, string $from, string $to): array
    {
        $company = Company::find($companyId);

        // One pass over the calendar for the whole window, then intersect each
        // request with it — rather than recomputing weekends per request.
        $working = array_flip($this->workingDatesBetween($company, $from, $to));

        $requests = LeaveRequest::where('company_id', $companyId)
            ->approved()
            ->overlapping($from, $to)
            ->get();

        $out = [];

        foreach ($requests as $request) {
            $start = $request->start_date->toDateString();
            $end   = $request->end_date->toDateString();

            $span = Carbon::parse(max($start, $from));
            $last = Carbon::parse(min($end, $to));

            for ($day = $span->copy(); $day->lte($last); $day->addDay()) {
                $date = $day->toDateString();

                if (isset($working[$date])) {
                    $out[$request->employee_id][$date] = $date;
                }
            }
        }

        // Keyed by date while building so overlapping requests cannot count the
        // same day twice; handed back as plain lists.
        return array_map('array_values', $out);
    }

    /**
     * The employee's balance row for a type and year, created on first use.
     *
     * Entitlement is copied from the leave type at creation time and then left
     * alone: raising next year's allowance must not silently rewrite what people
     * were granted this year. HR adjusts an individual balance by editing the row.
     */
    public function balanceFor(Employee $employee, LeaveType $type, ?int $year = null): LeaveBalance
    {
        return LeaveBalance::firstOrCreate(
            [
                'employee_id'   => $employee->id,
                'leave_type_id' => $type->id,
                'year'          => $year ?? (int) date('Y'),
            ],
            ['entitled_days' => $type->days_per_year]
        );
    }

    /**
     * Every active type for the company with this employee's balance attached,
     * for the balance cards and the apply form.
     *
     * @return \Illuminate\Support\Collection<int, array{type: LeaveType, balance: LeaveBalance}>
     */
    public function balanceSummary(Employee $employee, ?int $year = null): \Illuminate\Support\Collection
    {
        return LeaveType::where('company_id', $employee->company_id)
            ->active()
            ->orderBy('name')
            ->get()
            ->map(fn (LeaveType $type) => [
                'type'    => $type,
                'balance' => $this->balanceFor($employee, $type, $year),
            ]);
    }

    /**
     * Whether a type draws down a balance at all.
     *
     * A type granting zero days is treated as uncapped rather than unusable —
     * that is how unpaid leave is set up, and a type nobody could ever book
     * would be pointless. Usage is still recorded against it for reporting.
     */
    public function isCapped(LeaveBalance $balance): bool
    {
        return $balance->total > 0;
    }

    /**
     * Submit a request on the employee's behalf.
     *
     * Business-rule failures are thrown as validation errors so they land on the
     * form field that caused them instead of surfacing as a 500.
     *
     * @param array{leave_type_id:int,start_date:string,end_date:string,is_half_day?:bool,half_day_period?:?string,reason?:?string} $data
     */
    public function submit(Employee $employee, array $data): LeaveRequest
    {
        $type = LeaveType::find($data['leave_type_id']);

        if (! $type || $type->company_id !== $employee->company_id || ! $type->is_active) {
            throw ValidationException::withMessages([
                'leave_type_id' => 'That leave type is not available.',
            ]);
        }

        $start    = $data['start_date'];
        $end      = $data['end_date'];
        $halfDay  = (bool) ($data['is_half_day'] ?? false);

        if (Carbon::parse($end)->lt(Carbon::parse($start))) {
            throw ValidationException::withMessages([
                'end_date' => 'The end date cannot be before the start date.',
            ]);
        }

        if ($halfDay) {
            if (! $type->allow_half_day) {
                throw ValidationException::withMessages([
                    'is_half_day' => "Half days are not allowed for {$type->name}.",
                ]);
            }
            if ($start !== $end) {
                throw ValidationException::withMessages([
                    'is_half_day' => 'A half day must start and end on the same date.',
                ]);
            }
        }

        $days = $this->chargeableDays($employee->company, $start, $end, $halfDay);

        if ($days <= 0) {
            throw ValidationException::withMessages([
                'start_date' => 'That range contains no working days — it falls entirely on '
                    . 'weekends or company holidays, so there is nothing to book.',
            ]);
        }

        // Pending and approved requests both hold the dates. Rejected and
        // cancelled ones do not, so the same dates can be requested again.
        $clash = $employee->leaveRequests()
            ->whereIn('status', ['pending', 'approved'])
            ->overlapping($start, $end)
            ->first();

        if ($clash) {
            throw ValidationException::withMessages([
                'start_date' => sprintf(
                    'You already have %s leave from %s to %s that overlaps these dates.',
                    strtolower($clash->status_label),
                    $clash->start_date->format('M j, Y'),
                    $clash->end_date->format('M j, Y'),
                ),
            ]);
        }

        $balance = $this->balanceFor($employee, $type, Carbon::parse($start)->year);

        if ($this->isCapped($balance) && $days > $balance->available) {
            throw ValidationException::withMessages([
                'leave_type_id' => sprintf(
                    'This request is %s day(s) but you have only %s day(s) of %s left.',
                    $this->format($days),
                    $this->format($balance->available),
                    $type->name,
                ),
            ]);
        }

        $request = DB::transaction(function () use ($employee, $type, $data, $start, $end, $halfDay, $days) {
            $request = LeaveRequest::create([
                'company_id'      => $employee->company_id,
                'employee_id'     => $employee->id,
                'leave_type_id'   => $type->id,
                'start_date'      => $start,
                'end_date'        => $end,
                'days'            => $days,
                'is_half_day'     => $halfDay,
                'half_day_period' => $halfDay ? ($data['half_day_period'] ?? 'first_half') : null,
                'reason'          => $data['reason'] ?? null,
                'status'          => 'pending',
            ]);

            // A type that needs no approval is granted on the spot, so the
            // employee is not left waiting on a decision nobody has to make.
            if (! $type->requires_approval) {
                $this->approve($request, null, 'Auto-approved — this leave type does not require approval.');
            }

            return $request->refresh();
        });

        // Outside the transaction: a notification is not part of the booking,
        // and holding a database transaction open while talking to a queue or a
        // mail server is how a lock turns into an outage. Auto-approved leave
        // has already told the employee, and has nobody to ask.
        if ($request->isPending()) {
            $this->notifications->leaveSubmitted($request);
        }

        return $request;
    }

    /**
     * The manager step: pass a request up to HR without granting it.
     *
     * Nothing is spent here. The days are only committed by the final decision,
     * so a request stuck between the two steps holds no balance.
     */
    public function managerApprove(LeaveRequest $request, int $approverId, ?string $note = null): void
    {
        if (! $request->isPending()) {
            throw ValidationException::withMessages([
                'status' => 'This request has already been ' . strtolower($request->status_label) . '.',
            ]);
        }

        if (! $request->isAwaitingManager()) {
            throw ValidationException::withMessages([
                'status' => 'This request has already passed the manager step — it is with HR.',
            ]);
        }

        $request->update([
            'manager_approved_by' => $approverId,
            'manager_approved_at' => now(),
            'manager_note'        => $note,
        ]);

        $this->notifications->leavePassedToHr($request->fresh());
    }

    /**
     * Grant a pending request and spend the days.
     *
     * Balance is only ever moved here and in release(), so used_days cannot drift
     * from the requests that caused it.
     */
    public function approve(LeaveRequest $request, ?int $approverId = null, ?string $note = null): void
    {
        if (! $request->isPending()) {
            throw ValidationException::withMessages([
                'status' => 'This request has already been ' . strtolower($request->status_label) . '.',
            ]);
        }

        // Re-check the balance at the moment of granting, not just at submission.
        // Days can be spent in between — by an auto-approved request, or by
        // another request approved earlier in the same queue — and without this
        // the second approval would push used_days past the entitlement.
        $balance = $this->balanceFor($request->employee, $request->leaveType, $request->start_date->year);

        if ($this->isCapped($balance) && (float) $request->days > $balance->available) {
            throw ValidationException::withMessages([
                'status' => sprintf(
                    'This request is %s day(s) but %s has only %s day(s) of %s left. '
                    . 'Adjust their balance or reject the request.',
                    $this->format((float) $request->days),
                    $request->employee->first_name,
                    $this->format($balance->available),
                    $request->leaveType->name,
                ),
            ]);
        }

        DB::transaction(function () use ($request, $approverId, $note) {
            $this->consume($request);

            $request->update([
                'status'        => 'approved',
                'approved_by'   => $approverId,
                'approved_at'   => now(),
                'decision_note' => $note,
            ]);
        });

        $this->notifications->leaveDecided($request->fresh());
    }

    /** Refuse a pending request. Nothing is spent, so nothing is returned. */
    public function reject(LeaveRequest $request, ?int $approverId = null, ?string $note = null): void
    {
        if (! $request->isPending()) {
            throw ValidationException::withMessages([
                'status' => 'This request has already been ' . strtolower($request->status_label) . '.',
            ]);
        }

        $request->update([
            'status'        => 'rejected',
            'approved_by'   => $approverId,
            'approved_at'   => now(),
            'decision_note' => $note,
        ]);

        $this->notifications->leaveDecided($request->fresh());
    }

    /**
     * Withdraw a request. Approved leave gives its days back; pending leave never
     * spent any, so it must not be credited or the balance would inflate.
     */
    public function cancel(LeaveRequest $request): void
    {
        if (! $request->isCancellable()) {
            throw ValidationException::withMessages([
                'status' => $request->status === 'approved'
                    ? 'Approved leave can only be cancelled before it starts. Speak to HR.'
                    : 'This request has already been ' . strtolower($request->status_label) . '.',
            ]);
        }

        DB::transaction(function () use ($request) {
            if ($request->status === 'approved') {
                $this->release($request);
            }

            $request->update(['status' => 'cancelled']);
        });
    }

    /** Move days out of the balance. */
    protected function consume(LeaveRequest $request): void
    {
        $balance = $this->balanceFor(
            $request->employee,
            $request->leaveType,
            $request->start_date->year,
        );

        $balance->increment('used_days', (float) $request->days);
    }

    /** Put days back. Clamped at zero so a hand-edited balance cannot go negative. */
    protected function release(LeaveRequest $request): void
    {
        $balance = $this->balanceFor(
            $request->employee,
            $request->leaveType,
            $request->start_date->year,
        );

        $balance->update([
            'used_days' => max(0, (float) $balance->used_days - (float) $request->days),
        ]);
    }

    /** 2.0 → "2", 0.5 → "0.5". Days are decimals but usually whole. */
    protected function format(float $days): string
    {
        return rtrim(rtrim(number_format($days, 1), '0'), '.');
    }
}
