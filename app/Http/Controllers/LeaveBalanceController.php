<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Office;
use App\Services\LeaveService;
use Illuminate\Http\Request;

/**
 * HR administration of leave balances.
 *
 * Balances are created on demand as employees use the portal, and used_days is
 * maintained by the approval flow. This screen exists for the cases that flow
 * cannot cover: a mid-year joiner with a pro-rata entitlement, an agreed
 * carry-over, or a figure that has to be corrected after a manual data fix.
 */
class LeaveBalanceController extends Controller
{
    public function __construct(
        protected LeaveService $leave,
    ) {}

    protected function companyId(): int
    {
        return auth()->user()->company_id ?? Office::value('company_id');
    }

    public function index(Request $request)
    {
        $companyId = $this->companyId();
        $year = (int) $request->input('year', date('Y'));

        $balances = LeaveBalance::with(['employee.department', 'leaveType'])
            ->whereHas('employee', fn ($q) => $q->where('company_id', $companyId))
            ->where('year', $year)
            ->when($request->filled('leave_type_id'),
                fn ($q) => $q->where('leave_type_id', $request->leave_type_id))
            ->when($request->filled('q'), fn ($q) => $q->whereHas('employee', fn ($e) => $e
                ->where(fn ($w) => $w
                    ->where('first_name', 'like', "%{$request->q}%")
                    ->orWhere('last_name', 'like', "%{$request->q}%")
                    ->orWhere('employee_code', 'like', "%{$request->q}%"))))
            ->get()
            // Grouped by person, then by type within each person.
            ->sortBy(fn (LeaveBalance $b) => ($b->employee?->full_name ?? '') . '|' . ($b->leaveType?->name ?? ''))
            ->values();

        $types = LeaveType::where('company_id', $companyId)->orderBy('name')->get();
        $years = range((int) date('Y') - 2, (int) date('Y') + 1);

        // How many employee/type pairs have no row yet for this year, so the
        // provisioning button can say what it would actually do.
        $expected = Employee::where('company_id', $companyId)->active()->count()
            * $types->where('is_active', true)->count();

        return view('leave-balances.index', compact('balances', 'types', 'year', 'years', 'expected'));
    }

    /**
     * Create the missing balance rows for a year.
     *
     * Entitlement comes from each leave type, exactly as it would when the
     * employee first opens the portal — this only brings that forward so HR can
     * see and adjust the whole company before anyone books anything.
     */
    public function generate(Request $request)
    {
        $companyId = $this->companyId();
        $year = (int) $request->input('year', date('Y'));

        $employees = Employee::where('company_id', $companyId)->active()->get();
        $types = LeaveType::where('company_id', $companyId)->active()->get();

        $before = LeaveBalance::whereHas('employee', fn ($q) => $q->where('company_id', $companyId))
            ->where('year', $year)->count();

        foreach ($employees as $employee) {
            foreach ($types as $type) {
                $this->leave->balanceFor($employee, $type, $year);
            }
        }

        $after = LeaveBalance::whereHas('employee', fn ($q) => $q->where('company_id', $companyId))
            ->where('year', $year)->count();

        return back()->with('success', sprintf(
            '%d balance row(s) created for %d. Existing balances were left untouched.',
            $after - $before, $year,
        ));
    }

    public function update(Request $request, LeaveBalance $leaveBalance)
    {
        $this->authoriseCompany($leaveBalance);

        $data = $request->validate([
            'entitled_days'   => 'required|numeric|min:0|max:365',
            'carried_forward' => 'required|numeric|min:0|max:365',
            'used_days'       => 'required|numeric|min:0|max:365',
        ]);

        $leaveBalance->update($data);

        return back()->with('success', 'Balance updated.');
    }

    /**
     * Reset used_days to what the approved requests actually add up to.
     *
     * The escape hatch for drift. used_days is maintained incrementally so a
     * balance check never has to sum the request history; if a record is ever
     * edited directly in the database that running total is the thing that goes
     * stale, and this is what puts it back.
     */
    public function recalculate(LeaveBalance $leaveBalance)
    {
        $this->authoriseCompany($leaveBalance);

        $actual = LeaveRequest::where('employee_id', $leaveBalance->employee_id)
            ->where('leave_type_id', $leaveBalance->leave_type_id)
            ->approved()
            ->whereYear('start_date', $leaveBalance->year)
            ->sum('days');

        $was = (float) $leaveBalance->used_days;
        $leaveBalance->update(['used_days' => $actual]);

        return back()->with('success', $was === (float) $actual
            ? 'Already correct — used days match the approved requests.'
            : sprintf('Used days corrected from %s to %s.', $was, $actual));
    }

    protected function authoriseCompany(LeaveBalance $leaveBalance): void
    {
        abort_unless($leaveBalance->employee?->company_id === $this->companyId(), 403);
    }
}
