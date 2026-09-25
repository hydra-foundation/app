<?php

declare(strict_types=1);

namespace App\Admin\Modules;

use App\Admin\Presenters\AccountPresenter;
use App\Admin\Presenters\ApiTokensPresenter;
use App\Admin\Presenters\AppearancePresenter;
use App\Admin\Presenters\GeneralSettingsPresenter;
use App\Admin\Presenters\RegionalPresenter;
use App\Admin\Presenters\SecurityPresenter;
use App\Controllers\ApiTokenSettingsController;
use App\Controllers\SettingsController;
use App\Controllers\TwoFactorSettingsController;
use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Screens\PageScreen;

/**
 * A category is a screen, not a tab pane: /admin/settings/appearance is a place
 * you can link someone to and step back out of, and the strip across the top of
 * the module is ordinary navigation rather than script.
 */
final class SettingsModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('settings')
            ->title('Settings')
            ->icon('sliders')
            ->group('Application')
            ->screens(
                PageScreen::make('general', 'admin/settings/general')
                    ->title('Settings')
                    ->presentedBy(GeneralSettingsPresenter::class),
                PageScreen::make('account', 'admin/settings/account')
                    ->at('account')
                    ->title('Account')
                    ->presentedBy(AccountPresenter::class)
                    ->submittedTo([SettingsController::class, 'saveAccount']),
                PageScreen::make('security', 'admin/settings/security')
                    ->at('security')
                    ->title('Security')
                    ->presentedBy(SecurityPresenter::class)
                    ->submittedTo([TwoFactorSettingsController::class, 'save']),
                PageScreen::make('tokens', 'admin/settings/tokens')
                    ->at('tokens')
                    ->title('API tokens')
                    ->presentedBy(ApiTokensPresenter::class)
                    ->submittedTo([ApiTokenSettingsController::class, 'save']),
                PageScreen::make('appearance', 'admin/settings/appearance')
                    ->at('appearance')
                    ->title('Appearance')
                    ->presentedBy(AppearancePresenter::class)
                    ->submittedTo([SettingsController::class, 'saveAppearance']),
                PageScreen::make('regional', 'admin/settings/regional')
                    ->at('regional')
                    ->title('Regional')
                    ->presentedBy(RegionalPresenter::class)
                    ->submittedTo([SettingsController::class, 'saveRegional']),
            );
    }
}
