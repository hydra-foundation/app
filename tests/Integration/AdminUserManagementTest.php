<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Container;
use App\Database\ConnectionInterface;
use App\Database\PdoConnection;
use App\Providers\AppServiceProvider;
use App\Tests\Support\ArraySessionServiceProvider;
use Hydra\Auth\AuthConfig;
use Hydra\Auth\AuthServiceProvider;
use Hydra\Auth\Contracts\HasherInterface;
use Hydra\Authorization\AuthorizationServiceProvider;
use Hydra\Core\Application;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Environment;
use Hydra\Csrf\CsrfGuard;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The admin user-management slice end-to-end through the real composition root,
 * building on the authorization slice. Where AdminAuthorizationFlowTest proves
 * the two gates, this proves what happens behind them once an admin is in: the
 * create flow that gives validation, CSRF and the flashed post-redirect-get
 * their first real consumer.
 *
 * Same in-memory sqlite swap and cheap-cost hasher as the sibling flow tests.
 */
final class AdminUserManagementTest extends TestCase
{
    private const PASSWORD = 'correct-horse-battery-staple';

    private ContainerInterface $container;
    private PDO $pdo;
    private int $bossId;

    protected function setUp(): void
    {
        $container = new Container(new \DI\Container);
        $container->instance(ContainerInterface::class, $container);
        $container->instance(Environment::class, new Environment(__DIR__));

        (new Application($container))
            ->register(new ArraySessionServiceProvider)
            ->register(new AuthServiceProvider)
            ->register(new AuthorizationServiceProvider)
            ->register(new AppServiceProvider)
            ->boot();

        $container->instance(AuthConfig::class, new AuthConfig(hashCost: 4));

        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT \'user\',
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $container->instance(ConnectionInterface::class, new PdoConnection($this->pdo));

        $hash = $container->get(HasherInterface::class)->hash(self::PASSWORD);
        $insert = $this->pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)');
        $insert->execute(['boss', $hash, 'admin']);
        $this->bossId = (int) $this->pdo->lastInsertId();

