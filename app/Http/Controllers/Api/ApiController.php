<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;

/**
 * Shared shape for every API response.
 *
 * Success and failure both carry `ok`, so a client can branch on one field
 * instead of inferring from the status code and the presence of keys. Failures
 * are built by the handler in bootstrap/app.php; this covers the success side.
 */
abstract class ApiController extends Controller
{
    protected function ok(array $data = [], int $status = 200): JsonResponse
    {
        return response()->json(['ok' => true] + $data, $status);
    }

    protected function fail(string $error, string $message, int $status = 422, array $extra = []): JsonResponse
    {
        return response()->json([
            'ok'      => false,
            'error'   => $error,
            'message' => $message,
        ] + $extra, $status);
    }

    /**
     * The employee record behind the authenticated token.
     *
     * Almost every endpoint is about a person rather than a login, and an
     * account with no employee record cannot answer any of those questions.
     */
    protected function employee(): Employee
    {
        $employee = auth()->user()?->employee;

        abort_if(
            ! $employee,
            403,
            'No employee record is linked to this account. Contact HR.',
        );

        return $employee;
    }
}
