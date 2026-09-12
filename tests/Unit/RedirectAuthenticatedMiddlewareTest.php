<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\Middleware\RedirectAuthenticatedMiddleware;
use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Http\Responder;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Covers the middleware that keeps a signed-in user off the login page. The
 * htmx case is the one worth pinning: the redirect has to stay an ordinary
 * one, since htmx 4 reads no response header to follow.
 */
final class RedirectAuthenticatedMiddlewareTest extends TestCase
{
    public function test_guest_reaches_the_handler(): void
    {
        $handler = $this->handler();

        $response = $this->middleware(authenticated: false)->process($this->request(), $handler);

        $this->assertSame(1, $handler->calls);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_authenticated_browser_is_redirected_to_the_landing_page(): void
    {
        $handler = $this->handler();

        $response = $this->middleware(authenticated: true)->process($this->request(), $handler);

        $this->assertSame(0, $handler->calls, 'an authenticated visitor never reaches the guest route');
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin', $response->getHeaderLine('Location'));
    }

    public function test_htmx_gets_the_same_plain_redirect(): void
    {
        // HtmxRedirectMiddleware rewrites this further out into a body htmx
        // navigates on; this middleware stays unaware of htmx.
        $request = $this->request()->withHeader('HX-Request', 'true');

        $response = $this->middleware(authenticated: true)->process($request, $this->handler());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin', $response->getHeaderLine('Location'));
        $this->assertFalse($response->hasHeader('HX-Redirect'));
    }

    private function middleware(bool $authenticated): RedirectAuthenticatedMiddleware
    {
        $factory = new Psr17Factory;

        return new RedirectAuthenticatedMiddleware(
            new FakeGuestGuard($authenticated),
            new Responder($factory, $factory),
        );
    }

    private function request(): ServerRequestInterface
    {
        return (new Psr17Factory)->createServerRequest('GET', '/login');
    }

    private function handler(): CountingHandler
    {
        return new CountingHandler;
    }
}

/** Records whether the route behind the middleware was reached, and how often. */
final class CountingHandler implements RequestHandlerInterface
{
    public int $calls = 0;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->calls++;

        return (new Psr17Factory)->createResponse(200);
    }
}

/** A guard whose authentication state is fixed for the test. */
final class FakeGuestGuard implements GuardInterface
{
    public function __construct(private readonly bool $authenticated) {}

    public function check(): bool
    {
        return $this->authenticated;
    }

    public function user(): ?AuthenticatableInterface
    {
        return null;
    }

    public function id(): int|string|null
    {
        return null;
    }

    public function attempt(string $username, string $password): bool
    {
        return false;
    }

    public function login(AuthenticatableInterface $user): void {}

    public function logout(): void {}
}
