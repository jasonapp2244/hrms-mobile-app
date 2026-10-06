<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AttendanceLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
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

    public function test_recent_punches_carry_no_map_link(): void
    {
        // Client request, 2026-09-29: no map link and no location on a recent
        // punch, not even "no location", and no IP — both hidden everywhere for now.
        // The name, type and office stay.
        $located = $this->employee('Ann');
        $unlocated = $this->employee('Bo');
        $now = now()->format('Y-m-d H:i:s');

        foreach ([[$located, 40.7128, -74.0060], [$unlocated, null, null]] as [$who, $lat, $lng]) {
            AttendanceLog::create([
                'company_id' => $this->company->id, 'employee_id' => $who->id,
                'office_id' => $this->office->id, 'type' => 'in',
                'scanned_at' => Carbon::parse($now), 'work_date' => Carbon::parse($now)->toDateString(),
                'status' => 'ontime', 'source' => 'button',
                'latitude' => $lat, 'longitude' => $lng, 'ip_address' => '203.0.113.7',
            ]);
        }

        $this->actingAs($this->admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Recent Punches')
            ->assertSee('Ann Test')
            ->assertDontSee('google.com/maps', false)
            ->assertDontSee('no location')
            ->assertDontSee('203.0.113.7');
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
        $this->assertArrayNotHasKey('lateToday', $response->original->getData());
        $this->assertArrayNotHasKey('byDepartment', $response->original->getData());
    }

    // -------------------------------------------------------------------------
    // The redesigned panels
    // -------------------------------------------------------------------------

    private function leaveType(?int $companyId = null): LeaveType
    {
        return LeaveType::create([
            'company_id' => $companyId ?? $this->company->id, 'name' => 'Annual Leave',
            'days_per_year' => 20, 'requires_approval' => true, 'is_active' => true,
        ]);
    }

    public function test_the_new_panels_are_on_by_default_for_both_roles(): void
    {
        foreach ([$this->admin, $this->hr] as $user) {
            $defaults = DashboardWidgets::defaultsFor($user);

            foreach (['attendance_donut', 'late_today', 'by_department', 'upcoming'] as $key) {
                $this->assertContains($key, $defaults, "{$key} for {$user->email}");
            }
        }

        $this->assertContains('birthdays', DashboardWidgets::defaultsFor($this->hr));
    }

    public function test_late_arrivals_show_how_late_by_the_shift_that_marked_them(): void
    {
        // Shift starts 09:00 with 15 minutes' grace, so 09:40 is 25 minutes late
        // — the same arithmetic that set the status, not a second opinion.
        Carbon::setTestNow('2026-08-05 12:00:00');

        $ann = $this->employee('Ann');
        $bo = $this->employee('Bo');
        $this->punch($ann, '2026-08-05 09:40:00', 'late');
        $this->punch($bo, '2026-08-05 09:05:00');

        $response = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()
            ->assertSee('Late Arrivals Today')
            ->assertSee('+25 min');

        $late = $response->viewData('lateToday');
        $this->assertSame(1, $late['count']);
        $this->assertSame('Ann Test', $late['rows'][0]['log']->employee->full_name);
    }

    public function test_a_late_return_from_a_break_does_not_make_somebody_a_late_arrival(): void
    {
        // Only the first clock-in of the day decides it.
        Carbon::setTestNow('2026-08-05 18:00:00');

        $ann = $this->employee('Ann');
        $this->punch($ann, '2026-08-05 09:00:00');
        $this->punch($ann, '2026-08-05 14:00:00', 'late');

        $response = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk();

        $this->assertSame(0, $response->viewData('lateToday')['count']);
    }

    public function test_headcount_by_department_counts_only_active_staff(): void
    {
        $sales = Department::create(['company_id' => $this->company->id, 'name' => 'Sales']);

        $this->employee('Ann');
        $this->employee('Bo');
        $this->employee('Cy')->update(['department_id' => $sales->id]);
        $this->employee('Di')->update(['department_id' => null]);
        $this->employee('Ed')->update(['status' => 'inactive']);

        $response = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()
            ->assertSee('Employees By Department');

        $figures = $response->viewData('byDepartment');
        $counts = $figures['departments']->pluck('headcount', 'name');

        $this->assertSame(2, $counts['Ops']);
        $this->assertSame(1, $counts['Sales']);
        $this->assertSame(1, $figures['unassigned']);
        $this->assertSame(4, $figures['total']);
    }

    public function test_the_waiting_list_shows_this_companys_requests_only(): void
    {
        $ann = $this->employee('Ann');

        LeaveRequest::create([
            'company_id' => $this->company->id, 'employee_id' => $ann->id,
            'leave_type_id' => $this->leaveType()->id, 'start_date' => '2026-08-10',
            'end_date' => '2026-08-11', 'days' => 2, 'status' => 'pending',
        ]);

        $other = Company::create(['name' => 'Rival', 'timezone' => 'UTC', 'currency' => 'USD']);
        $stranger = Employee::create([
            'company_id' => $other->id, 'employee_code' => 'R1',
            'first_name' => 'Zed', 'last_name' => 'Outsider', 'status' => 'active',
        ]);
        LeaveRequest::create([
            'company_id' => $other->id, 'employee_id' => $stranger->id,
            'leave_type_id' => $this->leaveType($other->id)->id, 'start_date' => '2026-08-10',
            'end_date' => '2026-08-10', 'days' => 1, 'status' => 'pending',
        ]);

        $response = $this->actingAs($this->hr)->get(route('dashboard'))->assertOk()
            ->assertSee('Ann Test')
            ->assertDontSee('Zed Outsider');

        $this->assertSame(1, $response->viewData('approvals')['leave']);
        $this->assertCount(1, $response->viewData('approvals')['requests']);
    }

    public function test_upcoming_lists_booked_leave_and_the_next_holiday(): void
    {
        Carbon::setTestNow('2026-08-05 12:00:00');

        $ann = $this->employee('Ann');
        LeaveRequest::create([
            'company_id' => $this->company->id, 'employee_id' => $ann->id,
            'leave_type_id' => $this->leaveType()->id, 'start_date' => '2026-08-08',
            'end_date' => '2026-08-09', 'days' => 2, 'status' => 'approved',
        ]);
        Holiday::create(['company_id' => $this->company->id, 'name' => 'Founders Day', 'date' => '2026-09-01']);

        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()
            ->assertSee("Who&#039;s off soon", false)
            ->assertSee('Ann Test')
            ->assertSee('in 3 days')
            ->assertSee('Founders Day');
    }

    public function test_birthdays_show_only_this_months(): void
    {
        Carbon::setTestNow('2026-08-05 12:00:00');

        $this->employee('Ann')->update(['date_of_birth' => '1990-08-21']);
        $this->employee('Bo')->update(['date_of_birth' => '1990-03-02']);

        $response = $this->actingAs($this->hr)->get(route('dashboard'))->assertOk()
            ->assertSee('Birthdays This Month');

        $this->assertSame(['Ann Test'], $response->viewData('birthdays')->pluck('full_name')->all());
    }

    public function test_an_attendance_rate_of_nobody_is_no_rate_at_all(): void
    {
        // No staff at all: not 0%, which would read as everyone absent.
        $response = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk();

        $this->assertNull($response->viewData('stats')['rate']);
    }

    public function test_the_tiles_compare_with_the_last_working_day(): void
    {
        // Wednesday against Tuesday; on a Monday it would be Friday.
        Carbon::setTestNow('2026-08-05 12:00:00');

        $ann = $this->employee('Ann');
        $bo = $this->employee('Bo');
        $this->punch($ann, '2026-08-04 09:00:00');
        $this->punch($ann, '2026-08-05 09:00:00');
        $this->punch($bo, '2026-08-05 09:00:00');

        $stats = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->viewData('stats');

        $this->assertSame('Tue', $stats['versus']);
        $this->assertSame(1, $stats['before']['present']);
        $this->assertSame(2, $stats['present']);
        $this->assertSame(100, $stats['rate']);
    }

    public function test_the_donut_names_who_has_not_turned_up(): void
    {
        Carbon::setTestNow('2026-08-05 12:00:00');

        $this->punch($this->employee('Ann'), '2026-08-05 09:00:00');
        $this->employee('Bo');

        $donut = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()
            ->assertSee("Today's Attendance", false)
            ->viewData('donut');

        $this->assertSame(1, $donut['ontime']);
        $this->assertSame(1, $donut['missing']);
        $this->assertSame('Bo Test', $donut['absentees'][0]->full_name);
    }

    public function test_the_security_panel_ignores_another_companys_sign_in_failures(): void
    {
        // The activity log's own scope: ours, plus attempts nobody can
        // attribute (an address matching no account). Never a rival's.
        $other = Company::create(['name' => 'Rival', 'timezone' => 'UTC', 'currency' => 'USD']);

        foreach ([$this->company->id, null, $other->id] as $companyId) {
            ActivityLog::create([
                'company_id' => $companyId, 'event' => ActivityLog::LOGIN_FAILED,
                'actor_label' => 'someone@' . ($companyId ?? 'nowhere') . '.test',
            ]);
        }

        $security = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()
            ->assertDontSee('someone@' . $other->id . '.test')
            ->viewData('security');

        $this->assertSame(2, $security['failed_24h']);
        $this->assertCount(2, $security['recent']);
    }

    public function test_the_redesign_still_shows_no_location_or_ip_anywhere(): void
    {
        $ann = $this->employee('Ann');
        AttendanceLog::create([
            'company_id' => $this->company->id, 'employee_id' => $ann->id,
            'office_id' => $this->office->id, 'type' => 'in',
            'scanned_at' => now(), 'work_date' => now()->toDateString(),
            'status' => 'late', 'source' => 'button',
            'latitude' => 40.7128, 'longitude' => -74.0060, 'ip_address' => '198.51.100.23',
        ]);

        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()
            ->assertDontSee('198.51.100.23')
            ->assertDontSee('40.7128')
            ->assertDontSee('google.com/maps', false);
    }
}
