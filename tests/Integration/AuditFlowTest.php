<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entities\Audit;
use App\Providers\AppServiceProvider;
use App\Repositories\AuditRepository;
use App\Tests\Support\ArrayCacheServiceProvider;
use App\Tests\Support\ArraySessionServiceProvider;
use App\Tests\Support\FixedSignerServiceProvider;
use App\Tests\Support\QuietLogServiceProvider;
use App\Tests\Support\TestAdminProvider;
use App\Tests\Support\TestHttpProvider;
use App\Tests\Support\TestSchema;
use Hydra\Auth\AuthConfig;
use Hydra\Auth\AuthServiceProvider;
use Hydra\Auth\Contracts\HasherInterface;
use Hydra\Authorization\AuthorizationServiceProvider;
use Hydra\Core\Application;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Environment;
use Hydra\Csrf\CsrfGuard;
use Hydra\Database\Contracts\ConnectionInterface;
use Hydra\Database\PdoConnection;
use Hydra\Nyholm\NyholmServiceProvider;
use Hydra\PhpDi\Container;
use Hydra\Session\Contracts\SessionLifecycleInterface;
use Hydra\Throttle\ThrottleServiceProvider;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The audit slice end-to-end: rows written through the repository and read back
 * through the module, over the real pipeline.
 *
 * Nothing writes audit rows on its own yet, so every row here is seeded. That is
 * the difference from {@see ActivityFlowTest}, which can exercise its middleware
 * by making a request; this suite checks the half that exists.
 */
#[CoversNothing]
final class AuditFlowTest extends TestCase
{
    private const PASSWORD = 'correct-horse-battery-staple';

    private ContainerInterface $container;
    private ConnectionInterface $db;

    protected function setUp(): void
    {
        $container = Container::create();
        $container->instance(ContainerInterface::class, $container);
        $container->instance(Environment::class, new Environment(__DIR__));

        (new Application($container))
            ->register(new ArraySessionServiceProvider)
            ->register(new NyholmServiceProvider)
            ->register(new FixedSignerServiceProvider)
            ->register(new ArrayCacheServiceProvider)
            ->register(new ThrottleServiceProvider)
            ->register(TestHttpProvider::make())
            ->register(new AuthServiceProvider)
            ->register(new AuthorizationServiceProvider)
            ->register(new AppServiceProvider)
            ->register(new QuietLogServiceProvider)
            ->register(TestAdminProvider::make())
            ->boot();

        $container->instance(AuthConfig::class, new AuthConfig(hashCost: 4));

        $pdo = TestSchema::connect();
        $this->db = new PdoConnection($pdo);
        $container->instance(ConnectionInterface::class, $this->db);

        $hash = $container->get(HasherInterface::class)->hash(self::PASSWORD);
        $insert = $pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)');
        $insert->execute(['boss', $hash, 'admin']);
        $insert->execute(['clerk', $hash, 'user']);

