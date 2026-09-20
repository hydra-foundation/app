<?php

declare(strict_types=1);

namespace App\Admin\Widgets;

use App\Entities\Role;
use Hydra\Admin\Contracts\PeriodAwareInterface;
use Hydra\Admin\Contracts\TimezoneInterface;
use Hydra\Admin\Field;
use Hydra\Admin\Surface;
use Hydra\Admin\Window;
use Hydra\Database\Contracts\ConnectionInterface;

/** The accounts the period added most recently, and what each one may do. */
final class NewestAccountsWidget implements PeriodAwareInterface
{
    /** Also what the card reserves room for, so the two cannot drift. */
    public const ACCOUNTS = 5;

    private Window $window;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly TimezoneInterface $timezone,
    ) {}

    public function withWindow(Window $window): static
    {
        $clone = clone $this;
        $clone->window = $window;

        return $clone;
    }

    public function present(): array
    {
        [$condition, $bindings] = $this->window->condition('created_at');

        return [
            'period' => $this->window->label(),
            'accounts' => array_map(
                fn (array $row): array => array_replace($this->localised($row), [
                    'role' => Role::coerce($row['role'] ?? null)->label(),
                ]),
                $this->db->select(
                    sprintf(
                        'SELECT id, username, role, created_at
                         FROM users
                         WHERE %s
                         ORDER BY id DESC
                         LIMIT %d',
                        $condition,
                        self::ACCOUNTS,
                    ),
                    $bindings,
                ),
            ),
        ];
    }

    /**
     * The stamp as the lists render it. Through Field rather than by hand, so
     * a card and the table it links to cannot come to disagree about what time
     * a row was written at.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function localised(array $row): array
    {
        return array_replace($row, [
            'created_at' => Field::datetime('created_at')->display(Surface::List, $row, $this->timezone->zone()),
        ]);
    }
}
