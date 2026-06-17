<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Htmx;
use App\Http\HtmxResponse;
use Hydra\Auth\Exceptions\AuthenticationException;
use Hydra\Http\Responder;
use Hydra\Http\Status;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Turns auth's 401 into a redirect to the login page — the app-side policy auth
 * deliberately leaves to the application.
 *
 * The auth package owns no routes, so its {@see AuthenticateMiddleware} only
 * signals "not authenticated" by throwing an {@see AuthenticationException} (a
 * 401 HttpException). This catches that one exception and decides where to send
 * the visitor, in the transport the request speaks:
 *
 *   - a plain browser gets a 302 with a Location header (a real navigation);
 *   - an htmx request gets an HX-Redirect header, since htmx swallows the body
 *     of a normal redirect — only that header makes the browser navigate.
 *
 * It catches AuthenticationException ONLY; every other HttpException (a 404, a
 * CSRF 403) sails past to the outer ErrorHandlerMiddleware unchanged. It must
 * therefore sit inside the error handler but outside the router in the stack.
 */
final class RedirectUnauthenticatedMiddleware implements MiddlewareInterface
{
    /** Where an unauthenticated visitor is sent — the app's own login route. */
    private const LOGIN_PATH = '/login';

    public function __construct(private readonly Responder $respond) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (AuthenticationException) {
            if (Htmx::fromRequest($request)->isHtmx()) {
                return (new HtmxResponse)
                    ->redirect(self::LOGIN_PATH)
                    ->applyTo($this->respond->noContent(Status::NoContent));
            }

            return $this->respond->redirect(self::LOGIN_PATH);
        }
    }
}
