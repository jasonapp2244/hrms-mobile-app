<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Trap 6 on the web portal: after a break the last punch is `break_end`, and
 * reading the last punch offered "Check In" to somebody still at work.
 */
class EmployeePortalNextActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_portal_offers_check_out_after_a_break(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(Carbon::parse('2026-09-30 13:00:00'));

        $company = Company::create(['name' => 'Acme', 'timezone' => 'UTC', 'currency' => 'USD']);
        $office  = Office::create(['company_id' => $company->id, 'name' => 'HQ']);
        $user    = User::create([
            'name' => 'Ann', 'email' => 'ann@acme.test', 'password' => Hash::make('password'),
            'company_id' => $company->id, 'is_active' => true,
        ]);
        $user->assignRole('employee');
        $employee = Employee::create([
            'company_id' => $company->id, 'office_id' => $office->id, 'user_id' => $user->id,
            'employee_code' => 'E1', 'first_name' => 'Ann', 'last_name' => 'Lee', 'status' => 'active',
        ]);

        foreach (['in' => '09:00', 'break_start' => '12:00', 'break_end' => '12:30'] as $type => $time) {
            AttendanceLog::create([
                'company_id' => $company->id, 'employee_id' => $employee->id, 'office_id' => $office->id,
                'type' => $type, 'scanned_at' => "2026-09-30 {$time}:00", 'work_date' => '2026-09-30',
                'status' => 'ontime', 'source' => 'button',
            ]);
        }

        $this->actingAs($user)->get(route('employee.dashboard'))
            ->assertOk()
            ->assertSee('data-action="out"', false);
    }
}
