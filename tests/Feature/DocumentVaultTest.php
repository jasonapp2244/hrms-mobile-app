<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Office;
use App\Models\User;
use App\Notifications\DocumentExpiring;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A3.8 — the document vault and its expiry alerts.
 *
 * Two things are worth more than the CRUD. The files must not be reachable
 * without a session — these are passports and contracts. And the chasing has to
 * happen once per document rather than every night, because an alert that
 * repeats is an alert people filter away.
 */
class DocumentVaultTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Employee $employee;
    protected User $hr;
    protected User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'UTC', 'currency' => 'USD',
        ]);
        $office = Office::create(['company_id' => $this->company->id, 'name' => 'Head Office']);

        $this->employee = Employee::create([
            'company_id' => $this->company->id, 'office_id' => $office->id,
            'employee_code' => 'E1', 'first_name' => 'Ann', 'last_name' => 'Lee',
            'status' => 'active',
        ]);

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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function upload(array $overrides = [])
    {
        return $this->actingAs($this->hr)->post(
            route('employees.documents.store', $this->employee),
            array_merge([
                'type'  => 'contract',
                'title' => 'Employment contract',
                'file'  => UploadedFile::fake()->create('contract.pdf', 120, 'application/pdf'),
            ], $overrides),
        );
    }

    private function document(array $attributes = []): EmployeeDocument
    {
        return EmployeeDocument::create(array_merge([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'type' => 'right_to_work', 'title' => 'Work permit',
            'path' => 'employee-documents/1/permit.pdf', 'original_name' => 'permit.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 1024,
        ], $attributes));
    }

    // -------------------------------------------------------------------------
    // Filing
    // -------------------------------------------------------------------------

    public function test_a_document_can_be_uploaded(): void
    {
        $this->upload()->assertRedirect();

        $doc = EmployeeDocument::firstOrFail();

        $this->assertSame('Employment contract', $doc->title);
        $this->assertSame('contract.pdf', $doc->original_name);
        $this->assertSame($this->hr->id, $doc->uploaded_by_user_id);
        Storage::disk('local')->assertExists($doc->path);
    }

    public function test_the_stored_name_is_not_the_uploaded_name(): void
    {
        // Two people uploading "passport.pdf" must not overwrite each other.
        $this->upload();

        $doc = EmployeeDocument::firstOrFail();

        $this->assertStringNotContainsString('contract.pdf', $doc->path);
    }

    public function test_an_expiry_before_the_issue_date_is_refused(): void
    {
        $this->upload([
            'issued_on' => '2026-06-01',
            'expires_on' => '2026-01-01',
        ])->assertSessionHasErrors('expires_on');
    }

    public function test_an_executable_is_refused(): void
    {
        $this->upload([
            'file' => UploadedFile::fake()->create('payload.exe', 10, 'application/octet-stream'),
        ])->assertSessionHasErrors('file');
    }

    public function test_deleting_a_document_removes_the_file(): void
    {
        $this->upload();
        $doc = EmployeeDocument::firstOrFail();

        $this->actingAs($this->hr)
            ->delete(route('employees.documents.destroy', [$this->employee, $doc]))
            ->assertRedirect();

        // An orphaned passport scan left on disk is the worst thing to leave
        // behind after a deletion.
        Storage::disk('local')->assertMissing($doc->path);
        $this->assertDatabaseCount('employee_documents', 0);
    }

    public function test_deleting_an_employee_takes_their_documents_and_files(): void
    {
        $this->upload();
        $doc = EmployeeDocument::firstOrFail();

        $this->actingAs($this->hr)->delete(route('employees.destroy', $this->employee));

        Storage::disk('local')->assertMissing($doc->path);
    }

    // -------------------------------------------------------------------------
    // Who can reach them
    // -------------------------------------------------------------------------

    public function test_hr_can_download_a_document(): void
    {
        $this->upload();
        $doc = EmployeeDocument::firstOrFail();

        $this->actingAs($this->hr)
            ->get(route('employees.documents.download', [$this->employee, $doc]))
            ->assertOk();
    }

    public function test_an_employee_cannot_reach_the_vault_at_all(): void
    {
        $this->upload();
        $doc = EmployeeDocument::firstOrFail();

        $this->actingAs($this->staff)
            ->get(route('employees.documents.index', $this->employee))->assertForbidden();
        $this->actingAs($this->staff)
            ->get(route('employees.documents.download', [$this->employee, $doc]))->assertForbidden();
    }

    public function test_a_signed_out_visitor_cannot_download(): void
    {
        // Created directly rather than through upload(): actingAs persists for
        // the rest of a test, so uploading first would leave this request
        // signed in as HR and quietly assert nothing.
        $doc = $this->document();

        $this->get(route('employees.documents.download', [$this->employee, $doc]))
            ->assertRedirect(route('login'));
    }

    public function test_another_companys_employee_cannot_be_opened(): void
    {
        $other = Company::create(['name' => 'Other', 'timezone' => 'UTC', 'currency' => 'USD']);
        $otherOffice = Office::create(['company_id' => $other->id, 'name' => 'Remote']);
        $theirs = Employee::create([
            'company_id' => $other->id, 'office_id' => $otherOffice->id,
            'employee_code' => 'X1', 'first_name' => 'Sam', 'last_name' => 'Poe',
            'status' => 'active',
        ]);

        $this->actingAs($this->hr)
            ->get(route('employees.documents.index', $theirs))->assertForbidden();
    }

    public function test_a_document_id_from_another_employee_is_not_served(): void
    {
        $this->upload();
        $doc = EmployeeDocument::firstOrFail();

        $someoneElse = Employee::create([
            'company_id' => $this->company->id, 'office_id' => $this->employee->office_id,
            'employee_code' => 'E2', 'first_name' => 'Bo', 'last_name' => 'Kim',
            'status' => 'active',
        ]);

        $this->actingAs($this->hr)
            ->get(route('employees.documents.download', [$someoneElse, $doc]))
            ->assertNotFound();
    }

    // -------------------------------------------------------------------------
    // Expiry
    // -------------------------------------------------------------------------

    public function test_expiry_state_is_reported_correctly(): void
    {
        Carbon::setTestNow('2026-08-07');

        $this->assertSame('none', $this->document(['expires_on' => null])->expiry_state);
        $this->assertSame('expired', $this->document(['expires_on' => '2026-08-01'])->expiry_state);
        $this->assertSame('soon', $this->document(['expires_on' => '2026-08-20'])->expiry_state);
        $this->assertSame('valid', $this->document(['expires_on' => '2027-01-01'])->expiry_state);
    }

    public function test_hr_is_warned_about_an_expiring_document(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-08-07');

        $this->document(['expires_on' => '2026-08-20']);

        $this->artisan('documents:check-expiry')->assertSuccessful();

        Notification::assertSentTo($this->hr, DocumentExpiring::class);
    }

    public function test_an_expired_document_is_also_chased(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-08-07');

        $this->document(['expires_on' => '2026-07-01']);

        $this->artisan('documents:check-expiry')->assertSuccessful();

        Notification::assertSentTo($this->hr, DocumentExpiring::class);
    }

    public function test_a_document_far_from_expiry_is_left_alone(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-08-07');

        $this->document(['expires_on' => '2027-06-01']);

        $this->artisan('documents:check-expiry')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_a_document_with_no_expiry_is_never_chased(): void
    {
        Notification::fake();

        $this->document(['expires_on' => null]);

        $this->artisan('documents:check-expiry')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_the_same_document_is_not_chased_twice(): void
    {
        // The difference between a warning and a mail rule.
        Carbon::setTestNow('2026-08-07');
        $this->document(['expires_on' => '2026-08-20']);

        Notification::fake();
        $this->artisan('documents:check-expiry')->assertSuccessful();
        Notification::assertSentTimes(DocumentExpiring::class, 1);

        Notification::fake();
        $this->artisan('documents:check-expiry')->assertSuccessful();
        Notification::assertNothingSent();
    }

    public function test_editing_the_document_makes_it_chaseable_again(): void
    {
        Carbon::setTestNow('2026-08-07');
        $doc = $this->document(['expires_on' => '2026-08-20']);

        Notification::fake();
        $this->artisan('documents:check-expiry');
        Notification::assertSentTimes(DocumentExpiring::class, 1);

        // A renewal moves the date; the new date deserves its own warning when
        // it comes round.
        Carbon::setTestNow('2026-08-08');
        $doc->update(['expires_on' => '2026-08-25']);

        Notification::fake();
        $this->artisan('documents:check-expiry');
        Notification::assertSentTimes(DocumentExpiring::class, 1);
    }

    public function test_a_dry_run_warns_nobody(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-08-07');

        $doc = $this->document(['expires_on' => '2026-08-20']);

        $this->artisan('documents:check-expiry --dry-run')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertNull($doc->fresh()->expiry_notified_at);
    }

    public function test_an_employee_is_not_warned_about_their_own_document(): void
    {
        // Chasing a renewal is HR's job, and the employee cannot see the file.
        Notification::fake();
        Carbon::setTestNow('2026-08-07');

        $this->document(['expires_on' => '2026-08-20']);

        $this->artisan('documents:check-expiry');

        Notification::assertNotSentTo($this->staff, DocumentExpiring::class);
    }

    public function test_the_list_puts_expiring_documents_first(): void
    {
        Carbon::setTestNow('2026-08-07');

        $this->document(['title' => 'No expiry', 'expires_on' => null]);
        $this->document(['title' => 'Far off', 'expires_on' => '2027-06-01']);
        $this->document(['title' => 'Due soon', 'expires_on' => '2026-08-15']);

        $response = $this->actingAs($this->hr)
            ->get(route('employees.documents.index', $this->employee))->assertOk();

        $titles = $response->viewData('documents')->pluck('title')->all();

        $this->assertSame(['Due soon', 'Far off', 'No expiry'], $titles);
    }
}
