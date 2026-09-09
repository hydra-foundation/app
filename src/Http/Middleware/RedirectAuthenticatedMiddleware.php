<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Redirect authenticated user middleware
 */
final class RedirectAuthenticatedMiddleware implements MiddlewareInterface
{
    /** Where an already-authenticated visitor is sent — the post-login landing. */
    private const HOME_PATH = '/admin';

    public function __construct(
        private readonly GuardInterface $guard,
        private readonly Responder $respond,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->guard->check()) {
            return $handler->handle($request);
        }

        return $this->respond->redirect(self::HOME_PATH);
    }
}
