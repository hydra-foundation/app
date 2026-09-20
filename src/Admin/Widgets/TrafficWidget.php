<?php

declare(strict_types=1);

namespace App\Admin\Widgets;

use Hydra\Admin\Contracts\PeriodAwareInterface;
use Hydra\Admin\Window;
use Hydra\Database\Contracts\ConnectionInterface;

/**
 * Requests over the chosen period: how many, how many failed, and how long the
 * average one took.
 *
 * The window arrives resolved and its bounds are bound as values rather than
 * written as SQL date arithmetic, because `NOW() - INTERVAL 1 DAY` and
 * `datetime('now', '-1 day')` are not the same string and this has to answer
 * on both.
 */
final class TrafficWidget implements PeriodAwareInterface
{
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

        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status >= 400 THEN 1 ELSE 0 END) AS failed,
                    AVG(duration_ms) AS average
             FROM activity
             WHERE {$condition}",
            $bindings,
        );

        $total = (int) ($row['total'] ?? 0);
        $failed = (int) ($row['failed'] ?? 0);

        return [
            'period' => $this->window->label(),
            'total' => $total,
            'failed' => $failed,
            // Shown rather than computed in the template: a rate over no
            // requests is not zero percent, it is nothing to report.
            'failureRate' => $total === 0 ? null : round($failed / $total * 100, 1),
            'average' => $total === 0 ? null : (int) round((float) ($row['average'] ?? 0)),
        ];
    }
}
