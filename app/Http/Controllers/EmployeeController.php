<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeController extends Controller
{
    protected function companyId(): int
    {
        return auth()->user()->company_id ?? Office::value('company_id');
    }

    public function index(Request $request)
    {
        $companyId = $this->companyId();

        $employees = Employee::with(['office', 'department', 'designation'])
            ->where('company_id', $companyId)
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%' . $request->q . '%';
                $q->where(fn ($w) => $w->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('employee_code', 'like', $term)
                    ->orWhere('email', 'like', $term));
            })
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', $request->department_id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $departments = Department::where('company_id', $companyId)->get();

        return view('employees.index', compact('employees', 'departments'));
    }

    public function create()
    {
        return view('employees.create', $this->formData());
    }

    public function store(Request $request)
    {
        $companyId = $this->companyId();
        $data = $this->validateEmployee($request);
        $data['company_id'] = $companyId;
        $data['employee_code'] = $data['employee_code'] ?: $this->nextCode($companyId);

        Employee::create($data);

        return redirect()->route('employees.index')->with('success', 'Employee created successfully.');
    }

    public function show(Employee $employee)
    {
        $this->authorizeCompany($employee);
        $employee->load(['office', 'department', 'designation', 'attendanceLogs' => fn ($q) => $q->latest('scanned_at')->limit(20)]);
        return view('employees.show', compact('employee'));
    }

    public function edit(Employee $employee)
    {
        $this->authorizeCompany($employee);
        return view('employees.edit', array_merge($this->formData($employee), compact('employee')));
    }

    public function update(Request $request, Employee $employee)
    {
        $this->authorizeCompany($employee);
        $data = $this->validateEmployee($request, $employee);
        $employee->update($data);

        return redirect()->route('employees.index')->with('success', 'Employee updated successfully.');
    }

    public function destroy(Employee $employee)
    {
        $this->authorizeCompany($employee);
        $employee->delete();

        return redirect()->route('employees.index')->with('success', 'Employee deleted.');
    }

    /** Bulk import form. */
    public function importForm()
    {
        return view('employees.import');
    }

    /** Handle a CSV upload of employees. */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt',
        ]);

        $companyId = $this->companyId();
        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = null;
        $created = 0;
        $skipped = 0;

        DB::beginTransaction();
        try {
            while (($row = fgetcsv($handle)) !== false) {
                if (!$header) {
                    $header = array_map(fn ($h) => strtolower(trim($h)), $row);
                    continue;
                }
                $r = array_combine($header, $row);
                if (empty($r['first_name'])) { $skipped++; continue; }

                Employee::create([
                    'company_id'    => $companyId,
                    'employee_code' => $r['employee_code'] ?? $this->nextCode($companyId),
                    'first_name'    => $r['first_name'],
                    'last_name'     => $r['last_name'] ?? null,
                    'email'         => $r['email'] ?? null,
                    'phone'         => $r['phone'] ?? null,
                    'status'        => 'active',
                ]);
                $created++;
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            fclose($handle);
            return back()->with('error', 'Import failed: ' . $e->getMessage());
        }
        fclose($handle);

        return redirect()->route('employees.index')
            ->with('success', "Imported {$created} employees ({$skipped} skipped).");
    }

    // ---- helpers ----

    protected function formData(?Employee $employee = null): array
    {
        $companyId = $this->companyId();

        // Candidate managers: anyone active in the company except the employee
        // being edited. Assignments that would close a loop in the reporting
        // chain are still rejected in validation — this only trims the obvious.
        $managers = Employee::where('company_id', $companyId)->active()
            ->when($employee, fn ($q) => $q->whereKeyNot($employee->id))
            ->orderBy('first_name')->get();

        return [
            'offices'      => Office::where('company_id', $companyId)->get(),
            'departments'  => Department::with('shift')->where('company_id', $companyId)->get(),
            'designations' => Designation::where('company_id', $companyId)->get(),
            'managers'     => $managers,
            'shifts'       => Shift::where('company_id', $companyId)->active()->orderBy('start_time')->get(),
        ];
    }

    protected function validateEmployee(Request $request, ?Employee $employee = null): array
    {
        $data = $request->validate([
            'employee_code'  => 'nullable|string|max:50',
            'first_name'     => 'required|string|max:100',
            'last_name'      => 'nullable|string|max:100',
            'email'          => 'nullable|email|max:150|unique:employees,email' . ($employee ? ",{$employee->id}" : ''),
            'phone'          => 'nullable|string|max:30',
            'office_id'      => 'nullable|exists:offices,id',
            'department_id'  => 'nullable|exists:departments,id',
            'designation_id' => 'nullable|exists:designations,id',
            'manager_id'     => 'nullable|exists:employees,id',
            // Empty means "follow the department", which is the normal case.
            'shift_id'       => 'nullable|exists:shifts,id',
            'gender'         => 'nullable|in:male,female,other',
            'date_of_birth'  => 'nullable|date',
            'hire_date'      => 'nullable|date',
            'status'         => 'required|in:active,inactive,terminated',
            'work_mode'      => 'required|in:office,wfh,hybrid',
        ]);

        // A new employee cannot close a loop, so the guard only has real work to
        // do on update — but running it either way keeps the rule in one place.
        $managerId = ($data['manager_id'] ?? null) ? (int) $data['manager_id'] : null;

        if (! ($employee ?? new Employee())->canReportTo($managerId)) {
            throw ValidationException::withMessages([
                'manager_id' => 'That manager cannot be assigned — it would create a loop in the reporting line.',
            ]);
        }

        return $data;
    }

    protected function nextCode(int $companyId): string
    {
        $count = Employee::where('company_id', $companyId)->count() + 1;
        return 'EMP-' . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    protected function authorizeCompany(Employee $employee): void
    {
        abort_unless($employee->company_id === $this->companyId(), 403);
    }
}
