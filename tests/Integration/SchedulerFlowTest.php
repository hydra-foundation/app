<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\TestApp;
use Hydra\Queue\Worker;
use Hydra\Scheduler\PruneScheduledRuns;
use Hydra\Scheduler\Runner;
use Hydra\Scheduler\Schedule;
use Hydra\Scheduler\ScheduledTask;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/** What the scheduler did outlives the tick: every run is a row in scheduled_runs. */
#[CoversNothing]
final class SchedulerFlowTest extends TestCase
{
    private TestApp $app;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
    }

    public function test_runs_are_pruned_nightly_ahead_of_the_worker(): void
    {
        $tasks = $this->app->get(Schedule::class)->tasks();

        $this->assertSame(
            [PruneScheduledRuns::class, Worker::class],
            array_map(static fn (ScheduledTask $task): string => $task->class, $tasks),
        );
        $this->assertSame('0 3 * * *', $tasks[0]->expression()->expression);
    }

    public function test_a_tick_records_what_it_ran(): void
    {
        $this->app->get(Runner::class)->run();

        $rows = $this->app->db()->select('SELECT task, outcome, items, started_at, duration_ms FROM scheduled_runs');

        $this->assertCount(1, $rows);
        $this->assertSame(Worker::class, $rows[0]['task']);
        $this->assertSame('ran', $rows[0]['outcome']);
        $this->assertSame(0, $rows[0]['items']);
        $this->assertIsInt($rows[0]['started_at']);
        $this->assertIsInt($rows[0]['duration_ms']);
    }
}
