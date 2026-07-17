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
        // Company
        $company = Company::firstOrCreate(
            ['name' => 'Acme Corporation'],
            [
                'email' => 'info@acme.test',
                'phone' => '+1 (212) 555-0100',
                'city' => 'New York',
                'country' => 'United States',
                'timezone' => 'America/New_York',
                'is_active' => true,
            ]
        );

        // Admin user (Phase 1 = admin-only login)
        $admin = User::firstOrCreate(
            ['email' => 'admin@hrms.test'],
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
            ['email' => 'hr@hrms.test'],
            [
                'name' => 'HR Manager',
                'password' => Hash::make('password'),
                'company_id' => $company->id,
                'is_active' => true,
            ]
        );
        $hr->assignRole('hr');

        // Offices (each gets an auto-generated rotating-QR secret)
        $head = Office::firstOrCreate(
            ['company_id' => $company->id, 'name' => 'Head Office'],
            ['code' => 'HO', 'city' => 'New York', 'work_start_time' => '09:00:00', 'work_end_time' => '17:00:00', 'late_grace_minutes' => 15]
        );
        $branch = Office::firstOrCreate(
            ['company_id' => $company->id, 'name' => 'Los Angeles Branch'],
            ['code' => 'LA', 'city' => 'Los Angeles', 'work_start_time' => '09:30:00', 'work_end_time' => '17:30:00', 'late_grace_minutes' => 10]
        );

        // Departments
        $deptData = ['Engineering', 'Human Resources', 'Sales', 'Finance'];
        $departments = [];
        foreach ($deptData as $i => $name) {
            $departments[] = Department::firstOrCreate(
                ['company_id' => $company->id, 'name' => $name],
                ['code' => strtoupper(substr($name, 0, 3)), 'is_active' => true]
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
            Employee::firstOrCreate(
                ['employee_code' => 'EMP-' . str_pad($i + 1, 4, '0', STR_PAD_LEFT)],
                [
                    'company_id' => $company->id,
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
    }
}
