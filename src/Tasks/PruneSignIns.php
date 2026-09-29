<?php

declare(strict_types=1);

namespace App\Tasks;

use App\Auth\SignInWindow;
use Hydra\Auth\Contracts\SignInStoreInterface;
use Hydra\Scheduler\Contracts\TaskInterface;

/**
 * Deletes sign-ins idle past the {@see SignInWindow}. Such a session may already
 * be gone, or may not, depending on when PHP's collector last ran; with its
 * record gone it is signed out either way, so the idle limit is the limit.
 */
final class PruneSignIns implements TaskInterface
{
    public function __construct(
        private readonly SignInStoreInterface $signIns,
        private readonly SignInWindow $window,
    ) {}

    public function run(): void
    {
        $this->signIns->prune($this->window->since());
    }
}
