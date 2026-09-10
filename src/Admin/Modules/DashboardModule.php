<?php

declare(strict_types=1);

namespace App\Admin\Modules;

use App\Admin\Presenters\DashboardPresenter;
use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Screens\PageScreen;

/**
 * Dashboard module
 *
 * No source, no fields — one page screen. Signing in is the whole gate, so it
 * declares no ability and every authenticated user sees it.
 */
final class DashboardModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('dashboard')
            ->title('Dashboard')
            ->group('Overview')
            ->screens(
                PageScreen::make('overview', 'admin/dashboard')
                    ->presentedBy(DashboardPresenter::class),
            );
    }
}
