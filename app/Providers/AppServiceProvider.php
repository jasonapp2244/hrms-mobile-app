<?php

namespace App\Providers;

use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // SmartHR template is Bootstrap 5 — render pagination to match.
        Paginator::useBootstrapFive();

        // When an already-authenticated user hits a guest route (e.g. /login),
        // send them to the home page for their role, not the fixed dashboard
        // (employees would otherwise land on the staff dashboard and get a 403).
        RedirectIfAuthenticated::redirectUsing(function ($request) {
            return $request->user() ? route($request->user()->homeRoute()) : '/';
        });

        $this->defineRateLimits();
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
