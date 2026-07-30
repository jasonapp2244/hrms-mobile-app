<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Office;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftSwapRequest;
use App\Models\User;
use App\Services\RosterService;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shift swaps: the colleague agrees, then a manager or HR sanctions it, and only
 * then does the roster move.
 */
class ShiftSwapTest extends TestCase
{
    use RefreshDatabase;

    protected const MON = '2026-08-03';
    protected const TUE = '2026-08-04';
    protected const WED = '2026-08-05';

    protected Company $company;
    protected Office $office;
    protected Shift $day;
    protected Shift $night;

    protected User $annUser;
    protected Employee $ann;      // requester
    protected User $bobUser;
    protected Employee $bob;      // colleague
    protected User $miaUser;
    protected Employee $mia;      // manager of both

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->travelTo(Carbon::parse('2026-07-27 09:00:00'));

        $this->company = Company::create(['name' => 'Acme', 'timezone' => 'UTC']);
        $this->office = Office::create(['company_id' => $this->company->id, 'name' => 'HQ', 'code' => 'HQ']);

        $this->day = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Day', 'code' => 'D',
            'start_time' => '09:00:00', 'end_time' => '17:00:00',
        ]);
        $this->night = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Night', 'code' => 'N',
            'start_time' => '22:00:00', 'end_time' => '06:00:00',
        ]);

        $department = Department::create([
            'company_id' => $this->company->id, 'name' => 'Ops', 'shift_id' => $this->day->id,
        ]);

        [$this->annUser, $this->ann] = $this->makePerson('Ann', 'E1', ['employee'], $department->id);
        [$this->bobUser, $this->bob] = $this->makePerson('Bob', 'E2', ['employee'], $department->id);
        [$this->miaUser, $this->mia] = $this->makePerson('Mia', 'E3', ['employee', 'manager'], $department->id);

        $this->ann->update(['manager_id' => $this->mia->id]);
        $this->bob->update(['manager_id' => $this->mia->id]);
    }

    /** @return array{0: User, 1: Employee} */
    protected function makePerson(string $name, string $code, array $roles, ?int $departmentId = null): array
    {
        $user = User::create([
            'name' => $name, 'email' => strtolower($name) . uniqid() . '@test.local',
            'password' => 'password', 'company_id' => $this->company->id,
        ]);
        foreach ($roles as $role) {
            $user->assignRole($role);
        }

        $employee = Employee::create([
            'company_id' => $this->company->id, 'office_id' => $this->office->id,
            'department_id' => $departmentId, 'user_id' => $user->id,
            'employee_code' => $code, 'first_name' => $name, 'status' => 'active',
        ]);

        return [$user, $employee];
    }

    protected function roster(): RosterService
    {
        return app(RosterService::class);
    }

    protected function staff(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role), 'email' => $role . uniqid() . '@test.local',
            'password' => 'password', 'company_id' => $this->company->id,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /** Ann on Monday, Bob on Tuesday, both rostered off the other's day. */
    protected function planCrossDate(): void
    {
        $this->roster()->setDay($this->ann, self::MON, $this->day->id);
        $this->roster()->setDay($this->ann, self::TUE, 'off');
        $this->roster()->setDay($this->bob, self::TUE, $this->night->id);
        $this->roster()->setDay($this->bob, self::MON, 'off');
    }

    protected function makeSwap(): ShiftSwapRequest
    {
        $this->planCrossDate();

        return $this->roster()->requestSwap($this->ann, self::MON, $this->bob, self::TUE, 'family thing');
    }

    // ================= raising =================

    public function test_a_swap_starts_waiting_on_the_colleague(): void
    {
        $swap = $this->makeSwap();

        $this->assertSame('pending', $swap->status);
        $this->assertTrue($swap->isAwaitingColleague());
        $this->assertSame('Awaiting Colleague', $swap->status_label);
    }

    public function test_a_day_the_requester_does_not_work_cannot_be_swapped(): void
    {
        $this->planCrossDate();

        // Ann is rostered off on the Tuesday — there is nothing to give up.
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->roster()->requestSwap($this->ann, self::TUE, $this->bob, self::TUE);
    }

    public function test_a_day_the_colleague_does_not_work_cannot_be_asked_for(): void
    {
        $this->planCrossDate();

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->roster()->requestSwap($this->ann, self::MON, $this->bob, self::MON);
    }

    public function test_a_swap_that_would_double_book_the_requester_is_refused(): void
    {
        // Both work Monday and Tuesday, so taking Bob's Tuesday would leave Ann
        // on two shifts that day.
        $this->roster()->setDay($this->ann, self::MON, $this->day->id);
        $this->roster()->setDay($this->ann, self::TUE, $this->day->id);
        $this->roster()->setDay($this->bob, self::TUE, $this->night->id);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->roster()->requestSwap($this->ann, self::MON, $this->bob, self::TUE);
    }

    public function test_nobody_can_swap_with_themselves(): void
    {
        $this->roster()->setDay($this->ann, self::MON, $this->day->id);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->roster()->requestSwap($this->ann, self::MON, $this->ann, self::MON);
    }

    public function test_a_second_open_swap_for_the_same_day_is_refused(): void
    {
        $this->makeSwap();

        $this->roster()->setDay($this->mia, self::WED, $this->day->id);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->roster()->requestSwap($this->ann, self::MON, $this->mia, self::WED);
    }

    public function test_a_colleague_from_another_company_is_refused(): void
    {
        $other = Company::create(['name' => 'Globex', 'timezone' => 'UTC']);
        $outsider = Employee::create([
            'company_id' => $other->id, 'employee_code' => 'Z1', 'first_name' => 'Zed',
        ]);

        $this->roster()->setDay($this->ann, self::MON, $this->day->id);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->roster()->requestSwap($this->ann, self::MON, $outsider, self::MON);
    }

    // ================= the colleague's response =================

    public function test_the_colleague_can_accept(): void
    {
        $swap = $this->makeSwap();

        $this->actingAs($this->bobUser)
            ->post("/employee/swaps/{$swap->id}/accept")
            ->assertRedirect();

        $this->assertSame('accepted', $swap->fresh()->status);
        // Agreeing is not approval: nothing moves yet.
        $this->assertSame($this->day->id, $this->ann->fresh()->shiftOn(self::MON)->id);
    }

    public function test_the_colleague_can_decline(): void
    {
        $swap = $this->makeSwap();

        $this->actingAs($this->bobUser)
            ->post("/employee/swaps/{$swap->id}/decline", ['response_note' => 'sorry, busy'])
            ->assertRedirect();

        $this->assertSame('declined', $swap->fresh()->status);
        $this->assertSame('sorry, busy', $swap->fresh()->response_note);
    }

    public function test_only_the_named_colleague_can_respond(): void
    {
        $swap = $this->makeSwap();

        $this->actingAs($this->miaUser)
            ->post("/employee/swaps/{$swap->id}/accept")
            ->assertForbidden();

        $this->assertSame('pending', $swap->fresh()->status);
    }

    public function test_the_requester_can_withdraw_before_approval(): void
    {
        $swap = $this->makeSwap();

        $this->actingAs($this->annUser)
            ->post("/employee/swaps/{$swap->id}/cancel")
            ->assertRedirect();

        $this->assertSame('cancelled', $swap->fresh()->status);
    }

    public function test_the_colleague_cannot_withdraw_the_requesters_swap(): void
    {
        $swap = $this->makeSwap();

        $this->actingAs($this->bobUser)
            ->post("/employee/swaps/{$swap->id}/cancel")
            ->assertForbidden();
    }

    // ================= approval moves the roster =================

    public function test_a_cross_date_swap_exchanges_both_days(): void
    {
        $swap = $this->makeSwap();
        $this->roster()->acceptSwap($swap);

        $this->roster()->approveSwap($swap->fresh(), $this->miaUser->id);

        $ann = $this->ann->fresh();
        $bob = $this->bob->fresh();

        // Ann gives up Monday and takes Bob's Tuesday night.
        $this->assertNull($ann->shiftOn(self::MON));
        $this->assertSame($this->night->id, $ann->shiftOn(self::TUE)->id);
        // Bob gives up Tuesday and takes Ann's Monday day shift.
        $this->assertSame($this->day->id, $bob->shiftOn(self::MON)->id);
        $this->assertNull($bob->shiftOn(self::TUE));
    }

    public function test_a_same_day_swap_trades_shifts_without_days_off(): void
    {
        $this->roster()->setDay($this->ann, self::MON, $this->day->id);
        $this->roster()->setDay($this->bob, self::MON, $this->night->id);

        $swap = $this->roster()->requestSwap($this->ann, self::MON, $this->bob, self::MON);
        $this->roster()->acceptSwap($swap);
        $this->roster()->approveSwap($swap->fresh(), $this->miaUser->id);

        // Both still work; they have simply traded.
        $this->assertSame($this->night->id, $this->ann->fresh()->shiftOn(self::MON)->id);
        $this->assertSame($this->day->id, $this->bob->fresh()->shiftOn(self::MON)->id);
    }

    public function test_an_approved_swap_is_published_immediately(): void
    {
        $swap = $this->makeSwap();
        $this->roster()->acceptSwap($swap);
        $this->roster()->approveSwap($swap->fresh(), $this->miaUser->id);

        // The two people affected have already agreed, so there is nothing to
        // stage — the days they traded are visible at once.
        $touched = ShiftAssignment::whereIn('employee_id', [$this->ann->id, $this->bob->id])
            ->where(fn ($q) => $q->whereDate('date', self::MON)->orWhereDate('date', self::TUE))
            ->get();

        $this->assertCount(4, $touched);
        $this->assertTrue($touched->every(fn ($a) => $a->published_at !== null));
    }

    public function test_a_swap_cannot_be_approved_before_the_colleague_accepts(): void
    {
        $swap = $this->makeSwap();

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->roster()->approveSwap($swap, $this->miaUser->id);
    }

    public function test_a_swap_is_refused_if_the_roster_moved_underneath_it(): void
    {
        $swap = $this->makeSwap();
        $this->roster()->acceptSwap($swap);

        // The plan is regenerated and Ann's Monday is no longer a working day.
        $this->roster()->setDay($this->ann, self::MON, 'off');

        // Applying it to whatever now sits there would be worse than refusing.
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->roster()->approveSwap($swap->fresh(), $this->miaUser->id);
    }

    public function test_an_approved_swap_cannot_be_approved_twice(): void
    {
        $swap = $this->makeSwap();
        $this->roster()->acceptSwap($swap);
        $this->roster()->approveSwap($swap->fresh(), $this->miaUser->id);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->roster()->approveSwap($swap->fresh(), $this->miaUser->id);
    }

    // ================= who may approve =================

    public function test_a_manager_can_approve_a_swap_between_their_reports(): void
    {
        $swap = $this->makeSwap();
        $this->roster()->acceptSwap($swap);

        $this->actingAs($this->miaUser)
            ->post("/employee/swaps/{$swap->id}/approve")
            ->assertRedirect();

        $this->assertSame('approved', $swap->fresh()->status);
    }

    public function test_a_manager_cannot_approve_a_swap_they_are_part_of(): void
    {
        // Mia manages Ann, but she is standing in this trade herself.
        $this->roster()->setDay($this->ann, self::MON, $this->day->id);
        $this->roster()->setDay($this->mia, self::MON, $this->night->id);

        $swap = $this->roster()->requestSwap($this->ann, self::MON, $this->mia, self::MON);
        $this->roster()->acceptSwap($swap);

        $this->actingAs($this->miaUser)
            ->post("/employee/swaps/{$swap->id}/approve")
            ->assertForbidden();

        $this->assertSame('accepted', $swap->fresh()->status);
    }

    public function test_a_manager_cannot_approve_a_swap_outside_their_team(): void
    {
        [$otherMgrUser, $otherMgr] = $this->makePerson('Otto', 'E9', ['employee', 'manager']);

        $swap = $this->makeSwap();
        $this->roster()->acceptSwap($swap);

        $this->actingAs($otherMgrUser)
            ->post("/employee/swaps/{$swap->id}/approve")
            ->assertForbidden();
    }

    public function test_a_plain_employee_cannot_approve(): void
    {
        $swap = $this->makeSwap();
        $this->roster()->acceptSwap($swap);

        // Bob is named on it, which lets him accept but never sanction.
        $this->actingAs($this->bobUser)
            ->post("/employee/swaps/{$swap->id}/approve")
            ->assertForbidden();
    }

    public function test_a_manager_rejection_requires_a_reason(): void
    {
        $swap = $this->makeSwap();
        $this->roster()->acceptSwap($swap);

        $this->actingAs($this->miaUser)
            ->post("/employee/swaps/{$swap->id}/reject", ['decision_note' => ''])
            ->assertSessionHasErrors('decision_note');

        $this->assertSame('accepted', $swap->fresh()->status);
    }

    // ================= the HR register =================

    public function test_hr_can_view_the_swap_register(): void
    {
        $this->actingAs($this->staff('hr'))->get('/shift-swaps')->assertOk();
    }

    public function test_an_employee_cannot_view_the_swap_register(): void
    {
        $this->actingAs($this->annUser)->get('/shift-swaps')->assertForbidden();
    }

    public function test_hr_can_approve_a_swap_across_teams(): void
    {
        // The reason HR needs this at all: two people whose managers differ.
        $swap = $this->makeSwap();
        $this->roster()->acceptSwap($swap);

        $this->actingAs($this->staff('hr'))
            ->post("/shift-swaps/{$swap->id}/approve")
            ->assertRedirect();

        $this->assertSame('approved', $swap->fresh()->status);
        $this->assertSame($this->night->id, $this->ann->fresh()->shiftOn(self::TUE)->id);
    }

    public function test_hr_cannot_approve_another_companys_swap(): void
    {
        $swap = $this->makeSwap();
        $this->roster()->acceptSwap($swap);

        $other = Company::create(['name' => 'Globex', 'timezone' => 'UTC']);
        $swap->update(['company_id' => $other->id]);

        $this->actingAs($this->staff('hr'))
            ->post("/shift-swaps/{$swap->id}/approve")
            ->assertForbidden();
    }

    // ================= screens =================

    public function test_the_portal_lists_a_swap_waiting_on_you(): void
    {
        $this->makeSwap();

        $response = $this->actingAs($this->bobUser)->get('/employee/swaps')->assertOk();

        $this->assertCount(1, $response->viewData('incoming'));
        $this->assertCount(0, $response->viewData('mine'));
    }

    public function test_the_portal_lists_your_own_requests(): void
    {
        $this->makeSwap();

        $response = $this->actingAs($this->annUser)->get('/employee/swaps')->assertOk();

        $this->assertCount(1, $response->viewData('mine'));
        $this->assertCount(0, $response->viewData('incoming'));
    }

    public function test_the_manager_inbox_shows_agreed_swaps(): void
    {
        $swap = $this->makeSwap();
        $this->roster()->acceptSwap($swap);

        $response = $this->actingAs($this->miaUser)->get('/employee/approvals')->assertOk();

        $this->assertCount(1, $response->viewData('swaps'));
    }

    public function test_the_manager_inbox_hides_swaps_still_waiting_on_a_colleague(): void
    {
        $this->makeSwap();

        $response = $this->actingAs($this->miaUser)->get('/employee/approvals')->assertOk();

        $this->assertCount(0, $response->viewData('swaps'));
    }

    public function test_the_swap_form_creates_a_request(): void
    {
        $this->planCrossDate();

        $this->actingAs($this->annUser)
            ->post('/employee/swaps', [
                'requester_date' => self::MON,
                'target_id'      => $this->bob->id,
                'target_date'    => self::TUE,
                'reason'         => 'appointment',
            ])
            ->assertRedirect();

        $this->assertSame(1, ShiftSwapRequest::count());
        $this->assertSame('appointment', ShiftSwapRequest::first()->reason);
    }

    public function test_the_swap_form_surfaces_a_rule_failure_on_the_form(): void
    {
        $this->planCrossDate();

        $this->actingAs($this->annUser)
            ->post('/employee/swaps', [
                'requester_date' => self::TUE,   // Ann is off that day
                'target_id'      => $this->bob->id,
                'target_date'    => self::TUE,
            ])
            ->assertSessionHasErrors('requester_date');

        $this->assertSame(0, ShiftSwapRequest::count());
    }
}
