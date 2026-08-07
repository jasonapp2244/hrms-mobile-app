<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Shift;
use App\Models\User;
use App\Support\DashboardWidgets;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A8.4 role-specific dashboards, A8.5 configurable widgets, A8.6 week-on-week.
 *
 * The property worth protecting hardest is that a widget the viewer lacks
 * permission for is never rendered and never offered — a saved preference must
 * not be a way around a permission gate.
 */
class DashboardWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Office $office;
    protected Department $department;
    protected User $admin;
    protected User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'UTC', 'currency' => 'USD',
        ]);
        $this->office = Office::create(['company_id' => $this->company->id, 'name' => 'Head Office']);

        $shift = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Day',
            'start_time' => '09:00:00', 'end_time' => '17:00:00',
            'break_minutes' => 30, 'late_grace_minutes' => 15, 'is_active' => true,
        ]);

        $this->department = Department::create([
            'company_id' => $this->company->id, 'name' => 'Ops', 'shift_id' => $shift->id,
        ]);

        $this->admin = User::create([
            'name' => 'Ada Root', 'email' => 'ada@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $this->admin->assignRole('admin');

        $this->hr = User::create([
            'name' => 'Hana Ruiz', 'email' => 'hana@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $this->hr->assignRole('hr');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employee(string $first = 'Ann'): Employee
    {
        static $n = 0;
        $n++;

        return Employee::create([
            'company_id' => $this->company->id, 'office_id' => $this->office->id,
            'department_id' => $this->department->id,
            'employee_code' => 'E' . $n, 'first_name' => $first, 'last_name' => 'Test',
            'status' => 'active',
        ]);
    }

    private function punch(Employee $e, string $time, string $status = 'ontime', string $type = 'in'): void
    {
        AttendanceLog::create([
            'company_id' => $this->company->id, 'employee_id' => $e->id,
            'office_id' => $this->office->id, 'type' => $type,
            'scanned_at' => Carbon::parse($time), 'work_date' => Carbon::parse($time)->toDateString(),
            'status' => $status, 'source' => 'button',
        ]);
    }

    // -------------------------------------------------------------------------
    // A8.4 — different dashboards per role
    // -------------------------------------------------------------------------

    public function test_an_admin_gets_the_security_panel_by_default(): void
    {
        $this->assertContains('security', DashboardWidgets::defaultsFor($this->admin));
    }

    public function test_hr_does_not_get_the_security_panel_by_default(): void
    {
        $this->assertNotContains('security', DashboardWidgets::defaultsFor($this->hr));
    }

    public function test_hr_gets_the_approvals_and_document_panels_by_default(): void
    {
        $defaults = DashboardWidgets::defaultsFor($this->hr);

        $this->assertContains('pending_approvals', $defaults);
        $this->assertContains('document_expiries', $defaults);
    }

    public function test_a_role_nobody_anticipated_still_gets_a_usable_screen(): void
    {
        // A blank dashboard reads as a broken install.
        $stranger = User::create([
            'name' => 'Odd Role', 'email' => 'odd@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);

        $this->assertNotEmpty(DashboardWidgets::defaultsFor($stranger));
    }

    // -------------------------------------------------------------------------
    // A8.5 — choosing your own panels
    // -------------------------------------------------------------------------

    public function test_a_saved_choice_is_honoured(): void
    {
        $this->actingAs($this->admin)->post(route('dashboard.widgets'), [
            'widgets' => ['tiles', 'security'],
        ])->assertRedirect();

        $this->assertSame(['tiles', 'security'], DashboardWidgets::forUser($this->admin->fresh()));
    }

    public function test_turning_everything_off_is_respected_rather_than_reset(): void
    {
        // [] is a decision; null is "never asked". Collapsing the two would make
        // the empty dashboard impossible to reach.
        $this->actingAs($this->admin)->post(route('dashboard.widgets'), [])->assertRedirect();

        $this->assertSame([], DashboardWidgets::forUser($this->admin->fresh()));
        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertOk()->assertSee('Your dashboard is empty');
    }

    public function test_a_widget_the_viewer_cannot_see_is_never_offered(): void
    {
        $available = DashboardWidgets::availableTo($this->hr);

        // manage-settings is admin-only, and so is the panel behind it.
        $this->assertArrayNotHasKey('security', $available);
        $this->assertArrayHasKey('pending_approvals', $available);
    }

    public function test_a_widget_the_viewer_cannot_see_cannot_be_saved(): void
    {
        // Otherwise a hand-crafted form post is a way past a permission gate.
        $this->actingAs($this->hr)->post(route('dashboard.widgets'), [
            'widgets' => ['tiles', 'security'],
        ])->assertRedirect();

        $this->assertSame(['tiles'], $this->hr->fresh()->dashboard_widgets);
    }

    public function test_a_saved_widget_survives_a_permission_being_taken_away_and_restored(): void
    {
        $this->admin->forceFill(['dashboard_widgets' => ['tiles', 'security']])->save();

        $this->admin->syncRoles(['hr']);
        $this->assertSame(['tiles'], DashboardWidgets::forUser($this->admin->fresh()));

        // Restoring the role brings the panel back rather than having lost it.
        $this->admin->syncRoles(['admin']);
        $this->assertContains('security', DashboardWidgets::forUser($this->admin->fresh()));
    }

    public function test_the_dashboard_renders_for_both_roles(): void
    {
        $this->employee();

        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->assertSee('Administrator Dashboard');
        $this->actingAs($this->hr)->get(route('dashboard'))->assertOk()->assertSee('HR Dashboard');
    }

    // -------------------------------------------------------------------------
    // A8.6 — this week against last
    // -------------------------------------------------------------------------

    public function test_the_comparison_measures_like_for_like(): void
    {
        // Wednesday. This week is Mon–Wed; last week must be Mon–Wed too, not a
        // whole finished week, or every Monday looks like a collapse.
        Carbon::setTestNow('2026-08-05 12:00:00');

        $ann = $this->employee('Ann');

        // This week: two days in.
        $this->punch($ann, '2026-08-03 09:00:00');
        $this->punch($ann, '2026-08-04 09:00:00');

        // Last week: two days inside the Mon–Wed window, one outside it.
        $this->punch($ann, '2026-07-27 09:00:00');
        $this->punch($ann, '2026-07-28 09:00:00');
        $this->punch($ann, '2026-07-31 09:00:00');   // Friday — must not count

        $response = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk();

        $metrics = collect($response->viewData('comparison')['metrics'])->keyBy('key');

        $this->assertSame(2, $metrics['present']['now']);
        $this->assertSame(2, $metrics['present']['was']);
        $this->assertSame(0, $metrics['present']['delta']);
    }

    public function test_fewer_late_arrivals_reads_as_good_and_fewer_days_attended_does_not(): void
    {
        // The view cannot know which direction is an improvement, so the
        // controller decides. Getting this backwards paints a good week red.
        Carbon::setTestNow('2026-08-05 12:00:00');

        $ann = $this->employee('Ann');
        $this->punch($ann, '2026-07-27 09:40:00', 'late');

        $response = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk();
        $metrics = collect($response->viewData('comparison')['metrics'])->keyBy('key');

        // Late fell from 1 to 0 — an improvement.
        $this->assertTrue($metrics['late']['good']);
        // Attendance fell from 1 to 0 — not an improvement.
        $this->assertFalse($metrics['present']['good']);
    }

    public function test_a_percentage_of_nothing_is_reported_as_no_comparison(): void
    {
        // Not infinity, and not a misleading 100%.
        Carbon::setTestNow('2026-08-05 12:00:00');

        $ann = $this->employee('Ann');
        $this->punch($ann, '2026-08-03 09:00:00');

        $response = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk();
        $metrics = collect($response->viewData('comparison')['metrics'])->keyBy('key');

        $this->assertNull($metrics['present']['percent']);
    }

    public function test_the_seven_day_trend_counts_distinct_people_per_day(): void
    {
        Carbon::setTestNow('2026-08-05 18:00:00');

        $ann = $this->employee('Ann');
        $bob = $this->employee('Bob');

        // Ann punches twice today; she is one person present, not two.
        $this->punch($ann, '2026-08-05 09:00:00');
        $this->punch($ann, '2026-08-05 14:00:00');
        $this->punch($bob, '2026-08-05 09:00:00');

        $response = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk();

        $today = collect($response->viewData('trend'))->last();

        $this->assertSame(2, $today['count']);
    }

    public function test_the_trend_includes_today(): void
    {
        // The old version compared work_date as a raw string, which dropped the
        // boundary days on any engine storing a time with the date.
        Carbon::setTestNow('2026-08-05 18:00:00');

        $this->punch($this->employee('Ann'), '2026-08-05 09:00:00');

        $response = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk();

        $this->assertSame(1, collect($response->viewData('trend'))->last()['count']);
    }

    public function test_a_hidden_panel_is_not_computed(): void
    {
        // The whole reason the widget list is consulted before the queries run.
        $this->admin->forceFill(['dashboard_widgets' => ['tiles']])->save();

        $response = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk();

        $this->assertNotNull($response->viewData('stats'));
        $this->assertArrayNotHasKey('comparison', $response->original->getData());
        $this->assertArrayNotHasKey('security', $response->original->getData());
    }
}
