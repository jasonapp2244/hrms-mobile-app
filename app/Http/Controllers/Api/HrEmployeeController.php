<?php

namespace App\Http\Controllers\Api;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\LeaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The employee register, on the phone (client requirement, 2026-09-22).
 *
 * **This is not the directory, and the two must not be merged.** `/directory`
 * (B3.8) answers "who else works here" for every employee in the company, and
 * is deliberately the most conservative endpoint in the API: no date of birth,
 * no address, no national id, no emergency contact, no reporting line, and
 * contact details only when the company has switched them on. That restraint is
 * load-bearing — it is what every member of staff may know about a colleague.
 *
 * This endpoint answers the same question for **HR**, and returns the record.
 * It is gated `manage-employees`, which on the web is what stands between HR
 * and the employee screens, and which a manager deliberately does not hold:
 * CLAUDE.md records that even a manager never sees these fields for their own
 * team. The gate is the same one; only the surface is new.
 *
 * Company-scoped on top of the permission, like everything else. The permission
 * says what kind of work somebody may do; it never says whose data.
 *
 * **Read-only, and that is a decision.** Editing an employee on a phone means
 * an audit trail written one-handed, and the fields most likely to be mistyped
 * — national id, salary-adjacent dates — are the ones nobody would notice were
 * wrong. Onboarding stays at a desk; this is for looking somebody up.
 */
class HrEmployeeController extends ApiController
{
    /** How many days of attendance the detail view summarises. */
    protected const ATTENDANCE_WINDOW_DAYS = 30;

    public function __construct(
        protected LeaveService $leave,
    ) {}

    /**
     * The register: searchable, filterable, paginated.
     *
     * Leavers are **included** here, unlike the directory, and filtered by
     * status instead. A directory is for finding somebody who still works here;
     * a register is for answering questions about people who did — which is
     * most of what HR is asked after somebody leaves.
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyId();

        $data = $request->validate([
            'q'             => 'nullable|string|max:100',
            'department_id' => 'nullable|integer',
            'office_id'     => 'nullable|integer',
            'status'        => 'nullable|in:active,inactive,terminated,all',
            'page'          => 'nullable|integer|min:1',
        ]);

        $query = Employee::with(['department', 'designation', 'office'])
            ->where('company_id', $companyId)
            ->orderBy('first_name')
            ->orderBy('last_name');

        // Default to active. A register that opens on everybody who has ever
        // worked here answers the wrong question most of the time, and "all"
        // is one tap away.
        $status = $data['status'] ?? 'active';

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if (! empty($data['q'])) {
            $term = '%' . $data['q'] . '%';

            $query->where(function ($q) use ($term) {
                $q->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('employee_code', 'like', $term)
                    ->orWhere('email', 'like', $term);
            });
        }

        // Filtered, not scoped: these narrow the same company-wide list, so an
        // id from another company matches nothing rather than reaching past the
        // where above.
        foreach (['department_id', 'office_id'] as $filter) {
            if (! empty($data[$filter])) {
                $query->where($filter, $data[$filter]);
            }
        }

        $page = $query->paginate($this->perPage('hr_employees'));

        return $this->ok([
            'people' => collect($page->items())
                ->map(fn (Employee $person) => $this->summary($person))
                ->values(),
            'meta' => $this->pageMeta($page),
        ]);
    }

    /**
     * One employee, in full.
     *
     * Three things beyond the stored record, because they are what HR is
     * actually being asked when they look somebody up on a phone: how much
     * leave is left, what the last month of attendance looked like, and who
     * they report to. Each is one query and they are only paid for on the
     * detail view.
     */
    public function show(Employee $employee): JsonResponse
    {
        $this->authoriseCompany($employee);

        $employee->load(['department', 'designation', 'office', 'manager', 'shiftOverride', 'user']);

        return $this->ok([
            'employee'   => $this->summary($employee) + $this->record($employee),
            'balances'   => $this->balances($employee),
            'attendance' => $this->attendanceSummary($employee),
        ]);
    }

    /**
     * The leave register for one person — what they have asked for, and when.
     *
     * Separate from the detail view rather than folded into it: a long-serving
     * employee has a long history, and paying for it every time somebody opens
     * a record to check a phone number is the wrong trade.
     */
    public function leave(Employee $employee): JsonResponse
    {
        $this->authoriseCompany($employee);

        $page = LeaveRequest::with(['leaveType', 'approver'])
            ->where('employee_id', $employee->id)
            ->latest('start_date')
            ->paginate($this->perPage('hr_employees'));

        return $this->ok([
            'requests' => collect($page->items())->map(fn (LeaveRequest $r) => [
                'id'            => $r->id,
                'leave_type'    => $r->leaveType?->name,
                'start_date'    => $r->start_date->toDateString(),
                'end_date'      => $r->end_date->toDateString(),
                'days'          => (float) $r->days,
                'status'        => $r->status,
                'decided_by'    => $r->approver?->name,
                'decision_note' => $r->decision_note,
                'submitted_at'  => $r->created_at?->toIso8601String(),
            ])->values(),
            'meta' => $this->pageMeta($page),
        ]);
    }

