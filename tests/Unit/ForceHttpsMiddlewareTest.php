<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Config\AppConfig;
use App\Http\Middleware\ForceHttpsMiddleware;
use Hydra\Http\Responder;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ForceHttpsMiddlewareTest extends TestCase
{
    public function test_passes_through_untouched_when_disabled(): void
    {
        $handler = $this->handler();

        $response = $this->middleware(forceHttps: false)
            ->process($this->request('http://hydra.test/login'), $handler);

        $this->assertSame(1, $handler->calls, 'handler should run');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Strict-Transport-Security'));
    }

    public function test_redirects_insecure_request_to_https_with_a_301(): void
    {
        $handler = $this->handler();

        $response = $this->middleware(forceHttps: true)
            ->process($this->request('http://hydra.test/login?next=/x'), $handler);

        $this->assertSame(0, $handler->calls, 'handler must not run for a redirect');
        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('https://hydra.test/login?next=/x', $response->getHeaderLine('Location'));
    }

    public function test_redirect_drops_an_explicit_http_port(): void
    {
        $response = $this->middleware(forceHttps: true)
            ->process($this->request('http://hydra.test:80/login'), $this->handler());

        $this->assertSame('https://hydra.test/login', $response->getHeaderLine('Location'));
    }

    public function test_secure_request_proceeds_and_gets_hsts(): void
    {
        $handler = $this->handler();

        $response = $this->middleware(forceHttps: true)
            ->process($this->request('https://hydra.test/login'), $handler);

        $this->assertSame(1, $handler->calls);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('max-age=', $response->getHeaderLine('Strict-Transport-Security'));
    }

    public function test_trusts_x_forwarded_proto_for_proxy_terminated_tls(): void
    {
        $handler = $this->handler();
        $request = $this->request('http://hydra.test/login')->withHeader('X-Forwarded-Proto', 'https');

        $response = $this->middleware(forceHttps: true)->process($request, $handler);

        $this->assertSame(1, $handler->calls, 'forwarded https should count as secure');
        $this->assertStringContainsString('max-age=', $response->getHeaderLine('Strict-Transport-Security'));
    }

    private function middleware(bool $forceHttps): ForceHttpsMiddleware
    {
        $factory = new Psr17Factory;

        $config = new AppConfig(
            name: 'Hydra',
            url: 'http://hydra.test',
            debug: false,
            timezone: 'UTC',
            key: 'k',
            forceHttps: $forceHttps,
        );

        return new ForceHttpsMiddleware($config, new Responder($factory, $factory));
    }

    private function request(string $uri): ServerRequestInterface
    {
        return (new Psr17Factory)->createServerRequest('GET', $uri);
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public int $calls = 0;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->calls++;
                return (new Psr17Factory)->createResponse(200);
            }
        };
    }
}
