<?php

declare(strict_types=1);

namespace App\Tasks;

use Hydra\Scheduler\Contracts\TaskInterface;
use Hydra\Throttle\Contracts\LockoutStoreInterface;
use Psr\Clock\ClockInterface;

/**
 * Deletes lockouts that ended over a day ago. An ended lockout is already off
 * the Rate limits list; a day's grace keeps yesterday's in the table for
 * anyone reading it by hand. Daily.
 */
final class PruneLockouts implements TaskInterface
{
    public function __construct(
        private readonly LockoutStoreInterface $lockouts,
        private readonly ClockInterface $clock,
    ) {}

    public function run(): void
    {
        $this->lockouts->prune($this->clock->now()->modify('-1 day'));
    }
}
