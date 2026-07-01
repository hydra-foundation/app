<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Container;
use App\Providers\AppServiceProvider;
use App\Tests\Support\ArraySessionServiceProvider;
use App\Tests\Support\TestHttpProvider;
use Hydra\Auth\AuthConfig;
use Hydra\Auth\AuthServiceProvider;
use Hydra\Auth\Contracts\HasherInterface;
use Hydra\Core\Application;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Environment;
use Hydra\Csrf\CsrfGuard;
use Hydra\Database\Contracts\ConnectionInterface;
use Hydra\Database\PdoConnection;
use Hydra\Event\EventServiceProvider;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * The dogfood, end to end through the REAL composition root: EventServiceProvider
 * bound → AuthServiceProvider hands the guard the shared dispatcher → the guard
 * announces its lifecycle → auth's LogAuthEventsListener (registered by
 * AppServiceProvider's boot()) writes an audit line to the logger. Nothing here mocks the event path;
 * it drives actual HTTP requests and reads what landed in the log.
 *
 * This is the piece the unit tests can't prove: that the wiring in Bootstrap and
 * AppServiceProvider::boot actually connects the four packages in process.
 */
final class AuthEventsFlowTest extends TestCase
{
    private const USERNAME = 'will';
    private const PASSWORD = 'correct-horse-battery-staple';

    private ContainerInterface $container;
    private CapturingLogger $log;

    protected function setUp(): void
    {
        $container = new Container(new \DI\Container);
        $container->instance(ContainerInterface::class, $container);
        $container->instance(Environment::class, new Environment(__DIR__));

        // EventServiceProvider is registered here exactly as Bootstrap does it —
        // that binding is what makes AuthServiceProvider give the guard a real
        // dispatcher instead of null.
        $app = (new Application($container))
            ->register(new ArraySessionServiceProvider)
            ->register(TestHttpProvider::make())
            ->register(new EventServiceProvider)
            ->register(new AuthServiceProvider)
            ->register(new AppServiceProvider);

        // Swap the logger for a capturing one BEFORE boot(): boot() builds the
        // LogAuthEventsListener with whatever LoggerInterface resolves to, so the
        // override has to be in place first.
        $this->log = new CapturingLogger;
        $container->instance(\Psr\Log\LoggerInterface::class, $this->log);

        $app->boot();

        $container->instance(AuthConfig::class, new AuthConfig(hashCost: 4));

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT \'user\',
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $container->instance(ConnectionInterface::class, new PdoConnection($pdo));

        $hash = $container->get(HasherInterface::class)->hash(self::PASSWORD);
        $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)')
            ->execute([self::USERNAME, $hash]);

        $this->container = $container;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function handle(string $method, string $path, ?array $body = null): ResponseInterface
    {
        $request = (new Psr17Factory)->createServerRequest($method, $path);

        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            $request = $request->withHeader('X-CSRF-Token', $this->container->get(CsrfGuard::class)->token());
        }
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }

        return $this->container->get(RequestHandlerInterface::class)->handle($request);
    }

    public function testSuccessfulLoginEmitsAttemptingThenLoginAuditLines(): void
    {
        $this->handle('POST', '/login', [
            'username' => self::USERNAME,
            'password' => self::PASSWORD,
        ]);

        // The whole chain fired: the guard dispatched, the shared listener logged.
        $this->assertContains('auth.attempting', $this->log->messages());
        $this->assertContains('auth.login', $this->log->messages());
        $this->assertNotContains('auth.login_failed', $this->log->messages());

        // The identifier — not a password — is what got recorded.
        $login = $this->log->firstWith('auth.login');
        $this->assertArrayHasKey('user', $login['context']);
    }

    public function testWrongPasswordEmitsLoginFailedAuditLine(): void
    {
        $this->handle('POST', '/login', [
            'username' => self::USERNAME,
            'password' => 'wrong',
        ]);

        $this->assertContains('auth.attempting', $this->log->messages());
        $this->assertContains('auth.login_failed', $this->log->messages());
        $this->assertNotContains('auth.login', $this->log->messages());
        $this->assertSame('warning', $this->log->firstWith('auth.login_failed')['level']);
    }

    public function testLogoutEmitsLogoutAuditLine(): void
    {
        $this->handle('POST', '/login', [
            'username' => self::USERNAME,
            'password' => self::PASSWORD,
        ]);
        $this->log->clear();

        $this->handle('POST', '/logout');

        $this->assertContains('auth.logout', $this->log->messages());
        // The id captured before the session was cleared is carried on the event.
        $this->assertArrayHasKey('user', $this->log->firstWith('auth.logout')['context']);
    }
}

/** Captures every record as ['level' => ..., 'message' => ..., 'context' => ...]. */
final class CapturingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    public function clear(): void
    {
        $this->records = [];
    }

    /** @return list<string> */
    public function messages(): array
    {
        return array_column($this->records, 'message');
    }

    /** @return array{level: mixed, message: string, context: array<mixed>} */
    public function firstWith(string $message): array
    {
        foreach ($this->records as $record) {
            if ($record['message'] === $message) {
                return $record;
            }
        }

        throw new \RuntimeException("No '{$message}' record was logged.");
    }
}
