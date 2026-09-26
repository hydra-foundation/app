<?php

declare(strict_types=1);

namespace App\Admin\Modules;

use App\Admin\Sources\ScheduledRunsSource;
use App\Authorization\AccessAdmin;
use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Screens\ShowScreen;
use Hydra\Admin\Surface;
use Hydra\View\HtmlView;

/** Every run the scheduler recorded, kept for SCHEDULE_KEEP_DAYS. Read-only: the runner writes it. */
final class ScheduledRunsModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('scheduled-runs')
            ->title('Runs')
            ->group('Monitoring')
            ->icon('clock')
            ->ability(AccessAdmin::class)
            ->source(ScheduledRunsSource::class)
            ->perPage(50)
            ->defaultSort('id', 'desc')
            ->gone('That run has been pruned.')
            ->fields(
                Field::id()->onlyOn(Surface::Show),
                Field::text('task')->filterable()->format(self::task(...)),
                Field::select('outcome', SchedulerModule::OUTCOMES)->filterable(),
                Field::datetime('started_at')->labelled('Started')->relative()->sortable(),
                Field::number('duration_ms')->labelled('Took')->grouped()->suffix(' ms')->sortable(),
                Field::number('items')->sortable(),
                Field::number('held_minutes')->labelled('Held for')->suffix(' min')->onlyOn(Surface::Show),
                Field::boolean('idle', 'Idle', 'Did work')->filterable()->hiddenOn(Surface::List, Surface::Export),
                Field::text('error')->searchable()->truncate(80, Surface::List)->emptyAs('none'),
            )
            ->screens(
                ShowScreen::make()->title('Scheduled run'),
            );
    }

    /** The task's short name, linked to the task in Scheduler. */
    private static function task(mixed $value): HtmlView
    {
        $class = (string) $value;
        $name = substr(strrchr('\\' . $class, '\\') ?: $class, 1);

        return new HtmlView('<a href="/admin/scheduler/' . rawurlencode(str_replace('\\', '-', $class)) . '">' . htmlspecialchars($name, ENT_QUOTES) . '</a>');
    }
}
