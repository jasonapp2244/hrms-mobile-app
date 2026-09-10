<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Versioned at the prefix rather than the default 'api': a mobile app
        // in the field cannot be forced to upgrade, so v2 has to be able to
        // exist alongside v1 rather than replacing it.
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // In production the app sits behind nginx, so every request arrives from
        // the proxy rather than from the person making it. Without this, two
        // things break quietly: attendance records the proxy's address as the
        // punch location instead of the employee's, and generated URLs come out
        // http:// because Laravel cannot see that TLS terminated upstream.
        //
        // Configured rather than hardcoded because the correct value depends on
        // the deployment. A proxy on the same host is '127.0.0.1'; behind a load
        // balancer or Cloudflare it is that network's ranges. '*' trusts any
        // proxy — right only when nothing but the proxy can reach the app port,
        // since a reachable app would otherwise let a caller forge its own IP by
        // sending an X-Forwarded-For header.
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(
                at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)),
            );
        }

        // Every API route sits under the 'api' limiter defined in
        // AppServiceProvider. Laravel does not apply one by default, so without
        // this a single client could hammer any endpoint without limit; the
        // stricter per-route limiters stack on top of it.
        $middleware->throttleApi();

        // Answer in the language the caller asked for (C1.18). On the API group
        // only: the web dashboard stays English by decision, and every message the
        // two halves share resolves under the default locale there.
        //
        // Prepended, so it runs before `auth:sanctum` — the locale is then set for
        // the whole request including a refusal, which is the one response somebody
        // is most likely to have to read out to a colleague.
        $middleware->api(prepend: [
            \App\Http\Middleware\SetApiLocale::class,
        ]);

        // Sign people out after the inactivity their company allows (A1.9).
        // Appended to the web group rather than aliased onto routes: a timeout
        // that applies to most screens and not the one somebody happened to
        // leave open is not a timeout.
        $middleware->web(append: [
            \App\Http\Middleware\EnforceIdleTimeout::class,
            // Held before the timeout would matter, and after authentication:
            // an admin the company requires a second factor from goes to the
            // setup screen and nowhere else (A1.7).
            \App\Http\Middleware\RequireTwoFactor::class,
        ]);

        // Spatie permission middleware aliases
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // One error shape for the whole API. A mobile client parsing a different
        // structure per failure mode is a client that breaks on the first
        // unexpected one, so every error carries the same three keys.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;   // web routes keep Laravel's own handling
            }

            // A plain abort(403) throws a generic HttpException, while a policy
            // throws a typed one. To the client they are the same refusal, so
            // the code is derived from the status rather than from which class
            // happened to be thrown — otherwise one condition would arrive
            // under two different names depending on where it was raised.
            $byStatus = [
                400 => 'bad_request',
                401 => 'unauthenticated',
                403 => 'forbidden',
                404 => 'not_found',
                405 => 'method_not_allowed',
                409 => 'conflict',
                422 => 'unprocessable',
                429 => 'too_many_requests',
                503 => 'unavailable',
            ];

            // Answered in the caller's language (C1.18). SetApiLocale runs
            // before authentication, so a refusal is translated too — which is
            // the response somebody is most likely to have to read out.
            [$status, $code, $message] = match (true) {
                $e instanceof ValidationException      => [422, 'validation_failed', __('api.validation_failed')],
                $e instanceof AuthenticationException  => [401, 'unauthenticated', __('api.unauthenticated')],
                $e instanceof AccessDeniedHttpException,
                $e instanceof AuthorizationException   => [403, 'forbidden', __('api.forbidden')],
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException    => [404, 'not_found', __('api.not_found')],
                $e instanceof ThrottleRequestsException => [429, 'too_many_requests', __('api.too_many_requests')],
                $e instanceof HttpExceptionInterface   => [
                    $e->getStatusCode(),
                    $byStatus[$e->getStatusCode()] ?? 'http_error',
                    // An abort() message is already translated at the raise
                    // site; only the fallback belongs here.
                    $e->getMessage() ?: __('api.request_failed'),
                ],
                default                                => [500, 'server_error', __('api.server_error')],
            };

            $payload = [
                'ok'      => false,
                'error'   => $code,
                // Whatever the arm above decided, and nothing else.
                //
                // This used to prefer `$e->getMessage()`, so that an
                // `abort(403, '…')` reached the client with its own wording —
                // and the arm for that case still does exactly that. Preferring
                // it *here* as well put the framework's own English defaults
                // back in front of the translation: `AuthenticationException`
                // carries "Unauthenticated.", `AuthorizationException` carries
                // "This action is unauthorized.", and a Spanish handset was
                // shown both (C1.18).
                'message' => $message,
            ];

            // Field-level detail only for validation, which is the only case a
            // client can actually act on per-field.
            if ($e instanceof ValidationException) {
                $payload['errors'] = $e->errors();
            }

            // The reverse of the old guard: a 500 says nothing useful by
            // default — which is what production wants, and the real one is
            // still in the log — but while debugging, the actual exception
            // message is the whole point of looking.
            if ($status === 500 && config('app.debug') && $e->getMessage() !== '') {
                $payload['message'] = $e->getMessage();
            }

            return response()->json($payload, $status);
        });
    })->create();
