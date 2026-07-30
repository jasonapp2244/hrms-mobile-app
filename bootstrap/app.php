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

            [$status, $code, $message] = match (true) {
                $e instanceof ValidationException      => [422, 'validation_failed', 'The given data was invalid.'],
                $e instanceof AuthenticationException  => [401, 'unauthenticated', 'Authentication required.'],
                $e instanceof AccessDeniedHttpException,
                $e instanceof AuthorizationException   => [403, 'forbidden', 'You are not allowed to do that.'],
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException    => [404, 'not_found', 'Resource not found.'],
                $e instanceof ThrottleRequestsException => [429, 'too_many_requests', 'Too many requests. Please slow down.'],
                $e instanceof HttpExceptionInterface   => [$e->getStatusCode(), 'http_error', $e->getMessage() ?: 'Request failed.'],
                default                                => [500, 'server_error', 'Something went wrong.'],
            };

            $payload = [
                'ok'      => false,
                'error'   => $code,
                'message' => $e instanceof ValidationException ? $message : ($e->getMessage() ?: $message),
            ];

            // Field-level detail only for validation, which is the only case a
            // client can actually act on per-field.
            if ($e instanceof ValidationException) {
                $payload['errors'] = $e->errors();
            }

            // Never leak an internal message or stack trace to a mobile client
            // in production; the real one is still in the log.
            if ($status === 500 && ! config('app.debug')) {
                $payload['message'] = 'Something went wrong.';
            }

            return response()->json($payload, $status);
        });
    })->create();
