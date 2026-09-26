<?php

declare(strict_types=1);

namespace App\Admin\Modules;

use App\Admin\Sources\ScheduleSource;
use App\Authorization\AccessAdmin;
use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Screens\ShowScreen;
use Hydra\Admin\Surface;

/** What the scheduler runs, when, and what it did last. Read-only: the schedule lives in code. */
final class SchedulerModule implements ModuleInterface
{
    public const OUTCOMES = ['ran' => 'Ran', 'held' => 'Held', 'failed' => 'Failed'];

    private const KINDS = ['task' => 'Task', 'batch' => 'Batch'];

    public function define(): Definition
    {
        return Definition::make('scheduler')
            ->title('Scheduler')
            ->group('Monitoring')
            ->icon('clock-history')
            ->ability(AccessAdmin::class)
            ->source(ScheduleSource::class)
            ->perPage(50)
            ->gone('That task is no longer on the schedule.')
            ->fields(
                Field::id()->hiddenOn(Surface::List, Surface::Show, Surface::Export),
                Field::text('task'),
                Field::text('class')->onlyOn(Surface::Show),
                Field::select('kind', self::KINDS),
                Field::text('schedule'),
                Field::datetime('next_due')->labelled('Next due')->relative()->emptyAs('never'),
                Field::boolean('running', 'Running', 'Idle'),
                Field::datetime('last_run')->labelled('Last run')->relative()->emptyAs('never'),
                Field::select('last_outcome', self::OUTCOMES)->labelled('Outcome')->emptyAs('never'),
                Field::number('last_duration')->labelled('Took')->grouped()->suffix(' ms'),
                Field::number('last_items')->labelled('Items')->onlyOn(Surface::Show),
                Field::text('last_error')->labelled('Error')->onlyOn(Surface::Show)->emptyAs('none'),
            )
            ->screens(
                ShowScreen::make()->title('Scheduled task'),
            );
    }
}
