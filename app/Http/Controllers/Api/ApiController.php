<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
     * Paging facts a list client needs, in one predictable place.
     *
     * Laravel's own paginator JSON carries URLs built from the request host,
     * which is no use to an app that builds its own — page numbers and a total
     * are what it can actually act on.
     */
    protected function pageMeta(LengthAwarePaginator $page): array
    {
        return [
            'current_page' => $page->currentPage(),
            'last_page'    => $page->lastPage(),
            'per_page'     => $page->perPage(),
            'total'        => $page->total(),
        ];
    }

    /**
     * The page size this request asked for, bounded.
     *
     * pageMeta() has returned `per_page` since the API was written, which told
     * every client there was a page size worth knowing about — while no
     * endpoint read one from the request. The number was real and the control
     * it implied was not.
     *
     * **Clamped rather than validated.** Below 1, unparseable or absent takes
     * the default; above the ceiling takes the ceiling. Refusing the whole
     * request with a 422 because somebody asked for one row too many fails a
     * person trying to see their own leave, in order to protect a server that
     * could have answered. The response reports what was actually used, so a
     * client that asked for 500 can see that it got 100 and stop asking.
     *
     * The ceiling is the part that matters: without one, `?per_page=100000` is
     * a way to ask the server to build every row it owns into one document.
     *
     * $list names the endpoint, and supplies **only the default** — what a
     * client that sends no per_page receives. Those defaults are the sizes the
     * endpoints already served, so adding the parameter does not change what
     * an existing app gets back without asking for anything.
     */
    protected function perPage(?string $list = null): int
    {
        $default = (int) config('pagination.api.default', 15);

        if ($list !== null) {
            $default = (int) config("pagination.api.lists.$list", $default);
        }

        $max   = (int) config('pagination.api.max', 100);
        $asked = request()->integer('per_page');

        return $asked < 1 ? $default : min($asked, $max);
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
            __('api.no_employee_record'),
        );

        return $employee;
    }

    /**
     * The zone every time this employee sees is expressed in.
     *
     * Shared rather than per-controller: attendance and the team view both
     * answer with clock faces, and two definitions of "the company's zone"
     * would eventually show one person two different times for one punch.
     */
    protected function timezone(Employee $employee): string
    {
        return $employee->company?->tz() ?? config('app.timezone');
    }
}
