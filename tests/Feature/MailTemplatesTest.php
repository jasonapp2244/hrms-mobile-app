<?php

namespace Tests\Feature;

use App\Mail\ScheduledReportMail;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\ReportSubscription;
use App\Models\User;
use App\Notifications\EmployeeInvite;
use App\Notifications\LeaveRequestDecided;
use App\Notifications\LeaveRequestSubmitted;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * What the mails actually say, rendered — not just that one was sent.
 *
 * Every other mail test fakes the mailer and checks a mail was queued, which
 * is how a scheduled report that could never be queued for real, and a leave
 * reason that turned into a working link, both got through a green suite.
 */
class MailTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $staff;
    private LeaveRequest $leave;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create(['name' => 'Smith & Sons', 'timezone' => 'UTC', 'currency' => 'USD']);

        $this->staff = User::create([
            'name' => "Ann O'Brien", 'email' => 'ann@acme.test', 'password' => Hash::make('password'),
            'company_id' => $this->company->id, 'is_active' => true,
        ]);
        $this->staff->assignRole('employee');

        $employee = Employee::create([
            'company_id' => $this->company->id, 'user_id' => $this->staff->id, 'employee_code' => 'E1',
            'first_name' => 'Ann', 'last_name' => "O'Brien", 'status' => 'active',
        ]);

        $type = LeaveType::create([
            'company_id' => $this->company->id, 'name' => 'Annual', 'code' => 'AL', 'days_per_year' => 20,
        ]);

        $this->leave = LeaveRequest::create([
            'company_id' => $this->company->id, 'employee_id' => $employee->id, 'leave_type_id' => $type->id,
            'start_date' => '2026-10-01', 'end_date' => '2026-10-02', 'days' => 2,
            'reason' => '[Click here](https://evil.example/steal)', 'status' => 'pending',
            'decision_note' => '[Reset your password](https://evil.example/reset)',
        ]);
    }

    // ================= the scheduled report =================

    private function reportMail(string $bytes): ScheduledReportMail
    {
        $subscription = ReportSubscription::create([
            'company_id' => $this->company->id, 'report_type' => 'late', 'frequency' => 'weekly',
            'format' => 'pdf', 'recipients' => ['finance@acme.test'], 'is_active' => true,
        ]);

        return new ScheduledReportMail(
            subscription: $subscription,
            reportTitle: 'Late Arrivals',
            periodFrom: '2026-09-28',
            periodTo: '2026-10-04',
            tiles: [['label' => 'Late arrivals', 'value' => 3]],
            filename: 'late.pdf',
            fileContents: $bytes,
            mimeType: 'application/pdf',
        );
    }

    public function test_a_scheduled_report_survives_the_real_queue(): void
    {
        // A PDF is not UTF-8. The database queue stores jobs as JSON, and raw
        // bytes in the mailable made every queued report throw at the push.
        $bytes = "%PDF-1.7\n\xE2\xE3\xCF\xD3\xFF\x00\x81binary";
        config(['queue.default' => 'database']);

        Mail::to('finance@acme.test')->queue($this->reportMail($bytes));
        $this->assertSame(1, DB::table('jobs')->count());

        Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);

        $this->assertSame(0, DB::table('failed_jobs')->count());
        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $attachments = $sent[0]->getOriginalMessage()->getAttachments();
        $this->assertCount(1, $attachments);
        $this->assertSame($bytes, $attachments[0]->getBody(), 'The file arrives byte for byte.');
    }

    public function test_the_scheduled_report_is_written_in_the_mail_language(): void
    {
        App::setLocale('es');
        $html = (string) $this->reportMail('x')->render();

        $this->assertStringContainsString('El informe completo va adjunto', $html);
        $this->assertStringContainsString('cada lunes', $html);
        $this->assertStringNotContainsString('The full report is attached', $html);
    }

    // ================= what people typed =================

    public function test_a_leave_reason_cannot_become_a_link_in_the_managers_mail(): void
    {
        $html = (string) (new LeaveRequestSubmitted($this->leave))->toMail($this->staff)->render();

        $this->assertStringContainsString('Click here', $html, 'The reason is still shown…');
        $this->assertStringNotContainsString('href="https://evil.example', $html, '…but never as a link.');
    }

    public function test_a_decision_note_cannot_become_a_link_in_the_employees_mail(): void
    {
        $html = (string) (new LeaveRequestDecided($this->leave, 'rejected'))->toMail($this->staff)->render();

        $this->assertStringContainsString('Reset your password', $html);
        $this->assertStringNotContainsString('href="https://evil.example', $html);
    }

    // ================= language =================

    public function test_a_spanish_leave_mail_has_no_english_left_in_it(): void
    {
        App::setLocale('es');
        $html = (string) (new LeaveRequestDecided($this->leave, 'approved'))->toMail($this->staff)->render();

        $this->assertStringContainsString('1 oct. al 2 oct. 2026', $html);
        $this->assertStringContainsString('Todos los derechos reservados.', $html);
        $this->assertStringNotContainsString(' to ', strip_tags($html));
        $this->assertStringNotContainsString('All rights reserved', $html);
    }

    public function test_the_dates_are_said_once(): void
    {
        $html = (string) (new LeaveRequestDecided($this->leave, 'approved'))->toMail($this->staff)->render();

        $this->assertSame(1, substr_count($html, '1 Oct to 2 Oct 2026'));
    }

    // ================= the welcome mail =================

    public function test_the_plain_text_invite_does_not_show_html_entities(): void
    {
        $this->staff->notify(new EmployeeInvite('abc123'));

        $email = app('mailer')->getSymfonyTransport()->messages()[0]->getOriginalMessage();
        $text = (string) $email->getTextBody();

        $this->assertStringContainsString("Welcome, Ann O'Brien!", $text);
        $this->assertStringContainsString('Smith & Sons uses', $text);
        $this->assertStringNotContainsString('&#039;', $text);
        $this->assertStringNotContainsString('&amp;', $text);
    }

    public function test_the_html_invite_is_signed_like_every_other_mail(): void
    {
        $this->staff->notify(new EmployeeInvite('abc123'));

        $html = app('mailer')->getSymfonyTransport()->messages()[0]->getOriginalMessage()->getHtmlBody();

        $this->assertStringContainsString('color-scheme', $html);
        $this->assertStringContainsString('Regards,', $html);
        $this->assertStringContainsString(config('app.name') . '. All rights reserved.', $html);
    }

    public function test_the_html_invite_renders_outside_a_send(): void
    {
        // A preview has no $message to embed the QR into; it used to fatal.
        $html = view('emails.employee-invite', [
            'app' => 'KEMP', 'name' => 'Ann', 'email' => 'ann@acme.test', 'company' => 'Acme',
            'qrPng' => 'png-bytes', 'days' => 7, 'androidUrl' => null, 'iosUrl' => null,
            'resetUrl' => 'https://example.test/reset',
        ])->render();

        $this->assertStringContainsString('Welcome, Ann!', $html);
    }
}
