<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Hydra\PhpDi\Container;
use App\Providers\AppServiceProvider;
use App\Tests\Support\ArraySessionServiceProvider;
use App\Tests\Support\TestHttpProvider;
use Hydra\Auth\AuthConfig;
use Hydra\Auth\AuthServiceProvider;
use Hydra\Auth\Contracts\HasherInterface;
use Hydra\Authorization\AuthorizationServiceProvider;
use Hydra\Core\Application;
use Hydra\Core\Contracts\ContainerInterface;
use App\Tests\Support\FixedSignerServiceProvider;
use Hydra\Core\Environment;
use Hydra\Csrf\CsrfGuard;
use Hydra\Database\Contracts\ConnectionInterface;
use Hydra\Database\PdoConnection;
use Hydra\Nyholm\NyholmServiceProvider;
use Hydra\Session\Contracts\SessionLifecycleInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The /admin slice end-to-end through the real composition root, building on the
 * auth slice. The backend is gated on being signed in, not on holding a role —
 * standard users belong there too, so the split is anonymous vs authenticated:
 *
 *   - anonymous              → 401 mapped to a 302 /login (auth's policy);
 *   - logged-in plain user   → 200, same as an admin;
 *   - logged-in admin        → 200.
 *
 * Backed by the same in-memory sqlite swap and cheap-cost hasher as AuthFlowTest,
 * with two seeded users (one admin, one plain), so both roles are covered.
 */
final class AdminAuthorizationFlowTest extends TestCase
{
    private const PASSWORD = 'correct-horse-battery-staple';

    private ContainerInterface $container;

    protected function setUp(): void
    {
        $container = Container::create();
        $container->instance(ContainerInterface::class, $container);
        $container->instance(Environment::class, new Environment(__DIR__));

        (new Application($container))
            ->register(new ArraySessionServiceProvider)
            ->register(new NyholmServiceProvider)
            ->register(new FixedSignerServiceProvider)
            ->register(TestHttpProvider::make())
            ->register(new AuthServiceProvider)
            ->register(new AuthorizationServiceProvider)
            ->register(new AppServiceProvider)
            ->boot();

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

        // Two users, both with the same (cheap) hash: one admin, one plain.
        $hash = $container->get(HasherInterface::class)->hash(self::PASSWORD);
        $insert = $pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)');
        $insert->execute(['boss', $hash, 'admin']);
        $insert->execute(['clerk', $hash, 'user']);

        $this->container = $container;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $body
     */
    private function handle(string $method, string $path, array $headers = [], ?array $body = null): ResponseInterface
    {
        $request = (new Psr17Factory)->createServerRequest($method, $path);

        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            // Mint the token inside a started session window, the way a
            // rendered form would; the pipeline's session save() closes it.
            $this->container->get(SessionLifecycleInterface::class)->start();
            $request = $request->withHeader('X-CSRF-Token', $this->container->get(CsrfGuard::class)->token());
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }

        return $this->container->get(RequestHandlerInterface::class)->handle($request);
    }

    private function login(string $username): void
    {
        $response = $this->handle('POST', '/login', [], [
            'username' => $username,
            'password' => self::PASSWORD,
        ]);
        $this->assertSame(302, $response->getStatusCode(), "login as {$username} should succeed");
    }

    public function test_anonymous_visitor_is_redirected_to_login_not_forbidden(): void
    {
        // The auth guard fires first: not-logged-in is a 401 mapped to a redirect,
        // never the 403 (we don't tell anonymous visitors the page exists for some).
        $response = $this->handle('GET', '/admin');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaderLine('Location'));
    }

    public function test_logged_in_plain_user_reaches_the_admin_page(): void
    {
        $this->login('clerk');

        $response = $this->handle('GET', '/admin');

        // Signing in is the whole gate: no role check stands between a standard
        // user and the backend.
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('clerk', (string) $response->getBody());
    }

    public function test_logged_in_admin_reaches_the_admin_page(): void
    {
        $this->login('boss');

        $response = $this->handle('GET', '/admin');

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('Admin', $body);
        // The page greets whoever is signed in.
        $this->assertStringContainsString('boss', $body);
    }
}
