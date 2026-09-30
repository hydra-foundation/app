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
    /** Where an unauthenticated visitor is sent: the app's own login route. */
    private const LOGIN_PATH = '/login';

    private const API_PREFIX = '/api/';

    public function __construct(
        private readonly Responder $respond,
        private readonly CsrfGuard $csrf,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (AuthenticationException $e) {
            // A client with no browser to send anywhere: the 401 is the answer.
            // So is a page's own fetch() asking for JSON, which would follow
            // the redirect and get the login form where it expected data.
            if (
                $request->hasHeader('Authorization')
                || str_starts_with($request->getUri()->getPath(), self::API_PREFIX)
                || self::wantsJson($request)
            ) {
                throw $e;
            }

            return $this->redirectToLogin();
        } catch (TokenMismatchException $e) {
            if ($this->csrf->issued()) {
                throw $e;
            }

            return $this->redirectToLogin();
        }
    }

    /** Asks for JSON and not for a page: a script, not a person at a browser. */
    private static function wantsJson(ServerRequestInterface $request): bool
    {
        $accept = $request->getHeaderLine('Accept');

        return str_contains($accept, 'application/json') && !str_contains($accept, 'text/html');
    }

    private function redirectToLogin(): ResponseInterface
    {
        return $this->respond->redirect(self::LOGIN_PATH);
    }
}
