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
 * Listen tokens are asked for once per page load, and again when one runs
 * out. Sixty a minute is a page a second, which no person reaches and a
 * reconnect loop gone wrong does.
 */
final readonly class StreamTokenThrottleMiddleware implements MiddlewareInterface
{
    private const REQUESTS = 60;
    private const WINDOW = 60;

    public function __construct(private RateLimiter $limiter) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->limiter->enforce($request, new RateLimitPolicy('stream-token', self::REQUESTS, self::WINDOW));

        return $handler->handle($request);
    }
}
