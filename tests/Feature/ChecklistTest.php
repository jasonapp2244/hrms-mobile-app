<?php

namespace Tests\Feature;

use App\Models\ChecklistTemplate;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeChecklistItem;
use App\Models\Office;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A3.12 — joining and leaving checklists.
 *
 * The design decision under test is that raising a checklist *copies* the
 * template. A checklist is a record of what somebody was asked to do; editing
 * this year's template must not rewrite last year's history, and deleting one
 * must not blank the leaver record an audit will ask about.
 */
class ChecklistTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Office $office;
    protected Employee $employee;
    protected User $hr;
    protected User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'UTC', 'currency' => 'USD',
        ]);
        $this->office = Office::create(['company_id' => $this->company->id, 'name' => 'Head Office']);

        $this->employee = Employee::create([
            'company_id' => $this->company->id, 'office_id' => $this->office->id,
            'employee_code' => 'E1', 'first_name' => 'Ann', 'last_name' => 'Lee',
            'status' => 'active', 'hire_date' => '2026-09-01',
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

    private function template(array $overrides = []): ChecklistTemplate
    {
        static $n = 0;
        $n++;

        return ChecklistTemplate::create(array_merge([
            'company_id' => $this->company->id, 'kind' => 'onboarding',
            'title' => 'Step ' . $n, 'due_offset_days' => 0,
            'position' => $n, 'is_active' => true,
        ], $overrides));
    }

    private function raise(string $kind = 'onboarding', ?string $anchor = null)
    {
        return $this->actingAs($this->hr)->post(
            route('checklists.generate', $this->employee),
            array_filter(['kind' => $kind, 'anchor_date' => $anchor]),
        );
    }

    // -------------------------------------------------------------------------
    // The standard steps
    // -------------------------------------------------------------------------

    public function test_hr_can_add_a_step(): void
    {
        $this->actingAs($this->hr)->post(route('checklists.templates.store'), [
            'kind' => 'offboarding', 'title' => 'Revoke building access',
            'owner' => 'Facilities', 'due_offset_days' => 0, 'is_active' => 1,
        ])->assertRedirect();

        $this->assertDatabaseHas('checklist_templates', ['title' => 'Revoke building access']);
    }

    public function test_new_steps_are_appended_rather_than_inserted_first(): void
    {
        // A checklist is read in order; a new step landing at the top would
        // reorder everybody's list.
        $first = $this->template(['title' => 'First']);

        $this->actingAs($this->hr)->post(route('checklists.templates.store'), [
            'kind' => 'onboarding', 'title' => 'Second', 'due_offset_days' => 0,
        ]);

        $second = ChecklistTemplate::where('title', 'Second')->firstOrFail();

        $this->assertGreaterThan($first->position, $second->position);
    }

    public function test_the_timing_reads_in_words(): void
    {
        $this->assertSame('On the day', $this->template(['due_offset_days' => 0])->timing);
        $this->assertSame('3 days before', $this->template(['due_offset_days' => -3])->timing);
        $this->assertSame('1 day after', $this->template(['due_offset_days' => 1])->timing);
        $this->assertSame('2 weeks after', $this->template(['due_offset_days' => 14])->timing);
    }

    public function test_an_employee_cannot_touch_the_templates(): void
    {
        $this->actingAs($this->staff)->get(route('checklists.templates'))->assertForbidden();
        $this->actingAs($this->staff)->post(route('checklists.templates.store'), [
            'kind' => 'onboarding', 'title' => 'Sneaky', 'due_offset_days' => 0,
        ])->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Raising somebody's list
    // -------------------------------------------------------------------------

    public function test_raising_a_checklist_copies_the_current_steps(): void
    {
        $this->template(['title' => 'Issue laptop', 'owner' => 'IT', 'due_offset_days' => -3]);

        $this->raise('onboarding', '2026-09-01')->assertRedirect();

        $item = EmployeeChecklistItem::firstOrFail();

        $this->assertSame('Issue laptop', $item->title);
        $this->assertSame('IT', $item->owner);
        $this->assertSame('2026-08-29', $item->due_on->toDateString());
    }

    public function test_editing_a_template_does_not_rewrite_a_raised_checklist(): void
    {
        // The whole reason the item carries its own copy.
        $template = $this->template(['title' => 'Old wording']);
        $this->raise();

        $template->update(['title' => 'New wording']);

        $this->assertSame('Old wording', EmployeeChecklistItem::firstOrFail()->title);
    }

    public function test_deleting_a_template_leaves_raised_checklists_intact(): void
    {
        // An unticked "revoke building access" against a leaver is exactly what
        // an audit asks about; a cascade would erase it.
        $template = $this->template(['title' => 'Revoke access', 'kind' => 'offboarding']);
        $this->raise('offboarding', '2026-12-01');

        $this->actingAs($this->hr)->delete(route('checklists.templates.destroy', $template))->assertRedirect();

        $item = EmployeeChecklistItem::firstOrFail();

        $this->assertSame('Revoke access', $item->title);
        $this->assertNull($item->checklist_template_id);
    }

    public function test_raising_twice_does_not_duplicate_steps(): void
    {
        $this->template();

        $this->raise();
        $this->raise();

        $this->assertSame(1, EmployeeChecklistItem::count());
    }

    public function test_a_new_standard_step_can_be_added_to_a_list_in_progress(): void
    {
        $this->template(['title' => 'First']);
        $this->raise();

        $this->template(['title' => 'Added later']);
        $this->raise();

        $this->assertSame(2, EmployeeChecklistItem::count());
        $this->assertDatabaseHas('employee_checklist_items', ['title' => 'Added later']);
    }

    public function test_no_anchor_date_means_no_due_date_rather_than_a_wrong_one(): void
    {
        $this->template(['kind' => 'offboarding', 'due_offset_days' => -3]);

        // Nothing records a leaving date, so none was given.
        $this->raise('offboarding');

        $this->assertNull(EmployeeChecklistItem::firstOrFail()->due_on);
    }

    public function test_onboarding_falls_back_to_the_hire_date(): void
    {
        $this->template(['due_offset_days' => 0]);

        $this->raise('onboarding');

        $this->assertSame('2026-09-01', EmployeeChecklistItem::firstOrFail()->due_on->toDateString());
    }

    public function test_a_paused_step_is_not_raised(): void
    {
        $this->template(['title' => 'Retired step', 'is_active' => false]);

        $this->raise();

        $this->assertSame(0, EmployeeChecklistItem::count());
    }

    public function test_raising_with_no_steps_set_up_says_so(): void
    {
        $this->raise()->assertSessionHas('error');

        $this->assertSame(0, EmployeeChecklistItem::count());
    }

    // -------------------------------------------------------------------------
    // Ticking off
    // -------------------------------------------------------------------------

    public function test_a_step_can_be_ticked_and_records_who_did_it(): void
    {
        $this->template();
        $this->raise();

        $item = EmployeeChecklistItem::firstOrFail();

        $this->actingAs($this->hr)
            ->post(route('checklists.toggle', [$this->employee, $item]))
            ->assertRedirect();

        $item->refresh();

        $this->assertTrue($item->isDone());
        $this->assertSame($this->hr->id, $item->completed_by_user_id);
    }

    public function test_a_step_can_be_reopened(): void
    {
        $this->template();
        $this->raise();
        $item = EmployeeChecklistItem::firstOrFail();

        $this->actingAs($this->hr)->post(route('checklists.toggle', [$this->employee, $item]));
        $this->actingAs($this->hr)->post(route('checklists.toggle', [$this->employee, $item]));

        $item->refresh();

        $this->assertFalse($item->isDone());
        $this->assertNull($item->completed_by_user_id);
    }

    public function test_ticking_a_step_is_written_to_the_trail(): void
    {
        // "Who marked the door card revoked" is the question this exists for.
        $this->template(['title' => 'Revoke access']);
        $this->raise();
        $item = EmployeeChecklistItem::firstOrFail();

        $this->actingAs($this->hr)->post(route('checklists.toggle', [$this->employee, $item]));

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $this->hr->id,
            'description' => "Checklist step \"Revoke access\" completed for Ann Lee",
        ]);
    }

    public function test_overdue_is_only_reported_for_outstanding_steps(): void
    {
        // A permanently red tick against a finished task teaches people to
        // ignore the colour.
        Carbon::setTestNow('2026-08-07');

        $this->template(['due_offset_days' => 0]);
        $this->raise('onboarding', '2026-08-01');

        $item = EmployeeChecklistItem::firstOrFail();
        $this->assertTrue($item->isOverdue());

        $this->actingAs($this->hr)->post(route('checklists.toggle', [$this->employee, $item]));

        $this->assertFalse($item->fresh()->isOverdue());
    }

    public function test_a_step_with_no_date_is_never_overdue(): void
    {
        Carbon::setTestNow('2026-08-07');

        $this->template(['kind' => 'offboarding']);
        $this->raise('offboarding');

        $this->assertFalse(EmployeeChecklistItem::firstOrFail()->isOverdue());
    }

    // -------------------------------------------------------------------------
    // Scoping
    // -------------------------------------------------------------------------

    public function test_another_companys_employee_cannot_be_reached(): void
    {
        $other = Company::create(['name' => 'Other', 'timezone' => 'UTC', 'currency' => 'USD']);
        $otherOffice = Office::create(['company_id' => $other->id, 'name' => 'Remote']);
        $theirs = Employee::create([
            'company_id' => $other->id, 'office_id' => $otherOffice->id,
            'employee_code' => 'X1', 'first_name' => 'Sam', 'last_name' => 'Poe',
            'status' => 'active',
        ]);

        $this->actingAs($this->hr)->get(route('checklists.employee', $theirs))->assertForbidden();
    }

    public function test_a_checklist_item_from_another_employee_cannot_be_ticked(): void
    {
        $this->template();
        $this->raise();
        $item = EmployeeChecklistItem::firstOrFail();

        $someoneElse = Employee::create([
            'company_id' => $this->company->id, 'office_id' => $this->office->id,
            'employee_code' => 'E2', 'first_name' => 'Bo', 'last_name' => 'Kim',
            'status' => 'active',
        ]);

        $this->actingAs($this->hr)
            ->post(route('checklists.toggle', [$someoneElse, $item]))
            ->assertNotFound();
    }

    public function test_hr_can_open_the_screens(): void
    {
        $this->template(['title' => 'Issue laptop']);
        $this->raise();

        $this->actingAs($this->hr)->get(route('checklists.templates'))
            ->assertOk()->assertSee('Issue laptop');

        $this->actingAs($this->hr)->get(route('checklists.employee', $this->employee))
            ->assertOk()->assertSee('Issue laptop');
    }
}
