<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftAssignment;
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
        return ShiftAssignment::where('company_id', $companyId)
            ->between($from, $to)
            ->whereNull('published_at')
            ->update(['published_at' => now()]);
    }

    /** Take a week back off the board. */
    public function unpublish(int $companyId, string $from, string $to): int
    {
        return ShiftAssignment::where('company_id', $companyId)
            ->between($from, $to)
            ->whereNotNull('published_at')
            ->update(['published_at' => null]);
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
