<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Stamps a small set of conservative security headers onto every response.
 *
 * These are headers no app should ship without and whose value is the same for
 * almost every app, so the framework can pick a safe default the way it can't
 * for a Content-Security-Policy (which is bound to a specific app's asset
 * origins — left deliberately to the app, not guessed here):
 *
 *   - X-Content-Type-Options: nosniff  — stop the browser MIME-sniffing a
 *     response into a type it wasn't sent as (a classic XSS vector).
 *   - X-Frame-Options: SAMEORIGIN      — refuse to be framed cross-origin, so
 *     the UI can't be clickjacked.
 *   - Referrer-Policy: strict-origin-when-cross-origin — send the full URL as
 *     referrer same-origin, only the origin cross-origin, nothing when
 *     downgrading https→http.
 *
 * It sits OUTERMOST in the stack so the headers land on every response that
 * leaves the app — including the 500 the error handler synthesises and the 301
 * the https redirect returns, both of which are produced inside it. It only
 * decorates the response on the way out and never throws, so wrapping the error
 * handler doesn't weaken it.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /** @var array<string, string> Header name => value, applied to every response. */
    private const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        foreach (self::HEADERS as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
