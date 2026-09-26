<?php

declare(strict_types=1);

namespace App\Admin\Modules;

use App\Admin\Sources\FailedJobSource;
use App\Authorization\AccessAdmin;
use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Screens\DeleteScreen;
use Hydra\Admin\Screens\ShowScreen;
use Hydra\Admin\Surface;
use Hydra\Queue\FailedJob;
use Hydra\View\HtmlView;

/**
 * What ran out of tries. The table gives each failure's one-line reason; the
 * row gives the trace and the payload it was running with.
 */
final class FailedJobsModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('failed-jobs')
            ->title('Failed jobs')
            ->group('Queue')
            ->icon('exclamation-octagon')
            ->ability(AccessAdmin::class)
            ->source(FailedJobSource::class)
            ->perPage(25)
            ->defaultSort('id', 'desc')
            ->fields(
                Field::id()->labelled('ID')->sortable(),
                Field::text('job')->sortable()->searchable(),
                Field::text('exception')
                    ->format(static fn (mixed $value): string => (new FailedJob(0, '', '', (string) $value, 0))->reason(), Surface::List)
                    ->format(self::preformatted(...), Surface::Show),
                Field::text('payload')->onlyOn(Surface::Show)->format(self::preformatted(...)),
                Field::datetime('failed_at')->labelled('Failed')->sortable()->relative(),
            )
            ->screens(
                ShowScreen::make()->title('Failed job'),
                DeleteScreen::make()->confirm('Delete this failed job? It will not run again.'),
            );
    }

    /** A trace or a payload, with the line breaks that make it readable. */
    private static function preformatted(mixed $value): HtmlView
    {
        return new HtmlView('<pre class="mb-0 small">' . htmlspecialchars((string) $value, ENT_QUOTES) . '</pre>');
    }
}
