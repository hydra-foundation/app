<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Listeners\PublishQueueChanges;
use Hydra\Admin\Live\ModuleChanges;
use Hydra\Broadcast\Testing\FakeBroadcaster;
use Hydra\Queue\Events\QueueChanged;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** The queue names its tables; the skeleton knows which modules show them. */
#[CoversClass(PublishQueueChanges::class)]
final class PublishQueueChangesTest extends TestCase
{
    public function test_the_jobs_table_is_the_jobs_module(): void
    {
        $this->assertSame(['module.jobs'], $this->topics([QueueChanged::JOBS]));
    }

    public function test_the_failures_are_the_failed_jobs_module(): void
    {
        $this->assertSame(['module.failed-jobs'], $this->topics([QueueChanged::FAILED]));
    }

    public function test_both_tables_are_both_modules(): void
    {
        $this->assertSame(['module.jobs', 'module.failed-jobs'], $this->topics([QueueChanged::JOBS, QueueChanged::FAILED]));
    }

    /**
     * @param non-empty-list<QueueChanged::JOBS|QueueChanged::FAILED> $tables
     * @return list<string>
     */
    private function topics(array $tables): array
    {
        $broadcaster = new FakeBroadcaster;

        (new PublishQueueChanges(new ModuleChanges($broadcaster)))(new QueueChanged($tables));

        return array_map(static fn ($envelope): string => $envelope->topic, $broadcaster->published());
    }
}
