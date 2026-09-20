<?php

declare(strict_types=1);

namespace App\Admin\Widgets;

use Hydra\Admin\Contracts\PresenterInterface;
use Hydra\Database\Contracts\ConnectionInterface;

/**
 * The running totals above the grid. Deliberately outside the period: the cards
 * below answer for the stretch of time the visitor picked, and these answer for
 * all of it, so narrowing the dashboard to today never costs the totals.
 */
final class TotalsWidget implements PresenterInterface
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function present(): array
    {
        return [
            'stats' => [
                ['label' => 'Accounts', 'value' => $this->count('users'), 'icon' => 'people'],
                ['label' => 'Requests', 'value' => $this->count('activity'), 'icon' => 'activity'],
                ['label' => 'Changes', 'value' => $this->count('audit'), 'icon' => 'clock-history'],
            ],
        ];
    }

    private function count(string $table): string
    {
        return number_format((int) ($this->db->selectOne("SELECT COUNT(*) AS total FROM {$table}")['total'] ?? 0));
    }
}
