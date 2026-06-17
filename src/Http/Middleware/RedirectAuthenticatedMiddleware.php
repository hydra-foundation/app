<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Htmx;
use App\Http\HtmxResponse;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Http\Responder;
use Hydra\Http\Status;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The mirror of {@see RedirectUnauthenticatedMiddleware}: guards the "guest"
 * routes (login, registration) by sending an already-authenticated visitor away
 * from them instead of showing the form again.
 *
 * It's a per-route middleware, the counterpart to auth's AuthenticateMiddleware
 * — drop it on a route's `middleware:` list. Unlike the unauthenticated case it
 * asks the guard directly rather than catching an exception: there's no 401 to
 * map here, just a "you're already in" check the route makes before running.
 *
 * Where to send them is app policy (the app owns its routes), so the
 * destination lives here, and the redirect is spoken in the request's
 * transport: a 302 for a browser, an HX-Redirect for htmx (which would
 * otherwise swallow a 302's body).
 */
final class RedirectAuthenticatedMiddleware implements MiddlewareInterface
{
    /** Where an already-authenticated visitor is sent — the post-login landing. */
    private const HOME_PATH = '/dashboard';

    public function __construct(
        private readonly GuardInterface $guard,
        private readonly Responder $respond,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->guard->check()) {
            return $handler->handle($request);
        }

        if (Htmx::fromRequest($request)->isHtmx()) {
            return (new HtmxResponse)
                ->redirect(self::HOME_PATH)
                ->applyTo($this->respond->noContent(Status::NoContent));
        }

        return $this->respond->redirect(self::HOME_PATH);
    }
}
