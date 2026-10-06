<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Office;
use App\Models\QrDisplay;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\QrAttendanceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A punch the database failed to write is a server fault, not a refusal.
 *
 * The punch endpoints catch \RuntimeException to turn the service's own
 * refusals (outside the fence, a time in the future, no shift to break from)
 * into a 422 with a message the person can act on. Laravel's QueryException is
 * a \RuntimeException too, so a database failure used to land in the same
 * catch: the client was told `outside_geofence`, the SQL went to the phone as
 * the message, and an offline punch was marked `refused` — which the app reads
 * as "drop it from the queue", losing the punch for good.
 *
 * Found on a handset: a locked database put a full UPDATE statement, with the
 * file path and the bindings, on the screen of the person checking in.
 */
class PunchDatabaseFailureTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->travelTo(Carbon::parse('2026-09-30 08:50:00'));

        $company = Company::create(['name' => 'Acme', 'timezone' => 'UTC', 'currency' => 'USD']);
        $office  = Office::create(['company_id' => $company->id, 'name' => 'HQ', 'is_active' => true]);

        $this->user = User::create([
            'name' => 'Ann', 'email' => 'ann@acme.test', 'password' => Hash::make('password'),
            'company_id' => $company->id, 'is_active' => true,
        ]);
        $this->user->assignRole('employee');

        Employee::create([
            'company_id' => $company->id, 'office_id' => $office->id, 'user_id' => $this->user->id,
            'employee_code' => 'ANN', 'first_name' => 'Ann', 'last_name' => 'Test', 'status' => 'active',
        ]);

        QrDisplay::create(['company_id' => $company->id, 'office_id' => $office->id, 'name' => 'Front desk']);

        Sanctum::actingAs($this->user);

        // As a live server runs. With debug on, a 500 deliberately carries the
        // real message (bootstrap/app.php); that is for a developer's screen.
        config(['app.debug' => false]);
    }

    private function databaseGivesWay(string $method): void
    {
        $this->partialMock(AttendanceService::class, fn ($mock) => $mock
            ->shouldReceive($method)
            ->andThrow(new QueryException(
                'sqlite',
                'update "attendance_qr_tokens" set "consumed_at" = ?',
                ['2026-09-30 08:50:00'],
                new \PDOException('SQLSTATE[HY000]: General error: 5 database is locked'),
            )));
    }

    private function assertServerFaultWithoutSql(TestResponse $response): void
    {
        $response->assertStatus(500)->assertJsonPath('error', 'server_error');

        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('attendance_qr_tokens', $response->getContent());
    }

    public function test_a_scan_the_database_could_not_write_is_a_server_fault(): void
    {
        $this->databaseGivesWay('record');

        $code = app(QrAttendanceService::class)->issue(QrDisplay::sole())['payload'];

        $this->assertServerFaultWithoutSql($this->postJson('/api/v1/attendance/qr', ['qr' => $code]));
    }

    public function test_a_tap_the_database_could_not_write_is_a_server_fault(): void
    {
        $this->databaseGivesWay('record');

        $this->assertServerFaultWithoutSql($this->postJson('/api/v1/attendance/check'));
    }

    public function test_a_break_the_database_could_not_write_is_a_server_fault(): void
    {
        $this->databaseGivesWay('recordBreak');

        $this->assertServerFaultWithoutSql($this->postJson('/api/v1/attendance/break'));
    }

    public function test_an_offline_punch_the_database_could_not_write_is_not_refused(): void
    {
        $this->databaseGivesWay('recordQueued');

        // Not `refused`: that word tells the app to throw the punch away. A
        // failed request is retried, which is what a locked database needs.
        $this->assertServerFaultWithoutSql($this->postJson('/api/v1/attendance/sync', [
            'punches' => [['occurred_at' => '2026-09-30 08:45:00']],
        ]));
    }
}
