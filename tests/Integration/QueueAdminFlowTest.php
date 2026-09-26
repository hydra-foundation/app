<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entities\Role;
use App\Jobs\SendVerificationLink;
use App\Tests\Support\TestApp;
use Hydra\Database\Contracts\ConnectionInterface;
use Hydra\Http\Testing\Client;
use Hydra\Queue\DatabaseQueue;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The queue as the admin shows it: what is waiting and what gave up, and the
 * buttons that do what queue:retry, queue:forget and queue:flush do.
 */
#[CoversNothing]
final class QueueAdminFlowTest extends TestCase
{
    private TestApp $app;

    private ConnectionInterface $db;
    private DatabaseQueue $queue;
    private Client $http;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
        $this->app->seed('boss', Role::Admin);
        $this->app->seed('clerk', Role::User);

        $this->db = $this->app->db();
        $this->queue = $this->app->get(DatabaseQueue::class);
        $this->http = $this->app->http();
    }

    public function test_the_jobs_waiting_are_listed_under_the_queue(): void
    {
        $this->queue->push(SendVerificationLink::class, ['user' => 2]);
        $this->login('boss');
        $body = $this->body('/admin/jobs');

        $this->assertStringContainsString('<title>Jobs · Admin</title>', $body);
        $this->assertStringContainsString('>Queue<', $body);
        $this->assertStringContainsString(SendVerificationLink::class, $body);
        $this->assertStringContainsString('<time datetime=', $body);
        $this->assertStringContainsString('>waiting</td>', $body);
    }

    public function test_the_jobs_are_admin_only(): void
    {
        $this->login('clerk');

        $this->http->get('/admin/jobs')->assertStatus(403);
    }

    public function test_only_a_job_no_worker_holds_offers_a_cancel(): void
    {
        [$held, $free] = $this->holdOneOfTwo();
        $this->login('boss');
        $body = $this->body('/admin/jobs');

        $this->assertStringContainsString("/admin/jobs/{$free}/delete", $body);
        $this->assertStringNotContainsString("/admin/jobs/{$held}/delete", $body);
        $this->assertStringContainsString('>Cancel</button>', $body);
    }

    public function test_a_cancelled_job_is_gone_before_any_worker_sees_it(): void
    {
        [, $free] = $this->holdOneOfTwo();
        $this->login('boss');

        $this->http->post("/admin/jobs/{$free}/delete")->assertStatus(302);

        $this->assertNull($this->db->selectOne('SELECT id FROM jobs WHERE id = ?', [$free]));
    }

    public function test_a_held_job_refuses_a_cancel_and_stays(): void
    {
        [$held] = $this->holdOneOfTwo();
        $this->login('boss');

        $response = $this->http->post("/admin/jobs/{$held}/delete")->assertStatus(422);

        $this->assertStringContainsString('A worker is running this job', $response->body());
        $this->assertNotNull($this->db->selectOne('SELECT id FROM jobs WHERE id = ?', [$held]));
    }

    public function test_a_job_that_ran_while_the_list_was_open_says_so(): void
    {
        $this->login('boss');

        $this->frame()->get('/admin/jobs/99')->assertStatus(404)->assertSee('That job has already run or been cancelled.');
    }

    public function test_a_failure_retried_while_the_list_was_open_says_so(): void
    {
        $this->login('boss');

        $this->frame()->get('/admin/failed-jobs/99')->assertStatus(404)->assertSee('That failed job has been retried or deleted.');
    }

    public function test_a_failure_is_listed_by_its_reason_newest_first(): void
    {
        $this->failJob('mail server down');
        $this->failJob('address rejected');
        $this->login('boss');
        $body = $this->body('/admin/failed-jobs');

        $this->assertStringContainsString('<title>Failed jobs · Admin</title>', $body);
        $this->assertStringContainsString('>RuntimeException: address rejected</td>', $body);
        $this->assertLessThan(strpos($body, 'mail server down'), strpos($body, 'address rejected'));
        $this->assertStringNotContainsString('#0 ', $body);
    }

    public function test_the_failures_are_admin_only(): void
    {
        $this->login('clerk');

        $this->http->get('/admin/failed-jobs')->assertStatus(403);
    }

    public function test_a_failure_opens_on_its_whole_trace_and_its_payload(): void
    {
        $id = $this->failJob('mail server down');
        $this->login('boss');
        $body = $this->body("/admin/failed-jobs/{$id}");

        $this->assertMatchesRegularExpression('/<pre[^>]*>RuntimeException: mail server down in \S+\n.*#0 /s', $body);
        $this->assertStringContainsString('{&quot;user&quot;:7}</pre>', $body);
    }

    public function test_a_deleted_failure_is_gone_for_good(): void
    {
        $id = $this->failJob('mail server down');
        $this->login('boss');

        $this->http->post("/admin/failed-jobs/{$id}/delete")->assertStatus(302);

        $this->assertSame([], $this->queue->failed());
        $this->assertSame([], $this->db->select('SELECT id FROM jobs'));
    }

    public function test_each_failure_offers_a_retry_that_asks_first(): void
    {
        $id = $this->failJob('mail server down');
        $this->login('boss');
        $body = $this->body('/admin/failed-jobs');

        $this->assertStringContainsString("hx-post=\"/admin/failed-jobs/{$id}/retry", $body);
        $this->assertStringContainsString('hx-confirm="Put this job back on the queue?"', $body);
        $this->assertStringContainsString('>Retry all</button>', $body);
        $this->assertStringContainsString('>Clear all</button>', $body);
    }

    public function test_a_retried_failure_is_back_on_the_queue_due_now_with_its_tries_restored(): void
    {
        $id = $this->failJob('mail server down');
        $this->login('boss');

        $this->frame()->post("/admin/failed-jobs/{$id}/retry")->assertOk()->assertSee("Job {$id} is back on the queue.");

        $this->assertSame([], $this->queue->failed());
        $this->assertSame(
            [['job' => SendVerificationLink::class, 'payload' => '{"user":7}', 'attempts' => 0, 'reserved_at' => null]],
            $this->db->select('SELECT job, payload, attempts, reserved_at FROM jobs'),
        );
    }

    public function test_a_failure_already_gone_refuses_a_retry(): void
    {
        $id = $this->failJob('mail server down');
        $this->queue->forget($id);
        $this->login('boss');

        $this->frame()->post("/admin/failed-jobs/{$id}/retry")->assertStatus(422)->assertSee('That failed job is already gone.');
        $this->assertSame([], $this->db->select('SELECT id FROM jobs'));
    }

    public function test_retry_all_and_clear_all_say_how_many(): void
    {
        $this->login('boss');

        $this->frame()->post('/admin/failed-jobs/retry-all')->assertOk()->assertSee('No failed jobs.');

        $this->failJob('one');
        $this->failJob('two');
        $this->frame()->post('/admin/failed-jobs/retry-all')->assertOk()->assertSee('2 jobs are back on the queue.');
        $this->assertCount(2, $this->db->select('SELECT id FROM jobs'));

        $this->db->execute('DELETE FROM jobs');
        $this->failJob('three');
        $this->frame()->post('/admin/failed-jobs/clear')->assertOk()->assertSee('1 failed job deleted.');
        $this->assertSame([], $this->queue->failed());
        $this->assertSame([], $this->db->select('SELECT id FROM jobs'));
    }

    public function test_every_action_leaves_one_audit_line_and_no_trace_or_payload(): void
    {
        $retried = $this->failJob('secret trace');
        $deleted = $this->failJob('secret trace');
        $this->login('boss');

        $this->http->post("/admin/failed-jobs/{$retried}/retry");
        $this->http->post("/admin/failed-jobs/{$deleted}/delete");
        $this->failJob('secret trace');
        $this->http->post('/admin/failed-jobs/clear');
        [, $free] = $this->holdOneOfTwo();
        $this->http->post("/admin/jobs/{$free}/delete");

        $this->assertSame(
            [
                ['module' => 'failed-jobs', 'table_id' => (string) $retried, 'old_value' => null, 'new_value' => null, 'username' => 'boss', 'message' => 'admin.action: retry'],
                ['module' => 'failed-jobs', 'table_id' => (string) $deleted, 'old_value' => null, 'new_value' => null, 'username' => 'boss', 'message' => 'admin.row_deleted'],
                ['module' => 'failed-jobs', 'table_id' => 'clear', 'old_value' => null, 'new_value' => null, 'username' => 'boss', 'message' => 'admin.action: clear'],
                ['module' => 'jobs', 'table_id' => (string) $free, 'old_value' => null, 'new_value' => null, 'username' => 'boss', 'message' => 'admin.row_deleted'],
            ],
            $this->db->select('SELECT module, table_id, old_value, new_value, username, message FROM audit ORDER BY id'),
        );
    }

    public function test_an_admin_s_dashboard_carries_the_queue_card(): void
    {
        $this->login('boss');

        $this->assertStringContainsString('/admin/dashboard/w/queue', $this->body('/admin/dashboard'));
    }

    public function test_the_queue_card_counts_what_waits_what_is_held_and_what_failed(): void
    {
        $this->holdOneOfTwo();
        $this->queue->push(SendVerificationLink::class, ['user' => 3]);
        $this->failJob('mail server down');
        $this->login('boss');
        $body = $this->body('/admin/dashboard/w/queue');

        $this->assertSeeRow($body, 'Waiting', '2', '/admin/jobs');
        $this->assertSeeRow($body, 'Held by a worker', '1', '/admin/jobs');
        $this->assertSeeRow($body, 'Failed', '1', '/admin/failed-jobs');
        $this->assertStringContainsString('oldest', $body);
        $this->assertStringContainsString('<time datetime=', $body);
    }

    public function test_an_empty_queue_says_so_on_the_card(): void
    {
        $this->login('boss');

        $this->assertStringContainsString('Nothing has failed.', $this->body('/admin/dashboard/w/queue'));
    }

    public function test_a_standard_user_never_sees_the_queue_card(): void
    {
        $this->login('clerk');

        $this->assertStringNotContainsString('/admin/dashboard/w/queue', $this->body('/admin/dashboard'));
        $this->http->get('/admin/dashboard/w/queue')->assertStatus(403);
    }

    private function assertSeeRow(string $body, string $label, string $count, string $href): void
    {
        $this->assertMatchesRegularExpression(
            sprintf('#<a href="%s"[^>]*>%s</a>\s*<span class="widget-caption">%s\b#', preg_quote($href, '#'), preg_quote($label, '#'), $count),
            $body,
        );
    }

    private function frame(): Client
    {
        return $this->http->htmx('div#admin-frame');
    }

    /** Push, claim and fail one job, returning its id in failed_jobs. */
    private function failJob(string $message): int
    {
        $this->queue->push(SendVerificationLink::class, ['user' => 7]);
        [$job] = $this->queue->reserve(1);
        $this->queue->fail($job, new RuntimeException($message));

        return $this->queue->failed()[0]->id;
    }

    /** @return array{int, int} the held job's id, then the free one's */
    private function holdOneOfTwo(): array
    {
        $this->queue->push(SendVerificationLink::class, ['user' => 1]);
        $this->queue->push(SendVerificationLink::class, ['user' => 2]);
        [$held] = $this->queue->reserve(1);
        $free = (int) ($this->db->selectOne('SELECT id FROM jobs WHERE reserved_at IS NULL')['id'] ?? 0);

        return [$held->id, $free];
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
