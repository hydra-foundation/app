<?php

declare(strict_types=1);

namespace App\Admin\Modules;

use App\Admin\Sources\LockoutSource;
use App\Authorization\AccessAdmin;
use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Link;
use Hydra\Admin\Screens\DeleteScreen;
use Hydra\Admin\Screens\ShowScreen;
use Hydra\Admin\Surface;

/**
 * Who a rate limit is refusing right now, how long for, and a way to let them
 * back in: the answer to "it says too many attempts" that used to be a wait.
 * Its own entry rather than a tab of Access, since a lockout is not a way into
 * an account.
 */
final class RateLimitsModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('rate-limits')
            ->title('Rate limits')
            ->group('Administration')
            ->icon('speedometer2')
            ->ability(AccessAdmin::class)
            ->source(LockoutSource::class)
            ->perPage(25)
            ->defaultSort('until', 'asc')
            ->gone('That lockout has already ended.')
            ->links(
                Link::make('All'),
                Link::make('Sign-in')->where('kind', 'sign-in'),
                Link::make('Everything else')->where('kind', 'other'),
            )
            ->fields(
                Field::id()->onlyOn(Surface::Show)->labelled('Lockout'),
                Field::text('what')->labelled('What')->sortable(),
                Field::text('who')->labelled('Who')->searchable(),
                Field::text('budget')->labelled('Limit'),
                Field::datetime('locked_at')->labelled('Locked')->sortable()->relative(),
                Field::datetime('until')->labelled('Ends')->sortable()->relative(),
            )
            ->screens(
                ShowScreen::make()->title('Lockout'),
                DeleteScreen::make()
                    ->labelled('Let back in')
                    ->confirm('Let this client back in? Its count starts again from zero.'),
            );
    }
}
