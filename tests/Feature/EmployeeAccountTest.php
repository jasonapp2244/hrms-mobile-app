<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Giving an employee a login.
 *
 * The gap this covers: an employee record and a sign-in account are separate
 * rows, and creating the former never created the latter. Anybody hired after
 * go-live could be entered into the system and then never sign in to the portal
 * or the phone app, because nothing short of tinker on the server could mint
 * them an account.
 */
class EmployeeAccountTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'America/New_York', 'currency' => 'USD',
        ]);
    }

    private function staff(string $role): User
    {
        $n = ++$this->seq;

        $user = User::create([
            'name'       => ucfirst($role) . " Person {$n}",
            'email'      => "{$role}{$n}@acme.test",
            'password'   => Hash::make('CorrectHorse1'),
            'company_id' => $this->company->id,
            'is_active'  => true,
        ]);

        $user->assignRole($role);

        return $user;
    }

    private function employee(array $attributes = []): Employee
    {
        $n = ++$this->seq;

        return Employee::create(array_merge([
            'company_id'    => $this->company->id,
            'user_id'       => null,
            'employee_code' => "E{$n}",
            'first_name'    => 'New',
            'last_name'     => "Starter {$n}",
            'status'        => 'active',
        ], $attributes));
    }

    public function test_hr_can_create_a_login_for_an_employee(): void
    {
        $employee = $this->employee(['email' => 'newstarter@acme.test']);

        $this->actingAs($this->staff('hr'))
            ->post(route('employees.account.store', $employee), [
                'email' => 'newstarter@acme.test',
                'role'  => 'employee',
            ])
            ->assertRedirect();

        $employee->refresh();
        $this->assertNotNull($employee->user_id);
        $this->assertTrue($employee->user->hasRole('employee'));
        $this->assertSame($this->company->id, $employee->user->company_id);
        $this->assertTrue($employee->user->is_active);
    }

    public function test_the_new_account_can_actually_sign_in(): void
    {
        // The whole point of the feature. Creating a row that cannot log in
        // would satisfy every other assertion here and still be useless.
        $employee = $this->employee();

        $this->actingAs($this->staff('hr'))
            ->post(route('employees.account.store', $employee), [
                'email'                 => 'walkin@acme.test',
                'role'                  => 'employee',
                'password'              => 'a-strong-password',
                'password_confirmation' => 'a-strong-password',
            ]);

        auth()->logout();
        session()->flush();

        $this->post(route('login'), [
            'email'    => 'walkin@acme.test',
            'password' => 'a-strong-password',
        ])->assertRedirect(route('employee.dashboard'));

        $this->assertAuthenticated();
    }

    public function test_a_generated_password_is_shown_once_and_works(): void
    {
        $employee = $this->employee();

        $response = $this->actingAs($this->staff('hr'))
            ->post(route('employees.account.store', $employee), [
                'email' => 'generated@acme.test',
                'role'  => 'employee',
            ]);

        $password = session('generated_password');
        $this->assertNotEmpty($password);

        // Flashed for the administrator to hand over, never stored readable.
        $employee->refresh();
        $this->assertNotSame($password, $employee->user->password);
        $this->assertTrue(Hash::check($password, $employee->user->password));
    }

    public function test_a_typed_password_is_not_echoed_back(): void
    {
        $employee = $this->employee();

        $this->actingAs($this->staff('hr'))
            ->post(route('employees.account.store', $employee), [
                'email'                 => 'typed@acme.test',
                'role'                  => 'employee',
                'password'              => 'chosen-by-the-admin',
                'password_confirmation' => 'chosen-by-the-admin',
            ]);

        $this->assertNull(session('generated_password'));
    }

    public function test_hr_cannot_grant_the_admin_role(): void
    {
        // The escalation this guards: HR holds manage-employees, so without the
        // role split they could mint an account, make it an admin and sign in
        // as one — a privilege escalation wearing an onboarding form.
        $employee = $this->employee();

        $this->actingAs($this->staff('hr'))
            ->post(route('employees.account.store', $employee), [
                'email' => 'escalation@acme.test',
                'role'  => 'admin',
            ])
            ->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'escalation@acme.test']);
        $this->assertNull($employee->fresh()->user_id);
    }

    public function test_hr_cannot_grant_the_hr_role_either(): void
    {
        $employee = $this->employee();

        $this->actingAs($this->staff('hr'))
            ->post(route('employees.account.store', $employee), [
                'email' => 'sideways@acme.test',
                'role'  => 'hr',
            ])
            ->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'sideways@acme.test']);
    }

    public function test_an_admin_can_grant_the_admin_role(): void
    {
        $employee = $this->employee();

        $this->actingAs($this->staff('admin'))
            ->post(route('employees.account.store', $employee), [
                'email' => 'deputy@acme.test',
                'role'  => 'admin',
            ]);

        $this->assertTrue($employee->fresh()->user->hasRole('admin'));
    }

    public function test_hr_cannot_demote_an_administrator(): void
    {
        // The mirror of the escalation above: if HR could not grant `admin` but
        // could take it away, they could quietly strip every administrator.
        $admin = $this->staff('admin');
        $employee = $this->employee(['user_id' => $admin->id]);

        $this->actingAs($this->staff('hr'))
            ->post(route('employees.account.role', $employee), ['role' => 'employee'])
            ->assertSessionHas('error');

        $this->assertTrue($admin->fresh()->hasRole('admin'));
    }

    public function test_an_employee_cannot_reach_any_of_it(): void
    {
        $employee = $this->employee();

        $this->actingAs($this->staff('employee'))
            ->post(route('employees.account.store', $employee), [
                'email' => 'self@acme.test',
                'role'  => 'admin',
            ])
            ->assertForbidden();
    }

    public function test_a_duplicate_sign_in_email_is_refused(): void
    {
        $taken = $this->staff('employee');
        $employee = $this->employee();

        $this->actingAs($this->staff('hr'))
            ->post(route('employees.account.store', $employee), [
                'email' => $taken->email,
                'role'  => 'employee',
            ])
            ->assertSessionHasErrors('email');
    }

    public function test_an_employee_who_already_has_a_login_does_not_get_a_second(): void
    {
        $existing = $this->staff('employee');
        $employee = $this->employee(['user_id' => $existing->id]);

        $this->actingAs($this->staff('hr'))
            ->post(route('employees.account.store', $employee), [
                'email' => 'second@acme.test',
                'role'  => 'employee',
            ])
            ->assertSessionHas('error');

        $this->assertSame($existing->id, $employee->fresh()->user_id);
        $this->assertDatabaseMissing('users', ['email' => 'second@acme.test']);
    }

    public function test_resetting_the_password_replaces_it(): void
    {
        $account = $this->staff('employee');
        $account->update(['password' => Hash::make('the-old-one')]);
        $employee = $this->employee(['user_id' => $account->id]);

        $this->actingAs($this->staff('hr'))
            ->post(route('employees.account.password', $employee));

        $this->assertFalse(Hash::check('the-old-one', $account->fresh()->password));
        $this->assertTrue(Hash::check(session('generated_password'), $account->fresh()->password));
    }

    public function test_disabling_a_login_stops_the_sign_in(): void
    {
        $account = $this->staff('employee');
        $account->update(['password' => Hash::make('still-known')]);
        $employee = $this->employee(['user_id' => $account->id]);

        $this->actingAs($this->staff('hr'))
            ->post(route('employees.account.toggle', $employee));

        $this->assertFalse($account->fresh()->is_active);

        // Built without authenticating: an actingAs earlier in the test would
        // still be signed in here and the assertion would prove nothing.
        auth()->logout();
        session()->flush();

        $this->post(route('login'), [
            'email'    => $account->email,
            'password' => 'still-known',
        ]);

        $this->assertGuest();
    }

    public function test_a_disabled_login_can_be_switched_back_on(): void
    {
        $account = $this->staff('employee');
        $account->update(['is_active' => false]);
        $employee = $this->employee(['user_id' => $account->id]);

        $this->actingAs($this->staff('hr'))
            ->post(route('employees.account.toggle', $employee));

        $this->assertTrue($account->fresh()->is_active);
    }

    public function test_nobody_can_disable_their_own_login(): void
    {
        $admin = $this->staff('admin');
        $employee = $this->employee(['user_id' => $admin->id]);

        $this->actingAs($admin)
            ->post(route('employees.account.toggle', $employee))
            ->assertSessionHas('error');

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_creating_an_account_is_written_to_the_activity_log(): void
    {
        $employee = $this->employee();

        $this->actingAs($this->staff('hr'))
            ->post(route('employees.account.store', $employee), [
                'email' => 'audited@acme.test',
                'role'  => 'employee',
            ]);

        $this->assertDatabaseHas('activity_logs', [
            'event'        => ActivityLog::ACCOUNT_CHANGED,
            'subject_type' => User::class,
            'subject_id'   => $employee->fresh()->user_id,
        ]);
    }

    public function test_the_employee_page_offers_the_form_when_there_is_no_login(): void
    {
        $employee = $this->employee();

        $this->actingAs($this->staff('hr'))
            ->get(route('employees.show', $employee))
            ->assertOk()
            ->assertSee('Create sign-in account');
    }

    public function test_hr_is_not_shown_the_roles_it_cannot_grant(): void
    {
        $employee = $this->employee();

        $response = $this->actingAs($this->staff('hr'))
            ->get(route('employees.show', $employee))
            ->assertOk();

        $response->assertSee('value="employee"', false);
        $response->assertDontSee('value="admin"', false);
    }
}
