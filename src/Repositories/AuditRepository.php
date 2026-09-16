<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Entities\Audit;
use Hydra\Database\Contracts\ConnectionInterface;

/**
 * The write side of the audit log. Values are clipped to the column widths here
 * rather than at the call site: a change worth recording is not worth losing
 * because the message describing it ran long.
 *
 * old_value and new_value are not clipped. They are TEXT, and they are the
 * thing being recorded — a silently shortened one is worse than a large one.
 */
final class AuditRepository
{
    private const COLUMNS = [
        'table_name',
        'table_id',
        'old_value',
        'new_value',
        'user_id',
        'username',
        'message',
    ];

    private const LIMITS = [
        'table_name' => 255,
        'table_id' => 255,
        'username' => 64,
        'message' => 255,
    ];

    public function __construct(private readonly ConnectionInterface $db) {}

    public function record(Audit $audit): void
    {
        $columns = implode(',', self::COLUMNS);
        $values = implode(',', array_fill(0, count(self::COLUMNS), '?'));
        $sql = sprintf("INSERT INTO audit (%s) VALUES (%s)", $columns, $values);
        $this->db->execute($sql, [
            $this->clip('table_name', $audit->tableName),
            $this->clip('table_id', $audit->tableId),
            $audit->oldValue,
            $audit->newValue,
            $audit->userId,
            $this->clip('username', $audit->username),
            $this->clip('message', $audit->message)
        ]);
    }

    private function clip(string $column, ?string $value): ?string
    {
        return $value === null ? null : mb_substr($value, 0, self::LIMITS[$column]);
    }
}
