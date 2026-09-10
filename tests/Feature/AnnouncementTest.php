<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Office;
use App\Models\User;
use App\Notifications\CompanyAnnouncement;
use App\Support\AppRoute;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

/**
 * HR announcements and broadcasts (B5.5).
 *
 * The feature is one irreversible action wrapped in as much caution as a web
 * form can carry, so most of this is about the ways it must refuse: publishing
 * twice, editing something already read by two hundred people, sending to an
 * audience nobody is in, and reaching a company that is not yours.
 */
class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Office $hq;
    protected Office $depot;
    protected Department $ops;
    protected Department $admin_dept;
    protected User $hr;
    protected User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'UTC', 'currency' => 'USD',
        ]);

        $this->hq    = Office::create(['company_id' => $this->company->id, 'name' => 'HQ']);
        $this->depot = Office::create(['company_id' => $this->company->id, 'name' => 'Depot']);

        $this->ops        = Department::create(['company_id' => $this->company->id, 'name' => 'Ops']);
        $this->admin_dept = Department::create(['company_id' => $this->company->id, 'name' => 'Admin']);

        // An HR account with no employee record — the person who writes these.
        $this->hr = User::create([
            'name' => 'Hana Rose', 'email' => 'hr@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $this->hr->assignRole('hr');

        $this->employee = $this->staff('Ann', $this->ops, $this->hq);
    }

    protected function staff(string $name, Department $department, Office $office, bool $active = true): User
    {
        $user = User::create([
            'name' => $name, 'email' => strtolower($name) . '@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
            'is_active' => $active,
        ]);
        $user->assignRole('employee');

        Employee::create([
            'company_id' => $this->company->id, 'department_id' => $department->id,
            'office_id' => $office->id, 'user_id' => $user->id,
            'employee_code' => strtoupper($name), 'first_name' => $name, 'last_name' => 'Lee',
            'status' => 'active',
        ]);

        return $user;
    }

    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'title'    => 'Depot closed Friday',
            'body'     => 'The depot closes at 2pm this Friday for the annual service.',
            'audience' => Announcement::ALL,
        ], $overrides);
    }

    protected function draft(array $attrs = []): Announcement
    {
        return Announcement::create(array_merge([
            'company_id'   => $this->company->id,
            'created_by'   => $this->hr->id,
            'author_label' => $this->hr->name,
            'title'        => 'Depot closed Friday',
            'body'         => 'The depot closes at 2pm this Friday.',
            'audience'     => Announcement::ALL,
        ], $attrs));
    }

    // ================= writing =================

    public function test_hr_can_save_a_draft_without_sending_anything(): void
    {
        Notification::fake();

        $this->actingAs($this->hr)
            ->post(route('announcements.store'), $this->payload())
            ->assertRedirect();

        $this->assertDatabaseHas('announcements', [
            'title' => 'Depot closed Friday', 'published_at' => null,
        ]);

        // The whole point of the two-step gesture.
        Notification::assertNothingSent();
    }

    public function test_the_author_is_recorded_by_name_as_well_as_by_id(): void
    {
        $this->actingAs($this->hr)->post(route('announcements.store'), $this->payload());

        $this->assertDatabaseHas('announcements', [
            'created_by' => $this->hr->id, 'author_label' => 'Hana Rose',
        ]);
    }

    public function test_saving_and_sending_in_one_step_is_possible(): void
    {
        Notification::fake();

        $this->actingAs($this->hr)
            ->post(route('announcements.store'), $this->payload(['publish_now' => '1']))
            ->assertRedirect();

        Notification::assertSentTo($this->employee, CompanyAnnouncement::class);
        $this->assertNotNull(Announcement::first()->published_at);
    }

    public function test_a_draft_can_be_edited(): void
    {
        $draft = $this->draft();

        $this->actingAs($this->hr)
            ->put(route('announcements.update', $draft), $this->payload(['title' => 'Depot closed all day']))
            ->assertRedirect();

        $this->assertSame('Depot closed all day', $draft->fresh()->title);
    }

    public function test_a_draft_can_be_deleted(): void
    {
        $draft = $this->draft();

        $this->actingAs($this->hr)
            ->delete(route('announcements.destroy', $draft))
            ->assertRedirect();

        $this->assertDatabaseMissing('announcements', ['id' => $draft->id]);
    }

    // ================= sending =================

    public function test_publishing_reaches_everybody_active_in_the_company(): void
    {
        Notification::fake();

        $second = $this->staff('Ben', $this->admin_dept, $this->depot);
        $draft = $this->draft();

        $this->actingAs($this->hr)
            ->post(route('announcements.publish', $draft))
            ->assertRedirect()->assertSessionHas('success');

        Notification::assertSentTo([$this->employee, $second], CompanyAnnouncement::class);

        // Including the person who wrote it. An HR account has no employee
        // record, and leaving them out would make the one person who cannot
        // check what was sent the one who sent it.
        Notification::assertSentTo($this->hr, CompanyAnnouncement::class);
    }

    public function test_a_departmental_announcement_reaches_only_that_department(): void
    {
        Notification::fake();

        $other = $this->staff('Ben', $this->admin_dept, $this->hq);

        $draft = $this->draft([
            'audience' => Announcement::DEPARTMENT, 'department_id' => $this->ops->id,
        ]);

        $this->actingAs($this->hr)->post(route('announcements.publish', $draft));

        Notification::assertSentTo($this->employee, CompanyAnnouncement::class);
        Notification::assertNotSentTo($other, CompanyAnnouncement::class);

        // And not to the HR author either: they are not in that department, and
        // a narrowed audience means what it says.
        Notification::assertNotSentTo($this->hr, CompanyAnnouncement::class);
    }

    public function test_an_office_announcement_reaches_only_that_office(): void
    {
        Notification::fake();

        $atDepot = $this->staff('Ben', $this->ops, $this->depot);

        $draft = $this->draft([
            'audience' => Announcement::OFFICE, 'office_id' => $this->depot->id,
        ]);

        $this->actingAs($this->hr)->post(route('announcements.publish', $draft));

        Notification::assertSentTo($atDepot, CompanyAnnouncement::class);
        Notification::assertNotSentTo($this->employee, CompanyAnnouncement::class);
    }

    public function test_somebody_who_has_left_is_not_told(): void
    {
        Notification::fake();

        $gone = $this->staff('Cal', $this->ops, $this->hq, active: false);

        $this->actingAs($this->hr)->post(route('announcements.publish', $this->draft()));

        Notification::assertNotSentTo($gone, CompanyAnnouncement::class);
    }

    public function test_the_recipient_count_is_frozen_at_the_moment_it_was_sent(): void
    {
        Notification::fake();

        $draft = $this->draft();
        $this->actingAs($this->hr)->post(route('announcements.publish', $draft));

        $this->assertSame(2, $draft->fresh()->recipients_count);

        // Somebody joins afterwards. The register still reports how many phones
        // it actually reached, not how many it would reach today.
        $this->staff('Ben', $this->ops, $this->hq);

        $this->assertSame(2, $draft->fresh()->recipients_count);
    }

    public function test_an_empty_audience_is_refused_rather_than_sent_to_nobody(): void
    {
        Notification::fake();

        $empty = Department::create(['company_id' => $this->company->id, 'name' => 'Nobody']);

        $draft = $this->draft([
            'audience' => Announcement::DEPARTMENT, 'department_id' => $empty->id,
        ]);

        $this->actingAs($this->hr)
            ->post(route('announcements.publish', $draft))
            ->assertSessionHas('error');

        Notification::assertNothingSent();
        $this->assertNull($draft->fresh()->published_at);
    }

    // ================= what cannot be undone =================

    public function test_a_published_announcement_cannot_be_edited(): void
    {
        Notification::fake();

        $draft = $this->draft();
        $this->actingAs($this->hr)->post(route('announcements.publish', $draft));

        $this->actingAs($this->hr)
            ->put(route('announcements.update', $draft), $this->payload(['title' => 'Actually Thursday']))
            ->assertSessionHas('error');

        $this->assertSame('Depot closed Friday', $draft->fresh()->title);
    }

    public function test_a_published_announcement_cannot_be_deleted(): void
    {
        Notification::fake();

        $draft = $this->draft();
        $this->actingAs($this->hr)->post(route('announcements.publish', $draft));

        $this->actingAs($this->hr)
            ->delete(route('announcements.destroy', $draft))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('announcements', ['id' => $draft->id]);
    }

    public function test_the_model_refuses_even_without_the_controller(): void
    {
        $published = $this->draft();
        $published->forceFill(['published_at' => now()])->saveQuietly();

        // The controller's checks are the explanation; this is the guarantee.
        // Anything reaching the model directly — a console command, a future
        // endpoint — gets the same answer.
        $this->expectException(RuntimeException::class);

        $published->update(['title' => 'Rewritten']);
    }

    public function test_publishing_twice_sends_once(): void
    {
        Notification::fake();

        $draft = $this->draft();

        $this->actingAs($this->hr)->post(route('announcements.publish', $draft));
        $this->actingAs($this->hr)
            ->post(route('announcements.publish', $draft))
            ->assertSessionHas('error');

        Notification::assertSentTimes(CompanyAnnouncement::class, 2);
    }

    public function test_sending_is_written_to_the_security_trail(): void
    {
        Notification::fake();

        $this->actingAs($this->hr)->post(route('announcements.publish', $this->draft()));

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->hr->id, 'event' => 'settings_changed',
        ]);
    }

    // ================= validation and access =================

    public function test_a_departmental_announcement_needs_a_department(): void
    {
        $this->actingAs($this->hr)
            ->post(route('announcements.store'), $this->payload([
                'audience' => Announcement::DEPARTMENT,
            ]))
            ->assertSessionHasErrors('department_id');
    }

    public function test_the_unused_audience_field_is_cleared(): void
    {
        // Picked a department, then switched to everyone. The stale id would
        // otherwise sit on the row and be displayed by the register.
        $this->actingAs($this->hr)->post(route('announcements.store'), $this->payload([
            'audience'      => Announcement::ALL,
            'department_id' => $this->ops->id,
        ]));

        $this->assertNull(Announcement::first()->department_id);
    }

    public function test_a_department_from_another_company_is_refused(): void
    {
        $other = Company::create(['name' => 'Other', 'timezone' => 'UTC', 'currency' => 'USD']);
        $theirs = Department::create(['company_id' => $other->id, 'name' => 'Theirs']);

        $this->actingAs($this->hr)
            ->post(route('announcements.store'), $this->payload([
                'audience' => Announcement::DEPARTMENT, 'department_id' => $theirs->id,
            ]))
            ->assertSessionHasErrors('department_id');
    }

    public function test_an_announcement_belonging_to_another_company_is_forbidden(): void
    {
        $other = Company::create(['name' => 'Other', 'timezone' => 'UTC', 'currency' => 'USD']);

        $theirs = Announcement::create([
            'company_id' => $other->id, 'title' => 'Theirs', 'body' => 'Not yours',
            'audience' => Announcement::ALL,
        ]);

        $this->actingAs($this->hr)
            ->post(route('announcements.publish', $theirs))
            ->assertForbidden();
    }

    public function test_an_ordinary_employee_cannot_reach_the_register(): void
    {
        $this->actingAs($this->employee)->get(route('announcements.index'))->assertForbidden();
        $this->actingAs($this->employee)
            ->post(route('announcements.store'), $this->payload())
            ->assertForbidden();
    }

    public function test_the_register_renders(): void
    {
        $this->draft();

        $this->actingAs($this->hr)
            ->get(route('announcements.index'))
            ->assertOk()
            ->assertSee('Depot closed Friday')
            ->assertSee('Draft');
    }

    // ================= the permission reaching a live database =================

    public function test_hr_and_admin_hold_the_permission_and_nobody_else_does(): void
    {
        $this->assertTrue($this->hr->can('manage-announcements'));
        $this->assertFalse($this->employee->can('manage-announcements'));

        $admin = User::create([
            'name' => 'Adam', 'email' => 'admin@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $admin->assignRole('admin');

        $this->assertTrue($admin->can('manage-announcements'));
    }

    public function test_the_migration_grants_the_permission_on_a_database_the_seeder_will_not_run_again(): void
    {
        // `deploy.sh` runs `migrate --force` and nothing else, so this migration
        // is the only way the permission reaches a server that is already live.
        // Under RefreshDatabase it no-ops (migrations run before the seeder), so
        // the populated path is exercised here by hand — otherwise the one code
        // path that matters in production is the one nothing covers.
        $role = \Spatie\Permission\Models\Role::findByName('hr');
        $role->revokePermissionTo('manage-announcements');
        \Spatie\Permission\Models\Permission::where('name', 'manage-announcements')->delete();
        app()['cache']->forget('spatie.permission.cache');

        $this->assertFalse($this->hr->fresh()->can('manage-announcements'));

        $migration = require database_path('migrations/2026_09_11_000002_add_manage_announcements_permission.php');
        $migration->up();

        $this->assertTrue($this->hr->fresh()->can('manage-announcements'));

        // And running it a second time is not an error — a re-run of a
        // partially applied deploy has to be safe.
        $migration->up();
        $this->assertTrue($this->hr->fresh()->can('manage-announcements'));
    }

    public function test_the_migration_leaves_other_permissions_as_the_administrator_left_them(): void
    {
        // The reason this is not just `db:seed` in deploy.sh: the seeder ends in
        // syncPermissions(), which would hand back a permission somebody had
        // deliberately taken away.
        $role = \Spatie\Permission\Models\Role::findByName('hr');
        $role->revokePermissionTo('export-reports');
        app()['cache']->forget('spatie.permission.cache');

        $migration = require database_path('migrations/2026_09_11_000002_add_manage_announcements_permission.php');
        $migration->up();

        $this->assertFalse($this->hr->fresh()->can('export-reports'));
    }

    // ================= what lands on the phone =================

    public function test_the_notification_carries_the_typed_words_untranslated(): void
    {
        // The one notification whose text is not from lang/. A Spanish reader
        // gets exactly what HR wrote, because HR knows who reads what and a
        // machine translation of a closure notice is a liability.
        $this->hr->forceFill(['locale' => 'es'])->save();

        $draft = $this->draft(['body' => 'The depot closes at 2pm.']);
        $this->actingAs($this->hr)->post(route('announcements.publish', $draft));

        $row = $this->hr->fresh()->notifications()->first();

        $this->assertSame('Depot closed Friday', $row->data['title']);
        $this->assertSame('The depot closes at 2pm.', $row->data['body']);
        $this->assertSame('announcement', $row->data['type']);
    }

    public function test_the_bell_keeps_the_whole_message_and_the_push_keeps_an_opening(): void
    {
        $long = str_repeat('The depot closes early. ', 40);

        $note = new CompanyAnnouncement(1, 'Notice', $long);

        // In full where there is room for it.
        $this->assertSame($long, $note->toDatabase($this->hr)['body']);

        // And trimmed where there is not — an oversized data payload is a
        // failed send for every recipient, not a long notification.
        $push = $note->toPush($this->hr);
        $this->assertLessThanOrEqual(Announcement::PUSH_PREVIEW_CHARS + 3, strlen($push->body));
        $this->assertSame('Notice', $push->title);
    }

    public function test_an_announcement_points_nowhere_in_the_app(): void
    {
        // The body is the whole message and the notification centre shows it in
        // full, so there is nothing to open. Null means "no button", not "no
        // route yet".
        $this->assertNull(AppRoute::forType('announcement'));
    }
}
