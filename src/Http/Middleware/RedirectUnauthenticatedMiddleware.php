<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Hydra\Auth\Exceptions\AuthenticationException;
use Hydra\Csrf\CsrfGuard;
use Hydra\Csrf\Exceptions\TokenMismatchException;
use Hydra\Http\Responder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Redirect unauthenticated user middleware
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
            return $this->redirectToLogin();
        } catch (TokenMismatchException $e) {
            if ($this->csrf->issued()) {
                throw $e;
            }

            return $this->redirectToLogin();
        }
    }

    private function redirectToLogin(): ResponseInterface
    {
        return $this->respond->redirect(self::LOGIN_PATH);
    }
}
