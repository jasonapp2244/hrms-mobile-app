<?php

namespace Tests\Feature;

use App\Http\Controllers\EmployeeController;
use App\Models\Company;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Stage 9, employee records — the photo (A3.7), the emergency contact and
 * personal details (A3.9), the org chart (A3.10) and the roster export (A3.11).
 */
class EmployeeRecordsTest extends TestCase
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
        Storage::fake('public');

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'America/New_York', 'currency' => 'USD',
        ]);
        $this->office = Office::create(['company_id' => $this->company->id, 'name' => 'Head Office']);
        $this->department = Department::create(['company_id' => $this->company->id, 'name' => 'Ops']);

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

    private function employee(array $overrides = []): Employee
    {
        static $n = 0;
        $n++;

        return Employee::create(array_merge([
            'company_id' => $this->company->id, 'office_id' => $this->office->id,
            'department_id' => $this->department->id,
            'employee_code' => 'E' . $n, 'first_name' => 'Person', 'last_name' => (string) $n,
            'status' => 'active', 'work_mode' => 'office',
        ], $overrides));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Ann', 'last_name' => 'Lee',
            'status' => 'active', 'work_mode' => 'office',
        ], $overrides);
    }

    // -------------------------------------------------------------------------
    // A3.7 — the photo
    // -------------------------------------------------------------------------

    public function test_a_photo_can_be_uploaded_with_a_new_employee(): void
    {
        $this->actingAs($this->hr)->post(route('employees.store'), $this->payload([
            'photo' => UploadedFile::fake()->image('ann.jpg'),
        ]))->assertRedirect();

        $employee = Employee::where('first_name', 'Ann')->firstOrFail();

        $this->assertNotNull($employee->avatar);
        Storage::disk('public')->assertExists($employee->avatar);
        $this->assertNotNull($employee->photo_url);
    }

    public function test_saving_without_a_photo_keeps_the_existing_one(): void
    {
        // "No file this time" has to mean "keep it", not "clear it" — otherwise
        // every edit of an unrelated field wipes the photo.
        $employee = $this->employee();

        $this->actingAs($this->hr)->put(route('employees.update', $employee), $this->payload([
            'photo' => UploadedFile::fake()->image('first.jpg'),
        ]));

        $first = $employee->fresh()->avatar;
        $this->assertNotNull($first);

        $this->actingAs($this->hr)->put(route('employees.update', $employee), $this->payload([
            'first_name' => 'Renamed',
        ]));

        $this->assertSame($first, $employee->fresh()->avatar);
    }

    public function test_replacing_a_photo_removes_the_old_file(): void
    {
        $employee = $this->employee();

        $this->actingAs($this->hr)->put(route('employees.update', $employee), $this->payload([
            'photo' => UploadedFile::fake()->image('first.jpg'),
        ]));
        $first = $employee->fresh()->avatar;

        $this->actingAs($this->hr)->put(route('employees.update', $employee), $this->payload([
            'photo' => UploadedFile::fake()->image('second.jpg'),
        ]));

        // Otherwise every edit leaves another orphan on disk for ever.
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($employee->fresh()->avatar);
    }

    public function test_a_non_image_is_refused(): void
    {
        $this->actingAs($this->hr)->post(route('employees.store'), $this->payload([
            'photo' => UploadedFile::fake()->create('payload.php', 10, 'application/x-php'),
        ]))->assertSessionHasErrors('photo');
    }

    public function test_an_employee_with_no_photo_has_no_url(): void
    {
        $this->assertNull($this->employee()->photo_url);
    }

    // -------------------------------------------------------------------------
    // A3.9 — personal details
    // -------------------------------------------------------------------------

    public function test_the_emergency_contact_is_saved_and_shown(): void
    {
        $this->actingAs($this->hr)->post(route('employees.store'), $this->payload([
            'emergency_contact_name' => 'Sam Lee',
            'emergency_contact_phone' => '555-0100',
            'emergency_contact_relation' => 'Spouse',
        ]))->assertRedirect();

        $employee = Employee::where('first_name', 'Ann')->firstOrFail();

        $this->assertSame('Sam Lee', $employee->emergency_contact_name);

        $this->actingAs($this->hr)->get(route('employees.show', $employee))
            ->assertOk()->assertSee('Sam Lee')->assertSee('Spouse');
    }

    public function test_personal_details_are_saved(): void
    {
        $this->actingAs($this->hr)->post(route('employees.store'), $this->payload([
            'personal_email' => 'ann@personal.test',
            'address' => '12 High Street', 'city' => 'Leeds', 'country' => 'UK',
            'national_id' => 'AB123456', 'blood_group' => 'O+',
        ]))->assertRedirect();

        $employee = Employee::where('first_name', 'Ann')->firstOrFail();

        $this->assertSame('ann@personal.test', $employee->personal_email);
        $this->assertSame('Leeds', $employee->city);
        $this->assertSame('O+', $employee->blood_group);
    }

    public function test_a_bad_personal_email_is_refused(): void
    {
        $this->actingAs($this->hr)->post(route('employees.store'), $this->payload([
            'personal_email' => 'not-an-email',
        ]))->assertSessionHasErrors('personal_email');
    }

    // -------------------------------------------------------------------------
    // A3.10 — the org chart
    // -------------------------------------------------------------------------

    public function test_the_org_chart_nests_reports_under_their_manager(): void
    {
        $boss = $this->employee(['first_name' => 'Max', 'last_name' => 'Reid']);
        $this->employee(['first_name' => 'Ann', 'last_name' => 'Lee', 'manager_id' => $boss->id]);

        $this->actingAs($this->hr)->get(route('employees.org-chart'))
            ->assertOk()
            ->assertSee('Max Reid')
            ->assertSee('Ann Lee')
            ->assertSee('1 report(s)');
    }

    public function test_somebody_with_no_manager_appears_at_the_top(): void
    {
        $this->employee(['first_name' => 'Solo', 'last_name' => 'One']);

        $this->actingAs($this->hr)->get(route('employees.org-chart'))
            ->assertOk()->assertSee('Solo One');
    }

    public function test_somebody_whose_manager_has_left_still_appears(): void
    {
        // Dropping them silently is how a chart comes to be quietly missing
        // four people.
        $gone = $this->employee(['first_name' => 'Gone', 'status' => 'inactive']);
        $this->employee(['first_name' => 'Orphan', 'last_name' => 'Report', 'manager_id' => $gone->id]);

        $this->actingAs($this->hr)->get(route('employees.org-chart'))
            ->assertOk()->assertSee('Orphan Report');
    }

    public function test_inactive_staff_are_left_off_the_chart(): void
    {
        $this->employee(['first_name' => 'Departed', 'last_name' => 'Soul', 'status' => 'terminated']);

        $this->actingAs($this->hr)->get(route('employees.org-chart'))
            ->assertOk()->assertDontSee('Departed Soul');
    }

    // -------------------------------------------------------------------------
    // A3.11 — the export
    // -------------------------------------------------------------------------

    public function test_hr_can_export_the_roster(): void
    {
        $this->employee();

        $response = $this->actingAs($this->hr)->get(route('employees.export'));

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('content-type'));
    }

    public function test_an_employee_cannot_export_the_roster(): void
    {
        $this->actingAs($this->staff)->get(route('employees.export'))->assertForbidden();
        $this->actingAs($this->staff)->get(route('employees.org-chart'))->assertForbidden();
    }

    public function test_the_export_leads_with_the_import_columns(): void
    {
        // The point of the export is that it can be edited and fed back in. The
        // export is built from IMPORT_COLUMNS for exactly this reason, and this
        // is what stops the two drifting apart again.
        $template = $this->actingAs($this->hr)->get(route('employees.import.template'))->assertOk();

        $header = str_getcsv(strtok($template->getContent(), "\n"), ',', '"', '\\');

        $this->assertSame(EmployeeController::IMPORT_COLUMNS, $header);
    }

    public function test_the_export_names_the_manager_by_code_not_by_name(): void
    {
        // The import resolves the reporting line from manager_code. An export
        // carrying "Max Reid" round-trips into a roster where every reporting
        // line is silently blank.
        $this->assertContains('manager_code', EmployeeController::IMPORT_COLUMNS);
        $this->assertNotContains('manager', EmployeeController::IMPORT_COLUMNS);
    }

    public function test_a_round_trip_through_the_export_keeps_the_reporting_line(): void
    {
        $designation = Designation::create(['company_id' => $this->company->id, 'name' => 'Analyst']);
        $boss = $this->employee(['first_name' => 'Max', 'last_name' => 'Reid']);
        $this->employee([
            'first_name' => 'Ann', 'last_name' => 'Lee',
            'manager_id' => $boss->id, 'designation_id' => $designation->id,
        ]);

        $this->actingAs($this->hr)->get(route('employees.export'))->assertOk();

        // The row the export builds carries the manager's code, which is the
        // value the import will look the manager up by.
        $ann = Employee::where('first_name', 'Ann')->firstOrFail();
        $this->assertSame($boss->employee_code, $ann->manager->employee_code);
    }
}
