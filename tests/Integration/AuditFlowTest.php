<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entities\Audit;
use App\Providers\AppServiceProvider;
use App\Repositories\AuditRepository;
use Hydra\Cache\Testing\ArrayCacheServiceProvider;
use Hydra\Session\Testing\ArraySessionServiceProvider;
use Hydra\Core\Testing\FixedSignerServiceProvider;
use Hydra\Log\Testing\CapturingLogger;
use Psr\Log\LoggerInterface;
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
use Hydra\Event\EventServiceProvider;
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

        $app = (new Application($container))
            ->register(new ArraySessionServiceProvider)
            ->register(new NyholmServiceProvider)
            ->register(new FixedSignerServiceProvider)
            ->register(new ArrayCacheServiceProvider)
            ->register(new ThrottleServiceProvider)
            ->register(TestHttpProvider::make())
            // What makes the admin emit its events at all: without this binding
            // AdminController holds a null dispatcher and every write here would
            // pass while recording nothing. Kernel registers it in the real app.
            ->register(new EventServiceProvider)
            ->register(new AuthServiceProvider)
            ->register(new AuthorizationServiceProvider)
            ->register(new AppServiceProvider)
            ->register(TestAdminProvider::make());

        // Before boot(), not after: boot() builds its listeners with whatever
        // LoggerInterface resolves to, so a logger swapped in afterwards hears
        // nothing. In memory rather than stderr, because the real pipeline logs
        // one line per request and those land in the middle of PHPUnit's own
        // output, where they read as failures that are not failures.
        $container->instance(LoggerInterface::class, new CapturingLogger);

        $app->boot();

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

    public function test_the_table_labels_a_module_it_knows_and_prints_one_it_does_not(): void
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

    public function test_the_module_filter_narrows_the_table(): void
    {
        $this->seed(new Audit('users', '2', null, null, 1, 'boss', 'renamed'));
        $this->seed(new Audit('invoices', '9', null, null, 1, 'boss', 'voided'));
        $this->login('boss');
        $body = $this->body('GET', '/admin/audit?module=users');

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
            $this->body('GET', '/admin/audit?module=DROP+TABLE'),
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

    public function test_a_created_row_is_recorded_with_who_made_it(): void
    {
        $this->login('boss');
        $this->handle('POST', '/admin/users/new', [], [
            'username' => 'newcomer',
            'role' => 'user',
            'password' => 'correct-horse',
        ]);

        $row = $this->audited()[0];
        $this->assertSame('users', $row['module']);
        $this->assertSame('3', $row['table_id']);
        $this->assertNull($row['old_value']);
        $this->assertSame('{"username":"newcomer","role":"user"}', $row['new_value']);
        $this->assertSame(1, (int) $row['user_id']);
        $this->assertSame('boss', $row['username']);
        $this->assertSame('admin.row_created', $row['message']);
    }

    public function test_a_submitted_password_never_reaches_the_audit_table(): void
    {
        // The reason the listener works from an allowlist. UsersModule declares
        // a password input, so the values on the event carry one, and a listener
        // that recorded them wholesale would write it down here in plain text.
        $this->login('boss');
        $this->handle('POST', '/admin/users/new', [], [
            'username' => 'newcomer',
            'role' => 'user',
            'password' => 'correct-horse',
        ]);
        $this->handle('POST', '/admin/users/3/edit', [], [
            'username' => 'newcomer',
            'role' => 'user',
            'password' => 'a-brand-new-secret',
        ]);

        $written = json_encode($this->audited(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('correct-horse', $written);
        $this->assertStringNotContainsString('a-brand-new-secret', $written);
        $this->assertStringNotContainsString('$2y$', $written);
    }

    public function test_a_rewrite_records_the_before_and_after_of_what_moved(): void
    {
        $this->login('boss');
        $this->handle('POST', '/admin/users/2/edit', [], [
            'username' => 'clerk',
            'role' => 'admin',
            'password' => '',
        ]);

        $row = $this->audited()[0];
        $this->assertSame('users', $row['module']);
        $this->assertSame('2', $row['table_id']);
        $this->assertSame('{"role":"user"}', $row['old_value']);
        $this->assertSame('{"role":"admin"}', $row['new_value']);
        // Not "username": it was resubmitted unchanged, and a log that records
        // every field of every form records nothing about any of them.
        $this->assertSame('admin.row_updated: role', $row['message']);
    }

    public function test_an_untouched_password_is_not_recorded_as_a_change(): void
    {
        // The edit form always posts a password field and the source never reads
        // one back, so the event cannot tell a blank one from a new one on its
        // own. Every edit would otherwise claim the password changed.
        $this->login('boss');
        $this->handle('POST', '/admin/users/2/edit', [], [
            'username' => 'clerical',
            'role' => 'user',
            'password' => '',
        ]);

        $this->assertSame('admin.row_updated: username', $this->audited()[0]['message']);
    }

    public function test_a_changed_password_is_recorded_by_name_and_not_by_value(): void
    {
        $this->login('boss');
        $this->handle('POST', '/admin/users/2/edit', [], [
            'username' => 'clerk',
            'role' => 'user',
            'password' => 'a-brand-new-secret',
        ]);

        $row = $this->audited()[0];
        $this->assertSame('admin.row_updated: password', $row['message']);
        // Named in the message, absent from the values: this is the line that
        // says a password was set without being where it was written down.
        $this->assertNull($row['old_value']);
        $this->assertNull($row['new_value']);
    }

    public function test_a_deleted_row_is_recorded_by_the_id_it_removed(): void
    {
        $this->login('boss');
        $this->handle('POST', '/admin/users/2/delete');

        $row = $this->audited()[0];
        $this->assertSame('users', $row['module']);
        $this->assertSame('2', $row['table_id']);
        $this->assertSame('admin.row_deleted', $row['message']);
        // Everything the row held, not just what a rewrite would have moved:
        // after a delete there is nowhere else left to read it from.
        $this->assertSame('{"username":"clerk","role":"user"}', $row['old_value']);
        $this->assertNull($row['new_value']);
    }

    public function test_a_refused_write_records_nothing(): void
    {
        $this->login('boss');
        $response = $this->handle('POST', '/admin/users/new', [], [
            'username' => 'clerk',
            'role' => 'user',
            'password' => 'correct-horse',
        ]);

        // The event is announced only once the source has taken the row, so an
        // attempt the source refused must not be recorded as the deed.
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame([], $this->audited());
    }

    public function test_an_export_is_recorded_under_a_row_id_of_its_own(): void
    {
        $this->login('boss');
        $this->handle('GET', '/admin/users/export?role=admin');

        $row = $this->audited()[0];
        $this->assertSame('users', $row['module']);
        // An export names no row, so it is filed under one reserved for it
        // rather than under an id borrowed from a row it did not touch.
        $this->assertSame('export', $row['table_id']);
        $this->assertSame('admin.exported: 1 rows', $row['message']);
        $this->assertSame('boss', $row['username']);
        // The view it left as, pasteable back into the admin to see what went.
        $this->assertSame('{"rows":1,"view":"sort=id&dir=desc&role=admin"}', $row['new_value']);
    }

    public function test_a_module_that_only_exports_is_recorded_too(): void
    {
        $this->login('boss');
        $this->handle('GET', '/admin/audit/export');

        $this->assertSame('audit', $this->audited()[0]['module']);
    }

    public function test_the_change_arrives_on_the_audit_screen(): void
    {
        $this->login('boss');
        $this->handle('POST', '/admin/users/2/edit', [], [
            'username' => 'clerical',
            'role' => 'user',
            'password' => '',
        ]);
        $body = $this->body('GET', '/admin/audit');

        $this->assertStringContainsString('>Users</td>', $body);
        $this->assertStringContainsString('>boss</td>', $body);
        $this->assertStringContainsString('>admin.row_updated: username</td>', $body);
    }

    /** @return list<array<string, mixed>> */
    private function audited(): array
    {
        return $this->db->select('SELECT * FROM audit ORDER BY id');
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
