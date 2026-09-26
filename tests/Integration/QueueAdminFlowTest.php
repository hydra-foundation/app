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
