<?php

declare(strict_types=1);

namespace App\Admin\Sources;

use Hydra\Admin\Contracts\DeleteSourceInterface;
use Hydra\Admin\Exceptions\WriteRejected;
use Hydra\Admin\Sources\TableSource;
use Hydra\Database\Contracts\ConnectionInterface;
use Hydra\Queue\DatabaseQueue;

/**
 * The failed_jobs table, read here and written only through the queue, which
 * owns it.
 */
final class FailedJobSource extends TableSource implements DeleteSourceInterface
{
    public function __construct(ConnectionInterface $db, private readonly DatabaseQueue $queue)
    {
        parent::__construct(
            $db,
            table: 'failed_jobs',
            columns: ['id', 'job', 'payload', 'exception', 'failed_at'],
            sortable: ['id', 'job', 'failed_at'],
            searchable: ['job'],
        );
    }

    public function delete(string $id): void
    {
        if (!ctype_digit($id) || !$this->queue->forget((int) $id)) {
            throw WriteRejected::on('id', 'That failed job is already gone.');
        }
    }
}
