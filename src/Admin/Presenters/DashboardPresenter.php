<?php

declare(strict_types=1);

namespace App\Admin\Presenters;

use App\Entities\Role;
use Hydra\Admin\Contracts\PresenterInterface;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Database\Contracts\ConnectionInterface;

/**
 * The admin dashboard's numbers
 */
final class DashboardPresenter implements PresenterInterface
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly GuardInterface $guard,
    ) {}

    public function present(): array
    {
        $totals = array_column(
            $this->db->select('SELECT role, COUNT(*) AS total FROM users GROUP BY role'),
            'total',
            'role',
        );

        return [
            'user' => $this->guard->user(),
            // Summed before the roll-up, so a row holding a value no case
            // covers any more is still one of the users we report.
            'total' => array_sum($totals),
            'byRole' => $this->countsByRole($totals),
            'newest' => array_map(
                $this->labelRole(...),
                $this->db->select('SELECT username, role, created_at FROM users ORDER BY id DESC LIMIT 5'),
            ),
        ];
    }

    /**
     * One tile per role, in declaration order, so a role nobody holds yet
     * still reports its zero rather than vanishing from the dashboard.
     *
     * @param array<string, mixed> $totals
     * @return array<string, int>
     */
    private function countsByRole(array $totals): array
    {
        $counts = [];

        foreach (Role::cases() as $role) {
            $counts[$role->label()] = (int) ($totals[$role->value] ?? 0);
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function labelRole(array $row): array
    {
        return array_replace($row, ['role' => Role::coerce($row['role'] ?? null)->label()]);
    }
}
