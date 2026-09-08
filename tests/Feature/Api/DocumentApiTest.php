<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Office;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The employee reading their own file (B3.7).
 *
 * The vault is HR's and predates this by a long way; what is under test is that
 * the app reaches exactly one person's shelf of it, read-only, and that a
 * document belonging to somebody else is indistinguishable from one that was
 * never there.
 */
class DocumentApiTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Office $office;
    protected User $user;
    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        Storage::fake(EmployeeDocument::DISK);

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'UTC', 'currency' => 'USD',
        ]);

        $this->office = Office::create([
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

        $this->employee = Employee::create([
            'company_id' => $this->company->id, 'department_id' => $department->id,
            'office_id' => $this->office->id, 'user_id' => $this->user->id,
            'employee_code' => 'E1', 'first_name' => 'Ann', 'last_name' => 'Lee',
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->user);
    }

    /** A filed document, with a real file behind it on the fake disk. */
    protected function document(Employee $employee, array $overrides = []): EmployeeDocument
    {
        $file = UploadedFile::fake()->create('passport.pdf', 12, 'application/pdf');

        return $employee->documents()->create(array_merge([
            'company_id'    => $employee->company_id,
            'type'          => 'id',
            'title'         => 'Passport',
            'path'          => $file->store('employee-documents/' . $employee->id, EmployeeDocument::DISK),
            'original_name' => 'passport.pdf',
            'mime_type'     => 'application/pdf',
            'size_bytes'    => 12288,
        ], $overrides));
    }

    /** A second employee on the same company, with their own login. */
    protected function colleague(): Employee
    {
        $user = User::create([
            'name' => 'Bob Ray', 'email' => 'bob@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);

        return Employee::create([
            'company_id' => $this->company->id, 'office_id' => $this->office->id,
            'user_id' => $user->id, 'employee_code' => 'E2',
            'first_name' => 'Bob', 'last_name' => 'Ray', 'status' => 'active',
        ]);
    }

    // ================= index =================

    public function test_the_list_is_empty_before_hr_files_anything(): void
    {
        $this->getJson('/api/v1/documents')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(0, 'documents')
            ->assertJsonPath('expiring_soon', 0)
            ->assertJsonPath('expired', 0);
    }

    public function test_a_filed_document_is_listed_with_what_the_app_shows(): void
    {
        $this->document($this->employee, [
            'type' => 'contract', 'title' => 'Employment contract',
            'issued_on' => '2023-04-01',
        ]);

        $this->getJson('/api/v1/documents')
            ->assertOk()
            ->assertJsonCount(1, 'documents')
            ->assertJsonPath('documents.0.type', 'contract')
            ->assertJsonPath('documents.0.type_label', 'Contract')
            ->assertJsonPath('documents.0.title', 'Employment contract')
            ->assertJsonPath('documents.0.original_name', 'passport.pdf')
            ->assertJsonPath('documents.0.issued_on', '2023-04-01')
            ->assertJsonPath('documents.0.expires_on', null)
            ->assertJsonPath('documents.0.expiry_state', 'none');
    }

    public function test_the_list_holds_only_the_callers_own_documents(): void
    {
        $this->document($this->employee, ['title' => 'Mine']);
        $this->document($this->colleague(), ['title' => 'Not mine']);

        $this->getJson('/api/v1/documents')
            ->assertOk()
            ->assertJsonCount(1, 'documents')
            ->assertJsonPath('documents.0.title', 'Mine');
    }

    public function test_hr_notes_are_never_sent_to_the_employee(): void
    {
        // The notes field is where HR records why a visa is being chased. The
        // employee is the subject of that commentary, not its audience.
        $this->document($this->employee, [
            'notes' => 'Chase before the audit — third reminder ignored.',
        ]);

        $response = $this->getJson('/api/v1/documents')->assertOk();

        $this->assertArrayNotHasKey('notes', $response->json('documents.0'));
        $response->assertJsonMissing(['notes' => 'Chase before the audit — third reminder ignored.']);
    }

    public function test_the_uploader_is_not_named(): void
    {
        $this->document($this->employee, ['uploaded_by_user_id' => $this->user->id]);

        $document = $this->getJson('/api/v1/documents')->assertOk()->json('documents.0');

        $this->assertArrayNotHasKey('uploaded_by_user_id', $document);
        $this->assertArrayNotHasKey('uploader', $document);
    }

    public function test_the_soonest_to_expire_comes_first_and_undated_last(): void
    {
        // Filed in the wrong order on purpose: the list is read to find what
        // needs renewing, so upload order is the one thing it must not follow.
        $this->document($this->employee, ['title' => 'No date']);
        $this->document($this->employee, ['title' => 'Later', 'expires_on' => now()->addYear()->toDateString()]);
        $this->document($this->employee, ['title' => 'Sooner', 'expires_on' => now()->addDays(10)->toDateString()]);

        $this->getJson('/api/v1/documents')
            ->assertOk()
            ->assertJsonPath('documents.0.title', 'Sooner')
            ->assertJsonPath('documents.1.title', 'Later')
            ->assertJsonPath('documents.2.title', 'No date');
    }

    public function test_expiry_state_matches_the_badge_on_the_web(): void
    {
        $this->document($this->employee, ['title' => 'Gone', 'expires_on' => now()->subDay()->toDateString()]);
        $this->document($this->employee, ['title' => 'Soon', 'expires_on' => now()->addDays(10)->toDateString()]);
        $this->document($this->employee, ['title' => 'Fine', 'expires_on' => now()->addYear()->toDateString()]);

        $states = collect($this->getJson('/api/v1/documents')->json('documents'))
            ->pluck('expiry_state', 'title');

        $this->assertSame('expired', $states['Gone']);
        $this->assertSame('soon', $states['Soon']);
        $this->assertSame('valid', $states['Fine']);
    }

    public function test_the_counts_agree_with_the_list(): void
    {
        $this->document($this->employee, ['expires_on' => now()->subDay()->toDateString()]);
        $this->document($this->employee, ['expires_on' => now()->addDays(5)->toDateString()]);
        $this->document($this->employee, ['expires_on' => now()->addDays(20)->toDateString()]);
        $this->document($this->employee, ['expires_on' => now()->addYear()->toDateString()]);

        $this->getJson('/api/v1/documents')
            ->assertOk()
            ->assertJsonPath('expired', 1)
            ->assertJsonPath('expiring_soon', 2);
    }

    public function test_the_list_needs_a_token(): void
    {
        app('auth')->forgetGuards();

        $this->getJson('/api/v1/documents')->assertStatus(401);
    }

    public function test_an_account_with_no_employee_record_has_no_documents(): void
    {
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/documents')->assertStatus(403);
    }

    // ================= download =================

    public function test_the_file_comes_back_under_the_name_it_was_uploaded_with(): void
    {
        $document = $this->document($this->employee, ['original_name' => 'my-passport.pdf']);

        $response = $this->get("/api/v1/documents/{$document->id}");

        $response->assertOk();
        $this->assertStringContainsString('my-passport.pdf', $response->headers->get('content-disposition'));
    }

    public function test_somebody_elses_document_is_not_found_rather_than_forbidden(): void
    {
        // 403 would confirm the id exists. The ids are sequential and the files
        // are passports, so the two answers are deliberately the same one.
        $document = $this->document($this->colleague());

        $this->getJson("/api/v1/documents/{$document->id}")
            ->assertStatus(404)
            ->assertJsonPath('error', 'not_found');
    }

    public function test_a_document_that_never_existed_is_also_not_found(): void
    {
        $this->getJson('/api/v1/documents/9999')->assertStatus(404);
    }

    public function test_a_row_whose_file_is_gone_says_so(): void
    {
        // What a database restore without storage/app/employee-documents/ looks
        // like from a phone.
        $document = $this->document($this->employee);
        Storage::disk(EmployeeDocument::DISK)->delete($document->path);

        $this->getJson("/api/v1/documents/{$document->id}")
            ->assertStatus(404)
            ->assertJsonPath('error', 'file_missing');
    }

    public function test_downloading_needs_a_token(): void
    {
        $document = $this->document($this->employee);

        app('auth')->forgetGuards();

        $this->getJson("/api/v1/documents/{$document->id}")->assertStatus(401);
    }

    // ================= read only =================

    public function test_the_app_cannot_file_or_remove_a_document(): void
    {
        $document = $this->document($this->employee);

        // Filing is HR's, behind manage-employees. Nothing here is gated
        // against a write because no write exists — both paths are registered
        // for GET only, so the router refuses the method before any controller
        // is reached.
        $this->postJson('/api/v1/documents')->assertStatus(405);
        $this->deleteJson("/api/v1/documents/{$document->id}")->assertStatus(405);

        $this->assertDatabaseCount('employee_documents', 1);
    }
}
