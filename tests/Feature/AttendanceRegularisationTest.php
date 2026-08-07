<?php

namespace Tests\Feature;

use App\Models\AttendanceAuditEvent;
use App\Models\AttendanceLog;
use App\Models\AttendanceRegularisation;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Shift;
use App\Models\User;
use App\Services\RegularisationService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * A4.13 — employees asking for their attendance to be corrected.
 *
 * The workflow is only half the point. The other half is that a request is
 * inert: raising one changes nothing, and approving one goes through the same
 * void and manual-entry paths HR uses by hand, so there is no second route into
 * the attendance table with a thinner audit trail.
 *
 * The failures worth guarding are the quiet ones — a request approved twice
 * doubling somebody's day, an employee challenging a colleague's punch, and a
 * void landing without its replacement.
 */
class AttendanceRegularisationTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Office $office;
    protected Employee $employee;
    protected User $employeeUser;
    protected User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'America/New_York', 'currency' => 'USD',
        ]);

        $this->office = Office::create(['company_id' => $this->company->id, 'name' => 'Head Office']);

        $shift = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Day',
            'start_time' => '09:00:00', 'end_time' => '17:00:00',
            'break_minutes' => 30, 'late_grace_minutes' => 15, 'is_active' => true,
        ]);

        $department = Department::create([
            'company_id' => $this->company->id, 'name' => 'Ops', 'shift_id' => $shift->id,
        ]);

        $this->employeeUser = User::create([
            'name' => 'Ann Lee', 'email' => 'ann@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $this->employeeUser->assignRole('employee');

        $this->employee = Employee::create([
            'company_id' => $this->company->id, 'department_id' => $department->id,
            'office_id' => $this->office->id, 'user_id' => $this->employeeUser->id,
            'employee_code' => 'E1', 'first_name' => 'Ann', 'last_name' => 'Lee',
            'status' => 'active',
        ]);

        $this->hr = User::create([
            'name' => 'Hana Ruiz', 'email' => 'hana@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $this->hr->assignRole('hr');
    }

    private function punch(string $type, string $at, ?Employee $for = null): AttendanceLog
    {
        $employee = $for ?? $this->employee;
        $moment = Carbon::parse($at);

        return AttendanceLog::create([
            'company_id'  => $employee->company_id,
            'employee_id' => $employee->id,
            'office_id'   => $employee->office_id,
            'type'        => $type,
            'scanned_at'  => $moment,
            'work_date'   => $moment->toDateString(),
            'status'      => 'late',
            'source'      => 'pwa',
        ]);
    }

    private function request(array $overrides = []): AttendanceRegularisation
    {
        return AttendanceRegularisation::create(array_merge([
            'company_id'   => $this->company->id,
            'employee_id'  => $this->employee->id,
            'office_id'    => $this->office->id,
            'work_date'    => '2026-08-03',
            'type'         => 'out',
            'requested_at' => Carbon::parse('2026-08-03 18:00:00'),
            'reason'       => 'Forgot to check out',
        ], $overrides));
    }

    // -------------------------------------------------------------------------
    // Raising a request
    // -------------------------------------------------------------------------

    public function test_an_employee_can_ask_for_a_missing_punch(): void
    {
        $this->actingAs($this->employeeUser)->post(route('employee.regularisations.store'), [
            'type'         => 'out',
            'requested_at' => '2026-08-03T18:00',
            'reason'       => 'Left at 6pm but forgot to press check out',
        ])->assertRedirect();

        $req = AttendanceRegularisation::firstOrFail();
        $this->assertSame('pending', $req->status);
        $this->assertNull($req->attendance_log_id);

        // Raising it must not touch attendance.
        $this->assertSame(0, AttendanceLog::count());
    }

    public function test_an_employee_can_challenge_one_of_their_own_punches(): void
    {
        $log = $this->punch('in', '2026-08-03 09:40:00');

        $this->actingAs($this->employeeUser)->post(route('employee.regularisations.store'), [
            'attendance_log_id' => $log->id,
            'type'              => 'in',
            'requested_at'      => '2026-08-03T09:00',
            'reason'            => 'I was here at 9; the reader did not register until later',
        ])->assertRedirect();

        $this->assertSame($log->id, AttendanceRegularisation::firstOrFail()->attendance_log_id);
        $this->assertNull($log->fresh()->voided_at);
    }

    public function test_an_employee_cannot_challenge_someone_elses_punch(): void
    {
        $colleague = Employee::create([
            'company_id' => $this->company->id, 'office_id' => $this->office->id,
            'employee_code' => 'E2', 'first_name' => 'Bo', 'last_name' => 'Ray', 'status' => 'active',
        ]);
        $theirs = $this->punch('in', '2026-08-03 09:00:00', $colleague);

        $this->actingAs($this->employeeUser)->post(route('employee.regularisations.store'), [
            'attendance_log_id' => $theirs->id,
            'type'              => 'in',
            'requested_at'      => '2026-08-03T08:00',
            'reason'            => 'Trying to edit a colleague record',
        ])->assertSessionHasErrors('attendance_log_id');

        $this->assertSame(0, AttendanceRegularisation::count());
    }

    public function test_a_correction_cannot_be_asked_for_in_the_future(): void
    {
        $this->actingAs($this->employeeUser)->post(route('employee.regularisations.store'), [
            'type'         => 'in',
            'requested_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'reason'       => 'Asking about tomorrow',
        ])->assertSessionHasErrors('requested_at');
    }

    public function test_a_second_open_request_for_the_same_thing_is_refused(): void
    {
        // A double submit or a stale tab would otherwise produce two approvals
        // and two corrections for one problem.
        $payload = [
            'type'         => 'out',
            'requested_at' => '2026-08-03T18:00',
            'reason'       => 'Forgot to check out',
        ];

        $this->actingAs($this->employeeUser)->post(route('employee.regularisations.store'), $payload);
        $this->actingAs($this->employeeUser)->post(route('employee.regularisations.store'), $payload)
            ->assertSessionHasErrors('reason');

        $this->assertSame(1, AttendanceRegularisation::count());
    }

    public function test_an_employee_can_withdraw_a_pending_request(): void
    {
        $req = $this->request();

        $this->actingAs($this->employeeUser)
            ->post(route('employee.regularisations.cancel', $req))
            ->assertRedirect();

        $this->assertSame('cancelled', $req->fresh()->status);
    }

    // -------------------------------------------------------------------------
    // Approval
    // -------------------------------------------------------------------------

    public function test_approving_a_missing_punch_records_it(): void
    {
        $req = $this->request();

        app(RegularisationService::class)->approve($req, $this->hr);

        $log = AttendanceLog::firstOrFail();
        $this->assertSame('out', $log->type);
        $this->assertSame('manual', $log->source);
        $this->assertStringContainsString('Forgot to check out', $log->notes);

        $req->refresh();
        $this->assertSame('approved', $req->status);
        $this->assertSame($log->id, $req->created_log_id);
        $this->assertSame('Hana Ruiz', $req->decided_by_label);
    }

    public function test_approving_a_challenge_voids_the_old_punch_and_writes_the_new_one(): void
    {
        $wrong = $this->punch('in', '2026-08-03 09:40:00');
        $req = $this->request([
            'attendance_log_id' => $wrong->id,
            'type'              => 'in',
            'requested_at'      => Carbon::parse('2026-08-03 09:00:00'),
            'reason'            => 'Reader registered late',
        ]);

        app(RegularisationService::class)->approve($req, $this->hr);

        // Struck out, not deleted, and no longer counting.
        $this->assertNotNull(AttendanceLog::withVoided()->find($wrong->id)->voided_at);
        $this->assertNull(AttendanceLog::find($wrong->id));

        $replacement = AttendanceLog::firstOrFail();
        $this->assertSame('09:00:00', $replacement->scanned_at->format('H:i:s'));
        // 09:00 against a 09:00 shift with grace — on time, judged at that
        // moment rather than when HR pressed the button.
        $this->assertSame('ontime', $replacement->status);
    }

    public function test_the_void_from_an_approval_names_the_approver_and_the_request(): void
    {
        $wrong = $this->punch('in', '2026-08-03 09:40:00');
        $req = $this->request([
            'attendance_log_id' => $wrong->id, 'type' => 'in',
            'requested_at' => Carbon::parse('2026-08-03 09:00:00'),
            'reason' => 'Reader registered late',
        ]);

        app(RegularisationService::class)->approve($req, $this->hr);

        $event = AttendanceAuditEvent::where('attendance_log_id', $wrong->id)
            ->where('event', AttendanceAuditEvent::VOIDED)
            ->firstOrFail();

        $this->assertSame($this->hr->id, $event->actor_user_id);
        $this->assertStringContainsString('Regularisation #' . $req->id, $event->reason);
    }

    public function test_a_request_cannot_be_decided_twice(): void
    {
        $req = $this->request();
        $service = app(RegularisationService::class);

        $service->approve($req, $this->hr);

        $this->expectException(RuntimeException::class);
        $service->approve($req->fresh(), $this->hr);
    }

    public function test_rejecting_leaves_attendance_untouched(): void
    {
        $wrong = $this->punch('in', '2026-08-03 09:40:00');
        $req = $this->request(['attendance_log_id' => $wrong->id, 'type' => 'in']);

        app(RegularisationService::class)->reject($req, $this->hr, 'Roster shows you started at 09:40');

        $this->assertSame('rejected', $req->fresh()->status);
        $this->assertNull($wrong->fresh()->voided_at);
        $this->assertSame(1, AttendanceLog::count());
    }

    // -------------------------------------------------------------------------
    // Who may decide
    // -------------------------------------------------------------------------

    public function test_hr_can_decide_through_the_queue(): void
    {
        $req = $this->request();

        $this->actingAs($this->hr)
            ->post(route('attendance.regularisations.approve', $req), ['decision_note' => 'Confirmed with manager'])
            ->assertRedirect();

        $this->assertSame('approved', $req->fresh()->status);
    }

    public function test_an_employee_cannot_approve_anything(): void
    {
        $req = $this->request();

        // Including — especially — their own request.
        $this->actingAs($this->employeeUser)
            ->post(route('attendance.regularisations.approve', $req))
            ->assertForbidden();

        $this->assertSame('pending', $req->fresh()->status);
        $this->assertSame(0, AttendanceLog::count());
    }

    public function test_hr_cannot_decide_another_companys_request(): void
    {
        $other = Company::create(['name' => 'Other', 'timezone' => 'UTC', 'currency' => 'USD']);
        $otherOffice = Office::create(['company_id' => $other->id, 'name' => 'Remote']);
        $otherEmployee = Employee::create([
            'company_id' => $other->id, 'office_id' => $otherOffice->id,
            'employee_code' => 'X1', 'first_name' => 'Sam', 'last_name' => 'Poe', 'status' => 'active',
        ]);

        $foreign = AttendanceRegularisation::create([
            'company_id' => $other->id, 'employee_id' => $otherEmployee->id,
            'office_id' => $otherOffice->id, 'work_date' => '2026-08-03', 'type' => 'in',
            'requested_at' => Carbon::parse('2026-08-03 09:00:00'), 'reason' => 'Not yours',
        ]);

        $this->actingAs($this->hr)
            ->post(route('attendance.regularisations.approve', $foreign))
            ->assertNotFound();

        $this->assertSame('pending', $foreign->fresh()->status);
    }

    // -------------------------------------------------------------------------
    // The screens
    // -------------------------------------------------------------------------

    public function test_the_queue_shows_pending_requests_by_default(): void
    {
        $this->request(['reason' => 'zzpendingzz']);
        $decided = $this->request(['type' => 'in', 'reason' => 'zzdecidedzz']);
        app(RegularisationService::class)->reject($decided, $this->hr);

        $this->actingAs($this->hr)
            ->get(route('attendance.regularisations'))
            ->assertOk()
            ->assertSee('zzpendingzz')
            ->assertDontSee('zzdecidedzz');
    }

    public function test_the_portal_lists_the_employees_own_requests_only(): void
    {
        $this->request(['reason' => 'zzmineezz']);

        $colleague = Employee::create([
            'company_id' => $this->company->id, 'office_id' => $this->office->id,
            'employee_code' => 'E2', 'first_name' => 'Bo', 'last_name' => 'Ray', 'status' => 'active',
        ]);
        AttendanceRegularisation::create([
            'company_id' => $this->company->id, 'employee_id' => $colleague->id,
            'office_id' => $this->office->id, 'work_date' => '2026-08-03', 'type' => 'in',
            'requested_at' => Carbon::parse('2026-08-03 09:00:00'), 'reason' => 'zztheirszz',
        ]);

        $this->actingAs($this->employeeUser)
            ->get(route('employee.regularisations.index'))
            ->assertOk()
            ->assertSee('zzmineezz')
            ->assertDontSee('zztheirszz');
    }
}
