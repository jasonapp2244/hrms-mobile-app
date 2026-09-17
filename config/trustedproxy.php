<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | In production the app sits behind a reverse proxy — nginx, and Varnish in
    | front of that — so every request arrives from the proxy rather than from
    | the person making it. Until the proxy is trusted, three things are quietly
    | wrong, and none of them fails:
    |
    |   * every punch records the proxy's address instead of the employee's,
    |     so the per-punch IP capture and the IP column in exports are not
    |     telling the truth (Api\AttendanceController);
    |   * the login rate limiter keys on the address, so behind one proxy the
    |     whole company shares a bucket — five wrong passwords anywhere locks
    |     everybody out, which is the exact failure A1.10 was written to avoid
    |     (AppServiceProvider); and
    |   * generated URLs come out http://, because Laravel cannot see that TLS
    |     terminated upstream.
    |
    | **This has to be a config file rather than a line in bootstrap/app.php,
    | and that is not a style preference.** The `withMiddleware` closure there
    | runs on `afterResolving(HttpKernel::class)`, which `Application::
    | handleRequest` triggers *before* it calls `$kernel->handle()` — and
    | `handle()` is what runs `LoadEnvironmentVariables`. So `env()` in that
    | closure is read before .env has been loaded and answers null every time,
    | cached config or not. It did, for the whole life of this file: the guard
    | was never true and TrustProxies was never configured. Here the value is
    | read by the middleware at request time, through the framework's own
    | `config('trustedproxy.proxies')` fallback, by which point config is
    | loaded — and it survives `config:cache`, which a config file is the only
    | place that does.
    |
    | The correct value depends on the deployment. A proxy on the same host is
    | '127.0.0.1'; behind a load balancer or Cloudflare it is that network's
    | ranges; a comma-separated list is accepted. '*' trusts whatever is
    | calling, which is right only when nothing but the proxy can reach the
    | app's port — a directly reachable app would otherwise let any caller
    | forge its own address with an X-Forwarded-For header.
    |
    | Null means trust nothing, which is correct for local development and for
    | any host with no proxy in front of it.
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

];
