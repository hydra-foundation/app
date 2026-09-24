<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Jobs\SendTwoFactorNotice;
use Hydra\Auth\Events\RecoveryCodeUsed;
use Hydra\Queue\Contracts\QueueInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A recovery code is how someone without the phone gets in, so the owner hears
 * of every one used, at sign-in or in Settings.
 */
final class MailRecoveryCodeUseListener
{
    public function __construct(
        private readonly QueueInterface $queue,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(RecoveryCodeUsed $event): void
    {
        // The sign-in has already happened; a failed push must not undo it with a 500.
        try {
            $this->queue->push(SendTwoFactorNotice::class, [
                'user' => (int) $event->user->getAuthIdentifier(),
                'notice' => SendTwoFactorNotice::RECOVERY_CODE_USED,
                'remaining' => $event->remaining,
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Could not queue recovery code notice: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
