<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entities\Activity;
use App\Entities\Role;
use App\Repositories\ActivityRepository;
use App\Tests\Support\TestApp;
use Hydra\Database\Contracts\ConnectionInterface;
use Hydra\Http\Testing\Client;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The activity slice end-to-end: the middleware recording one row per request
 * through the real pipeline, and the Activity module reading those rows back.
 */
#[CoversNothing]
final class ActivityFlowTest extends TestCase
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

    public function test_a_request_writes_exactly_one_row(): void
    {
        $this->http->get('/');

        $rows = $this->recorded();
        $this->assertCount(1, $rows);
        $this->assertSame('GET', $rows[0]['method']);
        $this->assertSame('/', $rows[0]['path']);
        $this->assertSame('', $rows[0]['query']);
        $this->assertSame(200, (int) $rows[0]['status']);
    }

    public function test_a_guest_is_recorded_as_nobody(): void
    {
        $this->http->get('/');

        $row = $this->recorded()[0];
        $this->assertNull($row['user_id']);
        $this->assertNull($row['username']);
    }

    public function test_a_signed_in_visitor_is_recorded_by_id_and_by_name(): void
    {
        $this->login('boss');
        $this->clear();
        $this->http->get('/admin/dashboard');

        $row = $this->recorded()[0];
        $this->assertSame(1, (int) $row['user_id']);
        $this->assertSame('boss', $row['username']);
    }

    public function test_the_recorded_status_is_the_one_the_visitor_actually_got(): void
    {
        // The auth failure is turned into a redirect further in, so the log must
        // say 302, not the 401 the exception carried on its way past.
        $this->http->get('/admin/users')->assertStatus(302);
        $this->assertSame(302, (int) $this->recorded()[0]['status']);
    }

    public function test_an_error_is_recorded_at_the_status_it_renders_as(): void
    {
        $this->http->get('/does-not-exist');

        $this->assertSame(404, (int) $this->recorded()[0]['status']);
    }

    public function test_a_forbidden_screen_is_recorded_as_403(): void
    {
        $this->login('clerk');
        $this->clear();
        $this->http->get('/admin/users');

        $this->assertSame(403, (int) $this->recorded()[0]['status']);
    }

    public function test_the_request_details_are_captured(): void
    {
        $this->http->from('203.0.113.7')->get('/admin/users?role=admin&page=2', [
            'User-Agent' => 'curl/8.11.0',
            'Referer' => 'http://hydra.localhost/admin/dashboard',
        ]);

        $row = $this->recorded()[0];
        $this->assertSame('/admin/users', $row['path']);
        $this->assertSame('role=admin&page=2', $row['query']);
        $this->assertSame('203.0.113.7', $row['ip']);
        $this->assertSame('curl/8.11.0', $row['user_agent']);
        $this->assertSame('http://hydra.localhost/admin/dashboard', $row['referer']);
        $this->assertGreaterThanOrEqual(0, (int) $row['duration_ms']);
    }

    public function test_an_unwritable_log_never_breaks_the_request(): void
    {
        $this->db->execute('DROP TABLE activity');

        $this->http->get('/')->assertStatus(200);
    }

    public function test_the_module_lists_what_the_middleware_recorded(): void
    {
        $this->login('boss');
        $body = $this->body('/admin/activity');

        $this->assertStringContainsString('<title>Activity · Admin</title>', $body);
        $this->assertStringContainsString('>/login</td>', $body);
        // The row for this very request is written on the way out, so the table
        // it just rendered cannot contain it.
        $this->assertStringNotContainsString('>/admin/activity</td>', $body);
    }

    public function test_the_show_screen_carries_the_columns_the_table_cannot(): void
    {
        $this->seed(new Activity(null, null, 'GET', '/pricing', '', 200, 7, '203.0.113.9', 'Mozilla/5.0 (X11)', 'https://example.test/'));
        // Signing in records a row of its own, so take the id before that.
        $id = $this->lastId();
        $this->login('boss');
        $body = $this->body('/admin/activity/' . $id);

        $this->assertStringContainsString('<title>Request · Admin</title>', $body);
        $this->assertStringContainsString('>Mozilla/5.0 (X11)</dd>', $body);
        $this->assertStringContainsString('>https://example.test/</dd>', $body);
        $this->assertStringContainsString('>200 OK</dd>', $body);
    }

    public function test_a_request_shows_its_id_and_the_list_filters_to_it(): void
    {
        $this->seed(new Activity(null, null, 'GET', '/pricing', '', 200, 7, null, null, null, 'req-one'));
        $one = $this->lastId();
        $this->seed(new Activity(null, null, 'GET', '/about', '', 200, 7, null, null, null, 'req-two'));
        $this->login('boss');

        $this->assertStringContainsString('>req-one</dd>', $this->body('/admin/activity/' . $one));

        $body = $this->body('/admin/activity?request_id=req-one');

        $this->assertStringContainsString('>/pricing</td>', $body);
        $this->assertStringNotContainsString('>/about</td>', $body);
        $this->assertMatchesRegularExpression('#name="request_id"\s+value="req-one"#', $body);
    }

    public function test_the_log_can_be_read_one_row_at_a_time_and_still_not_be_written(): void
    {
        $this->seed(new Activity(null, null, 'GET', '/pricing', '', 200, 7, null, null, null));
        $id = $this->lastId();
        $this->login('boss');

        $this->assertStringNotContainsString('>Edit</a>', $this->body('/admin/activity/' . $id));
        $this->assertStringNotContainsString('>Delete</button>', $this->body('/admin/activity'));
        $this->http->get('/admin/activity/' . $id . '/edit')->assertStatus(404);
        $this->http->post('/admin/activity/' . $id . '/delete')->assertStatus(404);
    }

    public function test_the_module_is_admin_only(): void
    {
        $this->login('clerk');

        $this->http->get('/admin/activity')->assertStatus(403);
    }

    public function test_the_table_shows_a_guest_as_a_guest_and_labels_the_status(): void
    {
        $this->seed(new Activity(null, null, 'GET', '/pricing', '', 404, 12, '203.0.113.9', null, null));
        $this->login('boss');
        $body = $this->body('/admin/activity?q=/pricing');

        $this->assertStringContainsString('>guest</td>', $body);
        $this->assertStringContainsString('>404 Not found</td>', $body);
        $this->assertStringContainsString('>12 ms</td>', $body);
    }

    public function test_the_uri_column_carries_the_query_string(): void
    {
        $this->seed(new Activity(null, null, 'GET', '/search', 'q=hydra&page=3', 200, 4, null, null, null));
        $this->login('boss');

        $this->assertStringContainsString(
            '>/search?q=hydra&amp;page=3</td>',
            $this->body('/admin/activity?q=/search'),
        );
    }

    public function test_the_method_filter_narrows_the_table(): void
    {
        $this->login('boss');
        $body = $this->body('/admin/activity?method=POST');

        // Logging in is the only POST so far.
        $this->assertStringContainsString('Showing 1–1 of 1', $body);
        $this->assertStringContainsString('>/login</td>', $body);
    }

    public function test_search_spans_the_user_the_path_and_the_ip(): void
    {
        $this->seed(new Activity(null, 'ghost', 'GET', '/somewhere', '', 200, 1, '198.51.100.4', null, null));
        $this->login('boss');

        $this->assertStringContainsString('>ghost</td>', $this->body('/admin/activity?q=ghost'));
        $this->assertStringContainsString('>ghost</td>', $this->body('/admin/activity?q=198.51.100'));
        $this->assertStringContainsString('>ghost</td>', $this->body('/admin/activity?q=/somewhere'));
    }

    public function test_an_unknown_filter_value_is_ignored_rather_than_queried(): void
    {
        $this->seed(new Activity(null, 'ghost', 'GET', '/somewhere', '', 200, 1, null, null, null));
        $this->login('boss');

        // A value outside the field's declared options never reaches the source,
        // so the table is unfiltered rather than empty.
        $this->assertStringContainsString(
            '>ghost</td>',
            $this->body('/admin/activity?status=DROP+TABLE'),
        );
    }

    /** @return list<array<string, mixed>> */
    private function recorded(): array
    {
        return $this->db->select('SELECT * FROM activity ORDER BY id');
    }

    private function clear(): void
    {
        $this->db->execute('DELETE FROM activity');
    }

    private function lastId(): string
    {
        return (string) ($this->db->selectOne('SELECT MAX(id) AS id FROM activity')['id'] ?? '');
    }

    private function seed(Activity $activity): void
    {
        (new ActivityRepository($this->db))->record($activity);
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
