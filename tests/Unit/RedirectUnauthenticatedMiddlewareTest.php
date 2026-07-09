<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Http\Middleware\RedirectUnauthenticatedMiddleware;
use Hydra\Auth\Exceptions\AuthenticationException;
use Hydra\Csrf\CsrfGuard;
use Hydra\Csrf\Exceptions\TokenMismatchException;
use Hydra\Http\Responder;
use Hydra\Session\Stores\ArraySessionStore;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * The app's login-redirect policy: auth's 401 always becomes a redirect, and
 * csrf's 403 becomes one ONLY when the session holds no token at all (the
 * expired-session POST) — a mismatch against an issued token is a real CSRF
 * failure and stays a 403.
 */
final class RedirectUnauthenticatedMiddlewareTest extends TestCase
{
    private ArraySessionStore $session;

    protected function setUp(): void
    {
        $this->session = new ArraySessionStore;
        $this->session->start();
    }

    public function test_a_clean_response_passes_through_untouched(): void
    {
        $response = $this->middleware()->process($this->request(), $this->handler());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_authentication_exception_redirects_to_login(): void
    {
        $response = $this->middleware()
            ->process($this->request(), $this->throwingHandler(new AuthenticationException));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaderLine('Location'));
    }

    public function test_token_mismatch_without_an_issued_token_redirects_to_login(): void
    {
        // The expired-session POST: the old session (and its token) is gone,
        // the fresh session never minted one, so the CSRF check failed — the
        // user should land on the login page, not a bare 403.
        $response = $this->middleware()
            ->process($this->request(), $this->throwingHandler(new TokenMismatchException));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaderLine('Location'));
    }

    public function test_token_mismatch_without_an_issued_token_on_htmx_gets_an_hx_redirect(): void
    {
        $request = $this->request()->withHeader('HX-Request', 'true');

        $response = $this->middleware()
            ->process($request, $this->throwingHandler(new TokenMismatchException));

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaderLine('HX-Redirect'));
    }

    public function test_token_mismatch_against_an_issued_token_is_rethrown(): void
    {
        // A live session that HAS a token and still failed validation is a real
        // CSRF failure — swallowing it into a redirect would blunt the
        // protection.
        (new CsrfGuard($this->session))->token(); // mint: the session now has a token

        $this->expectException(TokenMismatchException::class);

        $this->middleware()
            ->process($this->request(), $this->throwingHandler(new TokenMismatchException));
    }

    public function test_other_exceptions_pass_out_unchanged(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->middleware()
            ->process($this->request(), $this->throwingHandler(new \RuntimeException('boom')));
    }

    private function middleware(): RedirectUnauthenticatedMiddleware
    {
        $factory = new Psr17Factory;

        return new RedirectUnauthenticatedMiddleware(
            new Responder($factory, $factory),
            new CsrfGuard($this->session),
        );
    }

    private function request(): ServerRequestInterface
    {
        return (new Psr17Factory)->createServerRequest('POST', '/profile');
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Psr17Factory)->createResponse(200);
            }
        };
    }

    private function throwingHandler(Throwable $e): RequestHandlerInterface
    {
        return new class($e) implements RequestHandlerInterface {
            public function __construct(private readonly Throwable $e) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw $this->e;
            }
        };
    }
}
