<?php

declare(strict_types=1);

namespace App\Admin\Actions;

use Hydra\Admin\Contracts\ModuleActionInterface;
use Hydra\Queue\DatabaseQueue;

/** queue:flush, from above the failed jobs table. */
final class ClearFailedJobs implements ModuleActionInterface
{
    public function __construct(private readonly DatabaseQueue $queue) {}

    public function run(): string
    {
        return match ($deleted = $this->queue->flush()) {
            0 => 'No failed jobs.',
            1 => '1 failed job deleted.',
            default => "{$deleted} failed jobs deleted.",
        };
    }
}
