<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Hydra\PhpDi\Container;
use App\Providers\AppServiceProvider;
use App\Tests\Support\ArraySessionServiceProvider;
use App\Tests\Support\TestAdminProvider;
use App\Tests\Support\TestHttpProvider;
use App\Tests\Support\TestSchema;
use Hydra\Auth\AuthConfig;
use Hydra\Auth\AuthServiceProvider;
use Hydra\Auth\Contracts\HasherInterface;
use Hydra\Core\Application;
use Hydra\Core\Contracts\ContainerInterface;
use App\Tests\Support\FixedSignerServiceProvider;
use Hydra\Core\Environment;
use Hydra\Csrf\CsrfGuard;
use Hydra\Database\Contracts\ConnectionInterface;
use Hydra\Database\PdoConnection;
use Hydra\Event\EventServiceProvider;
use Hydra\Nyholm\NyholmServiceProvider;
use Hydra\Session\Contracts\SessionLifecycleInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Proves the piece no unit test can: that Bootstrap and AppServiceProvider::boot
 * actually connect four packages in process, so an event the guard announces
 * reaches auth's LogAuthEventsListener and lands as an audit line in the logger.
 * Nothing here mocks the event path; it drives real HTTP requests and reads the
 * log.
 */
final class AuthEventsFlowTest extends TestCase
{
    private const USERNAME = 'will';
    private const PASSWORD = 'correct-horse-battery-staple';

    private ContainerInterface $container;
    private CapturingLogger $log;

    protected function setUp(): void
    {
        $container = Container::create();
        $container->instance(ContainerInterface::class, $container);
        $container->instance(Environment::class, new Environment(__DIR__));

        // EventServiceProvider is registered here exactly as Bootstrap does it:
        // that binding is what makes AuthServiceProvider give the guard a real
        // dispatcher instead of null.
        $app = (new Application($container))
            ->register(new ArraySessionServiceProvider)
            ->register(new NyholmServiceProvider)
            ->register(new FixedSignerServiceProvider)
            ->register(TestHttpProvider::make())
            ->register(new EventServiceProvider)
            ->register(new AuthServiceProvider)
            ->register(new AppServiceProvider)
            ->register(TestAdminProvider::make());

        // Swap the logger for a capturing one BEFORE boot(): boot() builds the
        // LogAuthEventsListener with whatever LoggerInterface resolves to, so the
        // override has to be in place first.
        $this->log = new CapturingLogger;
        $container->instance(\Psr\Log\LoggerInterface::class, $this->log);

        $app->boot();

        $container->instance(AuthConfig::class, new AuthConfig(hashCost: 4));

        $pdo = TestSchema::connect();
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
            // Mint the token inside a started session window, the way a
            // rendered form would; the pipeline's session save() closes it.
            $this->container->get(SessionLifecycleInterface::class)->start();
            $request = $request->withHeader('X-CSRF-Token', $this->container->get(CsrfGuard::class)->token());
        }
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }

        return $this->container->get(RequestHandlerInterface::class)->handle($request);
    }

    public function test_successful_login_emits_attempting_then_login_audit_lines(): void
    {
        $this->handle('POST', '/login', [
            'username' => self::USERNAME,
            'password' => self::PASSWORD,
        ]);

        // The whole chain fired: the guard dispatched, the shared listener logged.
        $this->assertContains('auth.attempting', $this->log->messages());
        $this->assertContains('auth.login', $this->log->messages());
        $this->assertNotContains('auth.login_failed', $this->log->messages());

        // The identifier, not a password, is what got recorded.
        $login = $this->log->firstWith('auth.login');
        $this->assertArrayHasKey('user', $login['context']);
    }

    public function test_wrong_password_emits_login_failed_audit_line(): void
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

    public function test_logout_emits_logout_audit_line(): void
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
