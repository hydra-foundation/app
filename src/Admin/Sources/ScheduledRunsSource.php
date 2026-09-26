<?php

declare(strict_types=1);

namespace App\Admin\Sources;

use Hydra\Admin\Criteria;
use Hydra\Admin\Page;
use Hydra\Admin\SourceDescription;
use Hydra\Admin\Sources\TableSource;
use Hydra\Database\Contracts\ConnectionInterface;

/**
 * The scheduler's run log, written only by the runner. `idle` is derived, not
 * stored: a run that ran and handled nothing, which is most of a drain's.
 */
final class ScheduledRunsSource extends TableSource
{
    private const IDLE = "CASE WHEN outcome = 'ran' AND items = 0 THEN 1 ELSE 0 END";

    public function __construct(ConnectionInterface $db)
    {
        parent::__construct(
            $db,
            table: 'scheduled_runs',
            columns: ['id', 'task', 'outcome', 'items', 'held_minutes', 'error', 'started_at', 'duration_ms'],
            sortable: ['id', 'task', 'outcome', 'items', 'started_at', 'duration_ms'],
            searchable: ['error'],
            filterable: ['task', 'outcome'],
        );
    }

    public function describe(): SourceDescription
    {
        $description = parent::describe();

        return new SourceDescription(
            table: $description->table,
            columns: [...$description->columns, 'idle'],
            sortable: $description->sortable,
            searchable: $description->searchable,
            filterable: [...$description->filterable, 'idle'],
            defaultSort: $description->defaultSort,
        );
    }

    public function find(string $id): ?array
    {
        $row = parent::find($id);

        return $row === null ? null : self::withIdle($row);
    }

    public function page(Criteria $criteria): Page
    {
        $page = parent::page($criteria);

        return new Page(array_map(self::withIdle(...), $page->rows), $page->total, $page->criteria, $page->note);
    }

    protected function conditions(Criteria $criteria): array
    {
        [$where, $params] = parent::conditions($criteria);

        if (isset($criteria->filters['idle'])) {
            $where .= ' AND ' . self::IDLE . ($criteria->filters['idle'] === '1' ? ' = 1' : ' = 0');
        }

        return [$where, $params];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function withIdle(array $row): array
    {
        $row['idle'] = $row['outcome'] === 'ran' && $row['items'] !== null && (int) $row['items'] === 0 ? '1' : '0';

        return $row;
    }
}
