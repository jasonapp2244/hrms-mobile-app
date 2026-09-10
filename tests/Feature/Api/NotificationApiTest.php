<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\User;
use App\Support\AppRoute;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The notification history (B5.6).
 *
 * The table and everything in it predate this endpoint — the web dashboard has
 * read it since A9 — so what is worth pinning is the scoping (a notification
 * belongs to one person and nobody else can reach it), the derived route, and
 * the two places this deliberately differs from the web screen.
 */
class NotificationApiTest extends TestCase
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

        $this->user = $this->account('ann@acme.test', 'Ann Lee');
        Sanctum::actingAs($this->user);
    }

    protected function account(string $email, string $name): User
    {
        $user = User::create([
            'name' => $name, 'email' => $email,
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $user->assignRole('employee');

        return $user;
    }

    protected function notify(User $user, array $data, ?string $readAt = null): string
    {
        $id = (string) Str::uuid();

        $user->notifications()->create([
            'id'      => $id,
            'type'    => 'App\\Notifications\\LeaveRequestDecided',
            'data'    => $data,
            'read_at' => $readAt,
        ]);

        return $id;
    }

    public function test_it_returns_the_callers_own_history(): void
    {
        $this->notify($this->user, [
            'type'  => 'leave.approved',
            'title' => 'Your leave was approved',
            'body'  => 'Your Annual Leave for 12 to 14 Sep has been approved.',
            'url'   => 'https://example.test/employee/leave',
        ]);

        $response = $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('unread', 1)
            ->assertJsonPath('notifications.0.type', 'leave.approved')
            ->assertJsonPath('notifications.0.title', 'Your leave was approved')
            ->assertJsonPath('notifications.0.route', AppRoute::LEAVE)
            ->assertJsonPath('notifications.0.read_at', null);

        // The stored payload carries a web URL and per-class extras. Neither is
        // any use to the app, and publishing them would make every new
        // notification type a client change.
        $this->assertArrayNotHasKey('url', $response->json('notifications.0'));
    }

    public function test_nobody_reads_anybody_elses(): void
    {
        $other = $this->account('bob@acme.test', 'Bob Ray');
        $this->notify($other, ['type' => 'leave.approved', 'title' => 'Not yours']);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'notifications')
            ->assertJsonPath('unread', 0);
    }

    public function test_the_route_is_derived_for_rows_written_before_it_existed(): void
    {
        // `toDatabase()` has never recorded a route — only the push payload had
        // one — so every row already in the table has to get its answer from
        // the type.
        $cases = [
            'leave.approved'                => AppRoute::LEAVE,
            'leave.rejected'                => AppRoute::LEAVE,
            'leave.manager_approved'        => AppRoute::LEAVE,
            'leave.submitted'               => AppRoute::APPROVALS,
            'attendance.missing_checkout'   => AppRoute::CLOCK,
            'schedule_updated'              => AppRoute::SCHEDULE,
            // Addressed to HR, who work at a desk. Null is the honest answer;
            // inventing a screen to point at would be worse.
            'document_expiring'             => null,
            'late_arrivals'                 => null,
        ];

        foreach ($cases as $type => $expected) {
            $this->assertSame($expected, AppRoute::forType($type), $type);
        }

        // And a type invented after this build shipped resolves to null rather
        // than throwing.
        $this->assertNull(AppRoute::forType('something.new'));
        $this->assertNull(AppRoute::forType(null));
    }

    public function test_unread_counts_everything_not_just_the_page(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->notify($this->user, ['type' => 'leave.approved', 'title' => "Note {$i}"]);
        }

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(25, 'notifications')
            ->assertJsonPath('unread', 30)
            ->assertJsonPath('meta.total', 30);
    }

    public function test_one_can_be_marked_read(): void
    {
        $id = $this->notify($this->user, ['type' => 'leave.approved', 'title' => 'A']);
        $this->notify($this->user, ['type' => 'leave.approved', 'title' => 'B']);

        $this->postJson("/api/v1/notifications/{$id}/read")
            ->assertOk()
            ->assertJsonPath('unread', 1);

        $this->assertNotNull($this->user->notifications()->find($id)->read_at);
    }

    public function test_marking_a_missing_one_read_is_not_an_error(): void
    {
        // The app may be delivering a tap made offline, and the row can be gone
        // by then. A 404 would leave the screen showing a badge it cannot
        // clear.
        $this->postJson('/api/v1/notifications/' . Str::uuid() . '/read')
            ->assertOk()
            ->assertJsonPath('unread', 0);
    }

    public function test_marking_somebody_elses_read_does_nothing(): void
    {
        $other = $this->account('cara@acme.test', 'Cara Fox');
        $id = $this->notify($other, ['type' => 'leave.approved', 'title' => 'Theirs']);

        $this->postJson("/api/v1/notifications/{$id}/read")->assertOk();

        // Answered politely, and did not touch it.
        $this->assertNull($other->notifications()->find($id)->read_at);
    }

    public function test_all_can_be_marked_read_at_once(): void
    {
        $this->notify($this->user, ['type' => 'leave.approved', 'title' => 'A']);
        $this->notify($this->user, ['type' => 'schedule_updated', 'title' => 'B']);

        $this->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('unread', 0);

        $this->assertSame(0, $this->user->unreadNotifications()->count());
        // Read, not deleted: the history is the point.
        $this->assertSame(2, $this->user->notifications()->count());
    }

    public function test_an_account_with_no_employee_record_still_has_a_history(): void
    {
        // The one employee-facing endpoint that is not behind an employee row.
        // A notification is addressed to a user, and HR gets document-expiry
        // warnings without ever being on the payroll.
        $this->assertNull($this->user->employee);

        $this->notify($this->user, ['type' => 'document_expiring', 'title' => 'ID expires soon']);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('notifications.0.title', 'ID expires soon')
            ->assertJsonPath('notifications.0.route', null);
    }

    public function test_it_needs_a_token(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/notifications')
            ->assertStatus(401)
            ->assertJsonPath('error', 'unauthenticated');
    }
}
