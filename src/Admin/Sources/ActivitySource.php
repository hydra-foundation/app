<?php

declare(strict_types=1);

namespace App\Admin\Sources;

use Hydra\Admin\Contracts\RowSourceInterface;
use Hydra\Admin\Contracts\SourceInterface;
use Hydra\Admin\Criteria;
use Hydra\Admin\Page;
use Hydra\Database\Contracts\ConnectionInterface;

/**
 * Activity source
 *
 * The admin's read side for the activity table, and only the read side: it
 * implements no write contract, so the log cannot be rewritten from the admin.
 * Criteria arrives whitelisted against the module's fields; the ORDER BY column
 * is checked again here so this class is safe to call from anywhere, not only
 * from a screen.
 */
final class ActivitySource implements SourceInterface, RowSourceInterface
{
    private const COLUMNS = 'id, user_id, username, method, path, query, status, duration_ms, ip, user_agent, referer, created_at';
    private const SORTABLE = ['id', 'username', 'method', 'path', 'status', 'duration_ms', 'ip', 'created_at'];
    private const SEARCHABLE = ['username', 'path', 'ip'];

    public function __construct(private readonly ConnectionInterface $db) {}

    public function find(string $id): ?array
    {
        return $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM activity WHERE id = ?',
            [(int) $id],
        );
    }

    public function page(Criteria $criteria): Page
    {
        [$where, $params] = $this->conditions($criteria);
        $order = in_array($criteria->sort, self::SORTABLE, true) ? $criteria->sort : 'id';
        $total = $this->db->selectOne("SELECT COUNT(*) AS total FROM activity {$where}", $params);

        return new Page(
            $this->db->select(
                'SELECT ' . self::COLUMNS . " FROM activity {$where}"
                . " ORDER BY {$order} {$criteria->direction}"
                . " LIMIT {$criteria->perPage} OFFSET {$criteria->offset()}",
                $params,
            ),
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
                static fn (string $column): string => "{$column} LIKE ?",
                self::SEARCHABLE,
            )) . ')';

            foreach (self::SEARCHABLE as $_) {
                $params[] = '%' . $criteria->search . '%';
            }
        }

        foreach (['method', 'status'] as $column) {
            if (isset($criteria->filters[$column])) {
                $clauses[] = "{$column} = ?";
                $params[] = $criteria->filters[$column];
            }
        }

        return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $params];
    }
}
