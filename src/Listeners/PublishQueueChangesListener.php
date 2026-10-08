<?php

declare(strict_types=1);

namespace App\Listeners;

use Hydra\Admin\Live\ModuleChanges;
use Hydra\Queue\Events\QueueChanged;

/**
 * Jobs and Failed jobs are written by the queue and the worker, never by the
 * admin, so the admin has nothing of its own to publish for them. The queue
 * names its tables; which modules show them is the skeleton's to say.
 */
final readonly class PublishQueueChangesListener
{
    private const MODULES = [
        QueueChanged::JOBS => 'jobs',
        QueueChanged::FAILED => 'failed-jobs',
    ];

    public function __construct(private ModuleChanges $changes) {}

    public function __invoke(QueueChanged $event): void
    {
        foreach ($event->tables as $table) {
            $this->changes->publish(self::MODULES[$table]);
        }
    }
}
