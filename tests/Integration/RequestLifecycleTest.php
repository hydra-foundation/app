<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Container;
use App\Providers\AppServiceProvider;
use App\Tests\Support\ArraySessionServiceProvider;
use Hydra\Core\Application;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Contracts\KernelInterface;
use Hydra\Core\Environment;
use Hydra\Http\HttpKernel;
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
        $container = new Container(new \DI\Container);
        $container->instance(ContainerInterface::class, $container);
        $container->instance(Environment::class, new Environment(__DIR__));

        (new Application($container))
            ->register(new ArraySessionServiceProvider)
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

    public function testKernelGraphResolves(): void
    {
        // Resolving the kernel constructs the entire object graph (request
        // provider, pipeline, router, emitter, logger) — a wiring typo fails here.
        $this->assertInstanceOf(HttpKernel::class, $this->container->get(KernelInterface::class));
    }

    public function testRootRouteReturnsWelcome(): void
    {
        $response = $this->handle('GET', '/');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('Welcome to Hydra', (string) $response->getBody());
        // The simplified home route always renders the full layout.
        $this->assertStringContainsString('<!doctype html>', (string) $response->getBody());
    }

    public function testUnknownPathRendersA404(): void
    {
        // The Router throws NotFoundException; the pipeline's ErrorHandlerMiddleware
        // catches it and renders a response — handle() never throws to the SAPI.
        $response = $this->handle('GET', '/does-not-exist');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Not Found', (string) $response->getBody());
    }

    public function testHeadRequestIsServedByTheGetRoute(): void
    {
        // Proves HEAD->GET fallback survives the full pipeline, not just the unit.
        $response = $this->handle('HEAD', '/');

        $this->assertSame(200, $response->getStatusCode());
    }
}
