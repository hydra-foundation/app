<?php

declare(strict_types=1);

namespace App\Admin\Sources;

use Hydra\Admin\Contracts\RowSourceInterface;
use Hydra\Admin\Contracts\SourceInterface;
use Hydra\Admin\Criteria;
use Hydra\Admin\Page;
use Hydra\Admin\RowId;
use Hydra\Database\Contracts\ConnectionInterface;

/**
* Audit module data contract
*/
final class AuditSource implements SourceInterface, RowSourceInterface
{
    private const TABLE = 'audit';
    private const COLUMNS = 'id, table_name, table_id, old_value, new_value, user_id, message, created_at';
    private const SORTABLE = ['id', 'table_name', 'table_id', 'old_value', 'new_value', 'created_at'];
    private const SEARCHABLE = ['table_name', 'table_id', 'message'];

    public function __construct(private readonly ConnectionInterface $db) {}

    public function find(string $id): ?array
    {
        $key = RowId::int($id);

        $sql = sprintf("SELECT %s
            FROM %s
            WHERE id=?", self::COLUMNS, self::TABLE);
        return $key === null ? null : $this->db->selectOne($sql, [$key]);
    }

    public function page(Criteria $criteria): Page
    {
        [$where, $params] = $this->conditions($criteria);
        $order = in_array($criteria->sort, self::SORTABLE, true) ? $criteria->sort : 'id';
        $sql = sprintf("SELECT COUNT(*) as total
            FROM %s
            WHERE %s", self::TABLE, $where);
        $total = $this->db->selectOne($sql, $params);
        $sql = sprintf(
            "SELECT %s 
            FROM %s 
            WHERE %s 
            ORDER BY %s %s 
            LIMIT %s OFFSET %s",
            self::COLUMNS,
            self::TABLE,
            $where,
            $order,
            $criteria->direction,
            $criteria->perPage,
            $criteria->offset()
        );
        return new Page(
            $this->db->select($sql, $params),
            (int) ($total['total'] ?? 0),
            $criteria,
        );
    }

    /** @return array{0: string, 1: list<string>} */
    private function conditions(Criteria $criteria): array
    {
        $clauses = [];
        $params = [];

        if ($criteria->search !== null) {
            $clauses[] = '(' . implode(' OR ', array_map(
                static fn (string $column): string => Criteria::like($column),
                self::SEARCHABLE,
            )) . ')';

            foreach (self::SEARCHABLE as $_) {
                $params[] = $criteria->searchPattern();
            }
        }

        if (isset($criteria->filters['table_name'])) {
            $clauses[] = 'table_name = ?';
            $params[] = $criteria->filters['table_name'];
        }

        return [$clauses === [] ? '1=1' : implode(' AND ', $clauses), $params];
    }
}
