<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\Office;
use App\Services\AttendanceService;
use App\Services\QrTokenService;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function __construct(
        protected QrTokenService $qr,
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

    /** Kiosk screen — picks an office and shows the rotating QR. */
    public function kiosk(Request $request)
    {
        $companyId = $this->companyId();
        $offices = Office::where('company_id', $companyId)->where('is_active', true)->get();
        $office = $request->filled('office')
            ? $offices->firstWhere('id', (int) $request->office)
            : $offices->first();

        return view('attendance.kiosk', compact('offices', 'office'));
    }

    /** Build the current-QR JSON payload for an office (shared by authed + signed kiosk). */
    protected function qrJson(Office $office)
    {
        $payload = $this->qr->payload($office);
        $svg = (new SvgWriter())->write(new QrCode($payload))->getString();

        return response()->json([
            'office'      => $office->name,
            'svg'         => $svg,
            'expires_in'  => $this->qr->secondsUntilRotate(),
            'window'      => QrTokenService::WINDOW_SECONDS,
        ]);
    }

    /** AJAX: current QR for the in-dashboard kiosk (admin session). */
    public function qrToken(Office $office)
    {
        abort_unless($office->company_id === $this->companyId(), 403);
        return $this->qrJson($office);
    }

    /**
     * Full-screen, unattended kiosk display for a tablet/monitor at the entrance.
     * Reached via a permanent SIGNED URL — no admin login needed, cannot be
     * enumerated, and exposes nothing but this office's rotating QR.
     */
    public function kioskDisplay(Office $office)
    {
        abort_unless($office->is_active, 404);
        $qrUrl = \Illuminate\Support\Facades\URL::signedRoute(
            'attendance.kiosk.display.qr', ['office' => $office->id]
        );
        return view('attendance.kiosk-fullscreen', compact('office', 'qrUrl'));
    }

    /** AJAX: current QR for the signed full-screen kiosk display. */
    public function kioskDisplayQr(Office $office)
    {
        return $this->qrJson($office);
    }

    /** PWA scanner page (browser camera, no app install). */
    public function scanner()
    {
        $companyId = $this->companyId();
        $employees = Employee::where('company_id', $companyId)->active()
            ->orderBy('first_name')->get(['id', 'employee_code', 'first_name', 'last_name']);

        return view('attendance.scanner', compact('employees'));
    }

    /** AJAX: validate scanned QR + record clock in/out. Called by the PWA scanner. */
    public function scan(Request $request)
    {
        $data = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'payload'     => 'required|string',
            'latitude'    => 'nullable|numeric',
            'longitude'   => 'nullable|numeric',
        ]);

        $parsed = $this->qr->parsePayload($data['payload']);
        if (!$parsed) {
            return response()->json(['ok' => false, 'message' => 'Invalid QR code.'], 422);
        }

        $office = Office::find($parsed['office_id']);
        if (!$office) {
            return response()->json(['ok' => false, 'message' => 'Unknown office.'], 404);
        }

        if (!$this->qr->validate($office, $parsed['token'])) {
            return response()->json(['ok' => false, 'message' => 'QR code expired. Please scan the live code again.'], 422);
        }

        $employee = Employee::where('company_id', $office->company_id)->find($data['employee_id']);
        if (!$employee) {
            return response()->json(['ok' => false, 'message' => 'Employee not found for this office\'s company.'], 404);
        }

        if ($this->attendance->recentlyScanned($employee)) {
            return response()->json(['ok' => false, 'message' => 'Already scanned moments ago. Please wait a minute.'], 429);
        }

        $result = $this->attendance->record($employee, $office, [
            'source'     => 'pwa',
            'latitude'   => $data['latitude'] ?? null,
            'longitude'  => $data['longitude'] ?? null,
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'ok'       => true,
            'type'     => $result['type'],
            'status'   => $result['status'],
            'employee' => $employee->full_name,
            'office'   => $office->name,
            'time'     => $result['log']->scanned_at->format('h:i:s A'),
            'message'  => sprintf('%s clocked %s (%s) at %s',
                $employee->full_name, strtoupper($result['type']), $result['status'], $result['log']->scanned_at->format('h:i A')),
        ]);
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
