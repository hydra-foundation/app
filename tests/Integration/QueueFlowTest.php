<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\Jobs\RecordingJob;
use App\Tests\Support\TestApp;
use Hydra\Queue\Contracts\QueueInterface;
use Hydra\Queue\Worker;
use Hydra\Scheduler\Outcome;
use Hydra\Scheduler\Runner;
use Hydra\Scheduler\Schedule;
use Hydra\Scheduler\ScheduledTask;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class QueueFlowTest extends TestCase
{
    private TestApp $app;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
        RecordingJob::$handled = [];
    }

    public function test_the_worker_is_on_the_schedule_every_minute(): void
    {
        $tasks = $this->app->get(Schedule::class)->tasks();

        $this->assertSame([Worker::class], array_map(static fn (ScheduledTask $task): string => $task->class, $tasks));
        $this->assertSame('* * * * *', $tasks[0]->expression()->expression);
        $this->assertSame(5, $tasks[0]->budgetMinutes());
    }

    public function test_a_pushed_job_is_handled_on_the_next_tick(): void
    {
        $this->app->get(QueueInterface::class)->push(RecordingJob::class, ['user' => 7]);

        [$run] = $this->app->get(Runner::class)->run();

        $this->assertSame(Outcome::Ran, $run->outcome);
        $this->assertSame(1, $run->items);
        $this->assertSame([['user' => 7]], RecordingJob::$handled);
        $this->assertSame([], $this->app->db()->select('SELECT id FROM jobs'));
    }
}
