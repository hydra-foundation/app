<?php

declare(strict_types=1);

namespace App\Admin\Modules;

use App\Admin\Actions\FlushCache;
use App\Admin\Widgets\DatabaseWidget;
use App\Admin\Widgets\MigrationsWidget;
use App\Admin\Widgets\SseHubWidget;
use App\Authorization\AccessAdmin;
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
            // The updates card says whether this install is behind a release,
            // which is a list of what it has not been patched against.
            ->ability(AccessAdmin::class)
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
                            ->from(CacheWidget::class)
                            ->action('flush', 'Flush', FlushCache::class, confirm: 'Empty the cache? This also lets everyone who is rate limited back in.'),
                        Widget::make('updates', 'admin/partials/widget-health')
                            ->titled('Updates')
                            ->withIcon('arrow-up-circle')
                            ->spanning(4)
                            ->reserving(3)
                            ->shaped(Shape::Lines)
                            ->from(UpdatesWidget::class),
                        // Beside Updates: both are what to look at after a deploy.
                        Widget::make('migrations', 'admin/partials/widget-health')
                            ->titled('Migrations')
                            ->withIcon('database-up')
                            ->spanning(4)
                            ->reserving(4)
                            ->shaped(Shape::Lines)
                            ->refreshEvery(self::REFRESH)
                            ->from(MigrationsWidget::class),
                        // Completes the row: whether pages update live.
                        Widget::make('sse', 'admin/partials/widget-health')
                            ->titled('Live updates')
                            ->withIcon('broadcast')
                            ->spanning(4)
                            ->reserving(3)
                            ->shaped(Shape::Lines)
                            ->refreshEvery(self::REFRESH)
                            ->from(SseHubWidget::class),
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
