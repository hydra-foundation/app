<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Providers\AppServiceProvider;
use App\Tests\Support\ArraySessionServiceProvider;
use App\Tests\Support\FixedSignerServiceProvider;
use App\Tests\Support\TestAdminProvider;
use App\Tests\Support\TestHttpProvider;
use App\Tests\Support\TestSchema;
use Hydra\Auth\AuthConfig;
use Hydra\Auth\AuthServiceProvider;
use Hydra\Auth\Contracts\HasherInterface;
use Hydra\Authorization\AuthorizationServiceProvider;
use Hydra\Core\Application;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Environment;
use Hydra\Csrf\CsrfGuard;
use Hydra\Database\Contracts\ConnectionInterface;
use Hydra\Database\PdoConnection;
use Hydra\Nyholm\NyholmServiceProvider;
use Hydra\PhpDi\Container;
use Hydra\Session\Contracts\SessionLifecycleInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The users admin module end-to-end: the route it compiles to, the ability it
 * declares, and the three depths htmx can ask a screen to render at.
 */
final class AdminModuleFlowTest extends TestCase
{
    private const PASSWORD = 'correct-horse-battery-staple';

    private ContainerInterface $container;

    protected function setUp(): void
    {
        $container = Container::create();
        $container->instance(ContainerInterface::class, $container);
        $container->instance(Environment::class, new Environment(__DIR__));

        (new Application($container))
            ->register(new ArraySessionServiceProvider)
            ->register(new NyholmServiceProvider)
            ->register(new FixedSignerServiceProvider)
            ->register(TestHttpProvider::make())
            ->register(new AuthServiceProvider)
            ->register(new AuthorizationServiceProvider)
            ->register(new AppServiceProvider)
            ->register(TestAdminProvider::make())
            ->boot();

        $container->instance(AuthConfig::class, new AuthConfig(hashCost: 4));

        $pdo = TestSchema::connect();
        $container->instance(ConnectionInterface::class, new PdoConnection($pdo));

        $hash = $container->get(HasherInterface::class)->hash(self::PASSWORD);
        $insert = $pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)');
        $insert->execute(['boss', $hash, 'admin']);
        $insert->execute(['clerk', $hash, 'user']);

        // Enough extra rows to push the 15-per-page module onto a second page.
        foreach (range(1, 20) as $n) {
            $insert->execute([sprintf('temp%02d', $n), $hash, 'user']);
        }

