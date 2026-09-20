<?php

declare(strict_types=1);

namespace App\Admin\Widgets;

use Hydra\Admin\Contracts\PeriodAwareInterface;
use Hydra\Admin\Window;
use Hydra\Database\Contracts\ConnectionInterface;

/**
 * The three figures above the grid: what the chosen period brought in, with the
 * running total underneath each one.
 */
final class TotalsWidget implements PeriodAwareInterface
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
        return [
            'stats' => [
                $this->stat('Requests', 'activity', 'activity'),
                $this->stat('Accounts', 'users', 'people'),
                $this->stat('Changes', 'audit', 'clock-history'),
            ],
        ];
    }

    /**
     * One figure, counted twice in one pass over the table: once for the window
     * and once for everything. Two queries would read the table twice to answer
     * a question about the same rows.
     *
     * @return array{label: string, value: string, icon: string, caption: string|null}
     */
    private function stat(string $label, string $table, string $icon): array
    {
        [$condition, $bindings] = $this->window->condition('created_at');

        $row = $this->db->selectOne(
            "SELECT COUNT(*) AS lifetime,
                    SUM(CASE WHEN {$condition} THEN 1 ELSE 0 END) AS within
             FROM {$table}",
            $bindings,
        );

        $lifetime = (int) ($row['lifetime'] ?? 0);

        return [
            'label' => $label,
            'value' => number_format((int) ($row['within'] ?? 0)),
            'icon' => $icon,
            // Nothing to add when the period is all of time: the caption would
            // repeat the figure it sits under.
            'caption' => $this->window->since === null ? null : number_format($lifetime) . ' all time',
        ];
    }
}
