<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Bulk employee import.
 *
 * The failure this is mostly written against is quiet: an employee imported
 * with no department has no shift, so there is no start time to be late
 * against and their attendance is never judged. Nothing on the screen says so —
 * they simply never appear in a lateness report, and the first evidence is a
 * payroll question months later.
 */
class EmployeeImportTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected User $admin;
    protected Office $office;
    protected Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create([
            'name' => 'Real Co', 'timezone' => 'America/New_York', 'currency' => 'USD',
        ]);

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@real.test',
            'password' => Hash::make('a-real-password'), 'company_id' => $this->company->id,
        ]);
        $this->admin->assignRole('admin');

        $this->office = Office::create([
            'company_id' => $this->company->id, 'name' => 'Head Office', 'code' => 'HO',
        ]);

        $shift = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Morning Shift', 'code' => 'MOR',
            'start_time' => '09:00:00', 'end_time' => '17:00:00',
            'break_minutes' => 60, 'late_grace_minutes' => 15, 'is_active' => true,
        ]);

        $this->department = Department::create([
            'company_id' => $this->company->id, 'name' => 'Engineering',
            'code' => 'ENG', 'shift_id' => $shift->id, 'is_active' => true,
        ]);

        Designation::create([
            'company_id' => $this->company->id, 'name' => 'Software Engineer',
            'department_id' => $this->department->id, 'is_active' => true,
        ]);
    }

    protected function upload(string $csv)
    {
        return $this->actingAs($this->admin)->post(route('employees.import.store'), [
            'file' => UploadedFile::fake()->createWithContent('staff.csv', $csv),
        ]);
    }

    public function test_a_good_file_imports_with_everything_attached(): void
    {
        $this->upload(<<<CSV
        employee_code,first_name,last_name,email,phone,gender,hire_date,office,department,designation,manager_code
        EMP-0101,Jane,Doe,jane@real.test,555 0142,female,2024-03-01,Head Office,Engineering,Software Engineer,
        ,John,Smith,john@real.test,,male,2025-11-17,Head Office,Engineering,,EMP-0101
        CSV)->assertRedirect(route('employees.index'));

        $jane = Employee::firstWhere('email', 'jane@real.test');
        $this->assertSame('EMP-0101', $jane->employee_code);
        $this->assertSame('female', $jane->gender);
        $this->assertSame('2024-03-01', $jane->hire_date->toDateString());
        $this->assertSame($this->office->id, $jane->office_id);

        // The one that matters: department resolved, so there is a shift, so
        // attendance can actually be judged.
        $this->assertSame($this->department->id, $jane->department_id);
        $this->assertNotNull($jane->department->shift);

        $john = Employee::firstWhere('email', 'john@real.test');
        $this->assertNotEmpty($john->employee_code);          // generated
        $this->assertSame($jane->id, $john->manager_id);      // resolved by code
    }

    public function test_a_manager_listed_after_their_report_still_resolves(): void
    {
        // People do not arrive in org-chart order.
        $this->upload(<<<CSV
        employee_code,first_name,email,department,manager_code
        EMP-0102,John,john@real.test,Engineering,EMP-0101
        EMP-0101,Jane,jane@real.test,Engineering,
        CSV)->assertRedirect(route('employees.index'));

        $this->assertSame(
            Employee::firstWhere('email', 'jane@real.test')->id,
            Employee::firstWhere('email', 'john@real.test')->manager_id,
        );
    }

    public function test_a_row_without_a_department_is_refused(): void
    {
        $this->upload(<<<CSV
        first_name,email
        Jane,jane@real.test
        CSV)
            ->assertRedirect()
            ->assertSessionHas('import_errors', fn ($errors) => str_contains($errors[0], 'department is required'));

        $this->assertSame(0, Employee::count());
    }

    public function test_an_unknown_department_is_reported_rather_than_left_blank(): void
    {
        $this->upload(<<<CSV
        first_name,email,department
        Jane,jane@real.test,Enginering
        CSV)
            ->assertRedirect()
            ->assertSessionHas('import_errors', fn ($errors) => str_contains($errors[0], "no department called 'Enginering'"));

        // A typo must not import somebody with no shift.
        $this->assertSame(0, Employee::count());
    }

    public function test_office_and_department_match_regardless_of_case(): void
    {
        $this->upload(<<<CSV
        first_name,email,office,department
        Jane,jane@real.test,head office,ENGINEERING
        CSV)->assertRedirect(route('employees.index'));

        $jane = Employee::firstWhere('email', 'jane@real.test');
        $this->assertSame($this->office->id, $jane->office_id);
        $this->assertSame($this->department->id, $jane->department_id);
    }

    public function test_one_bad_row_stops_the_whole_file(): void
    {
        // Partial imports are the worst outcome: you cannot tell who made it in,
        // and re-uploading the fixed file duplicates everyone who did.
        $this->upload(<<<CSV
        first_name,email,department
        Jane,jane@real.test,Engineering
        John,not-an-email,Engineering
        Ann,ann@real.test,Engineering
        CSV)->assertRedirect()->assertSessionHas('import_errors');

        $this->assertSame(0, Employee::count());
    }

    public function test_a_duplicate_email_inside_the_file_is_caught(): void
    {
        $this->upload(<<<CSV
        first_name,email,department
        Jane,same@real.test,Engineering
        John,same@real.test,Engineering
        CSV)
            ->assertRedirect()
            ->assertSessionHas('import_errors', fn ($errors) => str_contains(implode(' ', $errors), 'appears twice'));

        $this->assertSame(0, Employee::count());
    }

    public function test_an_email_already_in_the_company_is_caught(): void
    {
        Employee::create([
            'company_id' => $this->company->id, 'employee_code' => 'EMP-0001',
            'first_name' => 'Existing', 'email' => 'taken@real.test',
            'department_id' => $this->department->id, 'status' => 'active',
        ]);

        $this->upload(<<<CSV
        first_name,email,department
        Jane,taken@real.test,Engineering
        CSV)
            ->assertRedirect()
            ->assertSessionHas('import_errors', fn ($errors) => str_contains($errors[0], 'already belongs to'));

        $this->assertSame(1, Employee::count());
    }

    public function test_a_duplicate_employee_code_is_caught(): void
    {
        Employee::create([
            'company_id' => $this->company->id, 'employee_code' => 'EMP-0001',
            'first_name' => 'Existing', 'department_id' => $this->department->id, 'status' => 'active',
        ]);

        $this->upload(<<<CSV
        employee_code,first_name,department
        EMP-0001,Jane,Engineering
        CSV)
            ->assertRedirect()
            ->assertSessionHas('import_errors', fn ($errors) => str_contains($errors[0], 'already used'));
    }

    public function test_an_unparseable_hire_date_is_reported(): void
    {
        $this->upload(<<<CSV
        first_name,hire_date,department
        Jane,not-a-date,Engineering
        CSV)
            ->assertRedirect()
            ->assertSessionHas('import_errors', fn ($errors) => str_contains($errors[0], 'not a date'));
    }

    public function test_a_file_without_a_first_name_column_is_rejected_outright(): void
    {
        $this->upload(<<<CSV
        name,email
        Jane,jane@real.test
        CSV)->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, Employee::count());
    }

    public function test_blank_lines_at_the_end_are_ignored(): void
    {
        $this->upload("first_name,email,department\nJane,jane@real.test,Engineering\n\n\n")
            ->assertRedirect(route('employees.index'));

        $this->assertSame(1, Employee::count());
    }

    public function test_the_template_lists_this_companys_real_options(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('employees.import.template'))
            ->assertOk();

        $csv = $response->getContent();

        // A template naming departments that do not exist here would fail on
        // upload, which is a poor first experience of the feature.
        $this->assertStringContainsString('Head Office', $csv);
        $this->assertStringContainsString('Engineering', $csv);
        $this->assertStringContainsString('manager_code', $csv);
    }

    public function test_an_employee_without_the_permission_cannot_import(): void
    {
        $staff = User::create([
            'name' => 'Staff', 'email' => 'staff@real.test',
            'password' => Hash::make('a-real-password'), 'company_id' => $this->company->id,
        ]);
        $staff->assignRole('employee');

        $this->actingAs($staff)
            ->post(route('employees.import.store'), [
                'file' => UploadedFile::fake()->createWithContent('staff.csv', "first_name,department\nJane,Engineering\n"),
            ])
            ->assertForbidden();
    }
}
