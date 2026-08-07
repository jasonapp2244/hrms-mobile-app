<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftSwapRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Planning the roster: setting a person's shift on a date, and generating a
 * rotation across a stretch of weeks.
 */
class RosterService
{
    /** Longest rotation cycle the planner accepts, in days. */
    public const MAX_CYCLE = 7;

    /** Most weeks that can be generated in one go. */
    public const MAX_WEEKS = 12;

    /**
     * Plan one employee on one date.
     *
     * `$value` is a shift id, the string 'off' for a rostered day off, or null
     * to clear the plan and fall back to their standing shift. Clearing is a
     * delete rather than a null row: "nothing planned" and "planned as nothing"
     * would otherwise be indistinguishable.
     */
    public function setDay(Employee $employee, string $date, int|string|null $value, ?int $userId = null): ?ShiftAssignment
    {
        if ($value === null || $value === '') {
            ShiftAssignment::where('employee_id', $employee->id)->whereDate('date', $date)->delete();

            return null;
        }

        $isDayOff = $value === 'off';

        if (! $isDayOff) {
            $shift = Shift::find($value);

            if (! $shift || $shift->company_id !== $employee->company_id) {
                throw ValidationException::withMessages([
                    'roster' => 'That shift is not available for this company.',
                ]);
            }
        }

        $attributes = [
            'company_id' => $employee->company_id,
            'shift_id'   => $isDayOff ? null : (int) $value,
            'is_day_off' => $isDayOff,
            'created_by' => $userId,
        ];

        // Looked up with whereDate rather than updateOrCreate: `date` is cast to
        // a date, so the stored value carries a 00:00:00 time on any engine
        // without a real DATE type. An equality match would miss it, insert a
        // second row and hit the unique index.
        $existing = ShiftAssignment::where('employee_id', $employee->id)
            ->whereDate('date', $date)->first();

        if ($existing) {
            $existing->update($attributes);

            return $existing;
        }

        return ShiftAssignment::create($attributes + [
            'employee_id' => $employee->id,
            'date'        => $date,
        ]);
    }

    /**
     * Generate a repeating rotation.
     *
     * The cycle is a list of shift ids and 'off' entries applied to consecutive
     * days from the start date and repeated. Its length is what makes the
     * pattern rotate: a seven-entry cycle repeats on the same weekdays forever,
     * while a four-entry one walks around the week, which is what "four on,
     * four off" actually means.
     *
     * Existing plans in the range are replaced. Generating is how a planner
     * starts a period, so leaving old entries interleaved would produce a roster
     * nobody asked for.
     *
     * @param  array<int, string|int>  $cycle
     * @return int  assignments written
     */
    public function generateRotation(
        iterable $employees,
        array $cycle,
        string $startDate,
        int $weeks,
        ?int $userId = null,
    ): int {
        $cycle = array_values(array_filter($cycle, fn ($v) => $v !== null && $v !== ''));

        if ($cycle === []) {
            throw ValidationException::withMessages([
                'cycle' => 'Add at least one day to the pattern.',
            ]);
        }

        if (count($cycle) > self::MAX_CYCLE) {
            throw ValidationException::withMessages([
                'cycle' => 'A pattern can be at most ' . self::MAX_CYCLE . ' days long.',
            ]);
        }

        $weeks = max(1, min($weeks, self::MAX_WEEKS));
        $start = Carbon::parse($startDate)->startOfDay();
        $days  = $weeks * 7;
        $end   = $start->copy()->addDays($days - 1);

        $written = 0;

        DB::transaction(function () use ($employees, $cycle, $start, $end, $days, $userId, &$written) {
            foreach ($employees as $employee) {
                // Replace rather than merge, so the generated period is exactly
                // the pattern and not the pattern layered over an older plan.
                ShiftAssignment::where('employee_id', $employee->id)
                    ->between($start->toDateString(), $end->toDateString())
                    ->delete();

                foreach (range(0, $days - 1) as $offset) {
                    $value = $cycle[$offset % count($cycle)];

                    $this->setDay(
                        $employee,
                        $start->copy()->addDays($offset)->toDateString(),
                        $value,
                        $userId,
                    );
                    $written++;
                }
            }
        });

        return $written;
    }

