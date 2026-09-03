<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        // Company — attach to whichever company already exists rather than
        // matching on name. Matching on 'Acme Corporation' meant that once the
        // company was renamed through Company Profile, re-running this seeder
        // found no match and forked a second, parallel company: every later
        // re-run then seeded departments, offices and shifts into an orphan the
        // signed-in admin could not see.
        $company = Company::first() ?? Company::create([
            'name' => 'Acme Corporation',
            'email' => 'info@acme.test',
            'phone' => '+1 (212) 555-0100',
            'city' => 'New York',
            'country' => 'United States',
            'timezone' => 'America/New_York',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        // Admin user (Phase 1 = admin-only login)
        $admin = User::firstOrCreate(
            ['email' => 'admin@emp.test'],
            [
                'name' => 'System Administrator',
                'password' => Hash::make('password'),
                'company_id' => $company->id,
                'is_active' => true,
            ]
        );
        $admin->assignRole('admin');

        // HR user (structured now; login gate blocks HR in Phase 1)
        $hr = User::firstOrCreate(
            ['email' => 'hr@emp.test'],
            [
                'name' => 'HR Manager',
                'password' => Hash::make('password'),
                'company_id' => $company->id,
                'is_active' => true,
            ]
        );
        $hr->assignRole('hr');

        // Offices (location only; working time lives on Shifts)
        $head = Office::firstOrCreate(
            ['company_id' => $company->id, 'name' => 'Head Office'],
            ['code' => 'HO', 'city' => 'New York']
        );
        $branch = Office::firstOrCreate(
            ['company_id' => $company->id, 'name' => 'Los Angeles Branch'],
            ['code' => 'LA', 'city' => 'Los Angeles']
        );

        // Shifts (working time lives here; assigned to departments below)
        $shiftData = [
            ['name' => 'Morning Shift', 'code' => 'MOR', 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'color' => '#e8622e'],
            ['name' => 'Evening Shift', 'code' => 'EVE', 'start_time' => '13:00:00', 'end_time' => '21:00:00', 'color' => '#2b8fbe'],
            ['name' => 'Night Shift',   'code' => 'NGT', 'start_time' => '22:00:00', 'end_time' => '06:00:00', 'color' => '#7b4bd8'],
        ];
        $shifts = [];
        foreach ($shiftData as $sd) {
            $shifts[] = \App\Models\Shift::firstOrCreate(
                ['company_id' => $company->id, 'name' => $sd['name']],
                $sd + ['break_minutes' => 60, 'late_grace_minutes' => 15, 'is_active' => true]
            );
        }

        // Leave types. Carry-forward: null = unlimited, 0 = expires at year end.
        $leaveTypeData = [
            ['name' => 'Annual Leave', 'code' => 'AL', 'days_per_year' => 20, 'is_paid' => true,  'carry_forward_max' => 5,    'color' => '#F26522'],
            ['name' => 'Sick Leave',   'code' => 'SL', 'days_per_year' => 10, 'is_paid' => true,  'carry_forward_max' => 0,    'color' => '#dc3545'],
            ['name' => 'Casual Leave', 'code' => 'CL', 'days_per_year' => 6,  'is_paid' => true,  'carry_forward_max' => 0,    'color' => '#2b8fbe'],
            ['name' => 'Unpaid Leave', 'code' => 'UL', 'days_per_year' => 0,  'is_paid' => false, 'carry_forward_max' => null, 'color' => '#6c757d'],
        ];
        foreach ($leaveTypeData as $ltd) {
            \App\Models\LeaveType::firstOrCreate(
                ['company_id' => $company->id, 'name' => $ltd['name']],
                $ltd + ['requires_approval' => true, 'allow_half_day' => true, 'is_active' => true]
            );
        }

        // Departments (each assigned a shift that sets its working hours)
        $deptData = [
            ['Engineering', 0],      // Morning
            ['Human Resources', 0],  // Morning
            ['Sales', 1],            // Evening
            ['Finance', 0],          // Morning
        ];
        $departments = [];
        foreach ($deptData as $i => [$name, $shiftIdx]) {
            $departments[] = Department::firstOrCreate(
                ['company_id' => $company->id, 'name' => $name],
                ['code' => strtoupper(substr($name, 0, 3)), 'shift_id' => $shifts[$shiftIdx]->id, 'is_active' => true]
            );
        }

        // Designations
        $desigData = ['Software Engineer', 'HR Executive', 'Sales Manager', 'Accountant'];
        $designations = [];
        foreach ($desigData as $i => $name) {
            $designations[] = Designation::firstOrCreate(
                ['company_id' => $company->id, 'name' => $name],
                ['department_id' => $departments[$i]->id, 'is_active' => true]
            );
        }

        // Sample employees
        $sampleEmployees = [
            ['James', 'Smith', 'james.smith@acme.test', 'male'],
            ['Emily', 'Johnson', 'emily.johnson@acme.test', 'female'],
            ['Michael', 'Brown', 'michael.brown@acme.test', 'male'],
            ['Jessica', 'Davis', 'jessica.davis@acme.test', 'female'],
            ['David', 'Wilson', 'david.wilson@acme.test', 'male'],
        ];
        foreach ($sampleEmployees as $i => [$first, $last, $email, $gender]) {
            // Employee self-service login account (employee role).
            $empUser = User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => trim("$first $last"),
                    'password' => Hash::make('password'),
                    'company_id' => $company->id,
                    'is_active' => true,
                ]
            );
            $empUser->assignRole('employee');

            Employee::firstOrCreate(
                ['employee_code' => 'EMP-' . str_pad($i + 1, 4, '0', STR_PAD_LEFT)],
                [
                    'company_id' => $company->id,
                    'user_id' => $empUser->id,
                    'office_id' => $i % 2 === 0 ? $head->id : $branch->id,
                    'department_id' => $departments[$i % count($departments)]->id,
                    'designation_id' => $designations[$i % count($designations)]->id,
                    'first_name' => $first,
                    'last_name' => $last,
                    'email' => $email,
                    'gender' => $gender,
                    'hire_date' => now()->subMonths(rand(1, 36))->toDateString(),
                    'status' => 'active',
                ]
            );
        }

        // Reporting line, so the approval chain is demonstrable out of the box:
        // the first employee leads the rest. The manager role is held *in
        // addition to* employee — a manager is staff who also approves.
        $lead = Employee::where('company_id', $company->id)
            ->where('employee_code', 'EMP-0001')->first();

        if ($lead) {
            $lead->user?->assignRole('manager');

            // Only fills gaps, so a reporting line set by hand in the UI is
            // never overwritten by a re-seed.
            Employee::where('company_id', $company->id)
                ->where('id', '!=', $lead->id)
                ->whereNull('manager_id')
                ->update(['manager_id' => $lead->id]);
        }
    }
}
