<?php

declare(strict_types=1);

namespace App\Admin\Modules;

use App\Admin\Widgets\DatabaseWidget;
use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Screens\DashboardScreen;
use Hydra\Admin\Shape;
use Hydra\Admin\Widget;
use Hydra\Admin\Widgets\CacheWidget;
use Hydra\Admin\Widgets\ResourcesWidget;
use Hydra\Admin\Widgets\UpdatesWidget;
use Hydra\Admin\Widgets\UptimeWidget;

/**
 * What the application is running on, right now. No card here is periodic: a
 * service is either answering or it is not, and the period would have nothing
 * to narrow.
 */
final class SystemHealthModule implements ModuleInterface
{
    private const REFRESH = 60;

    public function define(): Definition
    {
        return Definition::make('system-health')
            ->title('System Health')
            ->group('Overview')
            ->icon('heart-pulse')
            ->screens(
                DashboardScreen::make()
                    ->named('overview')
                    ->widgets(
                        Widget::make('uptime', 'admin/partials/widget-health')
                            ->titled('Uptime')
                            ->withIcon('clock')
                            ->spanning(4)
                            ->reserving(5)
                            ->shaped(Shape::Lines)
                            ->refreshEvery(self::REFRESH)
                            ->from(UptimeWidget::class),
                        Widget::make('database', 'admin/partials/widget-health')
                            ->titled('Database')
                            ->withIcon('database')
                            ->spanning(4)
                            ->reserving(5)
                            ->shaped(Shape::Lines)
                            ->refreshEvery(self::REFRESH)
                            ->from(DatabaseWidget::class),
                        Widget::make('cache', 'admin/partials/widget-health')
                            ->titled('Cache')
                            ->withIcon('lightning-charge')
                            ->spanning(4)
                            ->reserving(5)
                            ->shaped(Shape::Lines)
                            ->refreshEvery(self::REFRESH)
                            ->from(CacheWidget::class),
                        Widget::make('updates', 'admin/partials/widget-health')
                            ->titled('Updates')
                            ->withIcon('arrow-up-circle')
                            ->spanning(4)
                            ->reserving(3)
                            ->shaped(Shape::Lines)
                            ->from(UpdatesWidget::class),
                        Widget::make('resources', 'admin/partials/widget-gauges')
                            ->titled('Disk and memory')
                            ->withIcon('hdd')
                            ->spanning(12)
                            ->reserving(ResourcesWidget::GAUGES)
                            ->shaped(Shape::Bars)
                            ->refreshEvery(self::REFRESH)
                            ->from(ResourcesWidget::class),
                    )
            );
    }
}
