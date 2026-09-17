<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Booking, tracking and withdrawing leave from the app, and the manager inbox.
 *
 * The rules themselves belong to LeaveService and are covered by its own tests.
 * What matters here is that the API reaches them rather than reimplementing
 * them, and that a token never becomes access to somebody else's leave.
 *
 * Day counts are compared with assertEquals rather than assertSame: JSON has a
 * single number type, so 3.0 arrives as 3 while 0.5 stays 0.5.
 */
class LeaveApiTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Department $department;
    protected User $user;
    protected Employee $employee;
    protected LeaveType $annual;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'UTC', 'currency' => 'USD',
        ]);

        $this->department = Department::create([
            'company_id' => $this->company->id, 'name' => 'Ops',
        ]);

        $this->user = User::create([
            'name' => 'Ann Lee', 'email' => 'ann@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $this->user->assignRole('employee');

        $this->employee = Employee::create([
            'company_id' => $this->company->id, 'department_id' => $this->department->id,
            'user_id' => $this->user->id, 'employee_code' => 'E1',
            'first_name' => 'Ann', 'last_name' => 'Lee', 'status' => 'active',
        ]);

        $this->annual = LeaveType::create([
            'company_id' => $this->company->id, 'name' => 'Annual', 'code' => 'AL',
            'days_per_year' => 20, 'is_paid' => true, 'requires_approval' => true,
            'allow_half_day' => true, 'is_active' => true,
        ]);

        // A Monday, so a plain week of leave does not trip over a weekend.
        $this->travelTo(Carbon::parse('2026-08-03 09:00:00'));

        Sanctum::actingAs($this->user);
    }

    protected function apply(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/leave/requests', array_merge([
            'leave_type_id' => $this->annual->id,
            'start_date'    => '2026-08-10',
            'end_date'      => '2026-08-12',
        ], $overrides));
    }

    // ================= balances =================

    public function test_balances_list_every_bookable_type(): void
    {
        $response = $this->getJson('/api/v1/leave/balances')->assertOk();

        $this->assertSame(2026, $response->json('year'));
        $this->assertSame('Annual', $response->json('balances.0.name'));
        $this->assertEquals(20, $response->json('balances.0.entitled_days'));
        $this->assertEquals(20, $response->json('balances.0.available_days'));
        $this->assertTrue($response->json('balances.0.is_capped'));
    }

    public function test_an_inactive_type_is_not_offered(): void
    {
        LeaveType::create([
            'company_id' => $this->company->id, 'name' => 'Retired Type',
            'days_per_year' => 5, 'is_active' => false,
        ]);

        // History keeps it; the apply form must not.
        $names = collect($this->getJson('/api/v1/leave/balances')->json('balances'))->pluck('name');
        $this->assertFalse($names->contains('Retired Type'));
    }

    public function test_an_uncapped_type_is_flagged_rather_than_shown_as_empty(): void
    {
        LeaveType::create([
            'company_id' => $this->company->id, 'name' => 'Unpaid',
            'days_per_year' => 0, 'is_paid' => false, 'requires_approval' => true, 'is_active' => true,
        ]);

        $unpaid = collect($this->getJson('/api/v1/leave/balances')->json('balances'))
            ->firstWhere('name', 'Unpaid');

        // Zero days means "no cap", not "nothing left" — the app must not grey it out.
        $this->assertFalse($unpaid['is_capped']);
    }

    public function test_a_pending_request_spends_nothing(): void
    {
        $this->apply()->assertStatus(201);

        // Only a decision commits days; a request awaiting one holds no balance.
        $this->assertEquals(0, (float) LeaveBalance::first()->used_days);
    }

    public function test_balances_are_only_ever_the_callers_own(): void
    {
        $other = $this->colleague();
        LeaveBalance::create([
            'employee_id' => $other->id, 'leave_type_id' => $this->annual->id,
            'year' => 2026, 'entitled_days' => 20, 'used_days' => 15,
        ]);

        $this->assertEquals(20, $this->getJson('/api/v1/leave/balances')->json('balances.0.available_days'));
    }

    // ================= applying =================

    public function test_leave_can_be_booked(): void
    {
        $response = $this->apply()->assertStatus(201);

        $this->assertSame('pending', $response->json('request.status'));
        $this->assertEquals(3, $response->json('request.days'));
        $this->assertSame(1, LeaveRequest::count());
    }

    public function test_the_server_counts_the_days_not_the_app(): void
    {
        // Fri 7th to Mon 10th spans a weekend: two chargeable days, not four.
        $response = $this->apply([
            'start_date' => '2026-08-07', 'end_date' => '2026-08-10', 'days' => 4,
        ])->assertStatus(201);

        $this->assertEquals(2, $response->json('request.days'));
    }

    public function test_a_company_holiday_inside_a_range_is_free(): void
    {
        Holiday::create([
            'company_id' => $this->company->id, 'name' => 'Founders Day',
            'date' => '2026-08-11', 'is_recurring' => false,
        ]);

        $this->assertEquals(2, $this->apply()->json('request.days'));
    }

    public function test_a_type_needing_no_approval_is_granted_on_the_spot(): void
    {
        $casual = LeaveType::create([
            'company_id' => $this->company->id, 'name' => 'Casual',
            'days_per_year' => 5, 'requires_approval' => false, 'is_active' => true,
        ]);

        $response = $this->apply(['leave_type_id' => $casual->id]);

        // Nobody has to make this decision, so the employee is not left waiting.
        $this->assertSame('approved', $response->json('request.status'));
    }

    public function test_overlapping_your_own_leave_is_refused(): void
    {
        $this->apply()->assertStatus(201);

        $this->apply(['start_date' => '2026-08-11', 'end_date' => '2026-08-14'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_failed')
            ->assertJsonStructure(['errors' => ['start_date']]);
    }

    public function test_booking_more_than_the_balance_is_refused(): void
    {
        LeaveBalance::create([
            'employee_id' => $this->employee->id, 'leave_type_id' => $this->annual->id,
            'year' => 2026, 'entitled_days' => 2, 'used_days' => 0,
        ]);

        $this->apply(['start_date' => '2026-08-10', 'end_date' => '2026-08-14'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['leave_type_id']]);
    }

    public function test_a_range_of_nothing_but_weekend_is_refused(): void
    {
        $this->apply(['start_date' => '2026-08-08', 'end_date' => '2026-08-09'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['start_date']]);
    }

    public function test_a_backwards_range_is_refused(): void
    {
        $this->apply(['start_date' => '2026-08-14', 'end_date' => '2026-08-10'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['end_date']]);
    }

    public function test_leave_cannot_be_booked_years_ahead(): void
    {
        $this->apply(['start_date' => '2999-01-01', 'end_date' => '2999-01-05'])
            ->assertStatus(422)
            ->assertJsonPath('errors.end_date.0', 'Leave cannot be booked more than two years ahead.');
    }

    public function test_a_half_day_costs_half_a_day(): void
    {
        $response = $this->apply([
            'start_date' => '2026-08-10', 'end_date' => '2026-08-10',
            'is_half_day' => true, 'half_day_period' => 'first_half',
        ])->assertStatus(201);

        $this->assertEquals(0.5, $response->json('request.days'));
    }

    public function test_a_half_day_cannot_span_two_dates(): void
    {
        $this->apply(['is_half_day' => true])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['is_half_day']]);
    }

    public function test_another_companys_leave_type_cannot_be_booked(): void
    {
        $rival = Company::create(['name' => 'Rival', 'timezone' => 'UTC']);
        $type = LeaveType::create([
            'company_id' => $rival->id, 'name' => 'Theirs',
            'days_per_year' => 10, 'is_active' => true,
        ]);

        $this->apply(['leave_type_id' => $type->id])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['leave_type_id']]);
    }

    // ================= listing =================

    public function test_requests_list_the_callers_own_leave(): void
    {
        $this->apply()->assertStatus(201);
        $this->leaveFor($this->colleague(), '2026-09-01', '2026-09-02');

        $response = $this->getJson('/api/v1/leave/requests')->assertOk();

        $this->assertCount(1, $response->json('requests'));
        $this->assertSame(1, $response->json('meta.total'));
    }

    public function test_requests_can_be_filtered_by_status(): void
    {
        $this->apply()->assertStatus(201);
        $this->leaveFor($this->employee, '2026-09-01', '2026-09-02', 'approved');

        $this->assertCount(1, $this->getJson('/api/v1/leave/requests?status=approved')->json('requests'));
        $this->assertCount(1, $this->getJson('/api/v1/leave/requests?status=pending')->json('requests'));
    }

    public function test_a_pending_request_says_which_desk_it_is_on(): void
    {
        $this->employee->update(['manager_id' => $this->colleague()->id]);
        $this->apply()->assertStatus(201);

        // "Pending" alone does not tell an employee who to chase.
        $this->assertSame('Awaiting Manager', $this->getJson('/api/v1/leave/requests')->json('requests.0.stage'));
    }

    public function test_an_employee_with_no_manager_waits_on_hr(): void
    {
        $this->apply()->assertStatus(201);

        // No manager means no queue with an owner — it goes straight to HR.
        $this->assertSame('Awaiting HR', $this->getJson('/api/v1/leave/requests')->json('requests.0.stage'));
    }

    public function test_one_request_can_be_read_in_full(): void
    {
        $id = $this->apply(['reason' => 'Family wedding'])->json('request.id');

        $this->getJson("/api/v1/leave/requests/{$id}")
            ->assertOk()
            ->assertJsonPath('request.reason', 'Family wedding')
            ->assertJsonStructure(['request' => ['decision_note', 'decided_by', 'manager_note']]);
    }

    public function test_another_persons_request_cannot_be_read(): void
    {
        $theirs = $this->leaveFor($this->colleague(), '2026-09-01', '2026-09-02');

        $this->getJson("/api/v1/leave/requests/{$theirs->id}")
            ->assertStatus(403)
            ->assertJsonPath('error', 'forbidden');
    }

    // ================= withdrawing =================

    public function test_a_pending_request_can_be_withdrawn(): void
    {
        $id = $this->apply()->json('request.id');

        $this->postJson("/api/v1/leave/requests/{$id}/cancel")
            ->assertOk()
            ->assertJsonPath('request.status', 'cancelled');
    }

    public function test_withdrawing_approved_leave_gives_the_days_back(): void
    {
        $casual = LeaveType::create([
            'company_id' => $this->company->id, 'name' => 'Casual',
            'days_per_year' => 5, 'requires_approval' => false, 'is_active' => true,
        ]);

        $id = $this->apply(['leave_type_id' => $casual->id])->json('request.id');

        $balance = LeaveBalance::where('leave_type_id', $casual->id)->first();
        $this->assertEquals(3, (float) $balance->used_days);

        $this->postJson("/api/v1/leave/requests/{$id}/cancel")->assertOk();

        $this->assertEquals(0, (float) $balance->fresh()->used_days);
    }

    public function test_leave_already_under_way_cannot_be_withdrawn(): void
    {
        $started = $this->leaveFor($this->employee, '2026-08-01', '2026-08-05', 'approved');

        $this->postJson("/api/v1/leave/requests/{$started->id}/cancel")
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['status']]);
    }

    public function test_another_persons_request_cannot_be_withdrawn(): void
    {
        $theirs = $this->leaveFor($this->colleague(), '2026-09-01', '2026-09-02');

        $this->postJson("/api/v1/leave/requests/{$theirs->id}/cancel")->assertStatus(403);

        $this->assertSame('pending', $theirs->fresh()->status);
    }

    // ================= manager inbox =================

    public function test_a_plain_employee_cannot_reach_the_inbox(): void
    {
        $this->getJson('/api/v1/leave/approvals')->assertStatus(403);
    }

    public function test_a_manager_sees_their_teams_pending_requests(): void
    {
        [$manager, $report] = $this->team();
        $this->leaveFor($report, '2026-08-10', '2026-08-12');

        Sanctum::actingAs($manager->user);

        $response = $this->getJson('/api/v1/leave/approvals')->assertOk();

        $this->assertSame(1, $response->json('pending_count'));
        $this->assertSame('Ray Poe', $response->json('pending.0.employee'));
    }

    public function test_the_inbox_warns_about_a_clash_in_the_team(): void
    {
        [$manager, $report] = $this->team();
        $second = $this->colleague('Sam', 'Fox', 'E4');
        $second->update(['manager_id' => $manager->id]);

        $this->leaveFor($second, '2026-08-11', '2026-08-13', 'approved');
        $this->leaveFor($report, '2026-08-10', '2026-08-12');

        Sanctum::actingAs($manager->user);

        // A manager approving cover has to know before saying yes, not after.
        $clashes = $this->getJson('/api/v1/leave/approvals')->json('pending.0.clashes');

        $this->assertCount(1, $clashes);
        $this->assertSame('Sam Fox', $clashes[0]['employee']);
    }

    public function test_a_manager_passes_a_request_up_to_hr(): void
    {
        [$manager, $report] = $this->team();
        $request = $this->leaveFor($report, '2026-08-10', '2026-08-12');

        Sanctum::actingAs($manager->user);

        $this->postJson("/api/v1/leave/approvals/{$request->id}/approve", ['manager_note' => 'Cover arranged'])
            ->assertOk()
            ->assertJsonPath('status', 'Awaiting HR');

        $request->refresh();
        $this->assertNotNull($request->manager_approved_at);
        // The manager step grants nothing — days are committed by HR's decision.
        $this->assertSame('pending', $request->status);
        $this->assertNull(LeaveBalance::first());
    }

    public function test_a_manager_rejection_needs_a_reason(): void
    {
        [$manager, $report] = $this->team();
        $request = $this->leaveFor($report, '2026-08-10', '2026-08-12');

        Sanctum::actingAs($manager->user);

        // The employee sees this, so "no" on its own is not an answer.
        $this->postJson("/api/v1/leave/approvals/{$request->id}/reject")
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['decision_note']]);

        $this->postJson("/api/v1/leave/approvals/{$request->id}/reject", ['decision_note' => 'Too many out'])
            ->assertOk();

        $this->assertSame('rejected', $request->fresh()->status);
    }

    public function test_a_manager_cannot_decide_on_a_request_outside_their_team(): void
    {
        [$manager] = $this->team();
        $stranger = $this->colleague('Kim', 'Vale', 'E5');
        $request = $this->leaveFor($stranger, '2026-08-10', '2026-08-12');

        Sanctum::actingAs($manager->user);

        // The permission opens the door; it is not access to anyone else's team.
        $this->postJson("/api/v1/leave/approvals/{$request->id}/approve")->assertStatus(403);

        $this->assertNull($request->fresh()->manager_approved_at);
    }

    public function test_a_manager_cannot_approve_their_own_leave(): void
    {
        [$manager] = $this->team();
        // Even if the data says they report to themselves.
        $manager->update(['manager_id' => $manager->id]);
        $own = $this->leaveFor($manager, '2026-08-10', '2026-08-12');

        Sanctum::actingAs($manager->user);

        $this->postJson("/api/v1/leave/approvals/{$own->id}/approve")
            ->assertStatus(403)
            ->assertJsonPath('message', 'You cannot decide on your own leave request.');
    }

    public function test_a_request_already_passed_up_leaves_the_managers_inbox(): void
    {
        [$manager, $report] = $this->team();
        $request = $this->leaveFor($report, '2026-08-10', '2026-08-12');

        Sanctum::actingAs($manager->user);
        $this->postJson("/api/v1/leave/approvals/{$request->id}/approve")->assertOk();

        $this->assertSame(0, $this->getJson('/api/v1/leave/approvals')->json('pending_count'));

        // And it cannot be passed up a second time.
        $this->postJson("/api/v1/leave/approvals/{$request->id}/approve")->assertStatus(422);
    }

    // ================= helpers =================

    protected function colleague(string $first = 'Bob', string $last = 'Ray', string $code = 'E2'): Employee
    {
        $user = User::create([
            'name' => "{$first} {$last}", 'email' => strtolower($first) . '@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $user->assignRole('employee');

        $employee = Employee::create([
            'company_id' => $this->company->id, 'department_id' => $this->department->id,
            'user_id' => $user->id, 'employee_code' => $code,
            'first_name' => $first, 'last_name' => $last, 'status' => 'active',
        ]);

        return $employee->load('user');
    }

    /** A manager with the permission, and one direct report. */
    protected function team(): array
    {
        $manager = $this->colleague('Mo', 'Diaz', 'E3');
        $manager->user->assignRole('manager');

        $report = $this->colleague('Ray', 'Poe', 'E9');
        $report->update(['manager_id' => $manager->id]);

        return [$manager->fresh()->load('user'), $report];
    }

    protected function leaveFor(Employee $employee, string $from, string $to, string $status = 'pending'): LeaveRequest
    {
        return LeaveRequest::create([
            'company_id' => $this->company->id, 'employee_id' => $employee->id,
            'leave_type_id' => $this->annual->id, 'start_date' => $from,
            'end_date' => $to, 'days' => 3, 'status' => $status,
        ]);
    }
    // ================= the day the picker opens on =================

    /**
     * Balances carry the company's today, and the app's date picker rings it.
     *
     * The picker used to ring `DateTime.now()` from the handset. On a phone a
     * few hours ahead of the company that ring sat on tomorrow, while the
     * Clock, History and Schedule tabs all correctly showed the day before —
     * so somebody booking "from today" booked the wrong day, and the app
     * disagreed with itself on screen. The company is put four hours behind
     * UTC at an hour where the two dates differ, because with a shared clock a
     * picker built from the handset passes.
     */
    public function test_balances_name_the_company_s_today_not_the_server_s(): void
    {
        $this->company->update(['timezone' => 'America/New_York']);

        // 01:00 UTC on New Year's Day is 20:00 on New Year's Eve in New York,
        // so the server and the company disagree about the day *and* the year.
        Carbon::setTestNow('2027-01-01 01:00:00');

        $body = $this->getJson('/api/v1/leave/balances')->assertOk()->json();

        $this->assertSame('2026-12-31', $body['today']);
        $this->assertNotSame(now()->toDateString(), $body['today']);

        // And the year defaults from that same date rather than the server's,
        // which is the same bug with a twelve-month blast radius: on the last
        // evening of December the app asked for next year's balances and would
        // have shown a full untouched entitlement to somebody who had spent it.
        //
        // Note it is compared against `now()->year` and not against PHP's
        // `date('Y')`, which is what the controller used to call: `date()`
        // reads the machine clock and ignores `Carbon::setTestNow` entirely,
        // so that version of this line could not be made to fail no matter
        // what the company's timezone was. An unfreezable clock is its own
        // reason not to ask one the time.
        $this->assertSame(2026, $body['year']);
        $this->assertNotSame(now()->year, $body['year']);

        Carbon::setTestNow();
    }    // ================= the supporting file (B4.1) =================

    /** Applying with a file attached, the way the app and the web form both do. */
    protected function applyWith(UploadedFile $file, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->post('/api/v1/leave/requests', array_merge([
            'leave_type_id' => $this->annual->id,
            'start_date'    => '2026-08-10',
            'end_date'      => '2026-08-12',
            'attachment'    => $file,
        ], $overrides));
    }

    /**
     * The file goes up with the request, and comes back to the person who
     * attached it.
     *
     * `leave_requests.attachment` had existed since the table was created and
     * had never been written to by anything: there was no way to attach a sick
     * note from the app or from the web, which is what left B4.1 amber.
     */
    public function test_a_request_can_carry_a_supporting_file(): void
    {
        Storage::fake(LeaveRequest::ATTACHMENT_DISK);

        $response = $this->applyWith(
            UploadedFile::fake()->create('sick-note.pdf', 120, 'application/pdf'),
        )->assertCreated();

        $this->assertTrue($response->json('request.has_attachment'));
        $this->assertSame('sick-note.pdf', $response->json('request.attachment_name'));

        $request = LeaveRequest::latest('id')->first();

        // The stored path is **not** built from the uploaded name: a path made
        // of user-supplied text is a traversal waiting to happen, and two
        // people attaching `scan.pdf` on the same day must not collide.
        $this->assertNotNull($request->attachment);
        $this->assertStringNotContainsString('sick-note', $request->attachment);
        Storage::disk(LeaveRequest::ATTACHMENT_DISK)->assertExists($request->attachment);

        // And it downloads under the name it was uploaded with, which is the
        // only reason that column exists.
        $download = $this->get("/api/v1/leave/requests/{$request->id}/attachment")->assertOk();
        $this->assertStringContainsString(
            'sick-note.pdf',
            (string) $download->headers->get('content-disposition'),
        );
    }

    public function test_a_refused_request_leaves_no_file_behind(): void
    {
        Storage::fake(LeaveRequest::ATTACHMENT_DISK);

        // Booked twice over the same dates: the second is refused by the overlap
        // rule, *after* its file has already been stored.
        $this->apply()->assertCreated();

        $this->applyWith(UploadedFile::fake()->create('note.pdf', 10, 'application/pdf'))
            ->assertStatus(422);

        // Nothing on disk. An orphan here is a medical document belonging to a
        // request that does not exist, which nobody will ever find to delete.
        $this->assertSame(
            [],
            Storage::disk(LeaveRequest::ATTACHMENT_DISK)->allFiles('leave-attachments'),
        );
    }

    public function test_the_line_manager_can_open_it_and_sees_that_it_is_there(): void
    {
        Storage::fake(LeaveRequest::ATTACHMENT_DISK);

        [$manager] = $this->team();
        $this->employee->update(['manager_id' => $manager->id]);

        $this->applyWith(UploadedFile::fake()->create('sick-note.pdf', 10, 'application/pdf'))
            ->assertCreated();

        $request = LeaveRequest::latest('id')->first();

        Sanctum::actingAs($manager->user);

        // A sick note is no use to an approver who cannot see that it exists.
        $this->getJson('/api/v1/leave/approvals')
            ->assertOk()
            ->assertJsonPath('pending.0.has_attachment', true)
            ->assertJsonPath('pending.0.attachment_name', 'sick-note.pdf');

        $this->get("/api/v1/leave/requests/{$request->id}/attachment")->assertOk();
    }

    public function test_a_colleague_and_another_teams_manager_are_both_refused(): void
    {
        Storage::fake(LeaveRequest::ATTACHMENT_DISK);

        $this->applyWith(UploadedFile::fake()->create('sick-note.pdf', 10, 'application/pdf'))
            ->assertCreated();

        $request = LeaveRequest::latest('id')->first();

        // An ordinary colleague in the same company and department.
        Sanctum::actingAs($this->colleague('Zoe', 'Park', 'E7')->user);
        $this->get("/api/v1/leave/requests/{$request->id}/attachment")->assertStatus(403);

        // And a manager who holds approve-leave, but not over this person. The
        // permission gets them through the door and grants nothing on its own —
        // a manager who cannot decide on the request has no business reading the
        // doctor's note attached to it.
        [$manager] = $this->team();
        Sanctum::actingAs($manager->user);
        $this->get("/api/v1/leave/requests/{$request->id}/attachment")->assertStatus(403);
    }

    public function test_an_oversized_or_unwelcome_file_is_refused(): void
    {
        Storage::fake(LeaveRequest::ATTACHMENT_DISK);

        // 12 MB, over the 10 MB ceiling.
        $this->applyWith(UploadedFile::fake()->create('huge.pdf', 12288, 'application/pdf'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('attachment');

        // An executable wearing a document's clothes.
        $this->applyWith(UploadedFile::fake()->create('payload.exe', 10, 'application/octet-stream'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('attachment');

        // Refused before anything is created or written, both times.
        $this->assertSame(0, LeaveRequest::count());
        $this->assertSame(
            [],
            Storage::disk(LeaveRequest::ATTACHMENT_DISK)->allFiles('leave-attachments'),
        );
    }

    public function test_deleting_the_request_deletes_the_file(): void
    {
        Storage::fake(LeaveRequest::ATTACHMENT_DISK);

        $this->applyWith(UploadedFile::fake()->create('sick-note.pdf', 10, 'application/pdf'))
            ->assertCreated();

        $request = LeaveRequest::latest('id')->first();
        $path    = $request->attachment;

        $request->delete();

        // Leave can go through a cascade — deleting an employee takes it with
        // them — so the file is removed by the model rather than by whoever
        // happened to call delete.
        Storage::disk(LeaveRequest::ATTACHMENT_DISK)->assertMissing($path);
    }
}