    /**
     * Make a week visible to staff.
     *
     * Drafts are deliberately invisible: a planner has to be able to move people
     * around without everyone watching it change under them.
     *
     * @return int  assignments published
     */
    public function publish(int $companyId, string $from, string $to): int
    {
        $unpublished = ShiftAssignment::with('employee.user')
            ->where('company_id', $companyId)
            ->between($from, $to)
            ->whereNull('published_at')
            ->get();

        if ($unpublished->isEmpty()) {
            return 0;
        }

        $count = ShiftAssignment::where('company_id', $companyId)
            ->between($from, $to)
            ->whereNull('published_at')
            ->update(['published_at' => now()]);

        $this->announce($unpublished, $from, $to);

        return $count;
    }

    /**
     * Tell each affected employee their roster is up (A9.5).
     *
     * One notification per person covering the whole range, not one per day —
     * publishing a week would otherwise send seven, which is the pattern that
     * gets an app muted and an email address filtered.
     *
     * Sent after the update rather than before, so a person who opens the link
     * immediately sees a published roster rather than an empty screen.
     *
     * Anybody without a user account is skipped in silence. Plenty of staff on
     * a roster have never signed in, and that is not an error worth surfacing
     * to whoever pressed Publish.
     *
     * @param  \Illuminate\Support\Collection<int, ShiftAssignment>  $assignments
     */
    protected function announce($assignments, string $from, string $to): void
    {
        foreach ($assignments->groupBy('employee_id') as $forEmployee) {
            $user = $forEmployee->first()->employee?->user;

            if (! $user) {
                continue;
            }

            // Always the "ready" wording. Nothing records whether a row was
            // published before and pulled back, so "changed" would be a guess —
            // and telling somebody their shift moved when it did not is worse
            // than the milder message. The notification still carries the
            // isChange flag for a caller that genuinely knows.
            $user->notify(new \App\Notifications\ScheduleUpdated(
                from: $from,
                to: $to,
                days: $forEmployee->count(),
            ));
        }
    }

    /** Take a week back off the board. */
    public function unpublish(int $companyId, string $from, string $to): int
    {
        return ShiftAssignment::where('company_id', $companyId)
            ->between($from, $to)
            ->whereNotNull('published_at')
            ->update(['published_at' => null]);
    }

    // ---- shift swaps ----

    /**
     * Raise a swap: the requester offers their rostered day and asks for the
     * colleague's.
     *
     * Both people must actually be working the days being traded — there is
     * nothing to swap otherwise — and on a cross-date swap neither may already
     * be working the day they would be moving onto, or approving it would
     * double-book them.
     */
    public function requestSwap(
        Employee $requester,
        string $requesterDate,
        Employee $target,
        string $targetDate,
        ?string $reason = null,
    ): ShiftSwapRequest {
        if ($requester->id === $target->id) {
            throw ValidationException::withMessages([
                'target_id' => 'Pick a colleague to swap with.',
            ]);
        }

        if ($target->company_id !== $requester->company_id) {
            throw ValidationException::withMessages([
                'target_id' => 'That employee is not available.',
            ]);
        }

        if (! $requester->shiftOn($requesterDate)) {
            throw ValidationException::withMessages([
                'requester_date' => 'You are not rostered to work that day, so there is nothing to swap.',
            ]);
        }

        if (! $target->shiftOn($targetDate)) {
            throw ValidationException::withMessages([
                'target_date' => $target->first_name . ' is not rostered to work that day.',
            ]);
        }

        if ($requesterDate !== $targetDate) {
            if ($requester->shiftOn($targetDate)) {
                throw ValidationException::withMessages([
                    'target_date' => 'You are already working that day — swapping onto it would double-book you.',
                ]);
            }

            if ($target->shiftOn($requesterDate)) {
                throw ValidationException::withMessages([
                    'requester_date' => $target->first_name . ' is already working your day.',
                ]);
            }
        }

        // One open request per pair of days, so two people cannot queue up
        // conflicting trades on the same shift.
        $existing = ShiftSwapRequest::open()
            ->where('requester_id', $requester->id)
            ->whereDate('requester_date', $requesterDate)
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages([
                'requester_date' => 'You already have a swap open for that day.',
            ]);
        }