        $this->container = $container;
    }

    public function test_the_module_compiles_to_a_route_that_anonymous_visitors_cannot_reach(): void
    {
        $response = $this->handle('GET', '/admin/users');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaderLine('Location'));
    }

    public function test_a_module_ability_keeps_signed_in_non_admins_out(): void
    {
        $this->login('clerk');

        $this->assertSame(403, $this->handle('GET', '/admin/users')->getStatusCode());
    }

    public function test_an_admin_sees_the_first_page_of_the_table(): void
    {
        $this->login('boss');
        $body = $this->body('GET', '/admin/users');

        $this->assertStringContainsString('<title>Users · Admin</title>', $body);
        $this->assertStringContainsString('id="admin-frame"', $body);
        $this->assertStringContainsString('id="admin-body"', $body);
        $this->assertSame(2, substr_count($body, 'breadcrumb-item'));
        $this->assertStringContainsString('Showing 1–15 of 22', $body);
        $this->assertStringContainsString('>temp20</td>', $body);
        $this->assertStringNotContainsString('password_hash', $body);
    }

    public function test_the_admin_root_redirects_to_the_landing_module(): void
    {
        $this->login('boss');
        $response = $this->handle('GET', '/admin');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/dashboard', $response->getHeaderLine('Location'));
    }

    public function test_a_page_module_needs_no_source_and_is_open_to_any_signed_in_user(): void
    {
        $this->login('clerk');
        $body = $this->body('GET', '/admin/dashboard');

        $this->assertStringContainsString('Dashboard', $body);
        $this->assertStringContainsString('Newest accounts', $body);
        $this->assertStringContainsString('Signed in as <strong>clerk</strong>', $body);
        $this->assertSame(2, substr_count($body, 'breadcrumb-item'));
    }

    public function test_the_presenter_supplies_the_page_its_numbers(): void
    {
        $this->login('boss');
        $body = $this->body('GET', '/admin/dashboard');

        // 22 seeded accounts, one of them an admin.
        $this->assertMatchesRegularExpression('/Users<\/div>\s*<div class="fs-2">22</', $body);
        $this->assertMatchesRegularExpression('/Admins<\/div>\s*<div class="fs-2">1</', $body);
    }

    public function test_the_sidebar_shows_only_the_modules_the_visitor_may_reach(): void
    {
        $this->login('clerk');
        $body = $this->body('GET', '/admin/dashboard');

        $this->assertStringContainsString('/admin/dashboard', $body);
        $this->assertStringNotContainsString('/admin/users', $body);
    }

    public function test_a_path_below_a_module_with_no_screen_there_is_not_a_route(): void
    {
        $this->login('boss');

        $this->assertSame(404, $this->handle('GET', '/admin/dashboard/trends')->getStatusCode());
    }

    public function test_an_unknown_slug_under_the_prefix_is_not_a_route(): void
    {
        $this->login('boss');

        $this->assertSame(404, $this->handle('GET', '/admin/invoices')->getStatusCode());
    }

    public function test_search_filter_and_sort_travel_in_the_query_string(): void
    {
        $this->login('boss');

        $searched = $this->body('GET', '/admin/users?q=clerk');
        $this->assertStringContainsString('Showing 1–1 of 1', $searched);
        $this->assertStringContainsString('clerk', $searched);

        $filtered = $this->body('GET', '/admin/users?role=admin');
        $this->assertStringContainsString('Showing 1–1 of 1', $filtered);

        $sorted = $this->body('GET', '/admin/users?sort=username&dir=asc');
        $this->assertLessThan(strpos($sorted, '>clerk<'), strpos($sorted, '>boss<'));
    }

    public function test_an_undeclared_sort_column_is_ignored(): void
    {
        $this->login('boss');

        $this->assertSame(200, $this->handle('GET', '/admin/users?sort=password_hash')->getStatusCode());
    }

    public function test_the_second_page_is_its_own_url(): void
    {
        $this->login('boss');

        $this->assertStringContainsString('Showing 16–22 of 22', $this->body('GET', '/admin/users?page=2'));
    }

    public function test_htmx_swaps_only_the_body_when_the_table_is_targeted(): void
    {
        $this->login('boss');
        $body = $this->body('GET', '/admin/users?page=2', ['HX-Request' => 'true', 'HX-Target' => 'div#admin-body']);

        $this->assertStringNotContainsString('<html', $body);
        $this->assertStringNotContainsString('admin-sidebar', $body);
        $this->assertStringNotContainsString('breadcrumb', $body);
        $this->assertStringContainsString('Showing 16–22 of 22', $body);
    }

    public function test_htmx_swaps_the_frame_when_the_sidebar_navigates(): void
    {
        $this->login('boss');
        $body = $this->body('GET', '/admin/users', ['HX-Request' => 'true', 'HX-Target' => 'div#admin-frame']);

        $this->assertStringNotContainsString('<html', $body);
        $this->assertStringNotContainsString('admin-sidebar', $body);
        $this->assertStringContainsString('breadcrumb', $body);
        $this->assertStringContainsString('id="admin-body"', $body);
    }

    public function test_a_frame_swap_carries_the_title_and_an_out_of_band_sidebar(): void
    {
        $this->login('boss');
        $body = $this->body('GET', '/admin/users', ['HX-Request' => 'true', 'HX-Target' => 'div#admin-frame']);

        // htmx reads the title out of a head block and applies it to the tab.
        $this->assertStringContainsString('<head><title>Users · Admin</title></head>', $body);

        // The sidebar lives outside the swapped frame, so the active item rides
        // along out of band.
        $this->assertStringContainsString('id="admin-nav" hx-swap-oob="true"', $body);
        $this->assertMatchesRegularExpression('/nav-link active"\s+href="\/admin\/users"/', $body);
        $this->assertMatchesRegularExpression('/nav-link"\s+href="\/admin\/dashboard"/', $body);
    }

    public function test_a_body_swap_leaves_the_sidebar_alone(): void
    {
        $this->login('boss');
        $body = $this->body('GET', '/admin/users?page=2', ['HX-Request' => 'true', 'HX-Target' => 'div#admin-body']);

        $this->assertStringNotContainsString('hx-swap-oob', $body);
        $this->assertStringNotContainsString('<head>', $body);
    }

    public function test_an_htmx_request_with_no_known_target_still_renders_the_whole_page(): void
    {
        $this->login('boss');
        $body = $this->body('GET', '/admin/users', ['HX-Request' => 'true', 'HX-Target' => 'div#somewhere-else']);

        $this->assertStringContainsString('<html', $body);
        $this->assertStringContainsString('admin-sidebar', $body);
    }

    public function test_a_body_swap_carries_the_sort_state_the_toolbar_reaches_for(): void
    {
        $this->login('boss');

        $full = $this->body('GET', '/admin/users');
        $this->assertStringContainsString('hx-include="#admin-sort-state"', $full);
        $this->assertSame(1, substr_count($full, 'name="sort"'));

        $sorted = $this->body('GET', '/admin/users?sort=username&dir=asc', [
            'HX-Request' => 'true',
            'HX-Target' => 'div#admin-body',
        ]);
        $this->assertStringContainsString('id="admin-sort-state"', $sorted);
        $this->assertStringContainsString('name="sort" value="username"', $sorted);
        $this->assertStringContainsString('name="dir" value="asc"', $sorted);
    }

    /** @param array<string, string> $headers */
    private function body(string $method, string $path, array $headers = []): string
    {
        $response = $this->handle($method, $path, $headers);
        $this->assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $body
     */
    private function handle(string $method, string $path, array $headers = [], ?array $body = null): ResponseInterface
    {
        $request = (new Psr17Factory)->createServerRequest($method, $path);

        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            $this->container->get(SessionLifecycleInterface::class)->start();
            $request = $request->withHeader('X-CSRF-Token', $this->container->get(CsrfGuard::class)->token());
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }

        return $this->container->get(RequestHandlerInterface::class)->handle($request);
    }

    private function login(string $username): void
    {
        $response = $this->handle('POST', '/login', [], [
            'username' => $username,
            'password' => self::PASSWORD,
        ]);
        $this->assertSame(302, $response->getStatusCode());
    }
}
