<?php

namespace App\Providers;

use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

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
    }
}
