<?php

declare(strict_types=1);

namespace App\Admin\Modules;

use App\Admin\Sources\JobSource;
use App\Authorization\AccessAdmin;
use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Screens\DeleteScreen;
use Hydra\Admin\Screens\ShowScreen;
use Hydra\Admin\Surface;

/**
 * What is waiting on the queue. Admin-only, since a payload can carry an
 * address.
 */
final class JobsModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('jobs')
            ->title('Jobs')
            ->group('Queue')
            ->icon('hourglass-split')
            ->ability(AccessAdmin::class)
            ->source(JobSource::class)
            ->perPage(25)
            ->defaultSort('id', 'asc')
            ->fields(
                Field::id()->labelled('ID')->sortable(),
                Field::text('job')->sortable()->searchable(),
                Field::text('payload')->onlyOn(Surface::Show),
                Field::number('attempts')->sortable(),
                Field::datetime('available_at')->labelled('Due')->sortable()->relative(),
                Field::datetime('reserved_at')->labelled('Held since')->sortable()->relative()->emptyAs('waiting'),
                Field::datetime('created_at')->labelled('Queued')->sortable()->relative(),
            )
            ->screens(
                ShowScreen::make()->title('Job'),
                DeleteScreen::make()
                    ->labelled('Cancel')
                    ->confirm('Cancel this job? It will not run.')
                    ->when(static fn (array $row): bool => $row['reserved_at'] === null),
            );
    }
}
