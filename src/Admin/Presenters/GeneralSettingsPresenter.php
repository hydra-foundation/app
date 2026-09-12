<?php

declare(strict_types=1);

namespace App\Admin\Presenters;

use App\Repositories\PreferenceRepository;
use Hydra\Admin\Contracts\PresenterInterface;
use Hydra\Auth\Contracts\GuardInterface;

/**
 * The account this page is about, and what it has chosen so far. Nothing here
 * is editable yet: the categories that write live on their own screens.
 */
final class GeneralSettingsPresenter implements PresenterInterface
{
    public function __construct(
        private readonly GuardInterface $guard,
        private readonly PreferenceRepository $preferences,
    ) {}

    public function present(): array
    {
        $user = $this->guard->user();

        return [
            'user' => $user,
            'preferences' => $user === null ? [] : $this->preferences->all($user->getAuthIdentifier()),
        ];
    }
}
