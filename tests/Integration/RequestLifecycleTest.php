<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Hydra\PhpDi\Container;
use App\Config\CspConfig;
use App\Providers\AppServiceProvider;
use App\Tests\Support\ArraySessionServiceProvider;
use App\Tests\Support\TestAdminProvider;
use App\Tests\Support\TestHttpProvider;
use App\Tests\Support\TestSchema;
use Hydra\Auth\AuthServiceProvider;
use Hydra\Authorization\AuthorizationServiceProvider;
use Hydra\Core\Application;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Contracts\KernelInterface;
use App\Tests\Support\FixedSignerServiceProvider;
use Hydra\Core\Environment;
use Hydra\Database\Contracts\ConnectionInterface;
use Hydra\Database\PdoConnection;
use Hydra\Http\HttpKernel;
use Hydra\Event\EventServiceProvider;
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
            ->register(new EventServiceProvider)
            ->register(TestHttpProvider::make())
            ->register(new AuthServiceProvider)
            ->register(new AuthorizationServiceProvider)
            ->register(new AppServiceProvider)
            ->register(TestAdminProvider::make())
            ->boot();

        // The activity middleware writes a row per request; give it somewhere
        // to write that isn't the developer's MariaDB.
        $container->instance(ConnectionInterface::class, new PdoConnection(TestSchema::connect()));

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

    public function test_every_response_carries_the_content_security_policy(): void
    {
        $policy = $this->handle('GET', '/')->getHeaderLine('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("form-action 'self'", $policy);
        $this->assertStringContainsString("img-src 'self' data:", $policy);
        $this->assertStringContainsString('font-src \'self\' https://fonts.gstatic.com', $policy);
        $this->assertStringContainsString('style-src \'self\' https://fonts.googleapis.com', $policy);
    }

    public function test_the_policys_nonce_is_the_one_the_page_was_rendered_with(): void
    {
        // The header and the markup are written by different parts of the
        // pipeline; a page whose nonce does not match its own policy would load
        // with every inline script blocked.
        $response = $this->handle('GET', '/');

        $this->assertSame(
            1,
            preg_match("/script-src 'self' 'nonce-([A-Za-z0-9_-]+)'/", $response->getHeaderLine('Content-Security-Policy'), $header),
        );
        $this->assertStringContainsString(sprintf('<script nonce="%s"', $header[1]), (string) $response->getBody());
    }

    /**
     * hx-csp recovers a swapped fragment's nonce from the policy header on the
     * response that carried it, so whichever header is sent has to name the
     * nonce the markup was stamped with. Report-only is the mode a policy is
     * rolled out in: if only the enforcing header ever named the nonce, every
     * swap during that rollout would arrive unrecognised and be stripped.
     */
    public function test_report_only_sends_the_nonce_under_the_report_only_header(): void
    {
        $this->container->instance(CspConfig::class, new CspConfig(
            enabled: true,
            reportOnly: true,
            reportUri: '',
        ));

        $response = $this->handle('GET', '/');

        $this->assertSame('', $response->getHeaderLine('Content-Security-Policy'));

        $this->assertSame(
            1,
            preg_match(
                "/script-src 'self' 'nonce-([A-Za-z0-9_-]+)'/",
                $response->getHeaderLine('Content-Security-Policy-Report-Only'),
                $header,
            ),
        );
        $this->assertStringContainsString(sprintf('<script nonce="%s"', $header[1]), (string) $response->getBody());
    }

    /**
     * Turning CSP off has to turn the htmx gate off with it. The gate is armed
     * by naming the extension in htmx-config, and it strips any element whose
     * nonce it cannot match against the response's policy — so leaving it armed
     * with no policy to read would break every swap on a page that is meant to
     * be running without a policy at all.
     */
    public function test_disabling_the_policy_disarms_the_htmx_gate(): void
    {
        $this->container->instance(CspConfig::class, new CspConfig(
            enabled: false,
            reportOnly: false,
            reportUri: '',
        ));

        $response = $this->handle('GET', '/');

        $this->assertSame('', $response->getHeaderLine('Content-Security-Policy'));
        $this->assertSame('', $response->getHeaderLine('Content-Security-Policy-Report-Only'));
        // The extension's file still loads and is inert; naming it in
        // htmx-config is what would arm it, so that is what must be absent.
        $this->assertStringNotContainsString('extensions:"hx-csp"', (string) $response->getBody());
    }

    public function test_an_enforced_policy_arms_the_htmx_gate(): void
    {
        $this->assertStringContainsString(
            'extensions:"hx-csp"',
            (string) $this->handle('GET', '/')->getBody(),
        );
    }

    public function test_one_container_holds_one_nonce(): void
    {
        // What makes the header and the page agree: every reader resolves the
        // same CspNonce out of the container, and a real SAPI builds one
        // container per request. A second instance would mint a second token
        // and leave the policy naming a nonce the page never carried.
        $first = $this->handle('GET', '/')->getHeaderLine('Content-Security-Policy');
        $second = $this->handle('GET', '/')->getHeaderLine('Content-Security-Policy');

        $this->assertSame($first, $second);
    }
}
