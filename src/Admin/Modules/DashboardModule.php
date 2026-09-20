<?php

declare(strict_types=1);

namespace App\Admin\Modules;

use App\Admin\Widgets\BusiestPathsWidget;
use App\Admin\Widgets\NewestAccountsWidget;
use App\Admin\Widgets\RecentChangesWidget;
use App\Admin\Widgets\TotalsWidget;
use App\Admin\Widgets\TrafficWidget;
use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Screens\DashboardScreen;
use Hydra\Admin\Shape;
use Hydra\Admin\Widget;

/**
 * No source, no fields, just a grid of cards. Signing in is the whole gate, so
 * it declares no ability and every authenticated user sees it.
 *
 * Each card fetches its own body once the grid is on screen, so the dashboard
 * costs one cheap request and then five independent ones — a slow count holds
 * up its own card and nothing else.
 *
 * Everything on it answers for the period the visitor picked, strip included.
 * The strip used to answer for all of time, which put three all-time figures
 * directly above three period figures in the same typeface; all time is the
 * caption under each total now, and the page has one clock.
 *
 * The rows group by subject rather than by shape: requests, then people. The
 * grid ran traffic, people, traffic, people, people, with the only full-width
 * card cutting the page between two halves it had nothing to do with.
 */
final class DashboardModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('dashboard')
            ->title('Dashboard')
            ->group('Overview')
            ->icon('grid-1x2')
            ->screens(
                DashboardScreen::make()
                    ->named('overview')
                    ->summarised(
                        Widget::make('totals', 'admin/partials/stats')
                            ->titled('Totals')
                            ->periodic()
                            ->from(TotalsWidget::class),
                    )
                    ->widgets(
                        Widget::make('traffic', 'admin/widgets/traffic')
                            ->titled('Traffic')
                            ->withIcon('activity')
                            // It earns eight columns by holding the only thing
                            // on the page with a shape rather than a value.
                            ->spanning(8)
                            ->reserving(1)
                            ->shaped(Shape::Block)
                            // The only number here that moves while somebody is
                            // watching it, which is also what earns it the one
                            // refresh button on the grid.
                            ->refreshEvery(60)
                            ->periodic()
                            ->from(TrafficWidget::class),
                        Widget::make('paths', 'admin/widgets/paths')
                            ->titled('Busiest paths')
                            ->withIcon('signpost-split')
                            // A ranking is a narrow thing. At twelve columns it
                            // drew a 700px bar to represent two requests.
                            ->spanning(4)
                            ->reserving(BusiestPathsWidget::PATHS)
                            ->shaped(Shape::Bars)
                            ->periodic()
                            ->from(BusiestPathsWidget::class),
                        Widget::make('newest', 'admin/widgets/newest')
                            ->titled('Newest accounts')
                            ->withIcon('person-plus')
                            ->spanning(6)
                            ->reserving(NewestAccountsWidget::ACCOUNTS)
                            ->shaped(Shape::Rows)
                            ->periodic()
                            ->from(NewestAccountsWidget::class),
                        Widget::make('changes', 'admin/widgets/changes')
                            ->titled('Recent changes')
                            ->withIcon('clock-history')
                            ->spanning(6)
                            ->reserving(RecentChangesWidget::CHANGES)
                            ->shaped(Shape::Rows)
                            ->periodic()
                            ->from(RecentChangesWidget::class),
                    ),
            );
    }
}