        $this->container = $container;
    }

    public function test_the_module_lists_what_the_repository_recorded(): void
    {
        $this->seed(new Audit('users', '2', '{"role":"user"}', '{"role":"admin"}', 1, 'boss', 'promoted clerk'));
        $this->login('boss');
        $body = $this->body('GET', '/admin/audit');

        $this->assertStringContainsString('<title>Audit · Admin</title>', $body);
        $this->assertStringContainsString('>boss</td>', $body);
        $this->assertStringContainsString('>promoted clerk</td>', $body);
    }

    public function test_the_table_labels_a_table_it_knows_and_prints_one_it_does_not(): void
    {
        $this->seed(new Audit('users', '2', null, null, 1, 'boss', 'renamed'));
        $this->seed(new Audit('invoices', '9', null, null, 1, 'boss', 'voided'));
        $this->login('boss');
        $body = $this->body('GET', '/admin/audit');

        $this->assertStringContainsString('>Users</td>', $body);
        // A table outside the field's options falls back to the stored value
        // rather than rendering blank, so an audit row is never unattributable.
        $this->assertStringContainsString('>invoices</td>', $body);
    }

    public function test_a_change_made_outside_a_session_is_shown_as_the_system(): void
    {
        $this->seed(new Audit('users', '2', null, null, null, null, 'seeded'));
        $this->login('boss');

        $this->assertStringContainsString('>system</td>', $this->body('GET', '/admin/audit'));
    }

    public function test_the_show_screen_carries_the_columns_the_table_cannot(): void
    {
        $this->seed(new Audit('users', '2', '{"role":"user"}', '{"role":"admin"}', 1, 'boss', 'promoted'));
        $this->login('boss');
        $body = $this->body('GET', '/admin/audit/' . $this->lastId());

        $this->assertStringContainsString('<title>Change · Admin</title>', $body);
        $this->assertStringContainsString('{&quot;role&quot;:&quot;user&quot;}</dd>', $body);
        $this->assertStringContainsString('{&quot;role&quot;:&quot;admin&quot;}</dd>', $body);
    }

    public function test_the_log_can_be_read_one_row_at_a_time_and_still_not_be_written(): void
    {
        $this->seed(new Audit('users', '2', null, null, 1, 'boss', 'promoted'));
        $this->login('boss');
        $id = $this->lastId();

        $this->assertStringNotContainsString('>Edit</a>', $this->body('GET', '/admin/audit/' . $id));
        $this->assertStringNotContainsString('>Delete</button>', $this->body('GET', '/admin/audit'));
        $this->assertSame(404, $this->handle('GET', '/admin/audit/' . $id . '/edit')->getStatusCode());
        $this->assertSame(404, $this->handle('POST', '/admin/audit/' . $id . '/delete')->getStatusCode());
    }

    public function test_the_module_is_admin_only(): void
    {
        $this->login('clerk');

        $this->assertSame(403, $this->handle('GET', '/admin/audit')->getStatusCode());
    }

    public function test_the_table_filter_narrows_the_table(): void
    {
        $this->seed(new Audit('users', '2', null, null, 1, 'boss', 'renamed'));
        $this->seed(new Audit('invoices', '9', null, null, 1, 'boss', 'voided'));
        $this->login('boss');
        $body = $this->body('GET', '/admin/audit?table_name=users');

        // The assertion the source's own case cannot make: this is the whole
        // round trip, from the select's name in the query string to the clause.
        $this->assertStringContainsString('Showing 1–1 of 1', $body);
        $this->assertStringContainsString('>renamed</td>', $body);
        $this->assertStringNotContainsString('>voided</td>', $body);
    }

    public function test_an_unknown_filter_value_is_ignored_rather_than_queried(): void
    {
        $this->seed(new Audit('invoices', '9', null, null, 1, 'boss', 'voided'));
        $this->login('boss');

        // A value outside the field's declared options never reaches the source,
        // so the table is unfiltered rather than empty.
        $this->assertStringContainsString(
            '>voided</td>',
            $this->body('GET', '/admin/audit?table_name=DROP+TABLE'),
        );
    }

    public function test_search_spans_the_table_the_row_the_user_and_the_message(): void
    {
        $this->seed(new Audit('invoices', '90210', null, null, null, 'ghost', 'voided in error'));
        $this->login('boss');

        foreach (['invoices', '90210', 'ghost', 'in error'] as $term) {
            $this->assertStringContainsString(
                '>ghost</td>',
                $this->body('GET', '/admin/audit?q=' . rawurlencode($term)),
                sprintf('Searching for "%s" did not reach the column holding it.', $term),
            );
        }
    }

    public function test_the_log_exports(): void
    {
        $this->seed(new Audit('users', '2', null, null, 1, 'boss', 'promoted clerk'));
        $this->login('boss');
        $response = $this->handle('GET', '/admin/audit/export');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('promoted clerk', (string) $response->getBody());
    }

    private function lastId(): string
    {
        return (string) ($this->db->selectOne('SELECT MAX(id) AS id FROM audit')['id'] ?? '');
    }

    private function seed(Audit $audit): void
    {
        (new AuditRepository($this->db))->record($audit);
    }

    /** @param array<string, string> $headers */
    private function body(string $method, string $path, array $headers = []): string
    {
        $response = $this->handle($method, $path, $headers);
        $this->assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $body
     */
    private function handle(string $method, string $path, array $headers = [], ?array $body = null): ResponseInterface
    {
        $request = (new Psr17Factory)->createServerRequest($method, $path);

        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
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
        $this->assertSame(302, $response->getStatusCode());
    }
}
