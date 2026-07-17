<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Office;
use App\Services\AttendanceService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(protected AttendanceService $attendance) {}

    public function index()
    {
        $companyId = auth()->user()->company_id ?? Office::value('company_id');

        $summary = $this->attendance->daySummary($companyId);

        $stats = [
            'employees'   => Employee::where('company_id', $companyId)->active()->count(),
            'departments' => Department::where('company_id', $companyId)->count(),
            'offices'     => Office::where('company_id', $companyId)->count(),
            'present'     => $summary['present'],
            'late'        => $summary['late'],
            'absent'      => $summary['absent'],
        ];

        $recent = AttendanceLog::with(['employee', 'office'])
            ->whereHas('employee', fn ($q) => $q->where('company_id', $companyId))
            ->latest('scanned_at')
            ->limit(10)
            ->get();

        // Last 7 days present-count trend (for the chart)
        $trend = collect(range(6, 0))->map(function ($daysAgo) use ($companyId) {
            $date = now()->subDays($daysAgo)->toDateString();
            $count = AttendanceLog::whereHas('employee', fn ($q) => $q->where('company_id', $companyId))
                ->where('work_date', $date)->where('type', 'in')
                ->distinct('employee_id')->count('employee_id');
            return ['label' => now()->subDays($daysAgo)->format('D'), 'count' => $count];
        });

        return view('dashboard', compact('stats', 'recent', 'trend'));
    }
}
