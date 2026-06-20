<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Container;
use Hydra\Database\Contracts\ConnectionInterface;
use Hydra\Database\PdoConnection;
use App\Providers\AppServiceProvider;
use App\Tests\Support\ArraySessionServiceProvider;
use Hydra\Auth\AuthConfig;
use Hydra\Auth\AuthServiceProvider;
use Hydra\Auth\Contracts\HasherInterface;
use Hydra\Csrf\CsrfGuard;
use Hydra\Core\Application;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Environment;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The auth slice end-to-end through the real composition root: the login form,
 * a failed attempt (generic 422, no session), a successful attempt (302 →
 * /dashboard, then the protected page renders), the htmx HX-Redirect variant,
 * logout, and the guard rejecting the protected route for an anonymous visitor.
 *
 * Backed by an in-memory sqlite swap for MariaDB (the ConnectionInterface seam),
 * plus a seeded user hashed by the real bound hasher at a cheap cost so the suite
 * stays fast.
 */
final class AuthFlowTest extends TestCase
{
    private const USERNAME = 'will';
    private const PASSWORD = 'correct-horse-battery-staple';

    private ContainerInterface $container;

    protected function setUp(): void
    {
        $container = new Container(new \DI\Container);
        $container->instance(ContainerInterface::class, $container);
        $container->instance(Environment::class, new Environment(__DIR__));

        (new Application($container))
            ->register(new ArraySessionServiceProvider)
            ->register(new AuthServiceProvider)
            ->register(new AppServiceProvider)
            ->boot();

        // Cheap bcrypt cost so the handful of hashes this test does stay fast.
        // Set before the hasher first resolves, so it builds with this config.
        $container->instance(AuthConfig::class, new AuthConfig(hashCost: 4));

        // Swap MariaDB for in-memory sqlite — the ConnectionInterface seam means
        // the repository and guard wiring are untouched.
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

        // Seed one user, hashed by the very hasher the guard will verify against.
        $hash = $container->get(HasherInterface::class)->hash(self::PASSWORD);
        $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)')
            ->execute([self::USERNAME, $hash]);

        $this->container = $container;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $body
     */
    private function handle(string $method, string $path, array $headers = [], ?array $body = null): ResponseInterface
    {
        $request = (new Psr17Factory)->createServerRequest($method, $path);

        // Supply the session's CSRF token on unsafe methods so these tests
        // exercise auth, not the CSRF guard (covered in CsrfFlowTest).
        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
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

    public function testLoginPageRendersTheForm(): void
    {
        $response = $this->handle('GET', '/login');

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('name="username"', $body);
        $this->assertStringContainsString('name="password"', $body);
        $this->assertStringContainsString('name="_token"', $body);
    }

    public function testProtectedRouteRedirectsAnonymousBrowserToLogin(): void
    {
        $response = $this->handle('GET', '/dashboard');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaderLine('Location'));
    }

    public function testProtectedRouteSignalsLoginToHtmxViaHeader(): void
    {
        $response = $this->handle('GET', '/dashboard', ['HX-Request' => 'true']);

        // htmx swallows a 302 body, so the redirect must ride an HX-Redirect header.
        $this->assertSame('/login', $response->getHeaderLine('HX-Redirect'));
    }

    public function testWrongPasswordIsRejectedGenericallyWithoutLoggingIn(): void
    {
        $response = $this->handle('POST', '/login', [], [
            'username' => self::USERNAME,
            'password' => 'wrong',
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('match our records', (string) $response->getBody());

        // Still anonymous: the protected route bounces us.
        $this->assertSame(302, $this->handle('GET', '/dashboard')->getStatusCode());
    }

    public function testEmptyFieldsShowRequiredErrors(): void
    {
        $response = $this->handle('POST', '/login', [], ['username' => '', 'password' => '']);

        $this->assertSame(422, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('Enter your username.', $body);
        $this->assertStringContainsString('Enter your password.', $body);
    }

    public function testSuccessfulLoginRedirectsAndGrantsTheProtectedPage(): void
    {
        $login = $this->handle('POST', '/login', [], [
            'username' => self::USERNAME,
            'password' => self::PASSWORD,
        ]);

        $this->assertSame(302, $login->getStatusCode());
        $this->assertSame('/dashboard', $login->getHeaderLine('Location'));

        // The session now carries the login, so the guarded page renders.
        $dashboard = $this->handle('GET', '/dashboard');
        $this->assertSame(200, $dashboard->getStatusCode());
        $this->assertStringContainsString('Signed in as', (string) $dashboard->getBody());
        $this->assertStringContainsString(self::USERNAME, (string) $dashboard->getBody());
    }

    public function testHtmxLoginSignalsRedirectViaHeader(): void
    {
        $response = $this->handle('POST', '/login', ['HX-Request' => 'true'], [
            'username' => self::USERNAME,
            'password' => self::PASSWORD,
        ]);

        $this->assertSame('/dashboard', $response->getHeaderLine('HX-Redirect'));
    }

    public function testLogoutEndsTheSession(): void
    {
        $this->handle('POST', '/login', [], [
            'username' => self::USERNAME,
            'password' => self::PASSWORD,
        ]);
        $this->assertSame(200, $this->handle('GET', '/dashboard')->getStatusCode());

        $logout = $this->handle('POST', '/logout');
        $this->assertSame(302, $logout->getStatusCode());
        $this->assertSame('/login', $logout->getHeaderLine('Location'));

        // Back to anonymous: the guard bounces the protected route again.
        $this->assertSame(302, $this->handle('GET', '/dashboard')->getStatusCode());
    }
}
