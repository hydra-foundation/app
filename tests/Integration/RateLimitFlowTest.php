<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Providers\AppServiceProvider;
use App\Tests\Support\ArrayCacheServiceProvider;
use App\Tests\Support\ArraySessionServiceProvider;
use App\Tests\Support\FixedSignerServiceProvider;
use App\Tests\Support\QuietLogServiceProvider;
use App\Tests\Support\TestAdminProvider;
use App\Tests\Support\TestHttpProvider;
use App\Tests\Support\TestSchema;
use Hydra\Auth\AuthServiceProvider;
use Hydra\Authorization\AuthorizationServiceProvider;
use Hydra\Core\Application;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Environment;
use Hydra\Csrf\CsrfGuard;
use Hydra\Database\Contracts\ConnectionInterface;
use Hydra\Database\PdoConnection;
use Hydra\Event\EventServiceProvider;
use Hydra\Nyholm\NyholmServiceProvider;
use Hydra\PhpDi\Container;
use Hydra\Session\Contracts\SessionLifecycleInterface;
use Hydra\Throttle\ThrottleConfig;
use Hydra\Throttle\ThrottleServiceProvider;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The limiter through the real stack, which is the only place some of this can
 * be seen: that a refusal is rendered rather than thrown at the SAPI, that it
 * is shaped for whoever asked, and that the login route carries a budget of its
 * own rather than the one every page view spends.
 */
#[CoversNothing]
final class RateLimitFlowTest extends TestCase
{
    /** Small enough to reach in a test, large enough that the page under it loads first. */
    private const GLOBAL_LIMIT = 3;

    private ContainerInterface $container;

    protected function setUp(): void
    {
        $this->boot(new ThrottleConfig(limit: self::GLOBAL_LIMIT, window: 60));
    }

    /**
     * The real composition root, with one budget swapped in. The override lands
     * AFTER the providers have registered: the container is last-write-wins, so
     * a config bound before them is the one that gets replaced.
     */
    private function boot(ThrottleConfig $throttle): void
    {
        $container = Container::create();
        $container->instance(ContainerInterface::class, $container);
        $container->instance(Environment::class, new Environment(__DIR__));

        (new Application($container))
            ->register(new ArraySessionServiceProvider)
            ->register(new NyholmServiceProvider)
            ->register(new FixedSignerServiceProvider)
            ->register(new EventServiceProvider)
            ->register(new ArrayCacheServiceProvider)
            ->register(new ThrottleServiceProvider)
            ->register(TestHttpProvider::make())
            ->register(new AuthServiceProvider)
            ->register(new AuthorizationServiceProvider)
            ->register(new AppServiceProvider)
            ->register(new QuietLogServiceProvider)
            ->register(TestAdminProvider::make())
            ->boot();

        $container->instance(ThrottleConfig::class, $throttle);
        $container->instance(ConnectionInterface::class, new PdoConnection(TestSchema::connect()));

        $this->container = $container;
    }

    public function test_a_client_within_the_budget_is_served(): void
    {
        $this->assertSame(200, $this->get('/')->getStatusCode());
    }

    public function test_the_request_past_the_budget_is_refused_with_a_retry_after(): void
    {
        for ($i = 0; $i < self::GLOBAL_LIMIT; $i++) {
            $this->assertSame(200, $this->get('/')->getStatusCode());
        }

        $response = $this->get('/');

        $this->assertSame(429, $response->getStatusCode());
        // Rendered, not thrown past the pipeline: the limiter sits inside the
        // error handler precisely so a refusal has a response to be.
        $this->assertGreaterThan(0, (int) $response->getHeaderLine('Retry-After'));
        $this->assertLessThanOrEqual(60, (int) $response->getHeaderLine('Retry-After'));
    }

    public function test_a_refusal_is_shaped_for_whoever_asked(): void
    {
        for ($i = 0; $i < self::GLOBAL_LIMIT; $i++) {
            $this->get('/');
        }

        $json = $this->get('/', ['Accept' => 'application/json']);

        $this->assertSame(429, $json->getStatusCode());
        $this->assertStringContainsString('application/json', $json->getHeaderLine('Content-Type'));

        // And for htmx, a fragment retargeted at the layout's error region, so
        // the element the request came from keeps what the reader was looking
        // at. The retarget is markup, not a header: htmx 4 reads no response
        // headers, so the directive travels out-of-band in the body.
        $htmx = $this->get('/', ['HX-Request' => 'true']);

        $this->assertSame(429, $htmx->getStatusCode());
        $this->assertStringContainsString('hx-swap-oob="innerHTML:#app-error"', (string) $htmx->getBody());
    }

    public function test_another_client_still_has_its_own_budget(): void
    {
        for ($i = 0; $i < self::GLOBAL_LIMIT + 1; $i++) {
            $this->get('/');
        }

        $this->assertSame(200, $this->get('/', peer: '203.0.113.9')->getStatusCode());
    }

    public function test_signing_in_is_budgeted_apart_from_reading_pages(): void
    {
        // The global budget is spent first. A login attempt must not already be
        // refused by it, and must not have spent the login budget either.
        for ($i = 0; $i < self::GLOBAL_LIMIT; $i++) {
            $this->get('/');
        }

        $this->assertSame(429, $this->get('/')->getStatusCode());

        // The per-route limiter runs inside the router, so the global one has
        // already refused this. Use a fresh client to see the login budget.
        $response = $this->post('/login', peer: '203.0.113.9');

        $this->assertNotSame(429, $response->getStatusCode());
    }

    public function test_the_login_budget_is_tighter_than_the_page_budget(): void
    {
        // A global budget far from spent, so the refusal below can only be the
        // login policy's own: five attempts, then no more.
        $this->boot(new ThrottleConfig(limit: 100, window: 60));

        $statuses = [];

        for ($i = 0; $i < 6; $i++) {
            $statuses[] = $this->post('/login')->getStatusCode();
        }

        $this->assertNotContains(429, array_slice($statuses, 0, 5));
        $this->assertSame(429, $statuses[5]);
    }

    /** @param array<string, string> $headers */
    private function get(string $path, array $headers = [], string $peer = '198.51.100.7'): ResponseInterface
    {
        return $this->handle('GET', $path, $headers, $peer);
    }

    /**
     * A post carrying the session's CSRF token. Without one the guard refuses
     * the request in the global stack, ahead of the router, and the route's own
     * budget never sees it: cheap requests are turned away by the cheaper check.
     */
    private function post(string $path, string $peer = '198.51.100.7'): ResponseInterface
    {
        $this->container->get(SessionLifecycleInterface::class)->start();

        return $this->handle('POST', $path, [
            'X-CSRF-Token' => $this->container->get(CsrfGuard::class)->token(),
        ], $peer);
    }

    /** @param array<string, string> $headers */
    private function handle(string $method, string $path, array $headers, string $peer): ResponseInterface
    {
        $request = (new Psr17Factory)->createServerRequest($method, $path, ['REMOTE_ADDR' => $peer]);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->container->get(RequestHandlerInterface::class)->handle($request);
    }
}
