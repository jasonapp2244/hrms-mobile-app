<?php

namespace Tests\Feature;

use App\Exceptions\AttendanceIsImmutable;
use App\Models\AttendanceAuditEvent;
use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Shift;
use App\Models\User;
use App\Services\AttendanceService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A4.12 — correcting the attendance record.
 *
 * Attendance is append-only, so a correction cannot be an edit. It is void the
 * wrong punch, then key in the right one, leaving both readings and the reason
 * on the record. These tests exist mostly to hold that line: the interesting
 * failures are not "can HR fix a punch" but "can anyone quietly change history",
 * and "can a struck-out punch still reach somebody's hours".
 *
 * That second one is why the void filter is a global scope rather than a scope
 * each caller opts into — there are twenty-odd query sites, and forgetting one
 * would overpay or underpay somebody with no visible symptom.
 */
class AttendanceCorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Office $office;
    protected Employee $employee;
    protected User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'America/New_York', 'currency' => 'USD',
        ]);

        $this->office = Office::create([
            'company_id' => $this->company->id, 'name' => 'Head Office',
        ]);

        $shift = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Day',
            'start_time' => '09:00:00', 'end_time' => '17:00:00',
            'break_minutes' => 30, 'late_grace_minutes' => 15, 'is_active' => true,
        ]);

        $department = Department::create([
            'company_id' => $this->company->id, 'name' => 'Ops', 'shift_id' => $shift->id,
        ]);

        $staff = User::create([
            'name' => 'Ann Lee', 'email' => 'ann@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $staff->assignRole('employee');

        $this->employee = Employee::create([
            'company_id' => $this->company->id, 'department_id' => $department->id,
            'office_id' => $this->office->id, 'user_id' => $staff->id,
            'employee_code' => 'E1', 'first_name' => 'Ann', 'last_name' => 'Lee',
            'status' => 'active',
        ]);

        $this->hr = User::create([
            'name' => 'Hana Ruiz', 'email' => 'hana@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $this->hr->assignRole('hr');
    }

    private function punch(string $type, string $at): AttendanceLog
    {
        $moment = Carbon::parse($at);

        return AttendanceLog::create([
            'company_id'  => $this->company->id,
            'employee_id' => $this->employee->id,
            'office_id'   => $this->office->id,
            'type'        => $type,
            'scanned_at'  => $moment,
            'work_date'   => $moment->toDateString(),
            'status'      => 'ontime',
            'source'      => 'pwa',
        ]);
    }

    // -------------------------------------------------------------------------
    // The record still cannot be rewritten
    // -------------------------------------------------------------------------

    public function test_a_punch_still_cannot_be_edited(): void
    {
        $log = $this->punch('in', '2026-08-03 09:00:00');

        $this->expectException(AttendanceIsImmutable::class);
        $log->update(['status' => 'ontime', 'scanned_at' => now()]);
    }

    public function test_a_punch_still_cannot_be_deleted(): void
    {
        $log = $this->punch('in', '2026-08-03 09:00:00');

        $this->expectException(AttendanceIsImmutable::class);
        $log->delete();
    }

    public function test_voiding_never_alters_what_the_punch_claims(): void
    {
        $log = $this->punch('in', '2026-08-03 09:40:00');
        $before = $log->only(['type', 'scanned_at', 'status', 'office_id', 'source']);

        $log->void($this->hr, 'Duplicate scan');

        $after = AttendanceLog::withVoided()->find($log->id);
        $this->assertSame($before['type'], $after->type);
        $this->assertSame($before['status'], $after->status);
        $this->assertEquals($before['scanned_at'], $after->scanned_at);
    }

    public function test_a_punch_cannot_be_voided_twice(): void
    {
        $log = $this->punch('in', '2026-08-03 09:00:00');
        $log->void($this->hr, 'Duplicate scan');

        // Voiding again would overwrite the first actor and reason — the exact
        // quiet rewrite the trail exists to prevent.
        $this->expectException(AttendanceIsImmutable::class);
        $log->void($this->hr, 'Changed my mind');
    }

    // -------------------------------------------------------------------------
    // A voided punch stops counting, everywhere
    // -------------------------------------------------------------------------

    public function test_a_voided_punch_leaves_ordinary_queries(): void
    {
        $log = $this->punch('in', '2026-08-03 09:00:00');

        $this->assertCount(1, AttendanceLog::all());

        $log->void($this->hr, 'Recorded against the wrong person');

        $this->assertCount(0, AttendanceLog::all());
        $this->assertCount(1, AttendanceLog::withVoided()->get());
        $this->assertCount(1, AttendanceLog::onlyVoided()->get());
    }

    public function test_a_voided_punch_stops_counting_towards_worked_hours(): void
    {
        // 09:00 in, a duplicate 09:00 in, and 17:00 out. Left alone the
        // duplicate distorts the pairing; voided, the day is a clean 8 hours.
        $this->punch('in', '2026-08-03 09:00:00');
        $duplicate = $this->punch('in', '2026-08-03 09:00:30');
        $this->punch('out', '2026-08-03 17:00:00');

        $duplicate->void($this->hr, 'Duplicate scan seconds apart');

        $service = app(AttendanceService::class);
        $logs = AttendanceLog::where('employee_id', $this->employee->id)
            ->orderBy('scanned_at')->get();

        $this->assertSame(480, $service->workedMinutes($logs));
    }

    // -------------------------------------------------------------------------
    // The trail
    // -------------------------------------------------------------------------

    public function test_voiding_writes_an_audit_event_naming_who_and_why(): void
    {
        $log = $this->punch('in', '2026-08-03 09:00:00');

        $log->void($this->hr, 'Employee was on approved leave');

        $event = AttendanceAuditEvent::where('attendance_log_id', $log->id)
            ->where('event', AttendanceAuditEvent::VOIDED)
            ->firstOrFail();

        $this->assertSame($this->hr->id, $event->actor_user_id);
        $this->assertSame('Hana Ruiz', $event->actor_label);
        $this->assertSame('Employee was on approved leave', $event->reason);
        $this->assertSame('in', $event->before['type']);
    }

    public function test_the_original_creation_event_survives_the_void(): void
    {
        $log = $this->punch('in', '2026-08-03 09:00:00');
        $log->void($this->hr, 'Duplicate scan');

        // Both halves of the story, in order.
        $events = AttendanceAuditEvent::where('attendance_log_id', $log->id)
            ->orderBy('id')->pluck('event')->all();

        $this->assertSame(
            [AttendanceAuditEvent::CREATED, AttendanceAuditEvent::VOIDED],
            $events,
        );
    }

    // -------------------------------------------------------------------------
    // Manual entry
    // -------------------------------------------------------------------------

    public function test_a_manual_punch_is_judged_against_the_shift_at_that_moment(): void
    {
        // 09:40 against a 09:00 shift with 15 minutes' grace. Keyed in now, but
        // late then — the whole point of passing the time rather than reading
        // the clock.
        $log = app(AttendanceService::class)->recordManual(
            $this->employee,
            $this->office,
            'in',
            Carbon::parse('2026-08-03 09:40:00'),
            'Badge reader failed',
        );

        $this->assertSame('late', $log->status);
        $this->assertSame('manual', $log->source);
        $this->assertSame('Badge reader failed', $log->notes);
    }

    public function test_hr_can_record_a_manual_punch_through_the_form(): void
    {
        $this->actingAs($this->hr)->post(route('attendance.manual'), [
            'employee_id' => $this->employee->id,
            'office_id'   => $this->office->id,
            'type'        => 'out',
            'scanned_at'  => '2026-08-03T17:05',
            'reason'      => 'Forgot to check out; confirmed with manager',
        ])->assertRedirect();

        $log = AttendanceLog::where('employee_id', $this->employee->id)->firstOrFail();
        $this->assertSame('out', $log->type);
        $this->assertSame('manual', $log->source);
    }

    public function test_a_manual_punch_cannot_be_dated_in_the_future(): void
    {
        $this->actingAs($this->hr)->post(route('attendance.manual'), [
            'employee_id' => $this->employee->id,
            'office_id'   => $this->office->id,
            'type'        => 'in',
            'scanned_at'  => now()->addDay()->format('Y-m-d\TH:i'),
            'reason'      => 'Trying to record tomorrow',
        ])->assertSessionHasErrors('scanned_at');

        $this->assertSame(0, AttendanceLog::count());
    }

    public function test_a_reason_is_required_for_both_actions(): void
    {
        $log = $this->punch('in', '2026-08-03 09:00:00');

        $this->actingAs($this->hr)
            ->post(route('attendance.void', $log), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->actingAs($this->hr)->post(route('attendance.manual'), [
            'employee_id' => $this->employee->id,
            'office_id'   => $this->office->id,
            'type'        => 'in',
            'scanned_at'  => '2026-08-03T09:00',
            'reason'      => '',
        ])->assertSessionHasErrors('reason');

        $this->assertNull($log->fresh()->voided_at);
    }

    // -------------------------------------------------------------------------
    // Who is allowed to do this
    // -------------------------------------------------------------------------

    public function test_an_employee_cannot_void_or_key_in_a_punch(): void
    {
        $log = $this->punch('in', '2026-08-03 09:00:00');
        $employeeUser = $this->employee->user;

        // They hold view-attendance for their own history, and must never be
        // able to edit the record it is drawn from.
        $this->actingAs($employeeUser)
            ->post(route('attendance.void', $log), ['reason' => 'I was actually here'])
            ->assertForbidden();

        $this->actingAs($employeeUser)->post(route('attendance.manual'), [
            'employee_id' => $this->employee->id,
            'office_id'   => $this->office->id,
            'type'        => 'in',
            'scanned_at'  => '2026-08-03T09:00',
            'reason'      => 'Adding my own punch',
        ])->assertForbidden();

        $this->assertNull($log->fresh()->voided_at);
    }

    public function test_hr_cannot_void_another_companys_punch(): void
    {
        $other = Company::create([
            'name' => 'Other', 'timezone' => 'UTC', 'currency' => 'USD',
        ]);
        $otherOffice = Office::create(['company_id' => $other->id, 'name' => 'Remote']);
        $otherEmployee = Employee::create([
            'company_id' => $other->id, 'office_id' => $otherOffice->id,
            'employee_code' => 'X1', 'first_name' => 'Sam', 'last_name' => 'Poe',
            'status' => 'active',
        ]);

        $foreign = AttendanceLog::create([
            'company_id' => $other->id, 'employee_id' => $otherEmployee->id,
            'office_id' => $otherOffice->id, 'type' => 'in',
            'scanned_at' => Carbon::parse('2026-08-03 09:00:00'),
            'work_date' => '2026-08-03', 'status' => 'ontime', 'source' => 'pwa',
        ]);

        $this->actingAs($this->hr)
            ->post(route('attendance.void', $foreign), ['reason' => 'Not mine to touch'])
            ->assertNotFound();

        $this->assertNull($foreign->fresh()->voided_at);
    }

    // -------------------------------------------------------------------------
    // The screen
    // -------------------------------------------------------------------------

    public function test_the_log_screen_hides_voided_punches_until_asked(): void
    {
        $log = $this->punch('in', '2026-08-03 09:00:00');

        // Deliberately unlike any placeholder or help text on the page — an
        // earlier version of this test used the same wording as the modal's
        // example reason and passed, or failed, for the wrong reason.
        $log->void($this->hr, 'Voided by zzmarkerzz for this assertion');

        $this->actingAs($this->hr)
            ->get(route('attendance.logs'))
            ->assertOk()
            ->assertDontSee('zzmarkerzz');

        $this->actingAs($this->hr)
            ->get(route('attendance.logs', ['show_voided' => 1]))
            ->assertOk()
            ->assertSee('zzmarkerzz');
    }
}
