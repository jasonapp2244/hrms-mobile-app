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

    // ================= linking an existing login =================

    public function test_an_admin_can_link_an_existing_hr_login_and_the_app_then_works(): void
    {
        $hrLogin = $this->staff('hr');
        $employee = $this->employee();

        \Laravel\Sanctum\Sanctum::actingAs($hrLogin);
        $this->getJson('/api/v1/attendance/history')->assertForbidden();

        $this->actingAs($this->staff('admin'))
            ->post(route('employees.account.link', $employee), ['user_id' => $hrLogin->id])
            ->assertSessionHas('success');

        $this->assertSame($hrLogin->id, $employee->fresh()->user_id);
        $this->assertDatabaseHas('activity_logs', [
            'event'      => ActivityLog::ACCOUNT_CHANGED,
            'subject_id' => $hrLogin->id,
        ]);

        \Laravel\Sanctum\Sanctum::actingAs($hrLogin->fresh());
        $this->getJson('/api/v1/attendance/history')->assertOk();
    }

    public function test_hr_can_link_an_employee_login(): void
    {
        $login = $this->staff('employee');
        $employee = $this->employee();

        $this->actingAs($this->staff('hr'))
            ->post(route('employees.account.link', $employee), ['user_id' => $login->id])
            ->assertSessionHas('success');

        $this->assertSame($login->id, $employee->fresh()->user_id);
    }

    public function test_hr_cannot_link_an_admin_or_hr_login(): void
    {
        foreach (['admin', 'hr'] as $role) {
            $login = $this->staff($role);
            $employee = $this->employee();

            $this->actingAs($this->staff('hr'))
                ->post(route('employees.account.link', $employee), ['user_id' => $login->id])
                ->assertSessionHas('error');

            $this->assertNull($employee->fresh()->user_id);
        }
    }

    public function test_a_login_already_behind_one_employee_cannot_be_linked_to_another(): void
    {
        $login = $this->staff('employee');
        $this->employee(['user_id' => $login->id]);
        $second = $this->employee();

        $this->actingAs($this->staff('admin'))
            ->post(route('employees.account.link', $second), ['user_id' => $login->id])
            ->assertSessionHas('error');

        $this->assertNull($second->fresh()->user_id);
    }

    public function test_an_employee_who_already_has_a_login_cannot_be_relinked(): void
    {
        $existing = $this->staff('employee');
        $employee = $this->employee(['user_id' => $existing->id]);

        $this->actingAs($this->staff('admin'))
            ->post(route('employees.account.link', $employee), ['user_id' => $this->staff('employee')->id])
            ->assertSessionHas('error');

        $this->assertSame($existing->id, $employee->fresh()->user_id);
    }

    public function test_a_login_from_another_company_cannot_be_linked(): void
    {
        $other = Company::create(['name' => 'Other', 'timezone' => 'UTC', 'currency' => 'USD']);
        $login = $this->staff('employee');
        $login->update(['company_id' => $other->id]);
        $employee = $this->employee();

        $this->actingAs($this->staff('admin'))
            ->post(route('employees.account.link', $employee), ['user_id' => $login->id])
            ->assertSessionHas('error');

        $this->assertNull($employee->fresh()->user_id);
    }

    public function test_an_employee_of_another_company_cannot_be_touched(): void
    {
        $other = Company::create(['name' => 'Other', 'timezone' => 'UTC', 'currency' => 'USD']);
        $foreign = $this->employee(['company_id' => $other->id]);

        $this->actingAs($this->staff('admin'))
            ->post(route('employees.account.link', $foreign), ['user_id' => $this->staff('employee')->id])
            ->assertForbidden();

        $this->actingAs($this->staff('admin'))
            ->post(route('employees.account.store', $foreign), ['email' => 'x@acme.test', 'role' => 'employee'])
            ->assertForbidden();
    }

    public function test_an_employee_cannot_link_anything(): void
    {
        $employee = $this->employee();

        $this->actingAs($this->staff('employee'))
            ->post(route('employees.account.link', $employee), ['user_id' => $this->staff('hr')->id])
            ->assertForbidden();

        $this->assertNull($employee->fresh()->user_id);
    }

    public function test_the_employee_page_offers_only_linkable_logins(): void
    {
        $free = $this->staff('employee');
        $taken = $this->staff('employee');
        $this->employee(['user_id' => $taken->id]);
        $adminLogin = $this->staff('admin');
        $employee = $this->employee();

        $response = $this->actingAs($this->staff('hr'))
            ->get(route('employees.show', $employee))
            ->assertOk()
            ->assertSee('Or link an existing login');

        $response->assertSee($free->email);
        $response->assertDontSee($taken->email);
        $response->assertDontSee($adminLogin->email);
    }

    // ================= emp:link-accounts =================

    public function test_the_command_changes_nothing_without_apply(): void
    {
        $login = $this->staff('hr');
        $employee = $this->employee(['email' => $login->email]);

        $this->artisan('emp:link-accounts')->assertSuccessful();

        $this->assertNull($employee->fresh()->user_id);
    }

    public function test_the_command_links_an_exact_email_match_in_the_same_company(): void
    {
        $login = $this->staff('hr');
        $employee = $this->employee(['email' => '  ' . strtoupper($login->email) . ' ']);

        $this->artisan('emp:link-accounts', ['--apply' => true])->assertSuccessful();

        $this->assertSame($login->id, $employee->fresh()->user_id);
    }

    public function test_the_command_skips_ambiguous_and_cross_company_matches(): void
    {
        $shared = $this->staff('hr');
        $a = $this->employee(['email' => $shared->email]);
        // employees.email is unique, so the only way two records can claim one
        // login is a spelling that differs by case alone.
        $b = $this->employee(['email' => strtoupper($shared->email)]);

        $other = Company::create(['name' => 'Other', 'timezone' => 'UTC', 'currency' => 'USD']);
        $abroad = $this->staff('employee');
        $foreign = $this->employee(['email' => $abroad->email, 'company_id' => $other->id]);

        $this->artisan('emp:link-accounts', ['--apply' => true])->assertSuccessful();

        $this->assertNull($a->fresh()->user_id);
        $this->assertNull($b->fresh()->user_id);
        $this->assertNull($foreign->fresh()->user_id);
    }
    public function test_hr_cannot_reset_or_switch_off_an_admin_login_by_posting_directly(): void
    {
        // The page hides both buttons on an HR or admin login. The routes did
        // not, so HR could set an administrator's password and sign in as one.
        $admin = $this->staff('admin');
        $employee = $this->employee(['user_id' => $admin->id]);
        $hash = $admin->password;

        $this->actingAs($this->staff('hr'))
            ->post(route('employees.account.password', $employee))
            ->assertSessionHas('error');
        $this->assertSame($hash, $admin->fresh()->password);

        $this->actingAs($this->staff('hr'))
            ->post(route('employees.account.toggle', $employee))
            ->assertSessionHas('error');
        $this->assertTrue((bool) $admin->fresh()->is_active);

        // An administrator still can.
        $this->actingAs($this->staff('admin'))
            ->post(route('employees.account.password', $employee))
            ->assertSessionHas('success');
        $this->assertNotSame($hash, $admin->fresh()->password);
    }
}
