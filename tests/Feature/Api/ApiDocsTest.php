<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The reference has to describe the API that exists.
 *
 * Hand-written docs drift the moment somebody adds an endpoint and forgets, and
 * a mobile developer working from a stale reference loses a day before they
 * suspect the document rather than their code. This walks the actual route
 * table, so forgetting fails the build instead.
 */
class ApiDocsTest extends TestCase
{
    protected const DOC = __DIR__ . '/../../../../API-Reference_v1.md';

    /** Every registered api/v1 route, as method + path. */
    protected function routes(): array
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                // HEAD is implied by GET, and OPTIONS is the framework's.
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $routes[] = [
                    'method' => $method,
                    // Documented without the api/v1 prefix, which the reference
                    // states once as the base URL.
                    'path' => '/' . substr($route->uri(), strlen('api/v1/')),
                    'name' => $route->getName(),
                ];
            }
        }

        return $routes;
    }

    public function test_the_reference_exists(): void
    {
        $this->assertFileExists(self::DOC, 'The API reference is missing.');
    }

    public function test_every_endpoint_is_documented(): void
    {
        $doc = file_get_contents(self::DOC);
        $missing = [];

        foreach ($this->routes() as $route) {
            // Route parameters are written with a readable name in the docs
            // ({id} rather than {leaveRequest}), so compare on the literal
            // segments and treat any {...} as a wildcard.
            $pattern = '/'
                . $route['method']
                . '\s+`?'
                . preg_replace('/\\\{[^}]+\\\}/', '\{[^}]+\}', preg_quote($route['path'], '/'))
                . '`?/i';

            if (! preg_match($pattern, $doc)) {
                $missing[] = "{$route['method']} {$route['path']} ({$route['name']})";
            }
        }

        $this->assertSame([], $missing, sprintf(
            "These endpoints are not in API-Reference_v1.md:\n  %s\n"
            . "Add them, or a mobile developer will build against a reference that is wrong.",
            implode("\n  ", $missing),
        ));
    }

    public function test_the_reference_documents_every_error_code_the_api_can_return(): void
    {
        $doc = file_get_contents(self::DOC);

        // Codes raised by hand in controllers, plus the shared ones from the
        // exception handler. A client branching on `error` needs all of them.
        $codes = [
            'validation_failed', 'unauthenticated', 'forbidden', 'not_found',
            'too_many_requests', 'server_error',
            'invalid_credentials', 'account_disabled', 'duplicate_scan',
            'no_office', 'wrong_password', 'invalid_range', 'range_too_large',
        ];

        foreach ($codes as $code) {
            $this->assertStringContainsString($code, $doc, "Error code '{$code}' is not documented.");
        }
    }
}
