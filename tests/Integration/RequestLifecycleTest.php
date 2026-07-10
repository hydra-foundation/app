<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Hydra\PhpDi\Container;
use App\Providers\AppServiceProvider;
use App\Tests\Support\ArraySessionServiceProvider;
use App\Tests\Support\TestHttpProvider;
use Hydra\Core\Application;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Contracts\KernelInterface;
use App\Tests\Support\FixedSignerServiceProvider;
use Hydra\Core\Environment;
use Hydra\Http\HttpKernel;
use Hydra\Nyholm\NyholmServiceProvider;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Exercises the real composition root end-to-end: the same container and
 * AppServiceProvider that public/index.php wires, driven by actual nyholm
 * requests. Unit tests mock the seams; this one proves they actually connect —
 * a wrong binding id or a circular get() shows up here, not at curl-time.
 */
final class RequestLifecycleTest extends TestCase
{
    private ContainerInterface $container;

    protected function setUp(): void
    {
        // Mirror public/index.php, minus run() (no SAPI emit). __DIR__ has no
        // .env, so the app boots on defaults (APP_DEBUG off, log to stderr).
        $container = Container::create();
        $container->instance(ContainerInterface::class, $container);
        $container->instance(Environment::class, new Environment(__DIR__));

        (new Application($container))
            ->register(new ArraySessionServiceProvider)
            ->register(new NyholmServiceProvider)
            // The CSRF guard signs with a Signer; bind a fixed-key one (the .env-less
            // Environment has no APP_KEY for the real provider to read).
            ->register(new FixedSignerServiceProvider)
            ->register(TestHttpProvider::make())
            ->register(new AppServiceProvider)
            ->boot();

        $this->container = $container;
    }

    /** @param array<string, string> $headers */
    private function handle(string $method, string $path, array $headers = []): ResponseInterface
    {
        $request = (new Psr17Factory)->createServerRequest($method, $path);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->container->get(RequestHandlerInterface::class)->handle($request);
    }

    public function test_kernel_graph_resolves(): void
    {
        // Resolving the kernel constructs the entire object graph (request
        // provider, pipeline, router, emitter, logger) — a wiring typo fails here.
        $this->assertInstanceOf(HttpKernel::class, $this->container->get(KernelInterface::class));
    }

    public function test_root_route_returns_welcome(): void
    {
        $response = $this->handle('GET', '/');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('Welcome to Hydra', (string) $response->getBody());
        // The simplified home route always renders the full layout.
        $this->assertStringContainsString('<!doctype html>', (string) $response->getBody());
    }

    public function test_unknown_path_renders_a404(): void
    {
        // The Router throws NotFoundException; the pipeline's ErrorHandlerMiddleware
        // catches it and renders a response — handle() never throws to the SAPI.
        $response = $this->handle('GET', '/does-not-exist');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Not Found', (string) $response->getBody());
    }

    public function test_head_request_is_served_by_the_get_route(): void
    {
        // Proves HEAD->GET fallback survives the full pipeline, not just the unit.
        $response = $this->handle('HEAD', '/');

        $this->assertSame(200, $response->getStatusCode());
    }
}
