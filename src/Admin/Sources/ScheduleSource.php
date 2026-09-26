<?php

declare(strict_types=1);

namespace App\Admin\Sources;

use Hydra\Admin\Contracts\DescribesColumnsInterface;
use Hydra\Admin\Contracts\RowSourceInterface;
use Hydra\Admin\Contracts\SourceInterface;
use Hydra\Admin\Criteria;
use Hydra\Admin\Page;
use Hydra\Admin\SourceDescription;
use Hydra\Scheduler\DatabaseRunLog;
use Hydra\Scheduler\LockDirectory;
use Hydra\Scheduler\Schedule;
use Hydra\Scheduler\ScheduledTask;
use Hydra\Scheduler\SchedulerConfig;
use Hydra\Scheduler\TaskRun;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * The schedule the application declared, one row per task, with each task's
 * last recorded run beside it. A row's id is the class with its backslashes
 * made dashes, the same name its lock file has.
 */
final class ScheduleSource implements SourceInterface, RowSourceInterface, DescribesColumnsInterface
{
    private const COLUMNS = [
        'id', 'task', 'class', 'kind', 'schedule', 'next_due', 'running',
        'last_run', 'last_outcome', 'last_duration', 'last_items', 'last_error',
    ];

    public function __construct(
        private readonly Schedule $schedule,
        private readonly DatabaseRunLog $runs,
        private readonly SchedulerConfig $config,
        private readonly ClockInterface $clock,
    ) {}

    public function page(Criteria $criteria): Page
    {
        $rows = $this->rows();

        return new Page(array_slice($rows, $criteria->offset(), $criteria->perPage), count($rows), $criteria);
    }

    public function find(string $id): ?array
    {
        foreach ($this->rows() as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    public function describe(): SourceDescription
    {
        return new SourceDescription(
            table: 'schedule',
            columns: self::COLUMNS,
            sortable: [],
            searchable: [],
            filterable: [],
            defaultSort: 'id',
        );
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        $latest = $this->runs->latest();
        $locks = new LockDirectory($this->config->lockPath);
        $now = $this->clock->now()->setTimezone($this->schedule->timezone());

        return array_map(
            static fn (ScheduledTask $task): array => self::row($task, $latest[$task->class] ?? null, $locks, $now),
            $this->schedule->tasks(),
        );
    }

    /** @return array<string, mixed> */
    private static function row(ScheduledTask $task, ?TaskRun $last, LockDirectory $locks, DateTimeImmutable $now): array
    {
        $next = $task->expression()->next($now);
        $name = strrchr($task->class, '\\');

        return [
            'id' => str_replace('\\', '-', $task->class),
            'task' => $name === false ? $task->class : substr($name, 1),
            'class' => $task->class,
            'kind' => $task->batched ? 'batch' : 'task',
            'schedule' => $task->expression()->expression,
            'next_due' => $next === null ? null : (string) $next->getTimestamp(),
            'running' => $locks->isHeld($task->class),
            'last_run' => $last?->startedAt === null ? null : (string) $last->startedAt->getTimestamp(),
            'last_outcome' => $last?->outcome->value,
            'last_duration' => $last?->durationMs,
            'last_items' => $last?->items,
            'last_error' => $last?->error,
        ];
    }
}
