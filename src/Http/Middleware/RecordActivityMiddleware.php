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
 */
final class RecordActivityMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ActivityRepository $activity,
        private readonly GuardInterface $guard,
        private readonly LoggerInterface $logger,
        private readonly ClientIpResolver $clients = new ClientIpResolver,
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
        try {
            $user = $this->guard->user();
            $uri = $request->getUri();

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

    private function header(ServerRequestInterface $request, string $name): ?string
    {
        $value = $request->getHeaderLine($name);

        return $value === '' ? null : $value;
    }
}
