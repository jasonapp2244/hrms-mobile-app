<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The API's page size is a real parameter now (`per_page`).
 *
 * `pageMeta()` has always returned `per_page`, which told every client there
 * was a page size worth knowing about while no endpoint read one from the
 * request — the number was real and the control it implied was not.
 *
 * What is pinned here is the *shape* of the answer rather than one endpoint's
 * numbers: an app that sends nothing keeps getting exactly what it got before,
 * an app that asks is answered, and an app that asks for the whole table is
 * answered with the ceiling instead of a refusal or a 50MB document.
 */
class PageSizeApiTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'UTC', 'currency' => 'USD',
        ]);

        $office = Office::create([
            'company_id' => $this->company->id, 'name' => 'HQ',
        ]);

        $department = Department::create([
            'company_id' => $this->company->id, 'name' => 'Ops',
        ]);

        $this->user = User::create([
            'name' => 'Ann Lee', 'email' => 'ann@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $this->user->assignRole('employee');

        Employee::create([
            'company_id' => $this->company->id, 'department_id' => $department->id,
            'office_id' => $office->id, 'user_id' => $this->user->id,
            'employee_code' => 'E1', 'first_name' => 'Ann', 'last_name' => 'Lee',
            'status' => 'active',
        ]);

        // Enough colleagues that a small page is genuinely smaller than the
        // result set, so asking for one proves something.
        for ($i = 2; $i <= 12; $i++) {
            Employee::create([
                'company_id' => $this->company->id, 'department_id' => $department->id,
                'office_id' => $office->id,
                'employee_code' => 'E' . $i, 'first_name' => 'Staff', 'last_name' => 'No' . $i,
                'status' => 'active',
            ]);
        }

        Sanctum::actingAs($this->user);
    }

    public function test_an_app_that_asks_for_nothing_gets_what_it_always_got(): void
    {
        // 30 is the size the directory served before `per_page` existed. The
        // point of pinning it is that adding a control did not quietly change
        // the answer for every app already in the field.
        $this->getJson('/api/v1/directory')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 30);
    }

    public function test_a_smaller_page_is_honoured(): void
    {
        $response = $this->getJson('/api/v1/directory?per_page=5')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.current_page', 1);

        $this->assertCount(5, $response->json('people'));
    }

    public function test_a_page_larger_than_the_ceiling_is_clamped_not_refused(): void
    {
        // Not a 422. Somebody reading their own colleagues should not be shown
        // an error because their client asked for too much — and the response
        // says what it used, so a client asking for 100000 can see it got 100
        // and stop asking.
        $this->getJson('/api/v1/directory?per_page=100000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_nonsense_and_zero_fall_back_to_the_default(): void
    {
        foreach (['0', '-5', 'all', ''] as $value) {
            $this->getJson('/api/v1/directory?per_page=' . $value)
                ->assertOk()
                ->assertJsonPath('meta.per_page', 30);
        }
    }

    public function test_the_ceiling_comes_from_config_rather_than_the_controller(): void
    {
        config(['pagination.api.max' => 7]);

        $this->getJson('/api/v1/directory?per_page=50')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 7);
    }

    public function test_an_endpoint_with_no_listed_default_uses_the_api_default(): void
    {
        config(['pagination.api.default' => 4]);

        // The leave list names no entry in `pagination.api.lists`, so it takes
        // the shared default. This is the case that breaks if somebody later
        // makes the lookup required rather than a fallback.
        $this->getJson('/api/v1/leave/requests')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 4);
    }
}
