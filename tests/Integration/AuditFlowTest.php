<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entities\Audit;
use App\Entities\Role;
use App\Repositories\AuditRepository;
use App\Tests\Support\TestApp;
use Hydra\Database\Contracts\ConnectionInterface;
use Hydra\Http\Testing\Client;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

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
    private TestApp $app;

    private ConnectionInterface $db;
    private Client $http;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
        $this->app->seed('boss', Role::Admin);
        $this->app->seed('clerk', Role::User);

        $this->db = $this->app->db();
        $this->http = $this->app->http();
    }

    public function test_the_module_lists_what_the_repository_recorded(): void
    {
        $this->seed(new Audit('users', '2', '{"role":"user"}', '{"role":"admin"}', 1, 'boss', 'promoted clerk'));
        $this->login('boss');
        $body = $this->body('/admin/audit');

        $this->assertStringContainsString('<title>Audit · Admin</title>', $body);
        $this->assertStringContainsString('>boss</td>', $body);
        $this->assertStringContainsString('>promoted clerk</td>', $body);
    }

    public function test_the_table_labels_a_module_it_knows_and_prints_one_it_does_not(): void
    {
        $this->seed(new Audit('users', '2', null, null, 1, 'boss', 'renamed'));
        $this->seed(new Audit('invoices', '9', null, null, 1, 'boss', 'voided'));
        $this->login('boss');
        $body = $this->body('/admin/audit');

        $this->assertStringContainsString('>Users</td>', $body);
        // A table outside the field's options falls back to the stored value
        // rather than rendering blank, so an audit row is never unattributable.
        $this->assertStringContainsString('>invoices</td>', $body);
    }

    public function test_a_change_made_outside_a_session_is_shown_as_the_system(): void
    {
        $this->seed(new Audit('users', '2', null, null, null, null, 'seeded'));
        $this->login('boss');

        $this->assertStringContainsString('>system</td>', $this->body('/admin/audit'));
    }

    public function test_the_show_screen_carries_the_columns_the_table_cannot(): void
    {
        $this->seed(new Audit('users', '2', '{"role":"user"}', '{"role":"admin"}', 1, 'boss', 'promoted'));
        $this->login('boss');
        $body = $this->body('/admin/audit/' . $this->lastId());

        $this->assertStringContainsString('<title>Change · Admin</title>', $body);
        $this->assertStringContainsString('{&quot;role&quot;:&quot;user&quot;}</dd>', $body);
        $this->assertStringContainsString('{&quot;role&quot;:&quot;admin&quot;}</dd>', $body);
    }

    public function test_the_log_can_be_read_one_row_at_a_time_and_still_not_be_written(): void
    {
        $this->seed(new Audit('users', '2', null, null, 1, 'boss', 'promoted'));
        $this->login('boss');
        $id = $this->lastId();

        $this->assertStringNotContainsString('>Edit</a>', $this->body('/admin/audit/' . $id));
        $this->assertStringNotContainsString('>Delete</button>', $this->body('/admin/audit'));
        $this->http->get('/admin/audit/' . $id . '/edit')->assertStatus(404);
        $this->http->post('/admin/audit/' . $id . '/delete')->assertStatus(404);
    }

    public function test_the_module_is_admin_only(): void
    {
        $this->login('clerk');

        $this->http->get('/admin/audit')->assertStatus(403);
    }

    public function test_the_module_filter_narrows_the_table(): void
    {
        $this->seed(new Audit('users', '2', null, null, 1, 'boss', 'renamed'));
        $this->seed(new Audit('invoices', '9', null, null, 1, 'boss', 'voided'));
        $this->login('boss');
        $body = $this->body('/admin/audit?module=users');

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
            $this->body('/admin/audit?module=DROP+TABLE'),
        );
    }

    public function test_search_spans_the_table_the_row_the_user_and_the_message(): void
    {
        $this->seed(new Audit('invoices', '90210', null, null, null, 'ghost', 'voided in error'));
        $this->login('boss');

        foreach (['invoices', '90210', 'ghost', 'in error'] as $term) {
            $this->assertStringContainsString(
                '>ghost</td>',
                $this->body('/admin/audit?q=' . rawurlencode($term)),
                sprintf('Searching for "%s" did not reach the column holding it.', $term),
            );
        }
    }

    public function test_the_log_exports(): void
    {
        $this->seed(new Audit('users', '2', null, null, 1, 'boss', 'promoted clerk'));
        $this->login('boss');
        $this->http->get('/admin/audit/export')->assertOk()->assertSee('promoted clerk');
    }

    public function test_a_created_row_is_recorded_with_who_made_it(): void
    {
        $this->login('boss');
        $this->http->post('/admin/users/new', [
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
        $this->http->post('/admin/users/new', [
            'username' => 'newcomer',
            'role' => 'user',
            'password' => 'correct-horse',
        ]);
        $this->http->post('/admin/users/3/edit', [
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
        $this->http->post('/admin/users/2/edit', [
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
        $this->http->post('/admin/users/2/edit', [
            'username' => 'clerical',
            'role' => 'user',
            'password' => '',
        ]);

        $this->assertSame('admin.row_updated: username', $this->audited()[0]['message']);
    }

    public function test_a_save_that_changed_nothing_records_nothing(): void
    {
        $this->login('boss');
        $this->http->post('/admin/users/2/edit', [
            'username' => 'clerk',
            'role' => 'user',
            'password' => '',
        ])->assertStatus(302);

        $this->assertSame([], $this->audited());
    }

    public function test_a_changed_password_is_recorded_by_name_and_not_by_value(): void
    {
        $this->login('boss');
        $this->http->post('/admin/users/2/edit', [
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
        $this->http->post('/admin/users/2/delete');

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
        // The event is announced only once the source has taken the row, so an
        // attempt the source refused must not be recorded as the deed.
        $this->http->post('/admin/users/new', [
            'username' => 'clerk',
            'role' => 'user',
            'password' => 'correct-horse',
        ])->assertStatus(422);
        $this->assertSame([], $this->audited());
    }

    public function test_an_export_is_recorded_under_a_row_id_of_its_own(): void
    {
        $this->login('boss');
        // The module offers roles as filter links rather than as a toolbar
        // select, so "admins only" is a view and the export inherits it.
        $this->http->get('/admin/users/export?view=admin');

        $row = $this->audited()[0];
        $this->assertSame('users', $row['module']);
        // An export names no row, so it is filed under one reserved for it
        // rather than under an id borrowed from a row it did not touch.
        $this->assertSame('export', $row['table_id']);
        $this->assertSame('admin.exported: 1 rows', $row['message']);
        $this->assertSame('boss', $row['username']);
        // The view it left as, pasteable back into the admin to see what went.
        $this->assertSame('{"rows":1,"view":"sort=id&dir=desc&view=admin"}', $row['new_value']);
    }

    public function test_a_module_that_only_exports_is_recorded_too(): void
    {
        $this->login('boss');
        $this->http->get('/admin/audit/export');

        $this->assertSame('audit', $this->audited()[0]['module']);
    }

    public function test_the_change_arrives_on_the_audit_screen(): void
    {
        $this->login('boss');
        $this->http->post('/admin/users/2/edit', [
            'username' => 'clerical',
            'role' => 'user',
            'password' => '',
        ]);
        $body = $this->body('/admin/audit');

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

    private function body(string $path): string
    {
        return $this->http->get($path)->assertOk()->body();
    }

    private function login(string $username): void
    {
        $this->app->login($username)->assertStatus(302);
    }
}
