<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

/**
 * Every parameterless web page, opened by every demo role, renders without a
 * server error.
 *
 * Settings went to production as a 500 although a dozen suites name its route:
 * they all assert that the wrong role is *refused*, and a refusal never renders
 * the view. The fault was in the Blade itself — an inline `@php(...)` and a
 * block `@php ... @endphp` in one file, which Blade pairs up wrongly so the block
 * is never compiled — and only a request that reaches the template can see it.
 * This walks the route table rather than a hand-kept list, so a new screen is
 * covered the day it is added.
 */
class WebPagesRenderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Pages that are not pages: they end a session, stream a file built from
     * the request, or are the HTML side of a JSON poll.
     */
    private const SKIP = [
        'logout',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(DemoDataSeeder::class);
    }

    public static function roles(): array
    {
        return [
            'admin'    => ['admin@emp.test'],
            'hr'       => ['hr@emp.test'],
            'manager'  => ['james.smith@acme.test'],
            'employee' => ['emily.johnson@acme.test'],
        ];
    }

    /** @dataProvider roles */
    public function test_every_page_the_role_can_open_renders(string $email): void
    {
        $user = User::where('email', $email)->firstOrFail();
        $failures = [];
        $rendered = 0;

        foreach ($this->pageUris() as $uri) {
            $response = $this->actingAs($user)->get($uri);
            $status = $response->getStatusCode();

            if ($status >= 500) {
                $failures[] = "{$status} {$uri}: " . ($response->exception?->getMessage() ?? '');
            } elseif ($status === 200) {
                $rendered++;
            }
        }

        $this->assertSame([], $failures, "Server errors as {$email}:\n" . implode("\n", $failures));
        $this->assertGreaterThan(0, $rendered, "{$email} rendered no page at all");
    }

    /** @return list<string> */
    private function pageUris(): array
    {
        return collect(Router::getRoutes()->getRoutes())
            ->filter(fn (Route $r) => in_array('GET', $r->methods(), true))
            ->reject(fn (Route $r) => str_starts_with($r->uri(), 'api/'))
            ->reject(fn (Route $r) => str_contains($r->uri(), '{'))
            ->reject(fn (Route $r) => in_array($r->getName(), self::SKIP, true))
            ->reject(fn (Route $r) => in_array('guest', $r->gatherMiddleware(), true))
            ->filter(fn (Route $r) => in_array('auth', $r->gatherMiddleware(), true))
            ->map(fn (Route $r) => '/' . ltrim($r->uri(), '/'))
            ->unique()
            ->values()
            ->all();
    }
}
