<?php

namespace App\Providers;

use App\Jobs\SendQueuedNotification;
use App\Models\ActivityLog;
use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Notification;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Carry queued notifications in our own job class rather than the
        // framework's. The queue reads deleteWhenMissingModels and backoff off
        // the *job*, never off the notification, so settings placed on a
        // notification class look right and do nothing. NotificationSender
        // resolves this from the container, so binding it here is enough.
        $this->app->bind(SendQueuedNotifications::class, SendQueuedNotification::class);
    }

    /**
     * Generate https:// links whenever APP_URL says the site is served over TLS.
     *
     * Driven by APP_URL rather than by APP_ENV so that local development, a
     * staging box on plain http, and production all behave correctly without a
     * per-environment branch — the setting that already records how the site is
     * reached is the one that decides.
     *
     * This matters beyond tidiness: a password-reset or email link built as
     * http:// is a link that either fails or briefly carries a token in clear,
     * and a mixed-content asset is one the browser refuses to load.
     */
    protected function forceHttpsWhenConfigured(): void
    {
        if (Str::startsWith(config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }

    /** Registers the push channel so notifications can list 'fcm' in via(). */
    protected function registerPushChannel(): void
    {
        Notification::resolved(function (ChannelManager $service) {
            $service->extend('fcm', fn ($app) => $app->make(FcmChannel::class));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->forceHttpsWhenConfigured();

        $this->registerPushChannel();

        // SmartHR template is Bootstrap 5 — render pagination to match.
        Paginator::useBootstrapFive();

        // When an already-authenticated user hits a guest route (e.g. /login),
        // send them to the home page for their role, not the fixed dashboard
        // (employees would otherwise land on the staff dashboard and get a 403).
        RedirectIfAuthenticated::redirectUsing(function ($request) {
            return $request->user() ? route($request->user()->homeRoute()) : '/';
        });

        $this->defineRateLimits();

        $this->recordAuthenticationEvents();
    }

    /**
     * Write sign-in, sign-out and failed attempts to the security trail (A1.8).
     *
     * Hung off the framework's own auth events rather than added to
     * LoginController, because there is more than one door: the web form, the
     * mobile API's token endpoint, the password-reset flow and the "remember
     * me" cookie all authenticate, and only the framework sees all four.
     *
     * A failed attempt is recorded with the address that was tried and no user
     * id — the point of the entry is that the credentials matched nobody, and
     * the pattern of addresses being tried is what somebody investigating is
     * actually reading.
     */
    protected function recordAuthenticationEvents(): void
    {
        Event::listen(function (Login $event) {
            ActivityLog::record(
                event: ActivityLog::LOGIN,
                description: 'Signed in via ' . $event->guard,
                actor: $event->user,
            );
        });

        Event::listen(function (Logout $event) {
            // Null on a session that had already expired server-side; there is
            // nobody to attribute it to and nothing worth recording.
            if ($event->user) {
                ActivityLog::record(
                    event: ActivityLog::LOGOUT,
                    actor: $event->user,
                );
            }
        });

        Event::listen(function (Failed $event) {
            ActivityLog::record(
                event: ActivityLog::LOGIN_FAILED,
                description: $event->user
                    // Distinguished because they mean different things to
                    // whoever reads this: a wrong password on a real account is
                    // somebody guessing at a person, an unknown address is
                    // somebody guessing at the door.
                    ? 'Wrong password for a known account'
                    : 'No account matches that email address',
                // Attributed to the account when one exists, so "show me
                // everything about this user" includes the attempts on them.
                // The label stays the address that was actually typed.
                actor: $event->user instanceof User ? $event->user : null,
                actorLabel: (string) ($event->credentials['email'] ?? 'unknown'),
            );
        });

        Event::listen(function (Lockout $event) {
            ActivityLog::record(
                event: ActivityLog::LOCKOUT,
                description: 'Too many failed attempts — further tries refused for a while',
                actorLabel: (string) $event->request->input('email', 'unknown'),
                request: $event->request,
            );
        });

        Event::listen(function (PasswordReset $event) {
            ActivityLog::record(
                event: ActivityLog::PASSWORD_RESET,
                description: 'Password set from a reset link',
                actor: $event->user,
            );
        });
    }

    /**
     * Rate limits for the mobile API.
     *
     * Keyed by user rather than by IP wherever there is a token: a whole office
     * behind one NAT address shares an IP, and limiting on that would have one
     * busy person throttle their colleagues. IP is only the fallback for calls
     * made before anyone is identified.
     */
    protected function defineRateLimits(): void
    {
        // The ceiling every API call sits under. Generous — it exists to stop a
        // runaway loop or a scraper, not to pace an app being used normally.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip()));

        // Guessing is the attack on login, and it is reachable without a token.
        // Keyed by address *and* IP together: on the address alone, anyone could
        // lock a colleague out of the app by failing their login five times.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
            ->by(Str::lower((string) $request->input('email')) . '|' . $request->ip()));

        // A punch is one deliberate tap. The service's own cooldown stops a
        // double tap; this stops a loop.
        RateLimiter::for('punch', fn (Request $request) => Limit::perMinute(20)
            ->by($request->user()?->id ?: $request->ip()));

        // Anything that creates or changes a record. Lower than the read ceiling
        // because no interface produces writes at that rate, and a client stuck
        // retrying a failed submission should be slowed down rather than left to
        // fill the leave register.
        RateLimiter::for('write', fn (Request $request) => Limit::perMinute(30)
            ->by($request->user()?->id ?: $request->ip()));
    }
}
