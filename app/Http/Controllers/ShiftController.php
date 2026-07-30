<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Shift;
use App\Services\LeaveService;
use App\Services\RosterService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    public function __construct(
        protected LeaveService $leave,
        protected RosterService $roster,
    ) {}

    protected function companyId(): int
    {
        return auth()->user()->company_id ?? Office::value('company_id');
    }

    public function index()
    {
        $shifts = Shift::withCount(['departments', 'employees'])
            ->where('company_id', $this->companyId())
            ->orderBy('start_time')
            ->paginate(15);

        return view('shifts.index', compact('shifts'));
    }

    /** Weekly roster: employees x Mon-Sun with scheduled shift + actual attendance. */
    public function roster(Request $request)
    {
        $companyId = $this->companyId();

        $weekStart = ($request->filled('week') ? Carbon::parse($request->week) : Carbon::now())
            ->startOfWeek(Carbon::MONDAY);
        $weekEnd = (clone $weekStart)->endOfWeek(Carbon::SUNDAY);

        $days = collect(range(0, 6))->map(fn ($i) => (clone $weekStart)->addDays($i));

        // shiftOverride eager loaded alongside the department's: the roster reads
        // $emp->shift for every cell, and that resolves one or the other.
        $employees = Employee::with('department.shift', 'shiftOverride')
            ->where('company_id', $companyId)->active()
            ->orderBy('department_id')->orderBy('first_name')->get();

        // Attendance status per employee per date (from the day's first IN scan).
        $logs = AttendanceLog::where('type', 'in')
            ->whereBetween('work_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->whereHas('employee', fn ($q) => $q->where('company_id', $companyId))
            ->get(['employee_id', 'work_date', 'status']);

        $attendance = [];
        foreach ($logs as $log) {
            $attendance[$log->employee_id][Carbon::parse($log->work_date)->toDateString()] = $log->status;
        }

        $company = Company::find($companyId);

        // The roster used to hardcode Saturday and Sunday as off and had no idea
        // about holidays or leave, so anyone on booked time off showed as absent
        // — the roster contradicting the leave register it sits next to.
        $weekend  = $this->leave->weekendDays($company);
        $holidays = collect(\App\Models\Holiday::datesBetween(
            $companyId, $weekStart->toDateString(), $weekEnd->toDateString(),
        ))->flip();

        $leaveDates = $this->leave->leaveDatesByEmployee(
            $companyId, $weekStart->toDateString(), $weekEnd->toDateString(),
        );
        $onLeave = collect($leaveDates)->map(fn (array $dates) => collect($dates)->flip());

        $plan = $this->roster->weekMap(
            $companyId, $weekStart->toDateString(), $weekEnd->toDateString(),
        );

        $planned = collect($plan)->flatten(1);

        return view('shifts.roster', [
            'weekStart'   => $weekStart,
            'weekEnd'     => $weekEnd,
            'days'        => $days,
            'employees'   => $employees,
            'attendance'  => $attendance,
            'weekend'     => $weekend,
            'holidays'    => $holidays,
            'onLeave'     => $onLeave,
            'plan'        => $plan,
            'shifts'      => Shift::where('company_id', $companyId)->active()->orderBy('start_time')->get(),
            'planning'    => $request->boolean('plan'),
            'plannedCount'   => $planned->count(),
            'unpublishedCount' => $planned->whereNull('published_at')->count(),
            'today'       => Carbon::today(),
        ]);
    }

    /** Save a whole week of the planner in one submit. */
    public function saveRoster(Request $request)
    {
        $companyId = $this->companyId();

        $data = $request->validate([
            'week'        => 'required|date',
            // roster[employeeId][Y-m-d] = shift id, 'off', or '' to clear.
            'roster'      => 'array',
            'roster.*'    => 'array',
        ]);

        $employees = Employee::where('company_id', $companyId)
            ->whereIn('id', array_keys($data['roster'] ?? []))
            ->get()
            ->keyBy('id');

        $changed = 0;

        foreach ($data['roster'] ?? [] as $employeeId => $days) {
            $employee = $employees->get((int) $employeeId);

            // Silently skipped rather than 403: a stale form could name someone
            // who has since been moved out of the company, and the rest of the
            // week is still worth saving.
            if (! $employee) {
                continue;
            }

            foreach ($days as $date => $value) {
                $this->roster->setDay($employee, $date, $value, auth()->id());
                $changed++;
            }
        }

        return redirect()
            ->route('shifts.roster', ['week' => $data['week'], 'plan' => 1])
            ->with('success', "Roster saved for {$changed} day(s). Publish the week to make it visible to staff.");
    }

    /** Generate a repeating rotation across several weeks. */
    public function generateRotation(Request $request)
    {
        $companyId = $this->companyId();

        $data = $request->validate([
            'week'          => 'required|date',
            'start_date'    => 'required|date',
            'weeks'         => 'required|integer|min:1|max:' . RosterService::MAX_WEEKS,
            'employee_ids'  => 'required|array|min:1',
            'employee_ids.*' => 'integer',
            'cycle'         => 'required|array|min:1|max:' . RosterService::MAX_CYCLE,
        ], [
            'employee_ids.required' => 'Choose at least one employee to put on the rotation.',
        ]);

        $employees = Employee::where('company_id', $companyId)
            ->whereIn('id', $data['employee_ids'])->active()->get();

        abort_if($employees->isEmpty(), 403);

        $written = $this->roster->generateRotation(
            $employees, $data['cycle'], $data['start_date'], (int) $data['weeks'], auth()->id(),
        );

        return redirect()
            ->route('shifts.roster', ['week' => $data['week'], 'plan' => 1])
            ->with('success', sprintf(
                '%d day(s) planned for %d employee(s). The rotation is a draft until you publish it.',
                $written, $employees->count(),
            ));
    }

    /** Publish or withdraw the displayed week. */
    public function publishRoster(Request $request)
    {
        $companyId = $this->companyId();

        $data = $request->validate([
            'week'   => 'required|date',
            'action' => 'required|in:publish,unpublish',
        ]);

        $from = Carbon::parse($data['week'])->startOfWeek(Carbon::MONDAY);
        $to   = $from->copy()->endOfWeek(Carbon::SUNDAY);

        $count = $data['action'] === 'publish'
            ? $this->roster->publish($companyId, $from->toDateString(), $to->toDateString())
            : $this->roster->unpublish($companyId, $from->toDateString(), $to->toDateString());

        return redirect()
            ->route('shifts.roster', ['week' => $data['week'], 'plan' => 1])
            ->with('success', $data['action'] === 'publish'
                ? "{$count} day(s) published — staff can now see this week."
                : "{$count} day(s) withdrawn — this week is a draft again.");
    }

    public function store(Request $request)
    {
        $data = $this->validateShift($request);
        $data['company_id'] = $this->companyId();
        Shift::create($data);

        return back()->with('success', 'Shift created.');
    }

    public function update(Request $request, Shift $shift)
    {
        abort_unless($shift->company_id === $this->companyId(), 403);
        $shift->update($this->validateShift($request));

        return back()->with('success', 'Shift updated.');
    }

    public function destroy(Shift $shift)
    {
        abort_unless($shift->company_id === $this->companyId(), 403);
        $shift->delete();

        return back()->with('success', 'Shift deleted.');
    }

    protected function validateShift(Request $request): array
    {
        return $request->validate([
            'name'               => 'required|string|max:100',
            'code'               => 'nullable|string|max:30',
            'start_time'         => 'required|date_format:H:i',
            'end_time'           => 'required|date_format:H:i',
            'break_minutes'      => 'required|integer|min:0|max:480',
            'late_grace_minutes' => 'required|integer|min:0|max:120',
            'color'              => 'nullable|string|max:20',
            'is_active'          => 'nullable|boolean',
        ]);
    }
}
