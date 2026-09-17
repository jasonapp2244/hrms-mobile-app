<?php

namespace Tests\Feature\Api;

use App\Models\AttendanceLog;
use App\Models\AttendanceRegularisation;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Raising a correction from the phone (A4.13 on the API).
 *
 * The decision half is HR's and is covered by AttendanceRegularisationTest.
 * What matters here is that the app can only ever raise, that the rules are the
 * portal's own rather than a second set, and that nothing on these endpoints
 * touches attendance.
 */
class RegularisationApiTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Office $office;
    protected User $user;
    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'UTC', 'currency' => 'USD',
        ]);

        $this->office = Office::create([
            'company_id' => $this->company->id, 'name' => 'HQ',
        ]);

        $department = Department::create([
            'company_id' => $this->company->id, 'name' => 'Ops',
        ]);

        $this->user = User::create([
            'name' => 'Ann Lee', 'email' => 'ann@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $this->user->assignRole('employee');

        $this->employee = Employee::create([
            'company_id' => $this->company->id, 'department_id' => $department->id,
            'office_id' => $this->office->id, 'user_id' => $this->user->id,
            'employee_code' => 'E1', 'first_name' => 'Ann', 'last_name' => 'Lee',
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->user);
    }

    protected function punch(string $type, string $at, ?Employee $employee = null): AttendanceLog
    {
        $employee ??= $this->employee;
        $moment = Carbon::parse($at);

        return AttendanceLog::create([
            'company_id'  => $employee->company_id,
            'employee_id' => $employee->id,
            'office_id'   => $this->office->id,
            'type'        => $type,
            'scanned_at'  => $moment,
            'work_date'   => $moment->toDateString(),
            'status'      => 'ontime',
            'source'      => 'mobile',
        ]);
    }

    protected function colleague(): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id, 'office_id' => $this->office->id,
            'employee_code' => 'E2', 'first_name' => 'Bo', 'last_name' => 'Ray',
            'status' => 'active',
        ]);
    }

    // ================= raising =================

    public function test_an_employee_can_report_a_missing_punch(): void
    {
        $this->postJson('/api/v1/attendance/regularisations', [
            'type'         => 'out',
            'requested_at' => '2026-08-03 18:00:00',
            'reason'       => 'Left at 6pm but forgot to press check out',
        ])
            ->assertStatus(201)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('request.status', 'pending')
            ->assertJsonPath('request.type', 'out')
            ->assertJsonPath('request.work_date', '2026-08-03')
            ->assertJsonPath('request.challenges_a_punch', false)
            ->assertJsonPath('request.attendance_log_id', null)
            ->assertJsonPath('request.can_cancel', true);

        // Raising must not touch attendance. It is inert until HR decides it.
        $this->assertSame(0, AttendanceLog::count());
    }

    public function test_an_employee_can_dispute_one_of_their_own_punches(): void
    {
        $log = $this->punch('in', '2026-08-03 09:40:00');

        $this->postJson('/api/v1/attendance/regularisations', [
            'attendance_log_id' => $log->id,
            'type'              => 'in',
            'requested_at'      => '2026-08-03 09:00:00',
            'reason'            => 'I was here at 9; the reader did not register until later',
        ])
            ->assertStatus(201)
            ->assertJsonPath('request.challenges_a_punch', true)
            ->assertJsonPath('request.attendance_log_id', $log->id);

        // The disputed punch stands until somebody decides otherwise.
        $this->assertNull($log->fresh()->voided_at);
    }

    public function test_a_punch_on_somebody_elses_record_cannot_be_disputed(): void
    {
        $theirs = $this->punch('in', '2026-08-03 09:00:00', $this->colleague());

        $this->postJson('/api/v1/attendance/regularisations', [
            'attendance_log_id' => $theirs->id,
            'type'              => 'in',
            'requested_at'      => '2026-08-03 08:00:00',
            'reason'            => 'Trying to edit a colleague record',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('attendance_log_id');

        $this->assertSame(0, AttendanceRegularisation::count());
    }

    public function test_a_correction_cannot_be_asked_for_in_the_future(): void
    {
        $this->postJson('/api/v1/attendance/regularisations', [
            'type'         => 'in',
            'requested_at' => now()->addDay()->toDateTimeString(),
            'reason'       => 'Asking about tomorrow',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('requested_at');
    }

    public function test_a_second_open_request_for_the_same_thing_is_refused(): void
    {
        $payload = [
            'type'         => 'out',
            'requested_at' => '2026-08-03 18:00:00',
            'reason'       => 'Forgot to check out',
        ];

        $this->postJson('/api/v1/attendance/regularisations', $payload)->assertStatus(201);
        $this->postJson('/api/v1/attendance/regularisations', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertSame(1, AttendanceRegularisation::count());
    }

    public function test_the_same_thing_can_be_asked_again_once_the_first_is_settled(): void
    {
        $payload = [
            'type'         => 'out',
            'requested_at' => '2026-08-03 18:00:00',
            'reason'       => 'Forgot to check out',
        ];

        $this->postJson('/api/v1/attendance/regularisations', $payload)->assertStatus(201);

        AttendanceRegularisation::first()->update(['status' => 'rejected']);

        // The bar is one *open* request, not one ever. A rejected request that
        // blocked the subject forever would leave the employee with no way back.
        $this->postJson('/api/v1/attendance/regularisations', $payload)->assertStatus(201);

        $this->assertSame(2, AttendanceRegularisation::count());
    }

    public function test_a_reason_is_required_and_has_to_say_something(): void
    {
        $this->postJson('/api/v1/attendance/regularisations', [
            'type' => 'in', 'requested_at' => '2026-08-03 09:00:00', 'reason' => 'x',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_a_break_punch_cannot_be_asked_for(): void
    {
        // Only in and out are correctable. A break has no shift to be judged
        // against and recordManual would have nothing to write.
        $this->postJson('/api/v1/attendance/regularisations', [
            'type' => 'break_start', 'requested_at' => '2026-08-03 13:00:00',
            'reason' => 'Forgot to log my break',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    public function test_raising_needs_a_token(): void
    {
        app('auth')->forgetGuards();

        $this->postJson('/api/v1/attendance/regularisations', [
            'type' => 'in', 'requested_at' => '2026-08-03 09:00:00', 'reason' => 'Anything at all',
        ])->assertStatus(401);
    }

    // ================= listing =================

    public function test_the_list_holds_only_the_callers_own_requests(): void
    {
        $this->postJson('/api/v1/attendance/regularisations', [
            'type' => 'out', 'requested_at' => '2026-08-03 18:00:00', 'reason' => 'Mine, and mine alone',
        ])->assertStatus(201);

        AttendanceRegularisation::create([
            'company_id' => $this->company->id, 'employee_id' => $this->colleague()->id,
            'office_id' => $this->office->id, 'work_date' => '2026-08-03',
            'type' => 'in', 'requested_at' => '2026-08-03 09:00:00', 'reason' => 'Not mine',
        ]);

        $this->getJson('/api/v1/attendance/regularisations')
            ->assertOk()
            ->assertJsonCount(1, 'requests')
            ->assertJsonPath('requests.0.reason', 'Mine, and mine alone');
    }

    public function test_the_list_offers_the_punches_that_could_be_disputed(): void
    {
        $this->punch('in', '2026-08-03 09:00:00');
        $this->punch('out', '2026-08-03 17:00:00');

        $this->getJson('/api/v1/attendance/regularisations')
            ->assertOk()
            // Newest first, and carrying the ids /attendance/history does not.
            ->assertJsonCount(2, 'recent_punches')
            ->assertJsonPath('recent_punches.0.type', 'out')
            ->assertJsonPath('recent_punches.0.work_date', '2026-08-03');
    }

    public function test_a_voided_punch_is_not_offered_for_dispute(): void
    {
        $log = $this->punch('in', '2026-08-03 09:00:00');
        $log->void($this->user, 'Struck out by HR');

        $this->getJson('/api/v1/attendance/regularisations')
            ->assertOk()
            ->assertJsonCount(0, 'recent_punches');
    }

    public function test_the_list_can_be_filtered_by_status(): void
    {
        $this->postJson('/api/v1/attendance/regularisations', [
            'type' => 'out', 'requested_at' => '2026-08-03 18:00:00', 'reason' => 'Still waiting on this',
        ])->assertStatus(201);

        $this->getJson('/api/v1/attendance/regularisations?status=pending')
            ->assertOk()->assertJsonCount(1, 'requests');

        $this->getJson('/api/v1/attendance/regularisations?status=approved')
            ->assertOk()->assertJsonCount(0, 'requests');
    }

    public function test_a_decision_names_who_made_it_after_that_account_is_gone(): void
    {
        $hr = User::create([
            'name' => 'Dana HR', 'email' => 'dana@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);

        $request = AttendanceRegularisation::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'office_id' => $this->office->id, 'work_date' => '2026-08-03',
            'type' => 'in', 'requested_at' => '2026-08-03 09:00:00', 'reason' => 'Reader missed me',
            'status' => 'rejected', 'decided_by_user_id' => $hr->id,
            'decided_by_label' => 'Dana HR', 'decided_at' => now(),
            'decision_note' => 'The badge log disagrees.',
        ]);

        $hr->delete();

        $this->getJson('/api/v1/attendance/regularisations')
            ->assertOk()
            ->assertJsonPath('requests.0.decided_by', 'Dana HR')
            ->assertJsonPath('requests.0.decision_note', 'The badge log disagrees.')
            ->assertJsonPath('requests.0.can_cancel', false);

        $this->assertSame($request->id, AttendanceRegularisation::first()->id);
    }

    // ================= withdrawing =================

    public function test_a_pending_request_can_be_withdrawn(): void
    {
        $this->postJson('/api/v1/attendance/regularisations', [
            'type' => 'out', 'requested_at' => '2026-08-03 18:00:00', 'reason' => 'Changed my mind about this',
        ])->assertStatus(201);

        $id = AttendanceRegularisation::first()->id;

        $this->postJson("/api/v1/attendance/regularisations/{$id}/cancel")
            ->assertOk()
            ->assertJsonPath('request.status', 'cancelled')
            ->assertJsonPath('request.can_cancel', false);
    }

    public function test_a_decided_request_cannot_be_withdrawn(): void
    {
        $request = AttendanceRegularisation::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'office_id' => $this->office->id, 'work_date' => '2026-08-03',
            'type' => 'in', 'requested_at' => '2026-08-03 09:00:00', 'reason' => 'Already settled',
            'status' => 'approved',
        ]);

        $this->postJson("/api/v1/attendance/regularisations/{$request->id}/cancel")
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_somebody_elses_request_cannot_be_withdrawn(): void
    {
        $theirs = AttendanceRegularisation::create([
            'company_id' => $this->company->id, 'employee_id' => $this->colleague()->id,
            'office_id' => $this->office->id, 'work_date' => '2026-08-03',
            'type' => 'in', 'requested_at' => '2026-08-03 09:00:00', 'reason' => 'Not mine to withdraw',
        ]);

        $this->postJson("/api/v1/attendance/regularisations/{$theirs->id}/cancel")
            ->assertStatus(403);

        $this->assertSame('pending', $theirs->fresh()->status);
    }

    // ================= deciding is not here =================

    public function test_the_app_cannot_approve_or_reject_anything(): void
    {
        $request = AttendanceRegularisation::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'office_id' => $this->office->id, 'work_date' => '2026-08-03',
            'type' => 'in', 'requested_at' => '2026-08-03 09:00:00', 'reason' => 'Waiting on HR',
        ]);

        // Deciding voids a punch and writes a replacement — manage-attendance,
        // and it stays on the web. No route exists here for either verb, for
        // the employee or for a manager.
        $this->postJson("/api/v1/attendance/regularisations/{$request->id}/approve")->assertStatus(404);
        $this->postJson("/api/v1/attendance/regularisations/{$request->id}/reject")->assertStatus(404);

        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame(0, AttendanceLog::count());
    }
    // ================= the day the picker may not go past =================

    /**
     * The list carries the company's today, and the app draws its date picker
     * from it.
     *
     * Without this the picker's upper bound came off the handset, and a phone
     * even a few hours ahead of the company offered a date this very controller
     * then refused — an error on a date the app itself had suggested. The
     * company is put four hours behind UTC and the clock set to an hour where
     * the two disagree about the date, because with a shared clock a picker
     * built from the handset passes.
     */
    public function test_the_list_names_the_company_s_today_not_the_server_s(): void
    {
        $this->company->update(['timezone' => 'America/New_York']);

        // 02:30 UTC on the 15th is 22:30 on the 14th in New York.
        Carbon::setTestNow('2026-09-15 02:30:00');

        $response = $this->getJson('/api/v1/attendance/regularisations')
            ->assertOk()
            ->json();

        $this->assertSame('2026-09-14', $response['today']);
        $this->assertNotSame(now()->toDateString(), $response['today']);

        Carbon::setTestNow();
    }
}
