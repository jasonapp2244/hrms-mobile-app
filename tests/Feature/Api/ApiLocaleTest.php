<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Office;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\LeaveRequestDecided;
use App\Support\Clock;
use App\Support\Locales;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The API answers in the caller's language (C1.18).
 *
 * Four things are worth pinning down here, and "does the Spanish read well" is
 * not one of them — that is a translator's job:
 *
 *   * **English is unchanged.** Every message in the product went from a
 *     literal to a `__()` call, and a typo in a key does not fail anything: it
 *     prints the key. The whole existing suite is the real guard, and the cases
 *     below cover the ones it does not reach.
 *   * **The header decides the response**, including a refusal, which is raised
 *     before authentication.
 *   * **A notification is written in the recipient's language, not the
 *     sender's.** HR approving leave in English is the ordinary case.
 *   * **Nothing is left untranslated**, which no runtime check can notice.
 */
class ApiLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected Office $office;
    protected Department $department;
    protected Shift $shift;
    protected User $user;
    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->company = Company::create([
            'name' => 'Acme', 'timezone' => 'UTC', 'currency' => 'USD',
        ]);

        $this->office = Office::create([
            'company_id' => $this->company->id, 'name' => 'HQ',
        ]);

        $this->shift = Shift::create([
            'company_id' => $this->company->id, 'name' => 'Day',
            'start_time' => '09:00:00', 'end_time' => '17:00:00',
            'break_minutes' => 30, 'late_grace_minutes' => 15, 'is_active' => true,
        ]);

        $this->department = Department::create([
            'company_id' => $this->company->id, 'name' => 'Ops', 'shift_id' => $this->shift->id,
        ]);

        $this->user = User::create([
            'name' => 'Ann Lee', 'email' => 'ann@acme.test',
            'password' => Hash::make('password'), 'company_id' => $this->company->id,
        ]);
        $this->user->assignRole('employee');

        $this->employee = Employee::create([
            'company_id' => $this->company->id, 'department_id' => $this->department->id,
            'office_id' => $this->office->id, 'user_id' => $this->user->id,
            'employee_code' => 'E1', 'first_name' => 'Ann', 'last_name' => 'Lee',
            'status' => 'active',
        ]);
    }

    // -----------------------------------------------------------------------
    // Reading the header
    // -----------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string}> */
    public static function headers(): array
    {
        return [
            'plain'              => ['es', 'es'],
            'with a region'      => ['es-419', 'es'],
            'weighted list'      => ['es-MX,es;q=0.9,en;q=0.8', 'es'],
            'English preferred'  => ['en-GB,en;q=0.9,es;q=0.8', 'en'],
            'unsupported'        => ['fr-FR,fr;q=0.9', 'en'],
            'wildcard only'      => ['*', 'en'],
            'refused explicitly' => ['es;q=0', 'en'],
            'nonsense'           => ['not a language', 'en'],
            'empty'              => ['', 'en'],
        ];
    }

    #[DataProvider("headers")]
    public function test_it_reads_accept_language_forgivingly(string $header, string $expected): void
    {
        // A preference, never a credential: junk resolves to the default rather
        // than to a 400 the client has no way to act on.
        $this->assertSame($expected, Locales::fromHeader($header));
    }

    public function test_a_missing_header_is_the_default(): void
    {
        $this->assertSame('en', Locales::fromHeader(null));
    }

    // -----------------------------------------------------------------------
    // What comes back
    // -----------------------------------------------------------------------

    public function test_a_refusal_is_translated_before_anybody_is_authenticated(): void
    {
        // The locale middleware is prepended to the API group, so it has run by
        // the time `auth:sanctum` turns this away. A sign-in failure in English
        // on a Spanish handset is the first thing anybody would see.
        $spanish = $this->withHeader('Accept-Language', 'es')
            ->postJson('/api/v1/auth/login', [
                'email' => 'ann@acme.test', 'password' => 'wrong', 'device_name' => 'phone',
            ]);

        $spanish->assertStatus(401)
            ->assertJsonPath('error', 'invalid_credentials')
            ->assertJsonPath('message', __('api.invalid_credentials', [], 'es'));

        $this->assertNotSame(
            __('api.invalid_credentials', [], 'en'),
            __('api.invalid_credentials', [], 'es'),
        );
    }

    public function test_the_frameworks_own_refusals_are_translated_too(): void
    {
        // These carry an English message on the exception itself —
        // "Unauthenticated.", "This action is unauthorized." — and the handler
        // used to prefer it over the translation, so a Spanish handset saw
        // English on the two refusals it meets most often. Nothing else in the
        // suite reads a message on a refusal, which is why it took a live call
        // to notice.
        $this->withHeader('Accept-Language', 'es')
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('error', 'unauthenticated')
            ->assertJsonPath('message', __('api.unauthenticated', [], 'es'));
    }

    public function test_and_are_unchanged_without_the_header(): void
    {
        // A separate test rather than a second call above: `withHeader` is
        // sticky for the rest of the test, so the two would not be independent.
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Authentication required.');
    }

    public function test_an_abort_keeps_the_message_it_was_given(): void
    {
        // The other half of the same rule: a message the code chose explicitly
        // still wins, because it is already translated at the raise site.
        Sanctum::actingAs($this->user);

        $this->withHeader('Accept-Language', 'es')
            ->postJson('/api/v1/attendance/regularisations/999999/cancel')
            ->assertStatus(404)
            ->assertJsonPath('message', __('api.not_found', [], 'es'));
    }

    public function test_the_same_call_in_english_is_unchanged(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'ann@acme.test', 'password' => 'wrong', 'device_name' => 'phone',
        ])->assertStatus(401)->assertJsonPath(
            'message',
            'Those details do not match our records.',
        );
    }

    public function test_a_validation_failure_comes_back_in_spanish(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->withHeader('Accept-Language', 'es')
            ->postJson('/api/v1/leave/requests', []);

        $response->assertStatus(422)
            ->assertJsonPath('message', __('api.validation_failed', [], 'es'));

        // The field detail is Laravel's own, which needs lang/es/validation.php
        // — the top line alone being Spanish would be worse than neither.
        $this->assertStringNotContainsString(
            'field is required',
            json_encode($response->json('errors')),
        );
    }

    public function test_a_punch_confirmation_is_translated_and_so_is_its_clock(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 14:05:00'));
        Sanctum::actingAs($this->user);

        $response = $this->withHeader('Accept-Language', 'es')
            ->postJson('/api/v1/attendance/check');

        $response->assertOk();

        // "02:05 p. m.", not "02:05 PM": the server sends the clock face
        // pre-formatted, so the meridiem is its to translate.
        $this->assertStringContainsString('p. m.', $response->json('message'));
        $this->assertStringContainsString('p. m.', $response->json('punch.time'));

        Carbon::setTestNow();
    }

    public function test_the_leave_stage_follows_the_reader(): void
    {
        Sanctum::actingAs($this->user);

        $type = LeaveType::create([
            'company_id' => $this->company->id, 'name' => 'Annual Leave',
            'days_per_year' => 20, 'requires_approval' => true, 'is_active' => true,
        ]);

        $request = LeaveRequest::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $type->id, 'start_date' => '2026-09-01',
            'end_date' => '2026-09-02', 'days' => 2, 'status' => 'pending',
        ]);

        $this->withHeader('Accept-Language', 'es')
            ->getJson('/api/v1/leave/requests')
            ->assertOk()
            ->assertJsonPath('requests.0.stage', __('leave.stage.awaiting_hr', [], 'es'));

        // The leave *type* is a row the company typed in, so it is untouched.
        $this->assertSame('Annual Leave', $request->leaveType->name);
    }

    // -----------------------------------------------------------------------
    // Remembering what somebody reads
    // -----------------------------------------------------------------------

    public function test_the_header_is_remembered_against_the_account(): void
    {
        Sanctum::actingAs($this->user);

        $this->assertNull($this->user->locale);

        $this->withHeader('Accept-Language', 'es')
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        $this->assertSame('es', $this->user->fresh()->locale);
    }

    public function test_switching_back_updates_it(): void
    {
        $this->user->forceFill(['locale' => 'es'])->save();
        Sanctum::actingAs($this->user);

        $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/auth/me')->assertOk();

        $this->assertSame('en', $this->user->fresh()->locale);
    }

    public function test_a_language_the_build_no_longer_has_is_not_used(): void
    {
        // A row written by a later build, or a translation withdrawn. Falling
        // back beats rendering a message in a language with no strings behind
        // it — which would print the keys.
        $this->user->forceFill(['locale' => 'fr'])->save();

        $this->assertNull($this->user->preferredLocale());
    }

    // -----------------------------------------------------------------------
    // The half a request cannot decide
    // -----------------------------------------------------------------------

    public function test_a_notification_is_written_in_the_recipients_language(): void
    {
        // The whole reason `users.locale` exists. A worker formats this with no
        // request and no header, and it is usually somebody else's action that
        // caused it: HR approving leave in English decides what an employee
        // reads in Spanish.
        $this->user->forceFill(['locale' => 'es'])->save();

        $type = LeaveType::create([
            'company_id' => $this->company->id, 'name' => 'Annual Leave',
            'days_per_year' => 20, 'requires_approval' => true, 'is_active' => true,
        ]);

        $request = LeaveRequest::create([
            'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
            'leave_type_id' => $type->id, 'start_date' => '2026-09-01',
            'end_date' => '2026-09-02', 'days' => 2, 'status' => 'approved',
        ]);

        // English throughout the sending side, exactly as an HR request would be.
        app()->setLocale('en');
        $this->user->notify(new LeaveRequestDecided($request->load('leaveType'), 'approved'));

        $row = $this->user->notifications()->first();

        $this->assertNotNull($row);
        $this->assertSame(
            __('notifications.leave_decided.title.approved', [], 'es'),
            $row->data['title'],
        );

        // And the locale is put back afterwards, or every response after the
        // first notification of a request would come out in the wrong language.
        $this->assertSame('en', app()->getLocale());
    }

    public function test_a_recipient_with_no_preference_gets_the_default(): void
    {
        Notification::fake();

        $this->assertNull($this->user->preferredLocale());
    }

    // -----------------------------------------------------------------------
    // Nothing left untranslated
    // -----------------------------------------------------------------------

    public function test_every_english_key_has_a_translation(): void
    {
        // A missing key does not fail: it falls back to English and ships as an
        // English sentence inside a Spanish screen. Nothing at runtime would
        // ever say so, which is what this is for.
        foreach (Locales::SUPPORTED as $locale) {
            if ($locale === Locales::default()) {
                continue;
            }

            foreach (glob(lang_path(Locales::default() . '/*.php')) as $file) {
                $group = basename($file, '.php');
                $translated = lang_path("{$locale}/{$group}.php");

                $this->assertFileExists(
                    $translated,
                    "lang/{$locale}/{$group}.php is missing entirely.",
                );

                $missing = array_diff(
                    array_keys($this->flatten(require $file)),
                    array_keys($this->flatten(require $translated)),
                );

                $this->assertSame(
                    [],
                    array_values($missing),
                    "Untranslated in {$locale}/{$group}: " . implode(', ', $missing),
                );
            }
        }
    }

    public function test_the_framework_validation_messages_are_all_translated(): void
    {
        // Not our file, and it grows: a Laravel upgrade that adds a rule leaves
        // one English sentence in the middle of a Spanish form, and nothing
        // else in the suite would notice.
        $english = $this->flatten(require base_path(
            'vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php',
        ));

        $spanish = $this->flatten(require lang_path('es/validation.php'));

        $missing = array_diff(array_keys($english), array_keys($spanish));

        $this->assertSame([], array_values($missing), 'Untranslated rules: ' . implode(', ', $missing));
    }

    public function test_no_spanish_string_is_still_the_english_one(): void
    {
        // A translator who skips a row often leaves the English behind rather
        // than deleting it, which a key comparison cannot see. Short rows and
        // rows that are only placeholders and separators legitimately match, so
        // this measures the words a row actually has.
        $identical = [];

        foreach (glob(lang_path('en/*.php')) as $file) {
            $group   = basename($file, '.php');
            $english = $this->flatten(require $file);
            $spanish = $this->flatten(require lang_path("es/{$group}.php"));

            foreach ($english as $key => $value) {
                $words = preg_replace('/:[a-z_]+/', ' ', $value);

                if (strlen(preg_replace('/[^A-Za-z]/', '', $words)) < 20) {
                    continue;
                }

                if (($spanish[$key] ?? null) === $value) {
                    $identical[] = "{$group}.{$key}";
                }
            }
        }

        $this->assertSame([], $identical, 'Still in English: ' . implode(', ', $identical));
    }

    public function test_the_clock_is_unchanged_in_english(): void
    {
        // Nine call sites moved onto Clock::time. The English reading has to be
        // character-for-character what format('h:i A') gave, or the web
        // dashboard and the Excel export have quietly changed.
        $at = Carbon::parse('2026-08-03 18:05:00');

        $this->assertSame('06:05 PM', Clock::time($at));
        $this->assertSame('12:30 AM', Clock::time(Carbon::parse('2026-08-03 00:30:00')));
        $this->assertSame('12:00 PM', Clock::time(Carbon::parse('2026-08-03 12:00:00')));

        app()->setLocale('es');
        $this->assertSame('06:05 p. m.', Clock::time($at));
        app()->setLocale('en');
    }

    /** @param  array<mixed>  $values */
    protected function flatten(array $values, string $prefix = ''): array
    {
        $flat = [];

        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value)) {
                $flat += $this->flatten($value, $path);
                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
    }
}
