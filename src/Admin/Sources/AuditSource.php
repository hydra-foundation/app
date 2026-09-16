<?php

declare(strict_types=1);

namespace App\Admin\Sources;

use Hydra\Admin\Sources\TableSource;
use Hydra\Database\Contracts\ConnectionInterface;

/**
* Audit module data contract.
*
* username is stored on the row rather than joined from users, the way activity
* does it. The join would be the only reason this class could not be a
* declaration, and it would answer the wrong question anyway: user_id is
* ON DELETE SET NULL, so deleting an account would quietly erase who made every
* change it ever made. The name as it was at the time is the record.
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