    /**
     * An employee on another client's books is a 403.
     *
     * The route takes a bound model, which is the leak — route-model binding
     * resolves by id alone, and `manage-employees` is company-blind.
     */
    protected function authoriseCompany(Employee $employee): void
    {
        abort_unless(
            $employee->company_id === $this->companyId(),
            403,
            __('api.employee_not_yours'),
        );
    }

    /**
     * Enough to recognise somebody in a list.
     *
     * Deliberately the same shape as the row the list returns, so opening a
     * record does not redraw the header with differently-spelled keys.
     *
     * @return array<string, mixed>
     */
    protected function summary(Employee $employee): array
    {
        return [
            'id'            => $employee->id,
            'employee_code' => $employee->employee_code,
            'name'          => $employee->full_name,
            'first_name'    => $employee->first_name,
            'last_name'     => $employee->last_name,
            'department'    => $employee->department?->name,
            'designation'   => $employee->designation?->name,
            'office'        => $employee->office?->name,
            'status'        => $employee->status,
            'photo_url'     => $employee->photo_url,
            'email'         => $employee->email,
            'phone'         => $employee->phone,
        ];
    }

    /**
     * The HR-grade half of the record.
     *
     * Everything the directory refuses to say. It is here because the person
     * reading holds `manage-employees`, and it is a flat map rather than nested
     * so the app can render it as rows without knowing what each one means.
     *
     * @return array<string, mixed>
     */
    protected function record(Employee $employee): array
    {
        return [
            'date_of_birth'  => $employee->date_of_birth?->toDateString(),
            'gender'         => $employee->gender,
            'hire_date'      => $employee->hire_date?->toDateString(),
            'work_mode'      => $employee->work_mode,
            'personal_email' => $employee->personal_email,
            'address'        => $employee->address,
            'city'           => $employee->city,
            'country'        => $employee->country,
            'national_id'    => $employee->national_id,
            'blood_group'    => $employee->blood_group,

            'emergency_contact' => array_filter([
                'name'     => $employee->emergency_contact_name,
                'phone'    => $employee->emergency_contact_phone,
                'relation' => $employee->emergency_contact_relation,
            ], fn ($value) => $value !== null && $value !== ''),

            'manager'       => $employee->manager?->full_name,
            'manager_id'    => $employee->manager_id,
            'shift'         => $employee->shiftOverride?->name,
            // Whether this person can sign in at all, which is the question HR
            // is asked most often about somebody who says the app will not let
            // them in. The account itself is administered on the web.
            'has_login'     => $employee->user !== null,
            'login_email'   => $employee->user?->email,
            'login_active'  => $employee->user ? $employee->user->is_active !== false : null,
        ];
    }

    /**
     * Every leave type this company runs, and what is left of each.
     *
     * The types come first and the balance second, so a type the employee has
     * never touched still appears at its full entitlement instead of being
     * absent — "no balance row" and "nothing taken" look the same to somebody
     * reading a phone, and only one of them is true.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function balances(Employee $employee): array
    {
        $year = (int) Carbon::now($this->timezone($employee))->year;

        return $employee->company?->leaveTypes()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function ($type) use ($employee, $year) {
                $balance = $this->leave->balanceFor($employee, $type, $year);

                return [
                    'leave_type' => $type->name,
                    'entitled'   => (float) $balance->entitled_days,
                    'carried'    => (float) $balance->carried_forward,
                    'used'       => (float) $balance->used_days,
                    'available'  => (float) $balance->available,
                    'capped'     => $this->leave->isCapped($balance),
                ];
            })
            ->values()
            ->all() ?? [];
    }

    /**
     * The last month of attendance, counted rather than listed.
     *
     * A phone cannot usefully show thirty rows of punches, and HR looking
     * somebody up wants the shape rather than the detail: how many days they
     * were in, how often late, how often they left early. The full history is
     * on the web where it can be read properly.
     *
     * Counted from `work_date`, never from `scanned_at` — a night shift's
     * punches belong to the day the shift started, and counting by timestamp
     * would split one shift across two days.
     *
     * @return array<string, mixed>
     */
    protected function attendanceSummary(Employee $employee): array
    {
        $timezone = $this->timezone($employee);
        $to = Carbon::now($timezone)->toDateString();
        $from = Carbon::now($timezone)->subDays(self::ATTENDANCE_WINDOW_DAYS - 1)->toDateString();

        $logs = AttendanceLog::where('employee_id', $employee->id)
            // forDates(), never whereBetween() — trap 1. `work_date` is a date
            // cast, every engine but MySQL stores it as a midnight timestamp,
            // and the string comparison drops the last day of every range. This
            // is the fifth instance; the note said to assume there would be one.
            ->forDates($from, $to)
            ->get(['work_date', 'type', 'status']);

        $arrivals = $logs->where('type', 'in');

        return [
            'from'        => $from,
            'to'          => $to,
            'days_worked' => $logs->pluck('work_date')->map(
                fn ($date) => $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : (string) $date,
            )->unique()->count(),
            'late'        => $arrivals->where('status', 'late')->count(),
            'early_leave' => $logs->where('type', 'out')->where('status', 'early_leave')->count(),
            'on_time'     => $arrivals->where('status', 'ontime')->count(),
        ];
    }
}
