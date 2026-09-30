<?php

declare(strict_types=1);

namespace App\Tasks;

use App\Repositories\NotificationRepository;
use Hydra\Scheduler\Contracts\TaskInterface;
use Psr\Clock\ClockInterface;

/**
 * Deletes notices read over ninety days ago. An unread one is kept however
 * old, since nobody has seen it yet. Daily.
 */
final class PruneNotifications implements TaskInterface
{
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly ClockInterface $clock,
    ) {}

    public function run(): void
    {
        $this->notifications->prune($this->clock->now()->modify('-90 days'));
    }
}
