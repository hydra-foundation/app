<?php

declare(strict_types=1);

namespace App\Admin\Widgets;

use App\Entities\Role;
use Hydra\Admin\Contracts\PeriodAwareInterface;
use Hydra\Admin\Window;
use Hydra\Database\Contracts\ConnectionInterface;

/** How many accounts the period brought in, and how they divide by role. */
final class AccountsWidget implements PeriodAwareInterface
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

        $totals = array_column(
            $this->db->select("SELECT role, COUNT(*) AS total FROM users WHERE {$condition} GROUP BY role", $bindings),
            'total',
            'role',
        );

        return [
            'period' => $this->window->label(),
            // Summed before the roll-up, so a row holding a value no case
            // covers any more is still one of the users we report.
            'total' => array_sum($totals),
            'byRole' => $this->byRole($totals),
        ];
    }

    /**
     * One row per role, in declaration order, so a role nobody holds yet still
     * reports its zero rather than vanishing from the card.
     *
     * @param array<string, mixed> $totals
     * @return list<array{label: string, value: string, count: int}>
     */
    private function byRole(array $totals): array
    {
        $roles = [];

        foreach (Role::cases() as $role) {
            $roles[] = [
                'label' => $role->label(),
                'value' => $role->value,
                'count' => (int) ($totals[$role->value] ?? 0),
            ];
        }

        return $roles;
    }
}
