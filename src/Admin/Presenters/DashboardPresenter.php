<?php

declare(strict_types=1);

namespace App\Admin\Presenters;

use Hydra\Admin\Contracts\PresenterInterface;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Database\Contracts\ConnectionInterface;

/**
 * Dashboard presenter
 *
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
        $roles = $this->db->select('SELECT role, COUNT(*) AS total FROM users GROUP BY role');

        return [
            'user' => $this->guard->user(),
            'total' => array_sum(array_column($roles, 'total')),
            'byRole' => array_column($roles, 'total', 'role'),
            'newest' => $this->db->select('SELECT username, role, created_at FROM users ORDER BY id DESC LIMIT 5'),
        ];
    }
}
