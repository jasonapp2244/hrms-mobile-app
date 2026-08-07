<?php

namespace App\Http\Controllers;

use App\Exports\TableExport;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Shift;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

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

    /**
     * Export the roster (A3.11).
     *
     * Deliberately the same columns the bulk import accepts, in the same order,
     * so an export can be edited and fed straight back in. An export that
     * cannot round-trip is a report; this is meant to be a working file.
     *
     * Honours whatever filters are on screen, because "export what I am looking
     * at" is what the button next to a filtered list is understood to mean.
     */
    public function export(Request $request)
    {
        $companyId = $this->companyId();

        // No 'shift' here — it is an accessor over the override and the
        // department's shift, not a relation, and eager-loading it throws.
        $employees = Employee::with(['office', 'department', 'designation', 'manager'])
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
            ->orderBy('employee_code')
            ->get();

        // Derived from IMPORT_COLUMNS rather than written out again, so the two
        // cannot drift apart. The extras go after, and the import ignores any
        // header it does not know — it matches by name, not position.
        //
        // manager_code, not a manager's name: the import resolves the reporting
        // line by employee code. An export carrying "Max Reid" round-trips into
        // a roster with every reporting line silently blank.
        $extra = [
            'status', 'work_mode',
            'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relation',
            'personal_email', 'city', 'country',
        ];

        $headings = array_merge(self::IMPORT_COLUMNS, $extra);

        $rows = $employees->map(fn (Employee $e) => [
            'employee_code' => $e->employee_code,
            'first_name'    => $e->first_name,
            'last_name'     => $e->last_name,
            'email'         => $e->email,
            'phone'         => $e->phone,
            'gender'        => $e->gender,
            'hire_date'     => $e->hire_date?->toDateString() ?? '',
            // Names, not ids — the import matches offices, departments and
            // designations by name, and an id means nothing in Excel.
            'office'        => $e->office->name ?? '',
            'department'    => $e->department->name ?? '',
            'designation'   => $e->designation->name ?? '',
            'manager_code'  => $e->manager->employee_code ?? '',

            'status'        => $e->status,
            'work_mode'     => $e->work_mode,
            'emergency_contact_name'     => $e->emergency_contact_name,
            'emergency_contact_phone'    => $e->emergency_contact_phone,
            'emergency_contact_relation' => $e->emergency_contact_relation,
            'personal_email' => $e->personal_email,
            'city'           => $e->city,
            'country'        => $e->country,
        ])->all();

        return Excel::download(
            new TableExport($headings, $rows),
            'employees_' . now()->toDateString() . '.xlsx',
        );
    }

    /**
     * The reporting hierarchy (A3.10).
     *
     * Built in memory from one query rather than walking the tree with a query
     * per node: a five-level hierarchy over three hundred staff is three
     * hundred queries done the obvious way, and the whole roster is a few
     * hundred rows.
     *
     * Anybody whose manager is missing, inactive, or outside the company is
     * treated as a root. They have to appear somewhere, and dropping them
     * silently is how an org chart comes to be quietly missing four people.
     */
    public function orgChart()
    {
        $employees = Employee::with(['designation', 'department'])
            ->where('company_id', $this->companyId())
            ->where('status', 'active')
            ->orderBy('first_name')
            ->get();

        $byManager = $employees->groupBy('manager_id');
        $ids = $employees->pluck('id')->all();

        $roots = $employees->filter(
            fn (Employee $e) => ! $e->manager_id || ! in_array($e->manager_id, $ids, true),
        )->values();

        return view('employees.org-chart', compact('roots', 'byManager', 'employees'));
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
        // ?? as well as ?: — the field is nullable, so a caller that omits it
        // entirely (the API, an import, a form without the input) left the key
        // missing and produced a 500 rather than an auto-generated code.
        $data['employee_code'] = ($data['employee_code'] ?? null) ?: $this->nextCode($companyId);

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

    /**
     * Delete an employee who has no history behind them.
     *
     * `attendance_logs.employee_id` is ON DELETE CASCADE, so deleting somebody
     * who has ever clocked in silently takes every punch they ever made with
     * them — the hours another month's payroll was calculated from, and the
     * audit trail that was the evidence for it. That contradicts the rule the
     * rest of the system is built on: attendance is append-only, punches are
     * voided rather than removed.
     *
     * So deletion stays available for the case it is actually for — a record
     * typed in by mistake — and anybody with history is deactivated instead,
     * which is what "this person has left" has always meant here.
     */
    public function destroy(Employee $employee)
    {
        $this->authorizeCompany($employee);

        $punches = $employee->attendanceLogs()->count();

        if ($punches > 0) {
            return redirect()->route('employees.show', $employee)->with(
                'error',
                "{$employee->full_name} has {$punches} attendance record(s) and cannot be deleted — "
                . 'deleting them would destroy the hours those records are the evidence for. '
                . 'Set their status to Terminated instead, and disable their sign-in account.'
            );
        }

        $employee->delete();

        return redirect()->route('employees.index')->with('success', 'Employee deleted.');
    }

    /** Bulk import form. */
    public function importForm()
    {
        return view('employees.import');
    }

    /** A CSV of the accepted columns, filled in with this company's real options. */
    public function importTemplate()
    {
        $companyId = $this->companyId();

        $office     = Office::where('company_id', $companyId)->value('name') ?? 'Head Office';
        $department = Department::where('company_id', $companyId)->value('name') ?? 'Engineering';
        $designation = Designation::where('company_id', $companyId)->value('name') ?? '';

        $rows = [
            self::IMPORT_COLUMNS,
            ['EMP-0101', 'Jane', 'Doe', 'jane.doe@example.com', '+1 212 555 0142', 'female', '2024-03-01', $office, $department, $designation, ''],
            ['', 'John', 'Smith', 'john.smith@example.com', '', 'male', '2025-11-17', $office, $department, '', 'EMP-0101'],
        ];

        $csv = '';
        foreach ($rows as $row) {
            $csv .= implode(',', array_map(fn ($v) => str_contains((string) $v, ',') ? '"' . $v . '"' : $v, $row)) . "\n";
        }

        return response($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="employee-import-template.csv"',
        ]);
    }

    /** Columns the import understands. Only first_name is required. */
    public const IMPORT_COLUMNS = [
        'employee_code', 'first_name', 'last_name', 'email', 'phone',
        'gender', 'hire_date', 'office', 'department', 'designation', 'manager_code',
    ];

    /**
     * Handle a CSV upload of employees.
     *
     * Checks the whole file before writing any of it. A part-imported staff
     * list is the worst outcome here: you cannot tell by looking which of two
     * hundred people made it in, and re-uploading the corrected file duplicates
     * everyone who did. So problems are collected and reported together, and
     * nothing is created until the file is clean.
     *
     * `office` and `department` are matched by name against what this company
     * actually has, and an unknown one is an error rather than a blank. The
     * department is what carries the shift, and an employee with no shift has
     * no start time to be late against — their attendance is never judged, and
     * nothing about the screen says so.
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt',
        ]);

        $companyId = $this->companyId();

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = null;
        $rows   = [];
        $errors = [];
        $line   = 1;

        // Name -> id, lower-cased, so "head office" and "Head Office" both land.
        $offices      = $this->lookup(Office::where('company_id', $companyId)->get());
        $departments  = $this->lookup(Department::where('company_id', $companyId)->get());
        $designations = $this->lookup(Designation::where('company_id', $companyId)->get());

        $existingEmails = Employee::where('company_id', $companyId)
            ->whereNotNull('email')->pluck('email')
            ->map(fn ($e) => strtolower($e))->flip();
        $existingCodes = Employee::where('company_id', $companyId)
            ->pluck('employee_code')->flip();

        $seenEmails = [];
        $seenCodes  = [];

        while (($row = fgetcsv($handle)) !== false) {
            $line++;

            if (! $header) {
                $header = array_map(fn ($h) => strtolower(trim($h)), $row);
                $line   = 1;

                if (! in_array('first_name', $header, true)) {
                    fclose($handle);

                    return back()->with('error',
                        'That file has no first_name column. Expected: ' . implode(', ', self::IMPORT_COLUMNS));
                }

                continue;
            }

            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;   // blank line, usually the end of the file
            }

            // A short row is a real mistake (an unquoted comma in an address),
            // not something to pad silently and import wrong.
            $r = array_combine(
                $header,
                array_pad(array_slice($row, 0, count($header)), count($header), null)
            );

            $get = fn (string $key) => trim((string) ($r[$key] ?? ''));

            $name = $get('first_name');

            if ($name === '') {
                $errors[] = "Row {$line}: first_name is empty.";
                continue;
            }

            $record = [
                'company_id' => $companyId,
                'first_name' => $name,
                'last_name'  => $get('last_name') ?: null,
                'phone'      => $get('phone') ?: null,
                'status'     => 'active',
            ];

            // --- email: unique in the file and in the company ---------------
            if ($email = $get('email')) {
                $key = strtolower($email);

                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = "Row {$line}: '{$email}' is not a valid email address.";
                } elseif (isset($existingEmails[$key])) {
                    $errors[] = "Row {$line}: {$email} already belongs to an employee.";
                } elseif (isset($seenEmails[$key])) {
                    $errors[] = "Row {$line}: {$email} appears twice in this file (also row {$seenEmails[$key]}).";
                } else {
                    $seenEmails[$key] = $line;
                    $record['email']  = $email;
                }
            }

            // --- code: unique, or generated later ---------------------------
            if ($code = $get('employee_code')) {
                if (isset($existingCodes[$code])) {
                    $errors[] = "Row {$line}: employee code {$code} is already used.";
                } elseif (isset($seenCodes[$code])) {
                    $errors[] = "Row {$line}: employee code {$code} appears twice in this file.";
                } else {
                    $seenCodes[$code] = $line;
                    $record['employee_code'] = $code;
                }
            }

            // --- gender -----------------------------------------------------
            if ($gender = strtolower($get('gender'))) {
                if (! in_array($gender, ['male', 'female', 'other'], true)) {
                    $errors[] = "Row {$line}: gender '{$gender}' should be male, female or other.";
                } else {
                    $record['gender'] = $gender;
                }
            }

            // --- hire date --------------------------------------------------
            if ($hired = $get('hire_date')) {
                try {
                    $record['hire_date'] = Carbon::parse($hired)->toDateString();
                } catch (\Throwable) {
                    $errors[] = "Row {$line}: '{$hired}' is not a date the importer understands. Use YYYY-MM-DD.";
                }
            }

            // --- the three that decide whether attendance works -------------
            foreach ([
                'office'      => [$offices, 'office_id'],
                'department'  => [$departments, 'department_id'],
                'designation' => [$designations, 'designation_id'],
            ] as $column => [$options, $field]) {
                if (! $value = $get($column)) {
                    continue;
                }

                $id = $options[strtolower($value)] ?? null;

                if ($id === null) {
                    $errors[] = "Row {$line}: there is no {$column} called '{$value}'. "
                        . 'Known: ' . (implode(', ', array_keys($options)) ?: 'none yet');
                } else {
                    $record[$field] = $id;
                }
            }

            if (empty($record['department_id'])) {
                $errors[] = "Row {$line}: department is required — it is what gives {$name} a shift, "
                    . 'and without one their attendance is never judged.';
            }

            $rows[] = ['record' => $record, 'manager_code' => $get('manager_code'), 'line' => $line];
        }

        fclose($handle);

        if ($rows === [] && $errors === []) {
            return back()->with('error', 'That file has a header but no rows.');
        }

        if ($errors !== []) {
            // Everything wrong with the file, in one go — so it is fixed once
            // rather than one upload per mistake.
            return back()
                ->with('error', count($errors) . ' problem(s) found. Nothing was imported.')
                ->with('import_errors', array_slice($errors, 0, 50));
        }

        try {
            $created = DB::transaction(function () use ($rows, $companyId) {
                $byCode = [];

                foreach ($rows as $i => $row) {
                    $record = $row['record'];
                    $record['employee_code'] ??= $this->nextCode($companyId);

                    $employee = Employee::create($record);
                    $byCode[$employee->employee_code] = $employee->id;
                    $rows[$i]['id'] = $employee->id;
                }

                // Managers second, so a manager listed further down the file
                // than their report still resolves.
                foreach ($rows as $row) {
                    if (! $row['manager_code']) {
                        continue;
                    }

                    $managerId = $byCode[$row['manager_code']]
                        ?? Employee::where('company_id', $companyId)
                            ->where('employee_code', $row['manager_code'])
                            ->value('id');

                    if ($managerId && $managerId !== $row['id']) {
                        Employee::whereKey($row['id'])->update(['manager_id' => $managerId]);
                    }
                }

                return count($rows);
            });
        } catch (\Throwable $e) {
            return back()->with('error', 'Import failed, nothing was created: ' . $e->getMessage());
        }

        return redirect()->route('employees.index')
            ->with('success', "Imported {$created} employees.");
    }

    /** name (lower-cased) => id, for matching CSV text against real records. */
    protected function lookup($models): array
    {
        return $models
            ->mapWithKeys(fn ($m) => [strtolower($m->name) => $m->id])
            ->all();
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

            // A3.9 — personal details and the emergency contact.
            'emergency_contact_name'     => 'nullable|string|max:150',
            'emergency_contact_phone'    => 'nullable|string|max:30',
            'emergency_contact_relation' => 'nullable|string|max:60',
            'personal_email' => 'nullable|email|max:150',
            'address'        => 'nullable|string|max:500',
            'city'           => 'nullable|string|max:100',
            'country'        => 'nullable|string|max:100',
            'national_id'    => 'nullable|string|max:60',
            'blood_group'    => 'nullable|string|max:10',

            // A3.7 — the profile photo. Capped at 2MB and to real image types:
            // this is displayed in a list of a hundred people, and a 12-megapixel
            // phone photo per row makes the page unusable.
            'photo'          => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        // Handled apart from the rest because it is a file, not a field: the
        // uploaded photo becomes a stored path, and "no file this time" has to
        // mean "keep the existing one" rather than "clear it".
        if ($request->hasFile('photo')) {
            if ($employee?->avatar) {
                Storage::disk('public')->delete($employee->avatar);
            }

            $data['avatar'] = $request->file('photo')->store('avatars', 'public');
        }

        unset($data['photo']);

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
