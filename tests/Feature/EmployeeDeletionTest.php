<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Deleting an employee must not delete the hours they worked.
 *
 * `attendance_logs.employee_id` is ON DELETE CASCADE, so a hard delete used to
 * take every punch with it — including the ones a finished payroll run was
 * calculated from. The rest of the system treats attendance as append-only and
 * voids punches rather than removing them; deletion was the one route around
 * that, and it was a single button on the employee page.
 */
class EmployeeDeletionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'America/New_York', 'currency' => 'USD',
        ]);

        $this->hr = User::create([
            'name'       => 'HR Person',
            'email'      => 'hr@acme.test',
            'password'   => Hash::make('CorrectHorse1'),
            'company_id' => $this->company->id,
            'is_active'  => true,
        ]);
        $this->hr->assignRole('hr');
    }

    private function employee(string $code = 'E1'): Employee
    {
        return Employee::create([
            'company_id'    => $this->company->id,
            'employee_code' => $code,
            'first_name'    => 'Some',
            'last_name'     => 'Body',
            'status'        => 'active',
        ]);
    }

    private function punch(Employee $employee): AttendanceLog
    {
        $office = Office::create([
            'company_id' => $this->company->id,
            'name'       => 'Head Office',
        ]);

        return AttendanceLog::create([
            'company_id'  => $this->company->id,
            'employee_id' => $employee->id,
            'office_id'   => $office->id,
            'work_date'   => '2026-08-03',
            'type'        => 'in',
            'scanned_at'  => '2026-08-03 09:00:00',
            'status'      => 'ontime',
            'source'      => 'pwa',
        ]);
    }

    public function test_an_employee_with_attendance_history_cannot_be_deleted(): void
    {
        $employee = $this->employee();
        $this->punch($employee);

        $this->actingAs($this->hr)
            ->delete(route('employees.destroy', $employee))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('employees', ['id' => $employee->id]);
    }

    public function test_the_punches_survive_the_attempt(): void
    {
        // The actual damage being prevented. The employee row surviving is only
        // interesting because the hours behind it survive too.
        $employee = $this->employee();
        $log = $this->punch($employee);

        $this->actingAs($this->hr)->delete(route('employees.destroy', $employee));

        $this->assertDatabaseHas('attendance_logs', ['id' => $log->id]);
    }

    public function test_the_refusal_says_what_to_do_instead(): void
    {
        $employee = $this->employee();
        $this->punch($employee);

        $this->actingAs($this->hr)->delete(route('employees.destroy', $employee));

        $this->assertStringContainsString('Terminated', session('error'));
    }

    public function test_an_employee_with_no_history_can_still_be_deleted(): void
    {
        // Deletion is still the right answer for the case it exists for: a
        // record typed in by mistake, with nothing behind it.
        $employee = $this->employee();

        $this->actingAs($this->hr)
            ->delete(route('employees.destroy', $employee))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('employees', ['id' => $employee->id]);
    }

    public function test_terminating_is_the_supported_route_and_keeps_everything(): void
    {
        $employee = $this->employee();
        $log = $this->punch($employee);

        $employee->update(['status' => 'terminated']);

        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'status' => 'terminated']);
        $this->assertDatabaseHas('attendance_logs', ['id' => $log->id]);
    }
}
