<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Office;
use App\Services\AttendanceService;
use App\Services\LeaveService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function __construct(
        protected AttendanceService $attendance,
        protected LeaveService $leave,
    ) {}

    protected function companyId(): int
    {
        return auth()->user()->company_id ?? Office::value('company_id');
    }

    /** Attendance overview with today's summary tiles + recent feed. */
    public function index()
    {
        $companyId = $this->companyId();
        $summary = $this->attendance->daySummary($companyId);

        $recent = AttendanceLog::with(['employee', 'office'])
            ->whereHas('employee', fn ($q) => $q->where('company_id', $companyId))
            ->latest('scanned_at')
            ->limit(15)
            ->get();

        // Named, not just counted: an absence tile nobody can drill into is the
        // first thing HR asks about.
        $onLeave = $this->leave->onLeaveOn($companyId)->sortBy(fn ($r) => $r->employee?->first_name);

        return view('attendance.index', compact('summary', 'recent', 'onLeave'));
    }

    /** Full, filterable attendance log table. */
    public function logs(Request $request)
    {
        $companyId = $this->companyId();

        $logs = AttendanceLog::with(['employee', 'office'])
            ->whereHas('employee', fn ($q) => $q->where('company_id', $companyId))
            // Voided punches are hidden by a global scope. Shown here on request
            // so HR can see what was struck out and why — the trail is worth
            // nothing if the only screen that lists punches cannot display it.
            ->when($request->boolean('show_voided'), fn ($q) => $q->withVoided())
            ->when($request->filled('date'), fn ($q) => $q->whereDate('work_date', $request->date))
            ->when($request->filled('office_id'), fn ($q) => $q->where('office_id', $request->office_id))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('scanned_at')
            ->paginate(25)
            ->withQueryString();

        $offices = Office::where('company_id', $companyId)->get();

        $employees = Employee::where('company_id', $companyId)
            ->where('status', 'active')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'employee_code']);

        return view('attendance.logs', compact('logs', 'offices', 'employees'));
    }

    /**
     * Strike out a punch that should not count.
     *
     * The row is kept. Correcting attendance is void-then-re-enter, never an
     * edit, so both the wrong reading and the right one stay on the record —
     * which is the only version of this feature that survives someone
     * disputing their pay.
     */
    public function void(Request $request, AttendanceLog $log)
    {
        abort_unless($log->employee?->company_id === $this->companyId(), 404);

        $data = $request->validate([
            // Not nullable and not trivially satisfiable: a void with no stated
            // cause is indistinguishable from a mistake six months later.
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $log->void(auth()->user(), $data['reason']);

        return back()->with('success', 'Punch voided. It no longer counts towards worked hours.');
    }

    /** Key in a punch that was never recorded — a missed check-out, a failed badge. */
    public function storeManual(Request $request)
    {
        $companyId = $this->companyId();

        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'office_id'   => ['required', 'integer', 'exists:offices,id'],
            'type'        => ['required', 'in:in,out'],
            'scanned_at'  => ['required', 'date'],
            'reason'      => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $employee = Employee::where('company_id', $companyId)->findOrFail($data['employee_id']);
        $office   = Office::where('company_id', $companyId)->findOrFail($data['office_id']);

        // Read in the company's timezone: HR types a wall-clock reading, and
        // parsing it as the app's UTC would silently shift the punch by the
        // office's offset and change whether it counts as late.
        $at = Carbon::parse($data['scanned_at'], $employee->company?->tz() ?? config('app.timezone'));

        if ($at->isFuture()) {
            return back()
                ->withInput()
                ->withErrors(['scanned_at' => 'A punch cannot be recorded for a time that has not happened yet.']);
        }

        $log = $this->attendance->recordManual($employee, $office, $data['type'], $at, $data['reason']);

        return back()->with('success', sprintf(
            'Manual %s recorded for %s at %s (%s).',
            strtoupper($log->type),
            $employee->first_name . ' ' . $employee->last_name,
            $at->format('d M Y H:i'),
            $log->status,
        ));
    }

    /** Build the filtered report dataset shared by the view + exports. */
    protected function reportData(Request $request): array
    {
        $companyId = $this->companyId();
        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to = $request->input('to', now()->toDateString());

        $logs = AttendanceLog::with(['employee', 'office'])
            ->whereHas('employee', fn ($q) => $q->where('company_id', $companyId))
            ->forDates($from, $to)
            ->when($request->filled('office_id'), fn ($q) => $q->where('office_id', $request->office_id))
            ->latest('scanned_at')
            ->get();

        $stats = [
            'total_scans' => $logs->count(),
            'late'        => $logs->where('type', 'in')->where('status', 'late')->count(),
            'ontime'      => $logs->where('type', 'in')->where('status', 'ontime')->count(),
            'days'        => $logs->pluck('work_date')->unique()->count(),
            // Employee-days of approved leave in the window, so a thin-looking
            // period is explainable rather than just looking like poor turnout.
            'leave_days'  => collect($this->leave->leaveDatesByEmployee($companyId, $from, $to))
                                ->flatten()->count(),
        ];

        $offices = Office::where('company_id', $companyId)->get();
        $office = $request->filled('office_id') ? $offices->firstWhere('id', (int) $request->office_id) : null;

        return compact('logs', 'offices', 'office', 'from', 'to', 'stats');
    }

    /** Attendance report view (filters + summary). */
    public function report(Request $request)
    {
        return view('attendance.report', $this->reportData($request));
    }

    /** Export the current report as a PDF. */
    public function exportPdf(Request $request)
    {
        $data = $this->reportData($request);
        $data['company'] = \App\Models\Company::find($this->companyId());

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('attendance.report-pdf', $data)
            ->setPaper('a4', 'landscape');

        return $pdf->download('attendance-report_' . $data['from'] . '_to_' . $data['to'] . '.pdf');
    }

    /** Export the current report as an Excel (.xlsx) file. */
    public function exportExcel(Request $request)
    {
        $data = $this->reportData($request);
        $filename = 'attendance-report_' . $data['from'] . '_to_' . $data['to'] . '.xlsx';

        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\AttendanceExport($data['logs']),
            $filename
        );
    }
}
