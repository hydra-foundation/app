<?php

declare(strict_types=1);

namespace App\Admin\Modules;

use App\Admin\Presenters\AppearancePresenter;
use App\Admin\Presenters\GeneralSettingsPresenter;
use App\Controllers\SettingsController;
use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Screens\PageScreen;

/**
 * Settings module
 *
 * A category is a screen, not a tab pane: /admin/settings/appearance is a place
 * you can link someone to and step back out of, and the strip across the top of
 * the module is ordinary navigation rather than script.
 *
 * No ability is declared. Everything here is the signed-in person's own, so
 * gating it on the admin role would lock a standard user out of their own
 * preferences — the modules that read other people's rows are the ones that
 * require AccessAdmin.
 */
final class SettingsModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('settings')
            ->title('Settings')
            ->icon('sliders')
            ->group('You')
            ->screens(
                PageScreen::make('general', 'admin/settings/general')
                    ->title('Settings')
                    ->presentedBy(GeneralSettingsPresenter::class),

                PageScreen::make('appearance', 'admin/settings/appearance')
                    ->at('appearance')
                    ->title('Appearance')
                    ->presentedBy(AppearancePresenter::class)
                    ->submittedTo([SettingsController::class, 'saveAppearance']),
            );
    }
}
