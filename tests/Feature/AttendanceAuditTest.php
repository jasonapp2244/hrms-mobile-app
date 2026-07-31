<?php

namespace Tests\Feature;

use App\Exceptions\AttendanceIsImmutable;
use App\Models\AttendanceAuditEvent;
use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Shift;
use App\Models\User;
use App\Services\AttendanceService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Attendance decides what people are paid, so the record has to be defensible:
 * append-only, and every write accounted for.
 */
class AttendanceAuditTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Office $office;
    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(Carbon::parse('2026-07-20 09:00:00'));

        $this->company = Company::create(['name' => 'Acme', 'timezone' => 'UTC']);
        $this->office = Office::create([
            'company_id' => $this->company->id, 'name' => 'Head Office',
        ]);
        $this->employee = Employee::create([
            'company_id' => $this->company->id, 'employee_code' => 'E1',
            'first_name' => 'Ann', 'status' => 'active', 'office_id' => $this->office->id,
        ]);
    }

    protected function punch(array $meta = []): AttendanceLog
    {
        return app(AttendanceService::class)
            ->record($this->employee, $this->office, $meta)['log'];
    }

    // ================= the trail =================

    public function test_a_punch_writes_an_audit_event(): void
    {
        $log = $this->punch(['source' => 'button', 'ip_address' => '203.0.113.9']);

        $event = AttendanceAuditEvent::where('attendance_log_id', $log->id)->firstOrFail();

        $this->assertSame(AttendanceAuditEvent::CREATED, $event->event);
        $this->assertSame($this->employee->id, $event->employee_id);
        $this->assertSame($this->company->id, $event->company_id);
        $this->assertSame('button', $event->source);
        $this->assertSame('203.0.113.9', $event->ip_address);
    }

    public function test_the_event_records_what_the_punch_claimed(): void
    {
        $log = $this->punch(['source' => 'button']);
        $event = AttendanceAuditEvent::where('attendance_log_id', $log->id)->firstOrFail();

        $this->assertNull($event->before, 'a creation has nothing before it');
        $this->assertSame($log->type, $event->after['type']);
        $this->assertSame($log->status, $event->after['status']);
        $this->assertSame($this->office->id, $event->after['office_id']);
    }

    public function test_a_signed_in_actor_is_named(): void
    {
        $user = User::create([
            'name' => 'Dana HR', 'email' => 'dana@test.local',
            'password' => 'password', 'company_id' => $this->company->id,
        ]);
        $user->assignRole('hr');

        $this->actingAs($user);
        $log = $this->punch();

        $event = AttendanceAuditEvent::where('attendance_log_id', $log->id)->firstOrFail();
        $this->assertSame($user->id, $event->actor_user_id);
        $this->assertSame('Dana HR', $event->actor_label);
        $this->assertSame('Dana HR', $event->actor_name);
    }

    public function test_an_unattended_punch_is_attributed_to_the_system(): void
    {
        // The nightly close runs with nobody signed in. Null actor is a real
        // answer — "the system did it" — not missing data.
        $log = $this->punch(['source' => 'auto']);

        $event = AttendanceAuditEvent::where('attendance_log_id', $log->id)->firstOrFail();
        $this->assertNull($event->actor_user_id);
        $this->assertSame('System', $event->actor_name);
    }

    public function test_the_actor_name_survives_the_account_being_deleted(): void
    {
        $user = User::create([
            'name' => 'Temp Admin', 'email' => 'temp@test.local',
            'password' => 'password', 'company_id' => $this->company->id,
        ]);
        $user->assignRole('admin');

        $this->actingAs($user);
        $log = $this->punch();
        auth()->logout();

        $user->delete();

        $event = AttendanceAuditEvent::where('attendance_log_id', $log->id)->firstOrFail();
        $this->assertNull($event->fresh()->actor_user_id, 'the link is released');
        $this->assertSame('Temp Admin', $event->actor_name, 'but the trail still names them');
    }

    public function test_the_auto_close_is_recorded_too(): void
    {
        // Both write paths must leave a trail, not just the button. The close
        // needs a scheduled end to close against, so give the employee a shift.
        $shift = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Day', 'code' => 'DAY',
            'start_time' => '09:00', 'end_time' => '17:00',
            'break_minutes' => 30, 'late_grace_minutes' => 10, 'is_active' => true,
        ]);
        $this->employee->update(['shift_id' => $shift->id]);

        $in = $this->punch(['source' => 'button']);
        $closed = app(AttendanceService::class)->autoClose($in);

        $this->assertNotNull($closed, 'the open day should have been closed');

        $event = AttendanceAuditEvent::where('attendance_log_id', $closed->id)->firstOrFail();
        $this->assertSame('auto', $event->source);
        $this->assertNull($event->actor_user_id, 'nobody pressed anything');
        $this->assertSame('out', $event->after['type']);
    }

    // ================= immutability =================

    public function test_a_punch_cannot_be_edited(): void
    {
        $log = $this->punch();

        // A value that differs from what was recorded. Assigning the same value
        // back leaves the model clean, Eloquent skips the write entirely, and
        // nothing would have been blocked because nothing was being changed.
        $this->assertNotSame('late', $log->status);

        $this->expectException(AttendanceIsImmutable::class);
        $log->update(['status' => 'late']);
    }

    public function test_the_scanned_time_cannot_be_moved(): void
    {
        // The field that matters most in a dispute over someone's hours.
        $log = $this->punch();

        $this->expectException(AttendanceIsImmutable::class);
        $log->update(['scanned_at' => $log->scanned_at->copy()->subHours(2)]);
    }

    public function test_a_punch_cannot_be_deleted(): void
    {
        $log = $this->punch();

        $this->expectException(AttendanceIsImmutable::class);
        $log->delete();
    }

    public function test_a_failed_edit_leaves_the_record_untouched(): void
    {
        $log = $this->punch();
        $original = $log->fresh()->status;

        try {
            $log->update(['status' => 'tampered']);
        } catch (AttendanceIsImmutable) {
            // expected
        }

        $this->assertSame($original, $log->fresh()->status);
    }

    public function test_an_audit_event_cannot_be_edited_or_deleted(): void
    {
        $log = $this->punch();
        $event = AttendanceAuditEvent::where('attendance_log_id', $log->id)->firstOrFail();

        try {
            $event->update(['reason' => 'rewritten']);
            $this->fail('an audit event should not be editable');
        } catch (RuntimeException) {
            // expected
        }

        try {
            $event->delete();
            $this->fail('an audit event should not be deletable');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertDatabaseHas('attendance_audit_events', ['id' => $event->id, 'reason' => null]);
    }

    // ================= scoping =================

    public function test_each_event_takes_its_own_employees_company(): void
    {
        $other = Company::create(['name' => 'Globex', 'timezone' => 'UTC']);
        $otherOffice = Office::create(['company_id' => $other->id, 'name' => 'Branch']);
        $theirs = Employee::create([
            'company_id' => $other->id, 'employee_code' => 'G1',
            'first_name' => 'Zed', 'status' => 'active', 'office_id' => $otherOffice->id,
        ]);

        $log = app(AttendanceService::class)->record($theirs, $otherOffice)['log'];
        $event = AttendanceAuditEvent::where('attendance_log_id', $log->id)->firstOrFail();

        $this->assertSame($other->id, $event->company_id);
        $this->assertNotSame($this->company->id, $event->company_id);
    }
}