        $this->container = $container;
    }

    /** Seed an extra (non-admin-by-default) user to act on, returning its id. */
    private function seedUser(string $username, string $role = 'user'): int
    {
        $insert = $this->pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)');
        $insert->execute([$username, 'irrelevant-hash', $role]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $body
     */
    private function handle(string $method, string $path, array $headers = [], ?array $body = null): ResponseInterface
    {
        $request = (new Psr17Factory)->createServerRequest($method, $path);

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

    private function loginAsAdmin(): void
    {
        $response = $this->handle('POST', '/login', [], [
            'username' => 'boss',
            'password' => self::PASSWORD,
        ]);
        $this->assertSame(302, $response->getStatusCode(), 'admin login should succeed');
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return $this->pdo->query('SELECT username, role FROM users ORDER BY id')->fetchAll();
    }

    public function testCreateFormIsBehindTheAdminGate(): void
    {
        // The new route inherits the group's gates: anonymous → 401 → /login.
        $response = $this->handle('GET', '/admin/users/new');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaderLine('Location'));
    }

    public function testAdminCanReachTheCreateForm(): void
    {
        $this->loginAsAdmin();

        $response = $this->handle('GET', '/admin/users/new');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('New user', (string) $response->getBody());
    }

    public function testPostWithoutCsrfTokenIsRejected(): void
    {
        $this->loginAsAdmin();

        // Same body, but bypass the helper's automatic token header.
        $request = (new Psr17Factory)->createServerRequest('POST', '/admin/users')
            ->withParsedBody(['username' => 'ada', 'password' => 'a-good-password', 'role' => 'user']);
        $response = $this->container->get(RequestHandlerInterface::class)->handle($request);

        $this->assertSame(403, $response->getStatusCode());
        // The guard stopped it before the controller: no row written.
        $this->assertCount(1, $this->rows());
    }

    public function testValidInputCreatesUserAndRedirectsWithFlash(): void
    {
        $this->loginAsAdmin();

        $response = $this->handle('POST', '/admin/users', [], [
            'username' => 'ada',
            'password' => 'a-good-password',
            'role' => 'admin',
        ]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin', $response->getHeaderLine('Location'));

        // The row landed, hashed (never the plaintext), with the chosen role.
        $rows = $this->rows();
        $this->assertCount(2, $rows);
        $this->assertSame(['username' => 'ada', 'role' => 'admin'], $rows[1]);

        // The dumb-provider rule, asserted not just commented: the stored value is
        // a bcrypt digest that verifies the password, and is never the plaintext.
        $stored = $this->pdo->query("SELECT password_hash FROM users WHERE username = 'ada'")->fetchColumn();
        $this->assertNotSame('a-good-password', $stored);
        $this->assertTrue(password_verify('a-good-password', (string) $stored));

        // The flash set on store() shows once on the next listing render.
        $listing = $this->handle('GET', '/admin');
        $this->assertStringContainsString('created', (string) $listing->getBody());
        // ...and only once: a second render no longer carries it.
        $again = $this->handle('GET', '/admin');
        $this->assertStringNotContainsString('created', (string) $again->getBody());
    }

    public function testInvalidInputReRendersFormWithErrorsAndWritesNothing(): void
    {
        $this->loginAsAdmin();

        $response = $this->handle('POST', '/admin/users', [], [
            'username' => 'ab',          // too short
            'password' => 'short',       // too short
            'role' => 'superuser',       // not an allowed role
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('at least 3 characters', $body);
        $this->assertStringContainsString('at least 8 characters', $body);
        $this->assertStringContainsString('Role must be user or admin', $body);
        $this->assertCount(1, $this->rows());
    }

    public function testDuplicateUsernameIsRejected(): void
    {
        $this->loginAsAdmin();

        $response = $this->handle('POST', '/admin/users', [], [
            'username' => 'boss',        // already seeded
            'password' => 'a-good-password',
            'role' => 'user',
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('already taken', (string) $response->getBody());
        $this->assertCount(1, $this->rows());
    }

    public function testEditFormLoadsForAnotherUser(): void
    {
        $this->loginAsAdmin();
        $id = $this->seedUser('clerk');

        $response = $this->handle('GET', "/admin/users/{$id}/edit");

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('Edit user', $body);
        $this->assertStringContainsString('clerk', $body);
    }

    public function testEditingYourOwnAccountIsForbidden(): void
    {
        $this->loginAsAdmin();

        // Subject-bound: ManageUser denies acting on yourself — a 403 dead end.
        $response = $this->handle('GET', "/admin/users/{$this->bossId}/edit");

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testEditingAMissingUserIs404(): void
    {
        $this->loginAsAdmin();

        $this->assertSame(404, $this->handle('GET', '/admin/users/9999/edit')->getStatusCode());
    }

    public function testUpdateChangesUsernameAndRoleAndFlashes(): void
    {
        $this->loginAsAdmin();
        $id = $this->seedUser('clerk', 'user');

        $response = $this->handle('POST', "/admin/users/{$id}", [], [
            'username' => 'clerk_renamed',
            'role' => 'admin',
        ]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin', $response->getHeaderLine('Location'));
        $this->assertSame(
            ['username' => 'clerk_renamed', 'role' => 'admin'],
            $this->pdo->query("SELECT username, role FROM users WHERE id = {$id}")->fetch(),
        );
        $this->assertStringContainsString('updated', (string) $this->handle('GET', '/admin')->getBody());
    }

    public function testUpdateKeepingYourOwnUsernameIsAllowed(): void
    {
        $this->loginAsAdmin();
        $id = $this->seedUser('clerk', 'user');

        // The unchanged name must not trip the uniqueness check against itself.
        $response = $this->handle('POST', "/admin/users/{$id}", [], [
            'username' => 'clerk',
            'role' => 'admin',
        ]);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testUpdateRejectsANameTakenByAnotherUser(): void
    {
        $this->loginAsAdmin();
        $id = $this->seedUser('clerk');

        // 'boss' belongs to someone else → conflict, re-render, nothing changed.
        $response = $this->handle('POST', "/admin/users/{$id}", [], [
            'username' => 'boss',
            'role' => 'user',
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('already taken', (string) $response->getBody());
        $this->assertSame('clerk', $this->pdo->query("SELECT username FROM users WHERE id = {$id}")->fetchColumn());
    }

    public function testUpdateWithInvalidInputReRendersFormWithErrorsAndWritesNothing(): void
    {
        $this->loginAsAdmin();
        $id = $this->seedUser('clerk', 'user');

        $response = $this->handle('POST', "/admin/users/{$id}", [], [
            'username' => 'ab',         // too short
            'role' => 'superuser',      // not an allowed role
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('at least 3 characters', $body);
        $this->assertStringContainsString('Role must be user or admin', $body);
        // The re-rendered form is still the EDIT form: it posts back to this id,
        // not the create collection (the shared view carried the id through).
        $this->assertStringContainsString("action=\"/admin/users/{$id}\"", $body);
        // Nothing changed.
        $this->assertSame(
            ['username' => 'clerk', 'role' => 'user'],
            $this->pdo->query("SELECT username, role FROM users WHERE id = {$id}")->fetch(),
        );
    }

    public function testUpdatingYourOwnAccountIsForbidden(): void
    {
        $this->loginAsAdmin();

        $response = $this->handle('POST', "/admin/users/{$this->bossId}", [], [
            'username' => 'boss_renamed',
            'role' => 'admin',
        ]);

        $this->assertSame(403, $response->getStatusCode());
        // Untouched.
        $this->assertSame('boss', $this->pdo->query("SELECT username FROM users WHERE id = {$this->bossId}")->fetchColumn());
    }

    public function testDeletingAnotherUserWorksAndFlashes(): void
    {
        $this->loginAsAdmin();
        $id = $this->seedUser('clerk');

        $response = $this->handle('POST', "/admin/users/{$id}/delete");

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin', $response->getHeaderLine('Location'));
        $this->assertCount(1, $this->rows()); // only boss remains
        $this->assertStringContainsString('deleted', (string) $this->handle('GET', '/admin')->getBody());
    }

    public function testDeletingYourOwnAccountIsForbidden(): void
    {
        $this->loginAsAdmin();

        $response = $this->handle('POST', "/admin/users/{$this->bossId}/delete");

        $this->assertSame(403, $response->getStatusCode());
        $this->assertCount(1, $this->rows()); // boss still there
    }

    public function testDeletingAMissingUserIs404(): void
    {
        $this->loginAsAdmin();

        $this->assertSame(404, $this->handle('POST', '/admin/users/9999/delete')->getStatusCode());
    }
}
