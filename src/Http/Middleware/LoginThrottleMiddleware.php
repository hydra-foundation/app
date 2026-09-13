<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Hydra\Throttle\RateLimiter;
use Hydra\Throttle\RateLimitPolicy;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A tighter budget for the one route where a request is worth repeating: a
 * password guess. The application-wide limit is sized for reading pages, which
 * is far more attempts than anyone needs to sign in.
 *
 * This is how a per-route budget is declared. The #[Route] attribute names
 * middleware by class, so a route-specific policy is a class of its own rather
 * than an argument, and it holds its own numbers instead of reading them from
 * the environment: a limit this deliberate should not change without the
 * reasoning changing with it.
 */
final readonly class LoginThrottleMiddleware implements MiddlewareInterface
{
    /**
     * Attempts, and the window they are counted over. Ten minutes rather than
     * a minute: a guessing run paced to a slow limit is still a guessing run,
     * and a person who has forgotten their password is not helped by a fifth
     * attempt thirty seconds later either.
     */
    private const ATTEMPTS = 5;
    private const WINDOW = 600;

    public function __construct(private RateLimiter $limiter) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Its own name, so these attempts are counted separately from the
        // page views the same client spent on the global budget.
        $this->limiter->enforce($request, new RateLimitPolicy('login', self::ATTEMPTS, self::WINDOW));

        return $handler->handle($request);
    }
}
