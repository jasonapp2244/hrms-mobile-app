<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The staff directory (B3.8).
 *
 * The only endpoint that answers about other people, so most of what is under
 * test here is what it refuses to say. The contact policy is off by default and
 * that default is asserted directly: it is the kind of thing that gets flipped
 * for convenience during a demo and never flipped back.
 */
class DirectoryApiTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Office $office;
    protected Department $department;
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
            'company_id' => $this->company->id, 'name' => 'Head Office',
        ]);

        $this->department = Department::create([
            'company_id' => $this->company->id, 'name' => 'Ops',
        ]);

        $this->user = User::create([
            'name' => 'Ann Lee', 'email' => 'ann@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $this->user->assignRole('employee');

        $this->employee = Employee::create([
            'company_id' => $this->company->id, 'department_id' => $this->department->id,
            'office_id' => $this->office->id, 'user_id' => $this->user->id,
            'employee_code' => 'E1', 'first_name' => 'Ann', 'last_name' => 'Lee',
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->user);
    }

    /** Counted, not randomised — a random code is a flake waiting for a rerun. */
    protected int $codes = 1;

    protected function colleague(array $overrides = []): Employee
    {
        return Employee::create(array_merge([
            'company_id'    => $this->company->id,
            'office_id'     => $this->office->id,
            'department_id' => $this->department->id,
            'employee_code' => 'E' . (100 + $this->codes++),
            'first_name'    => 'Bo',
            'last_name'     => 'Ray',
            'status'        => 'active',
        ], $overrides));
    }

    protected function allowContactDetails(): void
    {
        $this->company->update(['settings' => array_merge($this->company->settings ?? [], [
            'directory_show_contact_details' => true,
        ])]);
    }

    // ================= who is listed =================

    public function test_colleagues_are_listed_with_where_to_find_them(): void
    {
        $designation = Designation::create([
            'company_id' => $this->company->id, 'name' => 'Cleaner',
        ]);

        $this->colleague(['designation_id' => $designation->id, 'work_mode' => 'office']);

        $this->getJson('/api/v1/directory')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(2, 'people')
            ->assertJsonPath('people.1.full_name', 'Bo Ray')
            ->assertJsonPath('people.1.designation', 'Cleaner')
            ->assertJsonPath('people.1.department', 'Ops')
            ->assertJsonPath('people.1.office', 'Head Office')
            ->assertJsonPath('people.1.work_mode', 'office');
    }

    public function test_the_caller_appears_in_their_own_directory(): void
    {
        // Leaving yourself out reads as a bug to the person looking, and a
        // directory that disagrees with itself per viewer is worse than one row
        // of redundancy.
        $this->getJson('/api/v1/directory')
            ->assertOk()
            ->assertJsonPath('people.0.full_name', 'Ann Lee');
    }

    public function test_another_companys_staff_are_never_listed(): void
    {
        $other = Company::create(['name' => 'Rival', 'timezone' => 'UTC', 'currency' => 'USD']);
        $otherOffice = Office::create(['company_id' => $other->id, 'name' => 'Elsewhere']);

        Employee::create([
            'company_id' => $other->id, 'office_id' => $otherOffice->id,
            'employee_code' => 'X1', 'first_name' => 'Not', 'last_name' => 'Ours',
            'status' => 'active',
        ]);

        $this->getJson('/api/v1/directory')
            ->assertOk()
            ->assertJsonCount(1, 'people')
            ->assertJsonMissing(['full_name' => 'Not Ours']);
    }

    public function test_leavers_are_not_listed(): void
    {
        $this->colleague(['first_name' => 'Gone', 'last_name' => 'Away', 'status' => 'terminated']);

        $this->getJson('/api/v1/directory')
            ->assertOk()
            ->assertJsonCount(1, 'people')
            ->assertJsonMissing(['full_name' => 'Gone Away']);
    }

    public function test_the_list_is_ordered_by_name(): void
    {
        $this->colleague(['first_name' => 'Zed', 'last_name' => 'Ray']);
        $this->colleague(['first_name' => 'Abe', 'last_name' => 'Ray']);

        $names = collect($this->getJson('/api/v1/directory')->json('people'))->pluck('full_name');

        $this->assertSame(['Abe Ray', 'Ann Lee', 'Zed Ray'], $names->all());
    }

    public function test_the_directory_needs_a_token(): void
    {
        app('auth')->forgetGuards();

        $this->getJson('/api/v1/directory')->assertStatus(401);
    }

    public function test_an_account_with_no_employee_record_cannot_browse_it(): void
    {
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/directory')->assertStatus(403);
    }

    // ================= contact details =================

    public function test_contact_details_are_hidden_by_default(): void
    {
        // The default itself is the assertion. `employees.phone` is the only
        // phone column on the record, and where staff have no desk line it
        // holds a personal mobile.
        $this->colleague(['email' => 'bo@acme.test', 'phone' => '+15550134']);

        $response = $this->getJson('/api/v1/directory')->assertOk();

        $response->assertJsonPath('shows_contact_details', false);
        $this->assertArrayNotHasKey('email', $response->json('people.1'));
        $this->assertArrayNotHasKey('phone', $response->json('people.1'));
        $response->assertJsonMissing(['phone' => '+15550134']);
    }

    public function test_contact_details_appear_once_the_company_switches_them_on(): void
    {
        $this->colleague(['email' => 'bo@acme.test', 'phone' => '+15550134']);
        $this->allowContactDetails();

        $this->getJson('/api/v1/directory')
            ->assertOk()
            ->assertJsonPath('shows_contact_details', true)
            ->assertJsonPath('people.1.email', 'bo@acme.test')
            ->assertJsonPath('people.1.phone', '+15550134');
    }

    public function test_the_flag_is_stated_so_the_app_can_tell_off_from_absent(): void
    {
        // Switched on, but this colleague has nothing on file. The app must be
        // able to tell that apart from the company withholding it, or it draws
        // a call button that does nothing.
        $this->colleague(['email' => null, 'phone' => null]);
        $this->allowContactDetails();

        $this->getJson('/api/v1/directory')
            ->assertOk()
            ->assertJsonPath('shows_contact_details', true)
            ->assertJsonPath('people.1.phone', null);
    }

    // ================= what it will not say =================

    public function test_hr_grade_pii_is_never_returned(): void
    {
        $this->colleague([
            'date_of_birth'          => '1990-05-02',
            'address'                => '14 Privet Drive',
            'national_id'            => 'NI-9931-X',
            'blood_group'            => 'O+',
            'personal_email'         => 'bo.private@gmail.test',
            'emergency_contact_name' => 'Someone Close',
            'emergency_contact_phone' => '+15550999',
            'hire_date'              => '2023-01-09',
        ]);

        $this->allowContactDetails();

        $body = $this->getJson('/api/v1/directory')->assertOk()->getContent();

        // Asserted on the raw body rather than key by key: a future payload
        // could nest any of these under a new key and still leak it.
        foreach ([
            '1990-05-02', '14 Privet Drive', 'NI-9931-X', 'O+',
            'bo.private@gmail.test', 'Someone Close', '+15550999', '2023-01-09',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_the_reporting_line_is_not_exposed(): void
    {
        $boss = $this->colleague(['first_name' => 'Mo', 'last_name' => 'Chief']);
        $this->colleague(['first_name' => 'Bo', 'last_name' => 'Ray', 'manager_id' => $boss->id]);

        $person = collect($this->getJson('/api/v1/directory')->json('people'))
            ->firstWhere('full_name', 'Bo Ray');

        $this->assertArrayNotHasKey('manager', $person);
        $this->assertArrayNotHasKey('manager_id', $person);
    }

    // ================= searching =================

    public function test_it_can_be_searched_by_name(): void
    {
        $this->colleague(['first_name' => 'Zed', 'last_name' => 'Ray']);

        $this->getJson('/api/v1/directory?q=Zed')
            ->assertOk()
            ->assertJsonCount(1, 'people')
            ->assertJsonPath('people.0.full_name', 'Zed Ray');
    }

    public function test_it_can_be_searched_by_employee_code(): void
    {
        $this->colleague(['employee_code' => 'CODE-7', 'first_name' => 'Cy', 'last_name' => 'Pher']);

        $this->getJson('/api/v1/directory?q=CODE-7')
            ->assertOk()
            ->assertJsonCount(1, 'people')
            ->assertJsonPath('people.0.full_name', 'Cy Pher');
    }

    public function test_it_can_be_narrowed_to_a_department(): void
    {
        $other = Department::create(['company_id' => $this->company->id, 'name' => 'Admin']);
        $this->colleague(['first_name' => 'Des', 'last_name' => 'Kane', 'department_id' => $other->id]);

        $this->getJson("/api/v1/directory?department_id={$other->id}")
            ->assertOk()
            ->assertJsonCount(1, 'people')
            ->assertJsonPath('people.0.full_name', 'Des Kane');
    }

    public function test_a_department_from_another_company_narrows_to_nothing(): void
    {
        // Filtered, not scoped: the company clause still stands in front of it,
        // so a foreign id matches nobody rather than reaching past it.
        $other = Company::create(['name' => 'Rival', 'timezone' => 'UTC', 'currency' => 'USD']);
        $theirDepartment = Department::create(['company_id' => $other->id, 'name' => 'Theirs']);

        $this->getJson("/api/v1/directory?department_id={$theirDepartment->id}")
            ->assertOk()
            ->assertJsonCount(0, 'people');
    }
}
