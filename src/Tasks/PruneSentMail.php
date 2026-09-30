<?php

declare(strict_types=1);

namespace App\Tasks;

use App\Repositories\SentMailRepository;
use Hydra\Scheduler\Contracts\TaskInterface;
use Psr\Clock\ClockInterface;

/**
 * Deletes mail sent over thirty days ago. The log keeps whole bodies, reset
 * and verification links among them, so it keeps them for as long as "did
 * that email go out?" is still being asked, and no longer. Daily.
 */
final class PruneSentMail implements TaskInterface
{
    public function __construct(
        private readonly SentMailRepository $mail,
        private readonly ClockInterface $clock,
    ) {}

    public function run(): void
    {
        $this->mail->prune($this->clock->now()->modify('-30 days'));
    }
}
