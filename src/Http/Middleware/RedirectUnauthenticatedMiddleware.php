<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Hydra\Http\Htmx;
use Hydra\Http\HtmxResponse;
use Hydra\Auth\Exceptions\AuthenticationException;
use Hydra\Csrf\CsrfGuard;
use Hydra\Csrf\Exceptions\TokenMismatchException;
use Hydra\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Turns "you are not (or no longer) logged in" signals into a redirect to the
 * login page — the app-side policy the framework packages deliberately leave to
 * the application. Two typed exceptions are handled:
 *
 *   - auth's {@see AuthenticationException} (a 401): the per-route
 *     AuthenticateMiddleware found no user. Always a redirect to /login.
 *   - csrf's {@see TokenMismatchException} (a 403), but ONLY when the session
 *     holds no CSRF token at all ({@see CsrfGuard::issued()}). A session with
 *     no token cannot validate anything — the classic symptom of an EXPIRED
 *     session behind a stale form: the user loaded a page, the session died,
 *     and their submit arrives at a fresh session that never minted a token.
 *     Without this rule they'd see a bare 403 where they expected the login
 *     page. When a token IS issued and simply doesn't match, the exception is
 *     rethrown unchanged: that is a genuine CSRF failure (or a page rendered
 *     before an explicit rotate()) and the 403 is correct — swallowing it
 *     would blunt the protection.
 *
 * The expired-session check reads the CsrfGuard (session-only, cheap) rather
 * than the auth guard: asking the guard would resolve the user provider — and
 * with it the database connection — on EVERY request, just to sit in a catch
 * block that almost never fires.
 *
 * The redirect speaks the transport of the request:
 *
 *   - a plain browser gets a 302 with a Location header (a real navigation);
 *   - an htmx request gets an HX-Redirect header, since htmx swallows the body
 *     of a normal redirect — only that header makes the browser navigate.
 *
 * Every other HttpException (a 404, authorization's 403) sails past to the
 * outer ErrorHandlerMiddleware unchanged. Placement: inside the session
 * middleware (the CsrfGuard reads the started session) but OUTSIDE
 * VerifyCsrfTokenMiddleware — it can only catch the token mismatch if it wraps
 * the middleware that throws it.
 */
final class RedirectUnauthenticatedMiddleware implements MiddlewareInterface
{
    /** Where an unauthenticated visitor is sent — the app's own login route. */
    private const LOGIN_PATH = '/login';

    public function __construct(
        private readonly Responder $respond,
        private readonly CsrfGuard $csrf,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (AuthenticationException) {
            return $this->redirectToLogin($request);
        } catch (TokenMismatchException $e) {
            // A mismatch against an ISSUED token is a real CSRF failure —
            // rethrow so the error handler renders the 403. Only the no-token
            // (expired session) variant becomes a login redirect.
            if ($this->csrf->issued()) {
                throw $e;
            }

            return $this->redirectToLogin($request);
        }
    }

    private function redirectToLogin(ServerRequestInterface $request): ResponseInterface
    {
        if (Htmx::fromRequest($request)->isHtmx()) {
            return (new HtmxResponse)
                ->redirect(self::LOGIN_PATH)
                ->applyTo($this->respond->noContent());
        }

        return $this->respond->redirect(self::LOGIN_PATH);
    }
}
