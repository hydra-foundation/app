<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entities\Role;
use App\Entities\User;
use App\Http\Middleware\RecordActivityMiddleware;
use App\Repositories\ActivityRepository;
use App\Tests\Support\TestSchema;
use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Http\ClientIpResolver;
use Hydra\Http\TrustedProxies;
use Hydra\Database\Contracts\ConnectionInterface;
use Hydra\Database\PdoConnection;
use Hydra\Http\Exceptions\HttpException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

/**
 * The parts of the activity recorder that the end-to-end flow can't reach: whose
 * address gets written down, and what happens when the log itself fails.
 */
final class RecordActivityMiddlewareTest extends TestCase
{
    private ConnectionInterface $db;

    protected function setUp(): void
    {
        $this->db = new PdoConnection(TestSchema::connect());
    }

    public function test_the_socket_peer_is_recorded_by_default(): void
    {
        $this->middleware()->process(
            $this->request(['REMOTE_ADDR' => '10.0.0.8'])->withHeader('X-Forwarded-For', '203.0.113.5'),
            $this->handler(),
        );

        // The header is present but not trusted: a direct client could have
        // invented it, so the address PHP actually saw wins.
        $this->assertSame('10.0.0.8', $this->row()['ip']);
    }

    public function test_a_trusted_forwarded_header_names_the_original_client(): void
    {
        $this->middleware(trustedProxies: ['10.0.0.0/8'])->process(
            $this->request(['REMOTE_ADDR' => '10.0.0.8'])
                ->withHeader('X-Forwarded-For', '203.0.113.5, 10.0.0.2'),
            $this->handler(),
        );

        $this->assertSame('203.0.113.5', $this->row()['ip']);
    }

    public function test_a_client_cannot_name_itself_by_prepending_a_hop(): void
    {
        // The recorded address is what a per-client limit would be keyed on, so
        // a caller that can choose it can choose a fresh budget every request.
        $this->middleware(trustedProxies: ['10.0.0.0/8'])->process(
            $this->request(['REMOTE_ADDR' => '10.0.0.8'])
                ->withHeader('X-Forwarded-For', '1.2.3.4, 203.0.113.5'),
            $this->handler(),
        );

        $this->assertSame('203.0.113.5', $this->row()['ip']);
    }

    public function test_a_trusted_proxy_that_sent_no_header_falls_back_to_the_peer(): void
    {
        $this->middleware(trustedProxies: ['10.0.0.0/8'])
            ->process($this->request(['REMOTE_ADDR' => '10.0.0.8']), $this->handler());

        $this->assertSame('10.0.0.8', $this->row()['ip']);
    }

    public function test_a_request_with_no_peer_records_no_address(): void
    {
        $this->middleware()->process($this->request(), $this->handler());

        $this->assertNull($this->row()['ip']);
    }

    public function test_an_http_exception_is_recorded_at_its_status_and_rethrown(): void
    {
        try {
            $this->middleware()->process(
                $this->request(),
                $this->throwingHandler(new HttpException(403, 'nope')),
            );
            $this->fail('the exception must reach the error handler unchanged');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->status());
        }

        $this->assertSame(403, (int) $this->row()['status']);
    }

    public function test_any_other_failure_is_recorded_as_a500(): void
    {
        try {
            $this->middleware()->process($this->request(), $this->throwingHandler(new RuntimeException('boom')));
        } catch (RuntimeException) {
        }

        $this->assertSame(500, (int) $this->row()['status']);
    }

    public function test_a_failing_log_does_not_fail_the_request(): void
    {
        $this->db->execute('DROP TABLE activity');

        $response = $this->middleware()->process($this->request(), $this->handler());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_a_guard_that_cannot_answer_does_not_fail_the_request(): void
    {
        $middleware = new RecordActivityMiddleware(
            new ActivityRepository($this->db),
            $this->guard(throws: true),
            new NullLogger,
        );

        $this->assertSame(200, $middleware->process($this->request(), $this->handler())->getStatusCode());
        $this->assertNull($this->db->selectOne('SELECT * FROM activity'));
    }

    /** @param list<string> $trustedProxies */
    private function middleware(array $trustedProxies = []): RecordActivityMiddleware
    {
        return new RecordActivityMiddleware(
            new ActivityRepository($this->db),
            $this->guard(),
            new NullLogger,
            new ClientIpResolver(new TrustedProxies($trustedProxies)),
        );
    }

    private function guard(bool $throws = false): GuardInterface
    {
        return new class ($throws) implements GuardInterface {
            public function __construct(private readonly bool $throws) {}

            public function user(): ?AuthenticatableInterface
            {
                if ($this->throws) {
                    throw new RuntimeException('no session');
                }

                return new User(7, 'boss', 'hash', Role::Admin, '2026-09-09 00:00:00');
            }

            public function check(): bool
            {
                return true;
            }
            public function id(): int|string|null
            {
                return 7;
            }
            public function attempt(string $username, string $password): bool
            {
                return false;
            }
            public function login(AuthenticatableInterface $user): void {}
            public function logout(): void {}
        };
    }

    /** @param array<string, mixed> $serverParams */
    private function request(array $serverParams = []): ServerRequestInterface
    {
        return (new Psr17Factory)->createServerRequest('GET', '/admin/users?page=2', $serverParams);
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        $row = $this->db->selectOne('SELECT * FROM activity ORDER BY id');
        $this->assertNotNull($row, 'the request should have been recorded');

        return $row;
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Psr17Factory)->createResponse(200);
            }
        };
    }

    private function throwingHandler(Throwable $e): RequestHandlerInterface
    {
        return new class ($e) implements RequestHandlerInterface {
            public function __construct(private readonly Throwable $e) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw $this->e;
            }
        };
    }
}
