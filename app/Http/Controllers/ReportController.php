<?php

namespace App\Http\Controllers;

use App\Exports\TableExport;
use App\Models\Company;
use App\Models\Office;
use App\Services\ReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends Controller
{
    public function __construct(protected ReportService $reports) {}

    protected function companyId(): int
    {
        return auth()->user()->company_id ?? Office::value('company_id');
    }

    public function late(Request $request)
    {
        return $this->handle($request, 'late');
    }

    public function outliers(Request $request)
    {
        return $this->handle($request, 'outliers');
    }

    public function department(Request $request)
    {
        return $this->handle($request, 'department');
    }

    /** Shared: compute the requested report, then render view / PDF / Excel. */
    protected function handle(Request $request, string $type)
    {
        $companyId = $this->companyId();
        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to = $request->input('to', now()->toDateString());
        $officeId = $request->filled('office_id') ? (int) $request->office_id : null;

        $report = $this->reports->{$type}($companyId, $from, $to, $officeId);
        $report['from'] = $from;
        $report['to'] = $to;
        $report['office'] = $officeId ? Office::find($officeId) : null;
        $report['offices'] = Office::where('company_id', $companyId)->get();
        $report['type'] = $type;

        $export = $request->input('export');
        $slug = 'report-' . $type . '_' . $from . '_to_' . $to;

        if ($export) {
            abort_unless(auth()->user()->can('export-reports'), 403);
        }

        if ($export === 'excel') {
            return Excel::download(new TableExport($report['headings'], $report['rows']), $slug . '.xlsx');
        }

        if ($export === 'pdf') {
            $report['company'] = Company::find($companyId);
            return Pdf::loadView('reports.pdf', $report)->setPaper('a4', 'landscape')->download($slug . '.pdf');
        }

        return view('reports.show', $report);
    }
}
