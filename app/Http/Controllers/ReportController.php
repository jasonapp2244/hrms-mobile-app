<?php

namespace App\Http\Controllers;

use App\Exports\TableExport;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
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

    public function overtime(Request $request)
    {
        return $this->handle($request, 'overtime');
    }

    public function payroll(Request $request)
    {
        return $this->handle($request, 'payroll');
    }

    public function leave(Request $request)
    {
        return $this->handle($request, 'leave');
    }

    /** The report builder (A7.13) — whatever columns and filters were picked. */
    public function custom(Request $request)
    {
        return $this->handle($request, 'custom');
    }

    /** Shared: compute the requested report, then render view / PDF / Excel. */
    protected function handle(Request $request, string $type)
    {
        $companyId = $this->companyId();
        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to = $request->input('to', now()->toDateString());
        $officeId = $request->filled('office_id') ? (int) $request->office_id : null;

        $report = $type === 'custom'
            ? $this->reports->custom($companyId, $from, $to, $officeId, $this->customOptions($request, $companyId))
            : $this->reports->{$type}($companyId, $from, $to, $officeId);

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

        if ($type === 'custom') {
            $report['departments'] = Department::where('company_id', $companyId)->orderBy('name')->get();

            return view('reports.custom', $report);
        }

        return view('reports.show', $report);
    }

    /**
     * The builder's own inputs, validated against the column catalogue.
     *
     * A column key travels in the query string, and the service uses it to look
     * up a label and a value. Anything not in the catalogue is dropped rather
     * than rejected: a stale bookmark from before a column was renamed should
     * still produce the rest of the report instead of an error page.
     */
    protected function customOptions(Request $request, int $companyId): array
    {
        $requested = (array) $request->input('columns', []);

        return [
            'columns' => array_values(array_intersect(
                array_filter($requested, 'is_string'),
                ReportService::customColumnKeys(),
            )),

            // Scoped to the company, so a department id typed into the URL
            // cannot pull another company's staff into the filter.
            'department_id' => Department::where('company_id', $companyId)
                ->whereKey($request->input('department_id'))
                ->value('id'),

            'work_mode' => array_key_exists((string) $request->input('work_mode'), Employee::WORK_MODES)
                ? $request->input('work_mode')
                : null,
        ];
    }
}
