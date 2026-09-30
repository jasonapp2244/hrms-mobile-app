<?php

namespace Tests\Feature\Api;

use App\Models\ActivationCode;
use App\Models\Company;
use App\Models\Employee;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Notifications\EmployeeInvite;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The welcome email and its one-time sign-in code (A4.21).
 *
 * The code signs a phone in, once, and does nothing else. These pin that it is
 * sent when HR makes the login, that it works exactly once, that a resend kills
 * the old one, and that it respects everything a password sign-in does.
 */
class ActivationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create(['name' => 'Acme', 'timezone' => 'UTC', 'currency' => 'USD']);

        $this->hr = User::create([
            'name' => 'Hana', 'email' => 'hr@acme.test', 'password' => Hash::make('password'),
            'company_id' => $this->company->id, 'is_active' => true,
        ]);
        $this->hr->assignRole('hr');
    }

    private function employee(): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id, 'employee_code' => 'E1',
            'first_name' => 'Ann', 'last_name' => 'Lee', 'status' => 'active',
        ]);
    }

    /** A login made through the real screen; returns the code that was emailed. */
    private function createLogin(Employee $employee): string
    {
        Notification::fake();

        $this->actingAs($this->hr)
            ->post(route('employees.account.store', $employee), ['email' => 'ann@acme.test', 'role' => 'employee'])
            ->assertRedirect();

        $code = null;
        Notification::assertSentTo(
            User::where('email', 'ann@acme.test')->sole(),
            EmployeeInvite::class,
            function (EmployeeInvite $n) use (&$code) {
                $code = $n->code;

                return true;
            },
        );

        return $code;
    }

    private function activate(string $code, array $extra = [])
    {
        return $this->postJson('/api/v1/auth/activate', ['code' => $code, 'device_name' => 'Ann\'s phone'] + $extra);
    }

    public function test_creating_a_login_emails_a_sign_in_code(): void
    {
        $code = $this->createLogin($this->employee());

        $this->assertSame(40, strlen($code));
        $this->assertSame(1, ActivationCode::count());
        // Stored as a hash only.
        $this->assertNotSame($code, ActivationCode::sole()->code_hash);
    }

    public function test_the_code_signs_the_phone_in_once(): void
    {
        $code = $this->createLogin($this->employee());

        $this->activate(ActivationCode::PREFIX . $code)
            ->assertOk()
            ->assertJsonPath('user.email', 'ann@acme.test')
            ->assertJsonStructure(['token']);

        $this->activate(ActivationCode::PREFIX . $code)
            ->assertStatus(422)
            ->assertJsonPath('error', 'activation_expired');
    }

    public function test_a_made_up_code_is_invalid(): void
    {
        $this->activate('KEMP1-ACT:nothing-like-this')->assertStatus(422)->assertJsonPath('error', 'activation_invalid');
    }

    public function test_the_code_expires_after_a_week(): void
    {
        $code = $this->createLogin($this->employee());

        $this->travel(ActivationCode::VALID_DAYS + 1)->days();

        $this->activate($code)->assertStatus(422)->assertJsonPath('error', 'activation_expired');
    }

    public function test_a_resent_email_kills_the_old_code(): void
    {
        $employee = $this->employee();
        $old = $this->createLogin($employee);

        $this->actingAs($this->hr)->post(route('employees.account.invite', $employee))->assertSessionHas('success');

        $new = null;
        Notification::assertSentTo(User::where('email', 'ann@acme.test')->sole(), EmployeeInvite::class,
            function (EmployeeInvite $n) use (&$new, $old) {
                if ($n->code !== $old) {
                    $new = $n->code;
                }

                return true;
            });

        $this->activate($old)->assertStatus(422)->assertJsonPath('error', 'activation_expired');
        $this->activate($new)->assertOk();
    }

    public function test_a_disabled_account_cannot_be_signed_in_by_code(): void
    {
        $code = $this->createLogin($this->employee());
        User::where('email', 'ann@acme.test')->update(['is_active' => false]);

        $this->activate($code)->assertStatus(403)->assertJsonPath('error', 'account_disabled');
    }

    public function test_device_binding_still_applies(): void
    {
        $code = $this->createLogin($this->employee());
        $this->company->update(['settings' => ['enforce_device_binding' => true]]);

        TrustedDevice::create([
            'company_id' => $this->company->id, 'user_id' => User::where('email', 'ann@acme.test')->value('id'),
            'device_id' => 'first-phone', 'trusted_at' => now(),
        ]);

        $this->activate($code, ['device_id' => 'second-phone'])
            ->assertStatus(403)->assertJsonPath('error', 'device_not_trusted');

        // Not spent by the refusal: the right phone can still use it.
        $this->activate($code, ['device_id' => 'first-phone'])->assertOk();
    }

    public function test_hr_cannot_send_a_code_for_an_admin_login(): void
    {
        $admin = User::create([
            'name' => 'Boss', 'email' => 'boss@acme.test', 'password' => Hash::make('password'),
            'company_id' => $this->company->id, 'is_active' => true,
        ]);
        $admin->assignRole('admin');
        $employee = $this->employee();
        $employee->update(['user_id' => $admin->id]);

        Notification::fake();

        $this->actingAs($this->hr)->post(route('employees.account.invite', $employee))->assertSessionHas('error');

        Notification::assertNothingSent();
        $this->assertSame(0, ActivationCode::count());
    }

    public function test_the_email_carries_the_code_as_an_embedded_picture(): void
    {
        $user = User::create([
            'name' => 'Ann', 'email' => 'ann@acme.test', 'password' => Hash::make('password'),
            'company_id' => $this->company->id, 'is_active' => true,
        ]);

        // The array mailer and the sync queue send it for real, so the HTML is
        // what a mail client would receive.
        $user->notify(new EmployeeInvite('abc123'));

        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);

        $email = $sent[0]->getOriginalMessage();
        $this->assertStringContainsString('cid:', $email->getHtmlBody());
        $this->assertStringContainsString('Sign in with QR', $email->getHtmlBody());
        $this->assertStringNotContainsString('abc123', $email->getHtmlBody(), 'The code is in the picture, never in the text.');
        $this->assertStringNotContainsString('abc123', (string) $email->getTextBody());
        $this->assertCount(1, $email->getAttachments(), 'Embedded once, not once per part.');
    }
}
