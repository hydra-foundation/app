<?php

declare(strict_types=1);

namespace App\Admin\Modules;

use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Screens\DashboardScreen;

final class SystemHealthModule implements ModuleInterface
{
    public function define(): Definition
    {

        return Definition::make('system-health')
            ->title('System Health')
            ->group('Overview')
            ->icon('grid-1x2')
            ->screens(
                DashboardScreen::make()
                    ->named('overview')
                    ->widgets()
            );
    }
}
