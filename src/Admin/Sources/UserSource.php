<?php

declare(strict_types=1);

namespace App\Admin\Sources;

use Hydra\Admin\Contracts\SourceInterface;
use Hydra\Admin\Criteria;
use Hydra\Admin\Page;
use Hydra\Database\Contracts\ConnectionInterface;

/**
 * User source
 *
 * The admin's read side for the users table. Criteria arrives already whitelisted
 * against the module's fields; the ORDER BY column is checked again here so this
 * class is safe to call from anywhere, not only from a screen.
 */
final class UserSource implements SourceInterface
{
    private const COLUMNS = 'id, username, role, created_at';
    private const SORTABLE = ['id', 'username', 'role', 'created_at'];

    public function __construct(private readonly ConnectionInterface $db) {}

    public function page(Criteria $criteria): Page
    {
        [$where, $params] = $this->conditions($criteria);
        $order = in_array($criteria->sort, self::SORTABLE, true) ? $criteria->sort : 'id';
        $total = $this->db->selectOne("SELECT COUNT(*) AS total FROM users {$where}", $params);

        return new Page(
            $this->db->select(
                'SELECT ' . self::COLUMNS . " FROM users {$where}"
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
            $clauses[] = 'username LIKE ?';
            $params[] = '%' . $criteria->search . '%';
        }

        if (isset($criteria->filters['role'])) {
            $clauses[] = 'role = ?';
            $params[] = $criteria->filters['role'];
        }

        return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $params];
    }
}
