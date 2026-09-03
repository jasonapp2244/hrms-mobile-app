<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Starting a real install, and clearing up after the demo one.
 *
 * The thing being defended is that a plain `db:seed` can no longer invent
 * people. Everything else here follows from that: if the seeder no longer
 * creates the first administrator, something else has to, and whatever removes
 * the old demo rows has to be honest about what it takes with them.
 */
class InstallAndPurgeTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // The default seeder
    // -------------------------------------------------------------------------

    public function test_the_default_seeder_creates_roles_but_invents_nobody(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        // The half that has to survive: without these, no account can be an
        // administrator and every permission check fails.
        $this->assertDatabaseHas('roles', ['name' => 'admin']);
        $this->assertDatabaseHas('permissions', ['name' => 'manage-employees']);

        // The half that had to go.
        $this->assertSame(0, User::count());
        $this->assertSame(0, Employee::count());
        $this->assertSame(0, Company::count());
        $this->assertSame(0, AttendanceLog::count());
    }

    public function test_the_login_page_no_longer_publishes_demo_credentials(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('admin@emp.test')
            ->assertDontSee('Demo credentials');
    }

    // -------------------------------------------------------------------------
    // emp:install
    // -------------------------------------------------------------------------

    public function test_install_creates_a_company_and_a_working_administrator(): void
    {
        $this->artisan('emp:install', [
            '--company'  => 'Real Company Ltd',
            '--timezone' => 'Asia/Karachi',
            '--currency' => 'PKR',
            '--name'     => 'Tauseef Aslam',
            '--email'    => 'boss@realcompany.com',
            '--password' => 'a-real-password',
        ])
            ->expectsConfirmation('Create these?', 'yes')
            ->assertSuccessful();

        $company = Company::firstWhere('name', 'Real Company Ltd');
        $this->assertNotNull($company);
        $this->assertSame('Asia/Karachi', $company->timezone);

        $user = User::firstWhere('email', 'boss@realcompany.com');
        $this->assertNotNull($user);
        $this->assertSame($company->id, $user->company_id);
        $this->assertTrue(Hash::check('a-real-password', $user->password));

        // The step that is easiest to forget by hand, and whose absence looks
        // like a broken dashboard rather than a missing seeder.
        $this->assertTrue($user->hasRole('admin'));
        $this->assertTrue($user->can('manage-employees'));
    }

    public function test_install_will_not_silently_make_a_second_company(): void
    {
        Company::create(['name' => 'Already Here', 'timezone' => 'UTC', 'currency' => 'USD']);

        $this->artisan('emp:install', [
            '--company'      => 'Second One',
            '--timezone'     => 'UTC',
            '--name'         => 'Someone',
            '--email'        => 'someone@example.com',
            '--password'     => 'a-real-password',
            '--no-interaction' => true,
        ])->assertFailed();

        // Staff on different companies cannot see each other, so a second one
        // created by accident looks like the data has vanished.
        $this->assertSame(1, Company::count());
    }

    public function test_install_can_add_an_administrator_to_an_existing_company(): void
    {
        // The state a purge leaves behind, and the one most likely to be got
        // wrong: staff already here, but nobody who can administer them.
        $company = Company::create([
            'name' => 'Real Company Ltd', 'timezone' => 'Asia/Karachi', 'currency' => 'PKR',
        ]);

        $this->artisan('emp:install', [
            '--company-id' => $company->id,
            '--name'       => 'Tauseef Aslam',
            '--email'      => 'boss@realcompany.com',
            '--password'   => 'a-real-password',
        ])
            ->expectsConfirmation('Create these?', 'yes')
            ->assertSuccessful();

        $this->assertSame(1, Company::count());

        $user = User::firstWhere('email', 'boss@realcompany.com');
        $this->assertSame($company->id, $user->company_id);
        $this->assertTrue($user->hasRole('admin'));

        // Untouched. These decide what "09:00" means for everyone already here,
        // so adding a user must not restate them.
        $company->refresh();
        $this->assertSame('Asia/Karachi', $company->timezone);
        $this->assertSame('PKR', $company->currency);
    }

    public function test_install_refuses_an_unknown_company_id(): void
    {
        Company::create(['name' => 'Already Here', 'timezone' => 'UTC', 'currency' => 'USD']);

        $this->artisan('emp:install', [
            '--company-id' => 999,
            '--name'       => 'Someone',
            '--email'      => 'someone@example.com',
            '--password'   => 'a-real-password',
        ])->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_force_still_creates_a_second_company_when_that_is_asked_for(): void
    {
        Company::create(['name' => 'Already Here', 'timezone' => 'UTC', 'currency' => 'USD']);

        $this->artisan('emp:install', [
            '--force'    => true,
            '--company'  => 'Second One',
            '--timezone' => 'Europe/London',
            '--name'     => 'Someone',
            '--email'    => 'someone@example.com',
            '--password' => 'a-real-password',
        ])
            ->expectsConfirmation('Create these?', 'yes')
            ->assertSuccessful();

        $this->assertSame(2, Company::count());
    }

    public function test_install_declined_at_the_prompt_creates_nothing(): void
    {
        $this->artisan('emp:install', [
            '--company'  => 'Real Company Ltd',
            '--timezone' => 'UTC',
            '--name'     => 'Tauseef Aslam',
            '--email'    => 'boss@realcompany.com',
            '--password' => 'a-real-password',
        ])
            ->expectsConfirmation('Create these?', 'no')
            ->assertSuccessful();

        $this->assertSame(0, Company::count());
        $this->assertSame(0, User::count());
    }

    public function test_install_rejects_a_timezone_that_is_not_real(): void
    {
        // A typo here does not error — it falls back to UTC and then marks the
        // whole workforce late every morning, which is why it is validated.
        $this->artisan('emp:install', [
            '--company'  => 'Real Company Ltd',
            '--timezone' => 'Asia/Karachee',
            '--name'     => 'Tauseef Aslam',
            '--email'    => 'boss@realcompany.com',
            '--password' => 'a-real-password',
        ])
            ->expectsOutputToContain('is not an IANA timezone')
            ->expectsQuestion('Company timezone (this decides what "09:00" means)', 'Asia/Karachi')
            ->expectsConfirmation('Create these?', 'yes')
            ->assertSuccessful();

        $this->assertSame('Asia/Karachi', Company::first()->timezone);
    }

    // -------------------------------------------------------------------------
    // emp:purge-demo
    // -------------------------------------------------------------------------

    public function test_a_dry_run_changes_nothing(): void
    {
        $this->seedDemoData();

        $this->artisan('emp:purge-demo', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'admin@emp.test']);
        $this->assertDatabaseHas('employees', ['employee_code' => 'EMP-0001']);
        $this->assertSame(1, AttendanceLog::where('source', 'kiosk')->count());
    }

    public function test_purge_removes_the_seeded_rows_and_spares_everything_else(): void
    {
        [$company, $real] = $this->seedDemoData();

        $this->artisan('emp:purge-demo')
            ->expectsConfirmation('Delete everything listed above? There is no undo.', 'yes')
            ->assertSuccessful();

        $this->assertDatabaseMissing('users', ['email' => 'admin@emp.test']);
        $this->assertDatabaseMissing('employees', ['employee_code' => 'EMP-0001']);
        $this->assertSame(0, AttendanceLog::where('source', 'kiosk')->count());

        // The whole point: a real employee entered by hand is untouched, and so
        // is the company the real staff sit on.
        $this->assertDatabaseHas('employees', ['id' => $real->id]);
        $this->assertDatabaseHas('companies', ['id' => $company->id]);
    }

    public function test_purge_reports_the_real_rows_it_would_take_with_it(): void
    {
        [, , $demoEmployee] = $this->seedDemoData();

        // A punch made from the phone while testing, against a demo account.
        // Attendance cascades from employees, so this dies with EMP-0001 — and
        // being told that afterwards is no use.
        AttendanceLog::create([
            'employee_id' => $demoEmployee->id,
            'office_id'   => Office::first()->id,
            'type'        => 'in',
            'scanned_at'  => now(),
            'work_date'   => now()->toDateString(),
            'status'      => 'ontime',
            'source'      => 'mobile',
        ]);

        $this->artisan('emp:purge-demo', ['--dry-run' => true])
            ->expectsOutputToContain('Real attendance that would go with them')
            ->assertSuccessful();
    }

    public function test_keep_employees_spares_a_demo_account_and_its_real_punches(): void
    {
        [, , $demoEmployee] = $this->seedDemoData();

        $punch = AttendanceLog::create([
            'employee_id' => $demoEmployee->id,
            'office_id'   => Office::first()->id,
            'type'        => 'in',
            'scanned_at'  => now(),
            'work_date'   => now()->toDateString(),
            'status'      => 'ontime',
            'source'      => 'mobile',
        ]);

        $this->artisan('emp:purge-demo', ['--keep-employees' => ['EMP-0001']])
            ->expectsConfirmation('Delete everything listed above? There is no undo.', 'yes')
            ->assertSuccessful();

        $this->assertDatabaseHas('employees', ['employee_code' => 'EMP-0001']);
        $this->assertDatabaseHas('attendance_logs', ['id' => $punch->id]);

        // And their login, or the spared record is a manager who cannot sign
        // in — which leaves their team's leave with nobody to approve it.
        $this->assertDatabaseHas('users', ['email' => 'james.smith@acme.test']);

        // Spared as a record, but the seeded attendance still goes.
        $this->assertSame(0, AttendanceLog::where('source', 'kiosk')->count());
    }

    public function test_purge_says_so_when_there_is_nothing_to_remove(): void
    {
        $this->artisan('emp:purge-demo', ['--dry-run' => true])
            ->expectsOutputToContain('no seeded demo data')
            ->assertSuccessful();
    }

    // -------------------------------------------------------------------------

    /** @return array{0: Company, 1: Employee, 2: Employee} */
    protected function seedDemoData(): array
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(\Database\Seeders\DemoDataSeeder::class);

        $company = Company::first();
        $office  = Office::first();
        $demo    = Employee::firstWhere('employee_code', 'EMP-0001');

        // Somebody entered through the admin UI, the way real staff arrive.
        $real = Employee::create([
            'company_id'    => $company->id,
            'office_id'     => $office->id,
            'employee_code' => 'STAFF-1',
            'first_name'    => 'Trevor',
            'last_name'     => 'Alexander',
            'email'         => 'trevor@realcompany.com',
            'hire_date'     => now()->subYear()->toDateString(),
            'status'        => 'active',
        ]);

        AttendanceLog::create([
            'employee_id' => $demo->id,
            'office_id'   => $office->id,
            'type'        => 'in',
            'scanned_at'  => now()->subDay(),
            'work_date'   => now()->subDay()->toDateString(),
            'status'      => 'ontime',
            'source'      => 'kiosk',
        ]);

        return [$company, $real, $demo];
    }
}
