<?php

declare(strict_types=1);

namespace App\Admin\Actions;

use Hydra\Admin\Contracts\ModuleActionInterface;
use Hydra\Queue\DatabaseQueue;
use Hydra\Queue\FailedJob;

/** queue:retry --all, from above the failed jobs table. */
final class RetryAllFailedJobs implements ModuleActionInterface
{
    public function __construct(private readonly DatabaseQueue $queue) {}

    public function run(): string
    {
        $retried = count(array_filter(
            $this->queue->failed(),
            fn (FailedJob $job): bool => $this->queue->retry($job->id),
        ));

        return match ($retried) {
            0 => 'No failed jobs.',
            1 => '1 job is back on the queue.',
            default => "{$retried} jobs are back on the queue.",
        };
    }
}
