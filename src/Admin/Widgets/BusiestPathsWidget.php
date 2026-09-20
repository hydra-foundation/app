<?php

declare(strict_types=1);

namespace App\Admin\Widgets;

use Hydra\Admin\Contracts\PeriodAwareInterface;
use Hydra\Admin\Window;
use Hydra\Database\Contracts\ConnectionInterface;

/** Where the period's requests went, and what each path cost on average. */
final class BusiestPathsWidget implements PeriodAwareInterface
{
    private const PATHS = 6;

    private Window $window;

    public function __construct(private readonly ConnectionInterface $db) {}

    public function withWindow(Window $window): static
    {
        $clone = clone $this;
        $clone->window = $window;

        return $clone;
    }

    public function present(): array
    {
        [$condition, $bindings] = $this->window->condition('created_at');

        $rows = $this->db->select(
            sprintf(
                'SELECT path,
                        COUNT(*) AS hits,
                        AVG(duration_ms) AS average,
                        SUM(CASE WHEN status >= 400 THEN 1 ELSE 0 END) AS failed
                 FROM activity
                 WHERE %s
                 GROUP BY path
                 ORDER BY hits DESC
                 LIMIT %d',
                $condition,
                self::PATHS,
            ),
            $bindings,
        );

        $busiest = max(1, (int) ($rows[0]['hits'] ?? 1));

        return [
            'period' => $this->window->label(),
            'paths' => array_map(
                static fn (array $row): array => [
                    'path' => (string) $row['path'],
                    'hits' => (int) $row['hits'],
                    'failed' => (int) $row['failed'],
                    'average' => (int) round((float) $row['average']),
                    // The bar is drawn against the busiest path rather than the
                    // total, so the smallest row on a flat day is still visible.
                    'share' => (int) round((int) $row['hits'] / $busiest * 100),
                ],
                $rows,
            ),
        ];
    }
}
