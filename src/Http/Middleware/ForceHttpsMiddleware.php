<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Config\AppConfig;
use Hydra\Http\Responder;
use Hydra\Http\Status;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Forces every request onto HTTPS when the app opts in (FORCE_HTTPS=true).
 *
 * Two jobs, both gated on the {@see AppConfig::$forceHttps} flag so local http
 * dev is untouched by default:
 *
 *   - an insecure request is answered with a 301 to the same URL on https,
 *     before any session or routing work — no point starting a session on a
 *     request we're about to redirect;
 *   - a secure request proceeds, and its response carries an HSTS header so the
 *     browser upgrades subsequent requests itself, without the round trip.
 *
 * "Secure" honours X-Forwarded-Proto: in our dev/prod stacks TLS terminates at
 * a proxy (Traefik), which forwards plain http here with the original scheme in
 * that header — trusting it is what makes the check work behind the proxy.
 *
 * It sits near the OUTERMOST of the stack (just inside the header/logging
 * decorators, outside the error handler): the upgrade should happen before the
 * app does any real work, and it must see the request before the router.
 */
final class ForceHttpsMiddleware implements MiddlewareInterface
{
    /** One year, and apply to subdomains — the conventional HSTS baseline. */
    private const HSTS = 'max-age=31536000; includeSubDomains';

    public function __construct(
        private readonly AppConfig $config,
        private readonly Responder $respond,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->config->forceHttps) {
            return $handler->handle($request);
        }

        if (!$this->isSecure($request)) {
            // Drop any explicit port: an http URL may carry :80, which is wrong
            // for https — clearing it lets the default 443 apply.
            $secureUrl = $request->getUri()->withScheme('https')->withPort(null);

            return $this->respond->redirect((string) $secureUrl, Status::MovedPermanently);
        }

        return $handler->handle($request)
            ->withHeader('Strict-Transport-Security', self::HSTS);
    }

    private function isSecure(ServerRequestInterface $request): bool
    {
        if ($request->getUri()->getScheme() === 'https') {
            return true;
        }

        // TLS terminated at a proxy that forwarded the original scheme.
        return strtolower($request->getHeaderLine('X-Forwarded-Proto')) === 'https';
    }
}
