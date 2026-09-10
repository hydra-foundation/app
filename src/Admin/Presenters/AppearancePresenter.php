<?php

declare(strict_types=1);

namespace App\Admin\Presenters;

use App\View\ThemeResolver;
use App\View\Themes;
use Hydra\Admin\Contracts\PresenterInterface;

/**
 * Appearance presenter
 *
 * The palettes on offer and the one in force. Both come from the same places
 * the layout reads, so the picker cannot show a theme the page cannot apply or
 * disagree about which is selected.
 */
final class AppearancePresenter implements PresenterInterface
{
    public function __construct(
        private readonly Themes $themes,
        private readonly ThemeResolver $resolver,
    ) {}

    public function present(): array
    {
        return [
            'options' => $this->themes->options(),
            'selected' => $this->resolver->current(),
        ];
    }
}
