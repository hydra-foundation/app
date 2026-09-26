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
use Hydra\Scheduler\LockDirectory;
use Hydra\Scheduler\Outcome;
use Hydra\Scheduler\SchedulerConfig;
use Hydra\Scheduler\TaskRun;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * What is scheduled, when it runs next, whether it is running now, and what
 * it did last time, without a shell.
 */
#[CoversNothing]
final class SchedulerAdminFlowTest extends TestCase
{
    private const WORKER = 'Hydra-Queue-Worker';
    private const PRUNE = 'Hydra-Scheduler-PruneScheduledRuns';

    private TestApp $app;
    private Client $http;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
        $this->app->seed('boss', Role::Admin);
        $this->app->seed('clerk', Role::User);
        $this->app->container()->instance(ClockInterface::class, new FrozenClock('2026-09-26T12:00:30+00:00'));
        $this->http = $this->app->http();
    }

    public function test_every_scheduled_task_is_listed_with_when_it_runs_next(): void
    {
        $this->login('boss');
        $body = $this->body('/admin/scheduler');

        $this->assertStringContainsString('<title>Scheduler · Admin</title>', $body);
        $this->assertStringContainsString('>Monitoring<', $body);
        $this->assertStringContainsString('>PruneScheduledRuns<', $body);
        $this->assertStringContainsString('>Worker<', $body);
        $this->assertStringContainsString('>0 3 * * *<', $body);
        $this->assertStringContainsString('>* * * * *<', $body);
        $this->assertStringContainsString('>Batch<', $body);
        $this->assertStringContainsString('<time datetime="' . $this->iso('2026-09-26T12:01:00+00:00') . '"', $body);
        $this->assertStringContainsString('<time datetime="' . $this->iso('2026-09-27T03:00:00+00:00') . '"', $body);
        $this->assertStringContainsString('>never<', $body);
    }

    public function test_the_last_run_and_its_outcome_are_shown(): void
    {
        $this->record(new TaskRun(Worker::class, Outcome::Ran, items: 3, startedAt: new DateTimeImmutable('2026-09-26T11:59:00+00:00'), durationMs: 1204));
        $this->login('boss');

        $body = $this->body('/admin/scheduler');

        $this->assertStringContainsString('<time datetime="' . $this->iso('2026-09-26T11:59:00+00:00') . '"', $body);
        $this->assertStringContainsString('>Ran<', $body);
        $this->assertStringContainsString('1,204 ms', $body);
    }

    public function test_a_task_holding_its_lock_is_shown_running(): void
    {
        $locks = new LockDirectory($this->app->get(SchedulerConfig::class)->lockPath);
        $held = $locks->acquire(Worker::class, new DateTimeImmutable());
        $this->login('boss');

        try {
            $this->assertStringContainsString('>Running<', $this->body('/admin/scheduler/' . self::WORKER));
            $this->assertStringContainsString('>Idle<', $this->body('/admin/scheduler/' . self::PRUNE));
        } finally {
            $held?->release();
        }
    }

    public function test_a_task_opens_on_its_last_failure(): void
    {
        $this->record(new TaskRun(Worker::class, Outcome::Failed, startedAt: new DateTimeImmutable('2026-09-26T11:59:00+00:00'), durationMs: 5, error: 'RuntimeException: the disk is full'));
        $this->login('boss');

        $body = $this->body('/admin/scheduler/' . self::WORKER);

        $this->assertStringContainsString('<title>Scheduled task · Admin</title>', $body);
        $this->assertStringContainsString('Hydra\Queue\Worker', $body);
        $this->assertStringContainsString('>Failed<', $body);
        $this->assertStringContainsString('RuntimeException: the disk is full', $body);
    }

    public function test_a_task_no_longer_scheduled_says_so(): void
    {
        $this->login('boss');

        $this->http->htmx('div#admin-frame')->get('/admin/scheduler/App-Jobs-Gone')
            ->assertStatus(404)
            ->assertSee('That task is no longer on the schedule.');
    }

    public function test_the_scheduler_is_admin_only_and_read_only(): void
    {
        $this->login('clerk');
        $this->http->get('/admin/scheduler')->assertStatus(403);

        $this->http->post('/logout');
        $this->login('boss');
        $this->http->get('/admin/scheduler/' . self::WORKER . '/edit')->assertStatus(404);
        $this->http->post('/admin/scheduler/' . self::WORKER . '/delete')->assertStatus(404);
    }

    private function record(TaskRun $run): void
    {
        $this->app->get(DatabaseRunLog::class)->record($run);
    }

    /** How the admin writes an instant into <time datetime>. */
    private function iso(string $at): string
    {
        return (new DateTimeImmutable($at))->format(DATE_ATOM);
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
