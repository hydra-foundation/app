<?php

declare(strict_types=1);

namespace App\Admin\Modules;

use App\Admin\Widgets\BusiestPathsWidget;
use App\Admin\Widgets\NewestAccountsWidget;
use App\Admin\Widgets\QueueWidget;
use App\Admin\Widgets\RecentChangesWidget;
use App\Admin\Widgets\TotalsWidget;
use App\Admin\Widgets\TrafficWidget;
use App\Authorization\AccessAdmin;
use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Screens\DashboardScreen;
use Hydra\Admin\Shape;
use Hydra\Admin\Widget;

/**
 * No source, no fields, just a grid of cards. The module itself declares no
 * ability, because /admin lands here and every authenticated user must be able
 * to arrive somewhere. Each card is an admin's, though: they read the activity,
 * users and audit tables, whose own modules are admin-only, and a card that
 * showed them to everyone would undo those gates. A user who is not an admin
 * gets the grid's empty state.
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
                            ->requires(AccessAdmin::class)
                            ->from(TotalsWidget::class),
                    )
                    ->widgets(
                        Widget::make('traffic', 'admin/widgets/traffic')
                            ->titled('Traffic')
                            ->withIcon('activity')
                            ->spanning(8)
                            ->reserving(1)
                            ->shaped(Shape::Block)
                            ->refreshEvery(60)
                            ->periodic()
                            ->requires(AccessAdmin::class)
                            ->from(TrafficWidget::class),
                        Widget::make('paths', 'admin/widgets/paths')
                            ->titled('Busiest paths')
                            ->withIcon('signpost-split')
                            ->spanning(4)
                            ->reserving(BusiestPathsWidget::PATHS)
                            ->shaped(Shape::Bars)
                            ->periodic()
                            ->requires(AccessAdmin::class)
                            ->from(BusiestPathsWidget::class),
                        Widget::make('newest', 'admin/widgets/newest')
                            ->titled('Newest accounts')
                            ->withIcon('person-plus')
                            ->spanning(6)
                            ->reserving(NewestAccountsWidget::ACCOUNTS)
                            ->shaped(Shape::Rows)
                            ->periodic()
                            ->requires(AccessAdmin::class)
                            ->from(NewestAccountsWidget::class),
                        Widget::make('changes', 'admin/widgets/changes')
                            ->titled('Recent changes')
                            ->withIcon('clock-history')
                            ->spanning(6)
                            ->reserving(RecentChangesWidget::CHANGES)
                            ->shaped(Shape::Rows)
                            ->periodic()
                            ->requires(AccessAdmin::class)
                            ->from(RecentChangesWidget::class),
                        Widget::make('queue', 'admin/widgets/queue')
                            ->titled('Queue')
                            ->withIcon('hourglass-split')
                            ->spanning(4)
                            ->reserving(3)
                            ->shaped(Shape::Rows)
                            ->refreshEvery(60)
                            ->requires(AccessAdmin::class)
                            ->from(QueueWidget::class),
                    ),
            );
    }
}
