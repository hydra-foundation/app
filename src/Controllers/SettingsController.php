<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Admin\Presenters\AppearancePresenter;
use App\Repositories\PreferenceRepository;
use App\View\ThemeResolver;
use App\View\Themes;
use Hydra\Admin\Chrome;
use Hydra\Admin\ModuleRegistry;
use Hydra\Admin\Notice;
use Hydra\Admin\Renderer;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Http\Exceptions\NotFoundException;
use Hydra\Http\Input as SubmittedInput;
use Hydra\Http\Status;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The write half of the settings module. Its screens are declared there and
 * routed by the scanner like any other, so this is an ordinary action that
 * happens to render back into the admin's own chrome.
 */
final class SettingsController
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly Chrome $chrome,
        private readonly Renderer $renderer,
        private readonly GuardInterface $guard,
        private readonly PreferenceRepository $preferences,
        private readonly Themes $themes,
        private readonly AppearancePresenter $presenter,
    ) {}

    public function saveAppearance(Request $request): Response
    {
        $user = $this->guard->user() ?? throw new NotFoundException;
        $chosen = SubmittedInput::fromRequest($request)->string(ThemeResolver::PREFERENCE);

        // Validated against the directory rather than a list: a theme that is
        // not on disk cannot be applied, so accepting its name would leave the
        // page unstyled and the setting stuck.
        if (!$this->themes->has($chosen)) {
            return $this->screen(
                $request,
                Notice::failure('That theme is not available.'),
                Status::UnprocessableEntity,
            );
        }

        $this->preferences->set($user->getAuthIdentifier(), ThemeResolver::PREFERENCE, $chosen);

        return $this->screen($request, Notice::saved());
    }

    private function screen(Request $request, Notice $notice, int|Status $status = Status::Ok): Response
    {
        $blueprint = $this->registry->find('settings') ?? throw new NotFoundException;

        return $this->renderer->screen(
            $request,
            $this->chrome->screen($blueprint, 'Appearance', notice: $notice),
            'admin/settings/appearance',
            $this->presenter->present(),
            status: $status,
        );
    }
}
