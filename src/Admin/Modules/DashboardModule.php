<?php

declare(strict_types=1);

namespace App\Admin\Modules;

use App\Admin\Widgets\AccountsWidget;
use App\Admin\Widgets\BusiestPathsWidget;
use App\Admin\Widgets\NewestAccountsWidget;
use App\Admin\Widgets\RecentChangesWidget;
use App\Admin\Widgets\TotalsWidget;
use App\Admin\Widgets\TrafficWidget;
use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Screens\DashboardScreen;
use Hydra\Admin\Widget;

/**
 * No source, no fields, just a grid of cards. Signing in is the whole gate, so
 * it declares no ability and every authenticated user sees it.
 *
 * Each card fetches its own body once the grid is on screen, so the dashboard
 * costs one cheap request and then six independent ones — a slow count holds
 * up its own card and nothing else.
 *
 * Every card below the strip answers for the period the visitor picked. The
 * strip itself answers for all of time, which is what keeps the running totals
 * on screen when the dashboard is narrowed to today.
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
                            ->from(TotalsWidget::class),
                    )
                    ->widgets(
                        Widget::make('traffic', 'admin/widgets/traffic')
                            ->titled('Traffic')
                            ->withIcon('activity')
                            ->spanning(8)
                            // The only number here that moves while somebody is
                            // watching it.
                            ->refreshEvery(60)
                            ->periodic()
                            ->from(TrafficWidget::class),
                        Widget::make('accounts', 'admin/widgets/accounts')
                            ->titled('Accounts')
                            ->withIcon('people')
                            ->spanning(4)
                            ->periodic()
                            ->from(AccountsWidget::class),
                        Widget::make('paths', 'admin/widgets/paths')
                            ->titled('Busiest paths')
                            ->withIcon('signpost-split')
                            ->spanning(12)
                            ->periodic()
                            ->from(BusiestPathsWidget::class),
                        Widget::make('newest', 'admin/widgets/newest')
                            ->titled('Newest accounts')
                            ->withIcon('person-plus')
                            ->spanning(6)
                            ->periodic()
                            ->from(NewestAccountsWidget::class),
                        Widget::make('changes', 'admin/widgets/changes')
                            ->titled('Recent changes')
                            ->withIcon('clock-history')
                            ->spanning(6)
                            ->periodic()
                            ->from(RecentChangesWidget::class),
                    ),
            );
    }
}
