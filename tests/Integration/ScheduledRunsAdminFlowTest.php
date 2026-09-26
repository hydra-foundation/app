<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entities\Role;
use App\Tests\Support\TestApp;
use DateTimeImmutable;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Http\Testing\Client;
use Hydra\Queue\Worker;
use Hydra\Scheduler\DatabaseRunLog;
use Hydra\Scheduler\Outcome;
use Hydra\Scheduler\PruneScheduledRuns;
use Hydra\Scheduler\TaskRun;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/** Every run the scheduler recorded, narrowed to one task, to failures, or to runs that did something. */
#[CoversNothing]
final class ScheduledRunsAdminFlowTest extends TestCase
{
    private TestApp $app;
    private Client $http;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
        $this->app->seed('boss', Role::Admin);
        $this->app->seed('clerk', Role::User);
        $this->app->container()->instance(ClockInterface::class, new FrozenClock('2026-09-26T12:00:30+00:00'));
        $this->http = $this->app->http();

        $this->record(Worker::class, Outcome::Ran, '11:55', items: 4);
        $this->record(Worker::class, Outcome::Ran, '11:56', items: 0);
        $this->record(PruneScheduledRuns::class, Outcome::Ran, '11:57');
        $this->record(Worker::class, Outcome::Failed, '11:58', error: 'RuntimeException: ' . str_repeat('the disk is full ', 12) . 'at last');
        $this->record(Worker::class, Outcome::Held, '11:59', held: 7);
    }

    public function test_runs_are_listed_newest_first(): void
    {
        $this->login('boss');
        $body = $this->body('/admin/scheduled-runs');

        $this->assertStringContainsString('<title>Runs · Admin</title>', $body);
        $this->assertStringContainsString('>Monitoring<', $body);
        $this->assertLessThan(strpos($body, '>Ran<'), strpos($body, '>Held<'));
        $this->assertStringContainsString('>Failed<', $body);
        $this->assertStringNotContainsString('at last<', $body);
    }

    public function test_the_list_narrows_to_one_task(): void
    {
        $this->login('boss');
        $body = $this->body('/admin/scheduled-runs?task=' . rawurlencode(PruneScheduledRuns::class));

        $this->assertStringContainsString('>PruneScheduledRuns<', $body);
        $this->assertStringNotContainsString('>Held<', $body);
    }

    public function test_the_list_narrows_to_failures(): void
    {
        $this->login('boss');
        $body = $this->body('/admin/scheduled-runs?outcome=failed');

        $this->assertStringContainsString('>Failed<', $body);
        $this->assertStringNotContainsString('>Held<', $body);
    }

    public function test_idle_runs_can_be_left_out(): void
    {
        $this->login('boss');

        $busy = $this->body('/admin/scheduled-runs?idle=0');
        $idle = $this->body('/admin/scheduled-runs?idle=1');

        $this->assertStringContainsString('/admin/scheduled-runs/1?', $busy);
        $this->assertStringNotContainsString('/admin/scheduled-runs/2?', $busy);
        $this->assertStringContainsString('/admin/scheduled-runs/2?', $idle);
        $this->assertStringNotContainsString('/admin/scheduled-runs/1?', $idle);
    }

    public function test_a_run_opens_on_its_full_error_and_links_back_to_its_task(): void
    {
        $this->login('boss');
        $body = $this->body('/admin/scheduled-runs/4');

        $this->assertStringContainsString('<title>Scheduled run · Admin</title>', $body);
        $this->assertStringContainsString('the disk is full at last', $body);
        $this->assertStringContainsString('href="/admin/scheduler/Hydra-Queue-Worker"', $body);
    }

    public function test_a_task_links_to_its_runs(): void
    {
        $this->login('boss');
        $href = 'href="/admin/scheduled-runs?task=' . rawurlencode(Worker::class) . '"';

        $this->assertStringContainsString($href, $this->body('/admin/scheduler'));
        $this->assertStringContainsString($href, $this->body('/admin/scheduler/Hydra-Queue-Worker'));
    }

    public function test_runs_are_admin_only_and_read_only(): void
    {
        $this->login('clerk');
        $this->http->get('/admin/scheduled-runs')->assertStatus(403);

        $this->http->post('/logout');
        $this->login('boss');
        $this->http->get('/admin/scheduled-runs/1/edit')->assertStatus(404);
        $this->http->post('/admin/scheduled-runs/1/delete')->assertStatus(404);
    }

    private function record(string $task, Outcome $outcome, string $at, ?int $items = null, ?int $held = null, ?string $error = null): void
    {
        $this->app->get(DatabaseRunLog::class)->record(new TaskRun(
            $task,
            $outcome,
            items: $items,
            heldMinutes: $held,
            startedAt: new DateTimeImmutable('2026-09-26T' . $at . ':00+00:00'),
            durationMs: 12,
            error: $error,
        ));
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
