<?php

declare(strict_types=1);

namespace App\Admin\Sources;

use Hydra\Admin\Sources\TableSource;
use Hydra\Database\Contracts\ConnectionInterface;

/**
* Audit module data contract.
*/
final class AuditSource extends TableSource
{
    public function __construct(ConnectionInterface $db)
    {
        parent::__construct(
            $db,
            table: 'audit',
            columns: ['id', 'module', 'table_id', 'old_value', 'new_value', 'user_id', 'username', 'message', 'created_at'],
            sortable: ['id', 'module', 'table_id', 'old_value', 'new_value', 'username', 'created_at'],
            searchable: ['module', 'table_id', 'username', 'message'],
            filterable: ['module'],
        );
    }
}
