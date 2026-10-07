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

    /**
     * No page offers a link its reader is refused.
     *
     * Rendering is not the whole of it: two-factor handed managers and
     * employees the admin sidebar, and the QR Screens and Devices pages linked
     * HR to Policies, which HR cannot open. Each was a 200 page full of 403s.
     * Every same-site href on every page the role can open is followed once.
     *
     * @dataProvider roles
     */
    public function test_no_page_links_the_role_to_a_page_it_is_refused(string $email): void
    {
        $user = User::where('email', $email)->firstOrFail();
        $host = parse_url(config('app.url'), PHP_URL_HOST);
        $sources = [];

        foreach ($this->pageUris() as $uri) {
            $response = $this->actingAs($user)->get($uri);
            if ($response->getStatusCode() !== 200 || ! str_contains((string) $response->headers->get('Content-Type'), 'html')) {
                continue;
            }

            preg_match_all('/href="([^"#]+)"/', $response->getContent(), $m);
            foreach ($m[1] as $href) {
                $href = html_entity_decode($href);
                $parts = parse_url($href);
                if (isset($parts['host']) && $parts['host'] !== $host) {
                    continue;
                }
                $path = $parts['path'] ?? '';
                if ($path === '' || ! str_starts_with($path, '/') || preg_match('#^/(assets|build|storage)/|\.(css|js|png|jpe?g|svg|ico|webp)$#', $path)) {
                    continue;
                }
                $sources[$path] ??= $uri;
            }
        }

        $dead = [];
        foreach ($sources as $path => $from) {
            $status = $this->actingAs($user)->get($path)->getStatusCode();
            if ($status === 403 || $status >= 500) {
                $dead[] = "{$status} {$path}  (linked from {$from})";
            }
        }

        $this->assertNotEmpty($sources, "{$email} was shown no links at all");
        $this->assertSame([], $dead, "Links {$email} is shown but refused:\n" . implode("\n", $dead));
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
