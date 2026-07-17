<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Shift;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
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

        $employees = Employee::with('department.shift')
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

        return view('shifts.roster', [
            'weekStart'  => $weekStart,
            'weekEnd'    => $weekEnd,
            'days'       => $days,
            'employees'  => $employees,
            'attendance' => $attendance,
            'today'      => Carbon::today(),
        ]);
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
