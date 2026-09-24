<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Entities\Activity;
use App\Entities\User;
use App\Repositories\ActivityRepository;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Http\ClientIpResolver;
use Hydra\Http\Exceptions\HttpException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function fnmatch;
use function hrtime;

/**
 * Writes one row per request: who, what, from where, how it ended and how long
 * it took. Sits inside StartSessionMiddleware (it needs the guard to answer
 * "who") and outside the auth/CSRF middleware, so a redirect to the login page
 * or a rejected token is recorded as the response the visitor actually got. An
 * error thrown further in is recorded at the status the error handler will
 * render and then re-thrown untouched, and a row that fails to write is
 * swallowed: the log observes the request, it never changes it, and an
 * unreachable activity table must not take the site down with it.
 *
 * Some paths are not worth a row. A dashboard card that refetches itself on a
 * timer writes one request a minute for as long as the tab is open, and those
 * rows swamp the very figures the card is drawing: left in, the admin's own
 * polling was 84% of a day's traffic, the busiest path on the site was the
 * traffic widget, and the average response time was the average of fetching
 * one card. A log that loud about watching itself is not observing the site.
 */
final class RecordActivityMiddleware implements MiddlewareInterface
{
    /**
     * Glob patterns, matched against the path. Every dashboard's widget
     * fragments rather than one named card, because the polling is a property
     * of the route — Definition mounts them all under "<screen>/w/<widget>" —
     * and a card added next month should not have to be remembered here.
     */
    private const IGNORED = [
        '/admin/*/w/*',
        // The emailed link's token is live until spent, and every signed-in
        // user can read this table.
        '/reset-password/*',
        '/verify-email/*',
        '/change-email/*',
    ];

    public function __construct(
        private readonly ActivityRepository $activity,
        private readonly GuardInterface $guard,
        private readonly LoggerInterface $logger,
        private readonly ClientIpResolver $clients = new ClientIpResolver,
        /** @var list<string> */
        private readonly array $ignore = self::IGNORED,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $started = hrtime(true);

        try {
            $response = $handler->handle($request);
        } catch (Throwable $e) {
            $this->record($request, $e instanceof HttpException ? $e->status() : 500, $started);

            throw $e;
        }

        $this->record($request, $response->getStatusCode(), $started);

        return $response;
    }

    private function record(ServerRequestInterface $request, int $status, int $started): void
    {
        $uri = $request->getUri();

        // Asked before the guard is, so an ignored path costs a glob and not a
        // session read on top of it — these arrive once a minute per open tab.
        if ($this->ignored($uri->getPath())) {
            return;
        }

        try {
            $user = $this->guard->user();

            $this->activity->record(new Activity(
                userId: $user === null ? null : (int) $user->getAuthIdentifier(),
                username: $user instanceof User ? $user->username : null,
                method: $request->getMethod(),
                path: $uri->getPath(),
                query: $uri->getQuery(),
                status: $status,
                durationMs: intdiv(hrtime(true) - $started, 1_000_000),
                ip: $this->clients->resolve($request),
                userAgent: $this->header($request, 'User-Agent'),
                referer: $this->header($request, 'Referer'),
            ));
        } catch (Throwable $e) {
            $this->logger->warning('Could not record activity: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * Matched with fnmatch rather than by prefix, so a pattern can say where
     * its wildcards go: the widget pattern above is every card on every
     * dashboard, and not everything mounted under the admin.
     */
    private function ignored(string $path): bool
    {
        foreach ($this->ignore as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    private function header(ServerRequestInterface $request, string $name): ?string
    {
        $value = $request->getHeaderLine($name);

        return $value === '' ? null : $value;
    }
}
