<?php

declare(strict_types=1);

namespace App\Admin\Sources;

use Hydra\Admin\Contracts\DeleteSourceInterface;
use Hydra\Admin\Exceptions\WriteRejected;
use Hydra\Admin\Sources\TableSource;
use Hydra\Database\Contracts\ConnectionInterface;
use Hydra\Queue\DatabaseQueue;

/**
 * The jobs table, read here and written only through the queue, which owns it.
 */
final class JobSource extends TableSource implements DeleteSourceInterface
{
    public function __construct(ConnectionInterface $db, private readonly DatabaseQueue $queue)
    {
        parent::__construct(
            $db,
            table: 'jobs',
            columns: ['id', 'job', 'payload', 'attempts', 'available_at', 'reserved_at', 'created_at'],
            sortable: ['id', 'job', 'attempts', 'available_at', 'reserved_at', 'created_at'],
            searchable: ['job'],
        );
    }

    public function delete(string $id): void
    {
        if (!ctype_digit($id) || !$this->queue->cancel((int) $id)) {
            throw WriteRejected::on('id', 'A worker is running this job, so it can no longer be cancelled.');
        }
    }
}
