<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Office;
use App\Services\AttendanceService;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function __construct(
        protected AttendanceService $attendance,
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

        return view('attendance.index', compact('summary', 'recent'));
    }

    /** Full, filterable attendance log table. */
    public function logs(Request $request)
    {
        $companyId = $this->companyId();

        $logs = AttendanceLog::with(['employee', 'office'])
            ->whereHas('employee', fn ($q) => $q->where('company_id', $companyId))
            ->when($request->filled('date'), fn ($q) => $q->where('work_date', $request->date))
            ->when($request->filled('office_id'), fn ($q) => $q->where('office_id', $request->office_id))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest('scanned_at')
            ->paginate(25)
            ->withQueryString();

        $offices = Office::where('company_id', $companyId)->get();

        return view('attendance.logs', compact('logs', 'offices'));
    }

    /** Build the filtered report dataset shared by the view + exports. */
    protected function reportData(Request $request): array
    {
        $companyId = $this->companyId();
        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to = $request->input('to', now()->toDateString());

        $logs = AttendanceLog::with(['employee', 'office'])
            ->whereHas('employee', fn ($q) => $q->where('company_id', $companyId))
            ->whereBetween('work_date', [$from, $to])
            ->when($request->filled('office_id'), fn ($q) => $q->where('office_id', $request->office_id))
            ->latest('scanned_at')
            ->get();

        $stats = [
            'total_scans' => $logs->count(),
            'late'        => $logs->where('type', 'in')->where('status', 'late')->count(),
            'ontime'      => $logs->where('type', 'in')->where('status', 'ontime')->count(),
            'days'        => $logs->pluck('work_date')->unique()->count(),
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
