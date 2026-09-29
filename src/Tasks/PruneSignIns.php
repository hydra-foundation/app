<?php

declare(strict_types=1);

namespace App\Tasks;

use Hydra\Auth\Contracts\SignInStoreInterface;
use Hydra\Scheduler\Contracts\TaskInterface;
use Psr\Clock\ClockInterface;

/**
 * Deletes sign-ins idle longer than PHP keeps a session. Such a session may
 * already be gone, or may not, depending on when PHP's collector last ran; with
 * its record gone it is signed out either way, so the idle limit is the limit.
 * Hourly, and a minute of slack for the once-a-minute write of "last seen".
 */
final class PruneSignIns implements TaskInterface
{
    private const SLACK = 60;

    public function __construct(
        private readonly SignInStoreInterface $signIns,
        private readonly ClockInterface $clock,
        /** Seconds; PHP's session.gc_maxlifetime unless given. */
        private readonly ?int $idleSeconds = null,
    ) {}

    public function run(): void
    {
        $idle = $this->idleSeconds ?? (int) ini_get('session.gc_maxlifetime');

        $this->signIns->prune($this->clock->now()->modify('-' . ($idle + self::SLACK) . ' seconds'));
    }
}
