<?php

namespace App\Http\Controllers\Api;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\AttendanceService;
use App\Services\LeaveService;
use App\Services\ReportService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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

    /**
     * The furthest back one history call will reach.
     *
     * The same 92 as `Api\AttendanceController::MAX_HISTORY_DAYS`, and the same
     * number deliberately: a second, larger ceiling for HR would mean a second
     * `range_too_large` message to translate and a second rule for the app to
     * learn, to buy a window nobody asked for. A month and a week both fit.
     */
    protected const MAX_HISTORY_DAYS = 92;

    public function __construct(
        protected LeaveService $leave,
        protected AttendanceService $attendance,
        protected ReportService $reports,
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

        // The same period vocabulary the detail view speaks, so one control in
        // the app drives both. The company's zone, not the caller's: a register
        // read from a phone in Karachi still reports the employer's month.
        [$period, $from, $to, $today] = $this->attendanceWindow(
            $request,
            $request->user()?->company?->tz() ?? config('app.timezone'),
        );

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

        // The register carries a line of attendance per person, and it is
        // computed for **this page only**. A company-wide pass would read every
        // punch in the window to draw twenty rows, and the nineteen pages
        // nobody scrolled to would be paid for on every search.
        $people  = collect($page->items());
        $summary = $this->registerAttendance($people, $from, $to);

        return $this->ok([
            'people' => $people
                ->map(fn (Employee $person) => $this->summary($person) + [
                    'attendance' => $summary[$person->id] ?? null,
                ])
                ->values(),
            'period' => $period,
            'from'   => $from,
            'to'     => $to,
            'today'  => $today,
            'meta'   => $this->pageMeta($page),
        ]);
    }

    /**
     * Present days, late days and hours for the people on one page.
     *
     * Two queries for the whole page rather than two per person. `lateDayKeys`
     * counts **days**, not punches — three check-ins on one late morning is one
     * late day, and the rest of the app has always counted it that way.
     *
     * `employeeStats()` would have answered most of this, but it filters to
     * active employees and this register deliberately shows leavers: a row for
     * somebody who left in March would have come back blank rather than with
     * the March they worked.
     *
     * @param  Collection<int, Employee>  $people
     * @return array<int, array{present_days: int, late_days: int, early_leave_days: int, worked_minutes: int, break_minutes: int}>
     */
    protected function registerAttendance(Collection $people, string $from, string $to): array
    {
        if ($people->isEmpty()) {
            return [];
        }

        $ids = $people->pluck('id')->all();

        // forDates(), never whereBetween() — trap 1. `work_date` is a date cast
        // and every engine but MySQL stores it as a midnight timestamp, so the
        // string comparison drops the last day of the range.
        // The column list is not arbitrary: `lateDayKeys()` filters on `type`
        // and sorts on `scanned_at`, so a narrower select silently drops every
        // row and reports nobody late. Narrowed at all because this reads a
        // page of employees across up to 92 days.
        $arrivals = AttendanceLog::whereIn('employee_id', $ids)
            ->forDates($from, $to)
            ->where('type', 'in')
            ->get(['id', 'employee_id', 'work_date', 'status', 'type', 'scanned_at'])
            ->groupBy('employee_id');

        $hours = $this->reports->hoursByEmployee(
            EloquentCollection::make($people->all()),
            $from,
            $to,
        );

        $out = [];

        foreach ($ids as $id) {
            $ins = $arrivals->get($id, collect());

            $out[$id] = [
                'present_days' => $ins->pluck('work_date')
                    ->map(fn ($date) => $date instanceof \DateTimeInterface
                        ? $date->format('Y-m-d')
                        : (string) $date)
                    ->unique()->count(),
                'late_days'        => $this->attendance->lateDayKeys($ins)->count(),
                'early_leave_days' => $hours[$id]['early_leave'] ?? 0,
                // `worked_as_punched`, not `total`. This line sits directly
                // above the tap that opens the day rows, and it prints the
                // punched break total beside it — a settled figure here would
                // charge a nominal break the rows below never show, so the two
                // screens would differ by an hour a day and the break minutes
                // on this very line would not account for the gap.
                'worked_minutes'   => $hours[$id]['worked_as_punched'] ?? 0,
                'break_minutes'    => $hours[$id]['break_minutes'] ?? 0,
            ];
        }

        return $out;
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
     * One employee's attendance, day by day.
     *
     * The counts on the detail view answer "roughly how is this person doing".
     * This answers the question HR actually rings up about — *what happened on
     * the 14th* — and it is the reason `attendanceSummary()` below no longer
     * has to end with "the full history is on the web".
     *
     * Nothing here computes attendance. `dayRows()` is the one definition of
     * what a day was, shared with `/attendance/history` and with the web's
     * Attendance History screen, so the three cannot disagree about a break
     * left open or a day nobody clocked out of.
     *
     * **The window is resolved here, not on the handset.** A phone is wherever
     * its owner is and attendance is judged in the company's zone, so a "this
     * month" worked out on the device is a different month for part of every
     * day. The app sends `period` and reads `from`, `to` and `today` back out
     * of the reply — see [attendanceWindow].
     */
    public function attendance(Request $request, Employee $employee): JsonResponse
    {
        $this->authoriseCompany($employee);

        $timezone = $this->timezone($employee);
        [$period, $from, $to, $today] = $this->attendanceWindow($request, $timezone);

        // Newest first, like /attendance/history: the question is nearly always
        // about a day that has just happened. dayRows() reads forwards because
        // a running total has to.
        $rows = $this->attendance->dayRows($employee, $from, $to)->reverse()->values();

        $days = $rows->map(fn (array $row) => [
            'date'           => $row['date'],
            'weekday'        => $row['weekday'],
            'status'         => $row['status'],
            'late'           => $row['late'],
            'early_leave'    => $row['early_leave'],
            'first_in'       => $this->wall($row['first_in'], $timezone),
            'last_out'       => $this->wall($row['last_out'], $timezone),
            'break_start'    => $this->wall($row['break_start'], $timezone),
            'break_end'      => $this->wall($row['break_end'], $timezone),
            'break_count'    => $row['break_count'],
            'break_minutes'  => $row['break_minutes'],
            'worked_minutes' => $row['worked_minutes'],
            'punches'        => $row['punches'],
            'holiday'        => $row['holiday'],
            'shift'          => $row['shift']?->name,
            'remarks'        => $row['remarks'],
        ])->all();

        return $this->ok([
            'employee' => $this->summary($employee),
            'period'   => $period,
            'from'     => $from,
            'to'       => $to,
            // The company's today, for a picker that must not offer a day the
            // server would then refuse. The handset's own clock is a different
            // date for part of every day.
            'today'    => $today,
            'days'     => $days,
            'totals'   => [
                'present_days'     => $rows->where('status', 'present')->count(),
                'absent_days'      => $rows->where('status', 'absent')->count(),
                'leave_days'       => $rows->where('status', 'leave')->count(),
                'late_days'        => $rows->where('late', true)->count(),
                'early_leave_days' => $rows->where('early_leave', true)->count(),
                'worked_minutes'   => $rows->sum('worked_minutes'),
                'break_minutes'    => $rows->sum('break_minutes'),
            ],
        ]);
    }

    /**
     * The window an attendance view works over, named by the server.
     *
     * `period` is the app's whole vocabulary for this. It sends a word and gets
     * dates back; it never works one out. Trap 30 is the record of what happens
     * otherwise, and it has been paid for four times.
     *
     * `custom` is the one period that carries dates, and they are still clamped
     * here: a window ending tomorrow reads as a day nobody attended, and one
     * long enough to be a report is refused rather than served slowly.
     *
     * Refusals are thrown rather than returned so the callers stay readable —
     * `bootstrap/app.php` passes an `HttpResponseException` through untouched.
     *
     * @return array{0: string, 1: string, 2: string, 3: string}  period, from, to, today
     */
    protected function attendanceWindow(Request $request, string $timezone): array
    {
        // Read with ?? null throughout: validate() returns an array without a
        // key the caller never sent, so reading it directly is a 500 rather
        // than the fallback it looks like (trap 2).
        $data = $request->validate([
            'period' => 'nullable|in:daily,weekly,monthly,custom',
            'from'   => 'nullable|date_format:Y-m-d',
            'to'     => 'nullable|date_format:Y-m-d',
        ]);

        $now    = Carbon::now($timezone);
        $today  = $now->toDateString();
        $period = ($data['period'] ?? null) ?: 'daily';
        $recent = $now->copy()->subDays(self::ATTENDANCE_WINDOW_DAYS - 1)->toDateString();

        [$from, $to] = match ($period) {
            'weekly'  => [$now->copy()->startOfWeek()->toDateString(), $today],
            'monthly' => [$now->copy()->startOfMonth()->toDateString(), $today],
            'custom'  => [($data['from'] ?? null) ?: $recent, ($data['to'] ?? null) ?: $today],
            default   => [$recent, $today],
        };

        // A day that has not happened cannot be an absence, and calling it one
        // is a lie the employee cannot answer.
        if ($to > $today) {
            $to = $today;
        }

        if ($from > $to) {
            throw new HttpResponseException(
                $this->fail('invalid_range', __('api.invalid_range')),
            );
        }

        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) >= self::MAX_HISTORY_DAYS) {
            throw new HttpResponseException($this->fail('range_too_large', __('api.range_too_large', [
                'days' => self::MAX_HISTORY_DAYS,
            ])));
        }

        return [$period, $from, $to, $today];
    }

    /** A stored scan time as the wall clock it actually was, or null. */
    protected function wall(?Carbon $at, string $timezone): ?string
    {
        return $at ? $this->attendance->wallClock($at, $timezone)->toIso8601String() : null;
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
     * A phone cannot usefully open on thirty rows of punches, and HR looking
     * somebody up wants the shape first: how many days they were in, how often
     * late, how often they left early. The detail is one tap away —
     * [attendance] answers the same window day by day.
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
