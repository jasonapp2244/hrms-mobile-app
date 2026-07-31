<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PushDevice;
use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Messages\PushMessage;
use App\Services\Push\FcmClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Push delivery.
 *
 * The behaviour worth pinning is not "a message was sent" — it is what happens
 * when it is not: an install with no credentials must stay silent rather than
 * fail every notification, and a handset that no longer has the app must be
 * forgotten rather than retried forever.
 */
class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create(['name' => 'Acme', 'timezone' => 'UTC']);
        $this->user = User::create([
            'name' => 'Ann Lee', 'email' => 'ann@test.local',
            'password' => 'password', 'company_id' => $company->id,
        ]);
    }

    /** Points config at a credentials file that exists, so configured() passes. */
    protected function configurePush(): void
    {
        $path = storage_path('app/testing-service-account.json');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, json_encode(['type' => 'service_account']));

        config([
            'fcm.enabled'     => true,
            'fcm.project_id'  => 'hrms-test',
            'fcm.credentials' => $path,
        ]);
    }

    protected function device(string $token = 'handset-token'): PushDevice
    {
        return PushDevice::create([
            'user_id' => $this->user->id,
            'token'   => $token,
            'platform' => 'android',
        ]);
    }

    // ================= configuration =================

    public function test_push_is_off_until_it_is_configured(): void
    {
        config(['fcm.enabled' => false]);

        $this->assertFalse(app(FcmClient::class)->configured());
    }

    public function test_enabling_without_credentials_is_still_off(): void
    {
        // A half-configured install must send nothing rather than fail once per
        // notification into failed_jobs.
        config([
            'fcm.enabled'     => true,
            'fcm.project_id'  => 'hrms-test',
            'fcm.credentials' => storage_path('app/does-not-exist.json'),
        ]);

        $this->assertFalse(app(FcmClient::class)->configured());
    }

    public function test_the_channel_is_only_listed_when_push_is_configured(): void
    {
        $notification = new \App\Notifications\MissingCheckoutReminder(
            new \App\Models\AttendanceLog(['scanned_at' => now()]),
            '2026-08-01',
        );

        config(['fcm.enabled' => false]);
        $this->assertNotContains('fcm', $notification->via($this->user));

        config(['fcm.enabled' => true]);
        $this->assertContains('fcm', $notification->via($this->user));
    }

    // ================= delivery =================

    public function test_nothing_is_sent_when_the_person_has_no_handset(): void
    {
        $this->configurePush();
        Http::fake();

        app(FcmChannel::class)->send($this->user, $this->pushNotification());

        Http::assertNothingSent();
    }

    public function test_a_person_with_two_handsets_gets_both(): void
    {
        // A phone and a tablet are two installations, and FCM's v1 API has no
        // batch endpoint — one request each.
        $this->configurePush();
        $this->device('phone-token');
        $this->device('tablet-token');

        Http::fake([
            '*' => Http::response(['name' => 'projects/hrms-test/messages/1'], 200),
        ]);
        $this->fakeAccessToken();

        app(FcmChannel::class)->send($this->user, $this->pushNotification());

        Http::assertSentCount(2);
    }

    // ================= dead handsets =================

    public function test_a_handset_that_no_longer_has_the_app_is_forgotten(): void
    {
        // The behaviour this whole class exists for. An app uninstalled months
        // ago still has a row here, and without this every future notification
        // would try it again forever.
        $this->configurePush();
        $device = $this->device('stale-token');

        Http::fake([
            '*' => Http::response([
                'error' => [
                    'status'  => 'NOT_FOUND',
                    'details' => [['errorCode' => 'UNREGISTERED']],
                ],
            ], 404),
        ]);
        $this->fakeAccessToken();

        app(FcmChannel::class)->send($this->user, $this->pushNotification());

        $this->assertDatabaseMissing('push_devices', ['id' => $device->id]);
    }

    public function test_a_server_having_a_bad_minute_does_not_lose_the_handset(): void
    {
        // A 503 is Google struggling, not the app being gone. Deleting the row
        // here would silently unsubscribe somebody from every future
        // notification.
        $this->configurePush();
        $device = $this->device('good-token');

        Http::fake(['*' => Http::response(['error' => ['status' => 'UNAVAILABLE']], 503)]);
        $this->fakeAccessToken();

        app(FcmChannel::class)->send($this->user, $this->pushNotification());

        $this->assertDatabaseHas('push_devices', ['id' => $device->id]);
    }

    public function test_one_dead_handset_does_not_stop_the_other_receiving(): void
    {
        $this->configurePush();
        $dead = $this->device('stale-token');
        $live = $this->device('good-token');

        $this->fakeAccessToken();
        Http::fake([
            '*' => Http::sequence()
                ->push(['error' => ['details' => [['errorCode' => 'UNREGISTERED']]]], 404)
                ->push(['name' => 'projects/hrms-test/messages/1'], 200),
        ]);

        app(FcmChannel::class)->send($this->user, $this->pushNotification());

        $this->assertDatabaseMissing('push_devices', ['id' => $dead->id]);
        $this->assertDatabaseHas('push_devices', ['id' => $live->id]);
    }

    // ================= the message =================

    public function test_data_values_are_strings_because_fcm_rejects_anything_else(): void
    {
        // An int slipping into the data payload would fail every send for that
        // notification rather than one.
        $message = new PushMessage(
            title: 'Title',
            body: 'Body',
            data: ['leave_request_id' => 8, 'route' => 'leave'],
        );

        foreach ($message->toArray()['data'] as $value) {
            $this->assertIsString($value);
        }
    }

    // ================= helpers =================

    protected function fakeAccessToken(): void
    {
        // Skips the real OAuth exchange; the token cache is what the client
        // reads before building a request.
        cache()->put('fcm.access_token', 'test-access-token', 60);
    }

    protected function pushNotification(): Notification
    {
        return new class extends Notification {
            public function via(object $notifiable): array
            {
                return ['fcm'];
            }

            public function toPush(object $notifiable): PushMessage
            {
                return new PushMessage(title: 'Test', body: 'Body');
            }
        };
    }
}
