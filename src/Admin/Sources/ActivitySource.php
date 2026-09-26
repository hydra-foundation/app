<?php

declare(strict_types=1);

namespace App\Admin\Sources;

use Hydra\Admin\Sources\TableSource;
use Hydra\Database\Contracts\ConnectionInterface;

/**
* Activity module data contract
*/
final class ActivitySource extends TableSource
{
    public function __construct(ConnectionInterface $db)
    {
        parent::__construct(
            $db,
            table: 'activity',
            columns: [
                'id', 'user_id', 'username', 'method', 'path', 'query',
                'status', 'duration_ms', 'ip', 'user_agent', 'referer', 'created_at', 'request_id',
            ],
            sortable: ['id', 'username', 'method', 'path', 'status', 'duration_ms', 'ip', 'created_at'],
            searchable: ['username', 'path', 'ip'],
            filterable: ['method', 'status', 'request_id'],
        );
    }
}
