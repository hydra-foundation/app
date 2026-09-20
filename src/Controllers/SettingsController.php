<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Admin\Presenters\AppearancePresenter;
use App\Admin\Presenters\RegionalPresenter;
use App\Repositories\PreferenceRepository;
use App\View\Themes;
use App\View\Timezones;
use Hydra\Admin\Chrome;
use Hydra\Admin\ModuleRegistry;
use Hydra\Admin\Notice;
use Hydra\Admin\Renderer;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Http\Exceptions\NotFoundException;
use Hydra\Http\ParsedBody;
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
        private readonly Timezones $timezones,
        private readonly AppearancePresenter $presenter,
        private readonly RegionalPresenter $regionalPresenter,
    ) {}

    public function saveAppearance(Request $request): Response
    {
        $user = $this->guard->user() ?? throw new NotFoundException;
        $chosen = ParsedBody::fromRequest($request)->string(Themes::PREFERENCE);

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

        $this->preferences->set($user->getAuthIdentifier(), Themes::PREFERENCE, $chosen);

        return $this->screen($request, Notice::saved());
    }

    public function saveRegional(Request $request): Response
    {
        $user = $this->guard->user() ?? throw new NotFoundException;
        $chosen = ParsedBody::fromRequest($request)->string(Timezones::PREFERENCE);

        // Validated against PHP's own list for the same reason the theme is
        // validated against the directory: an identifier nothing recognises
        // throws where it is used, which is halfway down every screen that
        // renders a date.
        if (!$this->timezones->has($chosen)) {
            return $this->regional(
                $request,
                Notice::failure('That is not a timezone this installation knows.'),
                Status::UnprocessableEntity,
            );
        }

        $this->preferences->set($user->getAuthIdentifier(), Timezones::PREFERENCE, $chosen);

        return $this->regional($request, Notice::saved());
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

    private function regional(Request $request, Notice $notice, int|Status $status = Status::Ok): Response
    {
        $blueprint = $this->registry->find('settings') ?? throw new NotFoundException;

        return $this->renderer->screen(
            $request,
            $this->chrome->screen($blueprint, 'Regional', notice: $notice),
            'admin/settings/regional',
            $this->regionalPresenter->present(),
            status: $status,
        );
    }
}
