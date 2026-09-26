<?php

declare(strict_types=1);

namespace App\Admin\Actions;

use Hydra\Admin\Contracts\RowActionInterface;
use Hydra\Admin\Exceptions\WriteRejected;
use Hydra\Queue\DatabaseQueue;

/** queue:retry <id>, from the failed job's row. */
final class RetryFailedJob implements RowActionInterface
{
    public function __construct(private readonly DatabaseQueue $queue) {}

    public function run(string $id): string
    {
        if (!ctype_digit($id) || !$this->queue->retry((int) $id)) {
            throw WriteRejected::on('id', 'That failed job is already gone.');
        }

        return "Job {$id} is back on the queue.";
    }
}
