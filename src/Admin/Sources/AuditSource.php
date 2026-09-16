<?php

declare(strict_types=1);

namespace App\Admin\Sources;

use Hydra\Admin\Sources\TableSource;
use Hydra\Database\Contracts\ConnectionInterface;

/**
* Audit module data contract
*/
final class AuditSource extends TableSource
{
    public function __construct(ConnectionInterface $db)
    {
        parent::__construct(
            $db,
            table: 'audit',
            columns: ['id', 'table_name', 'table_id', 'old_value', 'new_value', 'user_id', 'message', 'created_at'],
            sortable: ['id', 'table_name', 'table_id', 'old_value', 'new_value', 'created_at'],
            searchable: ['table_name', 'table_id', 'message'],
            filterable: ['table_name'],
        );
    }
}
