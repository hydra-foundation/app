<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entities\Role;
use App\Providers\AppServiceProvider;
use Hydra\Admin\AdminServiceProvider;
use Hydra\Auth\AuthConfig;
use Hydra\Auth\AuthenticateMiddleware;
use Hydra\Auth\AuthServiceProvider;
use Hydra\Auth\Contracts\HasherInterface;
use Hydra\Authorization\AuthorizationServiceProvider;
use Hydra\Cache\Testing\ArrayCacheServiceProvider;
use Hydra\Core\Application;
use Hydra\Core\Clock\ClockServiceProvider;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Environment;
use Hydra\Core\Testing\FixedSignerServiceProvider;
use Hydra\Csrf\Testing\CarriesCsrfToken;
use Hydra\Database\Contracts\ConnectionInterface;
use Hydra\Database\PdoConnection;
use Hydra\Event\EventServiceProvider;
use Hydra\Http\Testing\Client;
use Hydra\Http\Testing\TestResponse;
use Hydra\Kernel\HttpServiceProvider;
use Hydra\Log\Testing\CapturingLogger;
use Hydra\Mail\MailServiceProvider;
use Hydra\Mail\Testing\FakeMailer;
use Hydra\Mail\Testing\FakeMailServiceProvider;
use Hydra\Nyholm\NyholmServiceProvider;
use Hydra\PhpDi\Container;
use Hydra\Session\Testing\ArraySessionServiceProvider;
use Hydra\Throttle\ThrottleServiceProvider;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * The application as {@see \App\Bootstrap} composes it, booted for a flow test.
 *
 * Kernel::application() and Bootstrap register the real providers in this
 * order; here each one that needs the outside world is swapped for its double
 * in the same slot — a session with no session_start(), a store with no Redis,
 * a signer with no APP_KEY, a mailer that sends nothing, and an in-memory
 * database — because the Environment deliberately has no .env to read.
 */
final class TestApp
{
    /** The password every seeded account holds. */
    public const PASSWORD = 'correct-horse-battery-staple';

    private function __construct(
        private readonly Application $application,
        private readonly ContainerInterface $container,
        private readonly CapturingLogger $log,
        private readonly PDO $pdo,
    ) {}

    public static function boot(): self
    {
        $container = Container::create();
        $container->instance(ContainerInterface::class, $container);
        $container->instance(Environment::class, new Environment(__DIR__));

        $application = (new Application($container))
            ->register(new ClockServiceProvider)
            ->register(new ArraySessionServiceProvider)
            ->register(new EventServiceProvider)
            ->register(new AuthServiceProvider)
            ->register(new AuthorizationServiceProvider)
            ->register(new NyholmServiceProvider)
            ->register(new FixedSignerServiceProvider)
            ->register(new ArrayCacheServiceProvider)
            ->register(new MailServiceProvider)
            ->register(new FakeMailServiceProvider)
            ->register(new ThrottleServiceProvider)
            ->register(new HttpServiceProvider(
                controllers: AppServiceProvider::CONTROLLERS,
                middleware: AppServiceProvider::MIDDLEWARE,
                // Scanned live and written nowhere: a cached route table is a
                // file two tests would share.
                routeCacheEnabled: false,
                routeCachePath: '/dev/null',
            ))
            ->register(new AppServiceProvider)
            ->register(new AdminServiceProvider(
                modules: AppServiceProvider::MODULES,
                prefix: '/admin',
                middleware: [AuthenticateMiddleware::class],
            ));

        // Before boot(): the listeners are built with whatever LoggerInterface
        // resolves to then. In memory, because the pipeline logs a line per
        // request and on stderr those read as PHPUnit failures.
        $log = new CapturingLogger;
        $container->instance(LoggerInterface::class, $log);

        $application->boot();

        // Every flow that signs in pays the hash cost, and none is about bcrypt.
        $container->instance(AuthConfig::class, new AuthConfig(hashCost: 4));

        $pdo = TestSchema::connect();
        $container->instance(ConnectionInterface::class, new PdoConnection($pdo));

        return new self($application, $container, $log, $pdo);
    }

    public function application(): Application
    {
        return $this->application;
    }

    public function container(): ContainerInterface
    {
        return $this->container;
    }

    /** @return mixed */
    public function get(string $id)
    {
        return $this->container->get($id);
    }

    public function db(): ConnectionInterface
    {
        return $this->container->get(ConnectionInterface::class);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function log(): CapturingLogger
    {
        return $this->log;
    }

    public function mailer(): FakeMailer
    {
        return $this->container->get(FakeMailer::class);
    }

    /** A client whose unsafe requests carry the session's CSRF token. */
    public function http(): Client
    {
        return Client::for($this->container, [CarriesCsrfToken::for($this->container)]);
    }

    /** Returns the id of the seeded user. */
    public function seed(string $username, Role $role = Role::DEFAULT): int
    {
        $this->pdo->prepare('INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)')
            ->execute([
                $username,
                "{$username}@example.com",
                $this->container->get(HasherInterface::class)->hash(self::PASSWORD),
                $role->value,
            ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function login(string $username, string $password = self::PASSWORD): TestResponse
    {
        return $this->http()->post('/login', ['username' => $username, 'password' => $password]);
    }
}