        return ShiftSwapRequest::create([
            'company_id'     => $requester->company_id,
            'requester_id'   => $requester->id,
            'requester_date' => $requesterDate,
            'target_id'      => $target->id,
            'target_date'    => $targetDate,
            'reason'         => $reason,
            'status'         => 'pending',
        ]);
    }

    /** The colleague agrees. Nothing moves yet — a manager still has to sanction it. */
    public function acceptSwap(ShiftSwapRequest $swap, ?string $note = null): void
    {
        if (! $swap->isAwaitingColleague()) {
            throw ValidationException::withMessages([
                'status' => 'This swap is no longer waiting on you.',
            ]);
        }

        $swap->update([
            'status'        => 'accepted',
            'responded_at'  => now(),
            'response_note' => $note,
        ]);
    }

    public function declineSwap(ShiftSwapRequest $swap, ?string $note = null): void
    {
        if (! $swap->isAwaitingColleague()) {
            throw ValidationException::withMessages([
                'status' => 'This swap is no longer waiting on you.',
            ]);
        }

        $swap->update([
            'status'        => 'declined',
            'responded_at'  => now(),
            'response_note' => $note,
        ]);
    }

    public function cancelSwap(ShiftSwapRequest $swap): void
    {
        if (! $swap->isOpen()) {
            throw ValidationException::withMessages([
                'status' => 'This swap has already been ' . strtolower($swap->status_label) . '.',
            ]);
        }

        $swap->update(['status' => 'cancelled']);
    }

    /**
     * Sanction an agreed swap and move the roster.
     *
     * The shifts are read now rather than at request time, because the plan can
     * be regenerated underneath a pending swap. If either day has since stopped
     * being a working day the swap is refused instead of applied to whatever
     * happens to sit there.
     *
     * Both sides are written explicitly, including the days off, so a cross-date
     * swap leaves neither person silently still rostered on their old day.
     */
    public function approveSwap(ShiftSwapRequest $swap, ?int $approverId = null, ?string $note = null): void
    {
        if (! $swap->isAwaitingApproval()) {
            throw ValidationException::withMessages([
                'status' => $swap->isAwaitingColleague()
                    ? 'The colleague has not accepted this swap yet.'
                    : 'This swap has already been ' . strtolower($swap->status_label) . '.',
            ]);
        }

        $requester = $swap->requester;
        $target    = $swap->target;
        $from      = $swap->requester_date->toDateString();
        $to        = $swap->target_date->toDateString();

        $requesterShift = $requester->shiftOn($from);
        $targetShift    = $target->shiftOn($to);

        if (! $requesterShift || ! $targetShift) {
            throw ValidationException::withMessages([
                'status' => 'The roster has changed since this swap was agreed — one of the '
                    . 'days is no longer a working shift. Ask them to raise it again.',
            ]);
        }

        DB::transaction(function () use ($swap, $requester, $target, $from, $to, $requesterShift, $targetShift, $approverId, $note) {
            if ($from === $to) {
                // Same day: the two simply trade shifts.
                $this->setDay($requester, $from, $targetShift->id, $approverId);
                $this->setDay($target, $to, $requesterShift->id, $approverId);
            } else {
                // Different days: each takes the other's day and is off their own.
                $this->setDay($requester, $to, $targetShift->id, $approverId);
                $this->setDay($requester, $from, 'off', $approverId);
                $this->setDay($target, $from, $requesterShift->id, $approverId);
                $this->setDay($target, $to, 'off', $approverId);
            }

            // Published straight away: the people affected have already agreed
            // to it, so there is nothing to stage.
            ShiftAssignment::whereIn('employee_id', [$requester->id, $target->id])
                ->where(fn ($q) => $q->whereDate('date', $from)->orWhereDate('date', $to))
                ->update(['published_at' => now()]);

            $swap->update([
                'status'        => 'approved',
                'approved_by'   => $approverId,
                'approved_at'   => now(),
                'decision_note' => $note,
            ]);
        });
    }

    public function rejectSwap(ShiftSwapRequest $swap, ?int $approverId = null, ?string $note = null): void
    {
        if (! $swap->isOpen()) {
            throw ValidationException::withMessages([
                'status' => 'This swap has already been ' . strtolower($swap->status_label) . '.',
            ]);
        }

        $swap->update([
            'status'        => 'rejected',
            'approved_by'   => $approverId,
            'approved_at'   => now(),
            'decision_note' => $note,
        ]);
    }

    /**
     * Assignments for a week, indexed employee_id => 'Y-m-d' => assignment.
     *
     * @return array<int, array<string, ShiftAssignment>>
     */
    public function weekMap(int $companyId, string $from, string $to): array
    {
        $map = [];

        foreach (ShiftAssignment::with('shift')->where('company_id', $companyId)->between($from, $to)->get() as $a) {
            $map[$a->employee_id][$a->date->toDateString()] = $a;
        }

        return $map;
    }
}
