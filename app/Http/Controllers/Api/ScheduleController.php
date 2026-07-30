<?php

namespace App\Http\Controllers\Api;

use App\Models\Holiday;
use App\Models\ShiftAssignment;
use App\Services\LeaveService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The days ahead: when the employee is on, off, or away.
 *
 * Only published roster days are visible. A roster still being planned is
 * deliberately invisible here — staff watching draft days move around is the
 * problem the publish step exists to prevent.
 */
class ScheduleController extends ApiController
{
    /** The furthest ahead one call will look. */
    public const MAX_DAYS = 92;

    public function __construct(
        protected LeaveService $leave,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $employee = $this->employee()->load('company', 'department');
        $today    = now($employee->company?->tz() ?? config('app.timezone'))->toDateString();

        $data = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to'   => 'nullable|date_format:Y-m-d',
        ]);

        $from = $data['from'] ?? $today;
        $to   = $data['to'] ?? Carbon::parse($from)->addDays(13)->toDateString();

        if ($from > $to) {
            return $this->fail('invalid_range', 'The start date must fall on or before the end date.');
        }

        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) >= self::MAX_DAYS) {
            return $this->fail('range_too_large', sprintf(
                'Ask for at most %d days at a time.', self::MAX_DAYS,
            ));
        }

        // One pass over the window rather than a query per day.
        $assignments = ShiftAssignment::with('shift')
            ->where('employee_id', $employee->id)
            ->published()
            ->between($from, $to)
            ->get()
            ->keyBy(fn (ShiftAssignment $a) => $a->date->toDateString());

        $holidays = $employee->company
            ? Holiday::namedBetween($employee->company_id, $from, $to)
            : [];

        $leaveDates = [];
        foreach ($employee->leaveRequests()->approved()->overlapping($from, $to)->with('leaveType')->get() as $request) {
            $span = Carbon::parse(max($request->start_date->toDateString(), $from));
            $last = Carbon::parse(min($request->end_date->toDateString(), $to));

            for ($day = $span->copy(); $day->lte($last); $day->addDay()) {
                $leaveDates[$day->toDateString()] ??= $request->leaveType?->name;
            }
        }

        $working = array_flip($this->leave->workingDatesBetween($employee->company, $from, $to));

        // The shift that applies when nobody has planned the day: the employee's
        // own if they have one, otherwise their department's.
        $standing = $employee->shift;

        $days = [];

        for ($day = Carbon::parse($from); $day->lte(Carbon::parse($to)); $day->addDay()) {
            $date       = $day->toDateString();
            $assignment = $assignments->get($date);

            // A rostered day off is a planned day with no hours, which is not the
            // same as a day nobody planned — hence the explicit check rather than
            // reading it off a null shift.
            $isDayOff   = (bool) $assignment?->is_day_off;
            $isWorking  = isset($working[$date]);

            // The plan wins where there is one: a Saturday somebody was rostered
            // onto is a Saturday they work. Where there is none, the standing
            // shift only applies on a day the company actually works — otherwise
            // every weekend and holiday would be served carrying 09:00–17:00,
            // and a client would print hours nobody is expected for.
            $shift = $assignment
                ? ($isDayOff ? null : $assignment->shift)
                : ($isWorking ? $standing : null);

            $days[] = [
                'date'    => $date,
                'weekday' => $day->format('D'),
                'shift'   => $shift ? [
                    'id'         => $shift->id,
                    'name'       => $shift->name,
                    'start_time' => $shift->start_time,
                    'end_time'   => $shift->end_time,
                    'color'      => $shift->color,
                ] : null,
                'is_day_off' => $isDayOff,
                // True when the day came off the published roster rather than
                // from the standing shift — the app can mark planned days.
                'is_rostered'    => $assignment !== null,
                'is_working_day' => $isWorking,
                'holiday'        => $holidays[$date] ?? null,
                'leave'          => $leaveDates[$date] ?? null,
            ];
        }

        return $this->ok([
            'from' => $from,
            'to'   => $to,
            'days' => $days,
            // What applies on any day nobody has planned, so the app can say
            // "your usual hours" rather than repeating it on every row.
            'standing_shift' => $standing ? [
                'id'         => $standing->id,
                'name'       => $standing->name,
                'start_time' => $standing->start_time,
                'end_time'   => $standing->end_time,
            ] : null,
        ]);
    }
}
