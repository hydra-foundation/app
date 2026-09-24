<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Jobs\SendVerificationLink;
use Hydra\Admin\Events\AdminEvent;
use Hydra\Admin\Events\RowCreated;
use Hydra\Admin\Events\RowUpdated;
use Hydra\Queue\Contracts\QueueInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends a verification link when the users module creates an account or moves
 * its address. Here rather than in UserSource, which writes rows and should not
 * know that a row is somebody's inbox.
 */
final class MailAddressChangesListener
{
    private const MODULE = 'users';

    public function __construct(
        private readonly QueueInterface $queue,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(AdminEvent $event): void
    {
        if ($event->module !== self::MODULE) {
            return;
        }

        // Swallowed like the audit write: the row is already saved, and a 500
        // would tell the reader it was not.
        try {
            $this->mail($event);
        } catch (Throwable $e) {
            $this->logger->error('Could not queue address mail: ' . $e->getMessage(), ['exception' => $e]);
        }
    }

    private function mail(AdminEvent $event): void
    {
        if ($event instanceof RowCreated || ($event instanceof RowUpdated && in_array('email', $event->changed(), true))) {
            $this->queue->push(SendVerificationLink::class, ['user' => (int) $event->id]);
        }
    }
}
