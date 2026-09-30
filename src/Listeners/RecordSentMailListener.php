<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Repositories\SentMailRepository;
use Hydra\Mail\Events\MessageSent;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Keeps each message a transport accepted, for Administration › Mail.
 *
 * It never throws. The mail has already gone out when this runs, and a queued
 * job whose listener threw would be marked failed and retried: the recipient
 * would get it twice, for the sake of a log row.
 */
final class RecordSentMailListener
{
    public function __construct(
        private readonly SentMailRepository $mail,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(MessageSent $sent): void
    {
        try {
            $this->mail->record($sent, $this->clock->now());
        } catch (Throwable $e) {
            $this->logger->error('Could not record sent mail: ' . $e->getMessage(), ['exception' => $e]);
        }
    }
}
