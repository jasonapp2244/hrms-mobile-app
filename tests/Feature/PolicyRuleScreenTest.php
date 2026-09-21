<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\PolicyRule;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The rule builder screen (A2.9, A6.6).
 *
 * `RuleEngineTest` covers what a rule *does*. This covers what may become one,
 * which is the half that decides whether the engine is ever handed something it
 * cannot read: a rule is JSON written through a form, and the form is the only
 * thing between an administrator and a row the engine has to trust.
 *
 * The cases worth the most here are the ones a working form would let through
 * and the engine would then silently refuse — a leave field on a punch rule, a
 * department belonging to another client, `is_not` on a number. Each of those
 * stores fine and never fires, which looks on the list exactly like a rule that
 * is simply waiting for somebody to be late.
 */
class PolicyRuleScreenTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Office $office;
    protected Department $department;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'UTC', 'currency' => 'USD',
        ]);

        $this->office = Office::create([
            'company_id' => $this->company->id, 'name' => 'Head Office',
        ]);

        $this->department = Department::create([
            'company_id' => $this->company->id, 'name' => 'Ops',
        ]);

        $this->admin = $this->account('admin@acme.test', 'admin', $this->company);
    }

    protected function account(string $email, string $role, Company $company): User
    {
        $user = User::create([
            'name'       => ucfirst($role),
            'email'      => $email,
            'password'   => Hash::make('password'),
            'company_id' => $company->id,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /** A well-formed post, which each test then spoils in exactly one way. */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'name'       => 'Late arrivals',
            'trigger'    => PolicyRule::TRIGGER_PUNCH,
            'is_active'  => '1',
            'conditions' => [
                ['field' => 'status', 'operator' => 'is', 'value' => 'late'],
            ],
            'actions'    => [
                ['type' => PolicyRule::ACTION_NOTIFY_MANAGER],
            ],
        ], $overrides);
    }

    protected function rule(array $attributes = []): PolicyRule
    {
        return PolicyRule::create(array_merge([
            'company_id' => $this->company->id,
            'name'       => 'Late arrivals',
            'trigger'    => PolicyRule::TRIGGER_PUNCH,
            'conditions' => [['field' => 'status', 'operator' => 'is', 'value' => 'late']],
            'actions'    => [['type' => PolicyRule::ACTION_LOG]],
            'is_active'  => true,
        ], $attributes));
    }

    // -------------------------------------------------------------------------
    // Who may open it
    // -------------------------------------------------------------------------

    public function test_the_screen_needs_a_sign_in(): void
    {
        $this->get(route('rules.index'))->assertRedirect(route('login'));
    }

    public function test_hr_is_refused(): void
    {
        // Same gate as the policies beside it. HR runs the company's people;
        // who the system speaks to about all of them is the administrator's.
        $this->actingAs($this->account('hr@acme.test', 'hr', $this->company))
            ->get(route('rules.index'))
            ->assertForbidden();
    }

    public function test_an_administrator_sees_the_list(): void
    {
        $this->rule(['name' => 'Night shift arrivals']);

        $this->actingAs($this->admin)
            ->get(route('rules.index'))
            ->assertOk()
            ->assertSee('Night shift arrivals')
            // The conditions read back in words rather than as stored JSON.
            ->assertSee('punch status is Late');
    }

    public function test_the_list_names_a_lookup_rather_than_its_id(): void
    {
        $this->rule([
            'name'       => 'Ops arrivals',
            'conditions' => [['field' => 'department_id', 'operator' => 'is', 'value' => $this->department->id]],
        ]);

        $this->actingAs($this->admin)
            ->get(route('rules.index'))
            ->assertOk()
            ->assertSee('department is Ops');
    }

    // -------------------------------------------------------------------------
    // Writing one
    // -------------------------------------------------------------------------

    public function test_a_rule_is_stored_against_the_signed_in_company(): void
    {
        $this->actingAs($this->admin)
            ->post(route('rules.store'), $this->payload())
            ->assertRedirect(route('rules.index'));

        $rule = PolicyRule::firstOrFail();

        $this->assertSame($this->company->id, $rule->company_id);
        $this->assertSame($this->admin->id, $rule->created_by_user_id);
        $this->assertTrue($rule->is_active);
        $this->assertSame('late', $rule->conditions[0]['value']);
    }

    public function test_a_blank_condition_row_is_dropped_rather_than_refused(): void
    {
        // The form always offers an empty line. Posting it untouched means
        // "no more conditions", which is not a mistake worth an error.
        $this->actingAs($this->admin)
            ->post(route('rules.store'), $this->payload(['conditions' => [
                ['field' => 'status', 'operator' => 'is', 'value' => 'late'],
                ['field' => '', 'operator' => '', 'value' => ''],
            ]]))
            ->assertSessionHasNoErrors();

        $this->assertCount(1, PolicyRule::firstOrFail()->conditions);
    }

    public function test_a_number_is_stored_as_a_number(): void
    {
        // The engine compares loosely, so a string would work. A rule read back
        // out of the column should still say 20 rather than "20" — the next
        // thing to read it may not be this engine.
        $this->actingAs($this->admin)
            ->post(route('rules.store'), $this->payload(['conditions' => [
                ['field' => 'minutes_late', 'operator' => 'at_least', 'value' => '20'],
            ]]))
            ->assertSessionHasNoErrors();

        $this->assertSame(20, PolicyRule::firstOrFail()->conditions[0]['value']);
    }

    public function test_a_rule_with_no_conditions_is_allowed(): void
    {
        // "Every time" is a legible thing to want — a rule on every leave
        // request, say. It is the actions that cannot be empty.
        $this->actingAs($this->admin)
            ->post(route('rules.store'), $this->payload(['conditions' => []]))
            ->assertSessionHasNoErrors();

        $this->assertSame([], PolicyRule::firstOrFail()->conditions);
    }

    public function test_a_rule_that_does_nothing_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('rules.store'), $this->payload(['actions' => []]))
            ->assertSessionHasErrors('actions');

        $this->assertDatabaseCount('policy_rules', 0);
    }

    public function test_notifying_a_role_without_naming_one_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('rules.store'), $this->payload(['actions' => [
                ['type' => PolicyRule::ACTION_NOTIFY_ROLE],
            ]]))
            ->assertSessionHasErrors('actions.0.role');

        $this->assertDatabaseCount('policy_rules', 0);
    }

    public function test_two_rules_may_not_share_a_name_in_one_company(): void
    {
        $this->rule();

        $this->actingAs($this->admin)
            ->post(route('rules.store'), $this->payload())
            ->assertSessionHasErrors('name');
    }

    public function test_another_company_may_use_the_same_name(): void
    {
        $other = Company::create(['name' => 'Other', 'timezone' => 'UTC', 'currency' => 'USD']);
        PolicyRule::create([
            'company_id' => $other->id, 'name' => 'Late arrivals',
            'trigger' => PolicyRule::TRIGGER_PUNCH, 'conditions' => [],
            'actions' => [['type' => PolicyRule::ACTION_LOG]], 'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->post(route('rules.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('policy_rules', 2);
    }

    // -------------------------------------------------------------------------
    // The vocabulary, which is the half the engine has to trust
    // -------------------------------------------------------------------------

    public function test_a_field_from_the_other_trigger_is_refused(): void
    {
        // Stored, this rule would never fire: the engine finds no `days` in a
        // punch context and reads the condition as false. Silent, and
        // indistinguishable on the list from a rule waiting to match.
        $this->actingAs($this->admin)
            ->post(route('rules.store'), $this->payload(['conditions' => [
                ['field' => 'days', 'operator' => 'at_least', 'value' => '5'],
            ]]))
            ->assertSessionHasErrors('conditions.0.field');

        $this->assertDatabaseCount('policy_rules', 0);
    }

    public function test_an_operator_the_field_type_does_not_allow_is_refused(): void
    {
        // `is_not` belongs to choices and lookups. On a number the engine
        // refuses it at read time, so accepting it here would store a rule
        // that can never hold.
        $this->actingAs($this->admin)
            ->post(route('rules.store'), $this->payload(['conditions' => [
                ['field' => 'minutes_late', 'operator' => 'is_not', 'value' => '20'],
            ]]))
            ->assertSessionHasErrors('conditions.0.operator');
    }

    public function test_a_choice_outside_its_list_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('rules.store'), $this->payload(['conditions' => [
                ['field' => 'status', 'operator' => 'is', 'value' => 'absent'],
            ]]))
            ->assertSessionHasErrors('conditions.0.value');
    }

    public function test_a_number_field_refuses_words(): void
    {
        $this->actingAs($this->admin)
            ->post(route('rules.store'), $this->payload(['conditions' => [
                ['field' => 'minutes_late', 'operator' => 'at_least', 'value' => 'twenty'],
            ]]))
            ->assertSessionHasErrors('conditions.0.value');
    }

    public function test_a_lookup_belonging_to_another_company_is_refused(): void
    {
        // The disclosure case. A department id from another client would make a
        // rule that reads somebody else's data, and nothing on the screen would
        // say so — the picker only ever offers this company's.
        $other = Company::create(['name' => 'Other', 'timezone' => 'UTC', 'currency' => 'USD']);
        $theirs = Department::create(['company_id' => $other->id, 'name' => 'Theirs']);

        $this->actingAs($this->admin)
            ->post(route('rules.store'), $this->payload(['conditions' => [
                ['field' => 'department_id', 'operator' => 'is', 'value' => $theirs->id],
            ]]))
            ->assertSessionHasErrors('conditions.0.value');

        $this->assertDatabaseCount('policy_rules', 0);
    }

    public function test_a_leave_rule_may_name_this_companys_leave_type(): void
    {
        $type = LeaveType::create([
            'company_id' => $this->company->id, 'name' => 'Sick Leave', 'days_per_year' => 10,
        ]);

        $this->actingAs($this->admin)
            ->post(route('rules.store'), $this->payload([
                'trigger'    => PolicyRule::TRIGGER_LEAVE,
                'conditions' => [['field' => 'leave_type_id', 'operator' => 'is', 'value' => $type->id]],
                'actions'    => [['type' => PolicyRule::ACTION_NOTIFY_ROLE, 'role' => 'hr']],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame($type->id, PolicyRule::firstOrFail()->conditions[0]['value']);
    }

    // -------------------------------------------------------------------------
    // Editing, switching off, deleting
    // -------------------------------------------------------------------------

    public function test_a_rule_can_be_edited(): void
    {
        $rule = $this->rule();

        $this->actingAs($this->admin)
            ->put(route('rules.update', $rule), $this->payload(['name' => 'Very late arrivals']))
            ->assertRedirect(route('rules.index'));

        $this->assertSame('Very late arrivals', $rule->fresh()->name);
    }

    public function test_an_edit_that_changes_the_trigger_cannot_keep_the_old_fields(): void
    {
        $rule = $this->rule();

        $this->actingAs($this->admin)
            ->put(route('rules.update', $rule), $this->payload([
                'trigger'    => PolicyRule::TRIGGER_LEAVE,
                'conditions' => [['field' => 'status', 'operator' => 'is', 'value' => 'late']],
            ]))
            ->assertSessionHasErrors('conditions.0.field');

        // Unchanged: a refused edit must not half-apply.
        $this->assertSame(PolicyRule::TRIGGER_PUNCH, $rule->fresh()->trigger);
    }

    public function test_a_rule_can_be_switched_off_and_on_again(): void
    {
        $rule = $this->rule();

        $this->actingAs($this->admin)->post(route('rules.toggle', $rule));
        $this->assertFalse($rule->fresh()->is_active);

        // The wording survives being switched off, which is the whole reason
        // this is not a delete.
        $this->assertNotEmpty($rule->fresh()->conditions);

        $this->actingAs($this->admin)->post(route('rules.toggle', $rule));
        $this->assertTrue($rule->fresh()->is_active);
    }

    public function test_a_rule_can_be_deleted(): void
    {
        $rule = $this->rule();

        $this->actingAs($this->admin)->delete(route('rules.destroy', $rule));

        $this->assertDatabaseCount('policy_rules', 0);
    }

    public function test_an_edit_leaves_the_activity_trail_a_line(): void
    {
        $this->actingAs($this->admin)->post(route('rules.store'), $this->payload());

        $this->assertDatabaseHas('activity_logs', [
            'event'       => ActivityLog::SETTINGS_CHANGED,
            'description' => 'Policy rule created: Late arrivals',
        ]);
    }

    // -------------------------------------------------------------------------
    // Somebody else's rule
    // -------------------------------------------------------------------------

    public function test_another_companys_rule_is_out_of_reach(): void
    {
        $other = Company::create(['name' => 'Other', 'timezone' => 'UTC', 'currency' => 'USD']);
        $theirs = PolicyRule::create([
            'company_id' => $other->id, 'name' => 'Theirs',
            'trigger' => PolicyRule::TRIGGER_PUNCH, 'conditions' => [],
            'actions' => [['type' => PolicyRule::ACTION_LOG]], 'is_active' => true,
        ]);

        $this->actingAs($this->admin)->get(route('rules.edit', $theirs))->assertForbidden();
        $this->actingAs($this->admin)->put(route('rules.update', $theirs), $this->payload())->assertForbidden();
        $this->actingAs($this->admin)->post(route('rules.toggle', $theirs))->assertForbidden();
        $this->actingAs($this->admin)->delete(route('rules.destroy', $theirs))->assertForbidden();

        $this->assertDatabaseHas('policy_rules', ['id' => $theirs->id, 'name' => 'Theirs']);
    }

    public function test_the_list_shows_only_this_companys_rules(): void
    {
        $other = Company::create(['name' => 'Other', 'timezone' => 'UTC', 'currency' => 'USD']);
        PolicyRule::create([
            'company_id' => $other->id, 'name' => 'Somebody elses rule',
            'trigger' => PolicyRule::TRIGGER_PUNCH, 'conditions' => [],
            'actions' => [['type' => PolicyRule::ACTION_LOG]], 'is_active' => true,
        ]);
        $this->rule(['name' => 'Ours']);

        $this->actingAs($this->admin)
            ->get(route('rules.index'))
            ->assertOk()
            ->assertSee('Ours')
            ->assertDontSee('Somebody elses rule');
    }

    // -------------------------------------------------------------------------
    // The form itself
    //
    // The builder is drawn by the browser from the vocabulary the controller
    // hands it, so a Blade or JSON mistake here is a blank page rather than a
    // wrong value. These two are what notice that.
    // -------------------------------------------------------------------------

    public function test_the_new_rule_form_carries_the_vocabulary(): void
    {
        $this->actingAs($this->admin)
            ->get(route('rules.create'))
            ->assertOk()
            ->assertSee('When somebody clocks in or out')
            ->assertSee('When somebody requests leave')
            // The field and action lists reach the page as JSON for the script
            // that draws the rows.
            ->assertSee('minutes_late')
            ->assertSee(PolicyRule::ACTION_NOTIFY_MANAGER)
            // And this company's departments, for a lookup condition.
            ->assertSee('Ops');
    }

    public function test_the_edit_form_carries_the_rule_it_is_editing(): void
    {
        $rule = $this->rule(['name' => 'Night shift arrivals']);

        $this->actingAs($this->admin)
            ->get(route('rules.edit', $rule))
            ->assertOk()
            ->assertSee('Night shift arrivals')
            // The stored conditions are seeded into the script as raw JSON, so
            // an edit opens on what was saved rather than on an empty builder.
            ->assertSee('"field":"status"', false);
    }

    public function test_the_form_offers_no_other_companys_departments(): void
    {
        $other = Company::create(['name' => 'Other', 'timezone' => 'UTC', 'currency' => 'USD']);
        Department::create(['company_id' => $other->id, 'name' => 'Their Depot']);

        $this->actingAs($this->admin)
            ->get(route('rules.create'))
            ->assertOk()
            ->assertDontSee('Their Depot');
    }
}
