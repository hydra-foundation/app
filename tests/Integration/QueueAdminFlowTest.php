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
