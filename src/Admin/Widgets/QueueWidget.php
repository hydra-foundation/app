<?php

declare(strict_types=1);

namespace App\Admin\Widgets;

use Hydra\Admin\Contracts\PresenterInterface;
use Hydra\Admin\Contracts\TimezoneInterface;
use Hydra\Admin\Field;
use Hydra\Admin\Surface;
use Hydra\Database\Contracts\ConnectionInterface;
use Psr\Clock\ClockInterface;

/**
 * The queue as it stands: a backlog is a count now, not something a period
 * brought in, so the card ignores the dashboard's period.
 */
final class QueueWidget implements PresenterInterface
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly TimezoneInterface $timezone,
        private readonly ClockInterface $clock,
    ) {}

    public function present(): array
    {
        $jobs = $this->db->selectOne('SELECT COUNT(*) AS total, COUNT(reserved_at) AS held FROM jobs') ?? [];
        $failed = $this->db->selectOne('SELECT COUNT(*) AS total, MIN(failed_at) AS oldest FROM failed_jobs') ?? [];
        $held = (int) ($jobs['held'] ?? 0);
        $oldest = $failed['oldest'] ?? null;

        return [
            'waiting' => (int) ($jobs['total'] ?? 0) - $held,
            'held' => $held,
            'failed' => (int) ($failed['total'] ?? 0),
            'oldest' => $oldest === null
                ? null
                : Field::datetime('failed_at')->relative()->display(
                    Surface::List,
                    ['failed_at' => $oldest],
                    $this->timezone->zone(),
                    $this->clock->now(),
                ),
        ];
    }
}
