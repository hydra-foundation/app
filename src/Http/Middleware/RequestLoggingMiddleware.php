<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Writes one access-log line per request: method, path, status, duration.
 *
 * It sits OUTERMOST in the stack so it times the whole pipeline and always sees
 * a finished response — the error handler beneath it turns any throwable into a
 * 500, so even a failed request returns here with a real status to log. That
 * makes this the access log (every request, its final status) as distinct from
 * the error handler's error log (the throwable and its stack); a 500 produces
 * one line from each, by design.
 *
 * The structured context goes to PSR-3 as fields, not interpolated into the
 * message, so a structured sink keeps them queryable.
 */
final class RequestLoggingMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $start = microtime(true);

        $response = $handler->handle($request);

        $this->logger->info('request handled', [
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'status' => $response->getStatusCode(),
            'duration_ms' => round((microtime(true) - $start) * 1000, 2),
        ]);

        return $response;
    }
}
