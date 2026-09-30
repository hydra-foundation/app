<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\Jobs\RecordingJob;
use App\Tests\Support\TestApp;
use Hydra\Broadcast\Testing\FakeBroadcaster;
use Hydra\Queue\Contracts\QueueInterface;
use Hydra\Queue\DatabaseQueue;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Jobs and Failed jobs are written by the queue and the worker, never by the
 * admin, so they are live only because the queue says when its tables move
 * and the skeleton tells the two modules.
 */
#[CoversNothing]
final class LiveQueueFlowTest extends TestCase
{
    private TestApp $app;
    private FakeBroadcaster $broadcaster;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
        $this->broadcaster = $this->app->get(FakeBroadcaster::class);
    }

    public function test_a_queued_job_is_told_to_the_jobs_list(): void
    {
        $this->app->get(QueueInterface::class)->push(RecordingJob::class);

        $this->broadcaster->assertPublished('module.jobs', 'changed', times: 1);
    }

    public function test_draining_the_queue_is_told_once_per_batch(): void
    {
        $queue = $this->app->get(QueueInterface::class);
        $queue->push(RecordingJob::class);
        $queue->push(RecordingJob::class);

        $this->assertSame(2, $this->app->work());

        // Two pushes, and one batch that took both.
        $this->broadcaster->assertPublished('module.jobs', 'changed', times: 3);
        $this->assertSame([], $this->broadcaster->published(static fn ($e): bool => $e->topic === 'module.failed-jobs'));
    }

    public function test_a_retried_failure_is_told_to_both_lists(): void
    {
        $this->app->db()->execute(
            "INSERT INTO failed_jobs (job, payload, exception, failed_at) VALUES (?, '{}', 'boom', 0)",
            [RecordingJob::class],
        );
        $id = (int) $this->app->db()->selectOne('SELECT MAX(id) AS id FROM failed_jobs')['id'];

        $this->assertTrue($this->app->get(DatabaseQueue::class)->retry($id));

        $this->broadcaster->assertPublished('module.jobs', 'changed', times: 1);
        $this->broadcaster->assertPublished('module.failed-jobs', 'changed', times: 1);
    }
}
