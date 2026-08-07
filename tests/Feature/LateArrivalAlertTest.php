<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\LateArrivalsDigest;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A9.3 — the late-arrival digest.
 *
 * The design decision worth protecting is that it is a digest. One message
 * listing everybody beats twelve messages, and a test that only checked
 * "something was sent" would let the per-person version back in.
 */
class LateArrivalAlertTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Office $office;
    protected Department $department;
    protected User $hr;
    protected User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'UTC', 'currency' => 'USD',
        ]);
        $this->office = Office::create(['company_id' => $this->company->id, 'name' => 'Head Office']);

        $shift = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Day',
            'start_time' => '09:00:00', 'end_time' => '17:00:00',
            'break_minutes' => 30, 'late_grace_minutes' => 15, 'is_active' => true,
        ]);

        $this->department = Department::create([
            'company_id' => $this->company->id, 'name' => 'Ops', 'shift_id' => $shift->id,
        ]);

        $this->hr = User::create([
            'name' => 'Hana Ruiz', 'email' => 'hana@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $this->hr->assignRole('hr');

        $this->staff = User::create([
            'name' => 'Ann Lee', 'email' => 'ann@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $this->staff->assignRole('employee');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employee(string $first): Employee
    {
        static $n = 0;
        $n++;

        return Employee::create([
            'company_id' => $this->company->id, 'office_id' => $this->office->id,
            'department_id' => $this->department->id,
            'employee_code' => 'E' . $n, 'first_name' => $first, 'last_name' => 'Test',
            'status' => 'active',
        ]);
    }

    private function punchIn(Employee $e, string $time, string $status): void
    {
        AttendanceLog::create([
            'company_id' => $this->company->id, 'employee_id' => $e->id,
            'office_id' => $this->office->id, 'type' => 'in',
            'scanned_at' => Carbon::parse($time), 'work_date' => Carbon::parse($time)->toDateString(),
            'status' => $status, 'source' => 'button',
        ]);
    }

    public function test_hr_is_told_about_a_late_arrival(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-08-05 10:30:00');

        $this->punchIn($this->employee('Ann'), '2026-08-05 09:40:00', 'late');

        $this->artisan('attendance:report-late')->assertSuccessful();

        Notification::assertSentTo($this->hr, LateArrivalsDigest::class);
    }

    public function test_everybody_late_arrives_in_one_message(): void
    {
        // The whole point. Twelve separate alerts is a morning nobody reads.
        Notification::fake();
        Carbon::setTestNow('2026-08-05 10:30:00');

        foreach (['Ann', 'Bob', 'Cal'] as $name) {
            $this->punchIn($this->employee($name), '2026-08-05 09:40:00', 'late');
        }

        $this->artisan('attendance:report-late')->assertSuccessful();

        Notification::assertSentToTimes($this->hr, LateArrivalsDigest::class, 1);

        Notification::assertSentTo($this->hr, LateArrivalsDigest::class, function ($notification) {
            return count($notification->arrivals) === 3;
        });
    }

    public function test_an_on_time_arrival_is_not_reported(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-08-05 10:30:00');

        $this->punchIn($this->employee('Ann'), '2026-08-05 09:05:00', 'ontime');

        $this->artisan('attendance:report-late')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_nothing_is_sent_on_a_day_with_no_lateness(): void
    {
        // Silence is the correct output. A daily "0 late arrivals" mail is the
        // fastest way to teach people to filter the whole thread.
        Notification::fake();
        Carbon::setTestNow('2026-08-05 10:30:00');

        $this->employee('Ann');

        $this->artisan('attendance:report-late')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_the_digest_carries_how_late_each_person_was(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-08-05 10:30:00');

        $this->punchIn($this->employee('Ann'), '2026-08-05 09:40:00', 'late');

        $this->artisan('attendance:report-late');

        Notification::assertSentTo($this->hr, LateArrivalsDigest::class, function ($notification) {
            // 09:00 start, in at 09:40.
            return $notification->arrivals[0]['minutes'] === 40
                && $notification->arrivals[0]['at'] === '09:40';
        });
    }

    public function test_an_employee_is_not_sent_the_digest(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-08-05 10:30:00');

        $this->punchIn($this->employee('Ann'), '2026-08-05 09:40:00', 'late');

        $this->artisan('attendance:report-late');

        Notification::assertNotSentTo($this->staff, LateArrivalsDigest::class);
    }

    public function test_a_dry_run_sends_nothing(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-08-05 10:30:00');

        $this->punchIn($this->employee('Ann'), '2026-08-05 09:40:00', 'late');

        $this->artisan('attendance:report-late --dry-run')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_another_companys_lateness_is_not_reported_here(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-08-05 10:30:00');

        $other = Company::create(['name' => 'Other', 'timezone' => 'UTC', 'currency' => 'USD']);
        $otherOffice = Office::create(['company_id' => $other->id, 'name' => 'Remote']);
        $theirs = Employee::create([
            'company_id' => $other->id, 'office_id' => $otherOffice->id,
            'employee_code' => 'X1', 'first_name' => 'Sam', 'last_name' => 'Poe',
            'status' => 'active',
        ]);

        AttendanceLog::create([
            'company_id' => $other->id, 'employee_id' => $theirs->id,
            'office_id' => $otherOffice->id, 'type' => 'in',
            'scanned_at' => Carbon::parse('2026-08-05 09:40:00'), 'work_date' => '2026-08-05',
            'status' => 'late', 'source' => 'button',
        ]);

        $this->artisan('attendance:report-late --company=' . $this->company->id)->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_a_voided_late_punch_is_not_reported(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-08-05 10:30:00');

        $ann = $this->employee('Ann');
        $this->punchIn($ann, '2026-08-05 09:40:00', 'late');

        AttendanceLog::where('employee_id', $ann->id)->firstOrFail()
            ->void($this->hr, 'Recorded against the wrong person');

        $this->artisan('attendance:report-late')->assertSuccessful();

        Notification::assertNothingSent();
    }
}
