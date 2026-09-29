<?php

declare(strict_types=1);

namespace App\Admin\Modules;

use App\Admin\Actions\RevokeOwnerTokens;
use App\Admin\Sources\AccessTokenSource;
use App\Authorization\AccessAdmin;
use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Link;
use Hydra\Admin\Screens\ActionScreen;
use Hydra\Admin\Screens\DeleteScreen;
use Hydra\Admin\Screens\ShowScreen;
use Hydra\Admin\Surface;

/**
 * Every API token across all users. Settings › API tokens is one person's own;
 * this is the list for when a token leaks and the admin holds the secret but
 * not the name of whoever it belongs to. Paste it into search to find it.
 */
final class AccessModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('access')
            ->title('Access')
            ->tabLabel('API tokens')
            ->group('Administration')
            ->icon('key')
            ->ability(AccessAdmin::class)
            ->source(AccessTokenSource::class)
            ->perPage(25)
            ->defaultSort('created_at', 'desc')
            ->gone('That token has been revoked.')
            ->links(
                Link::make('All'),
                Link::make('Active')->where('state', 'active'),
                Link::make('Expired')->where('state', 'expired'),
            )
            ->fields(
                Field::id()->labelled('ID')->sortable(),
                Field::text('owner')->sortable()->searchable(),
                Field::text('owner_email')->labelled('Email')->onlyOn(Surface::Show),
                Field::text('name')->sortable()->searchable(),
                Field::select('state', ['active' => 'Active', 'expired' => 'Expired']),
                Field::datetime('created_at')->labelled('Created')->sortable()->relative(),
                Field::datetime('last_used_at')->labelled('Last used')->sortable()->relative()->emptyAs('Never'),
                Field::datetime('expires_at')->labelled('Expires')->sortable()->relative()->emptyAs('Never'),
            )
            ->screens(
                ShowScreen::make()->title('API token'),
                DeleteScreen::make()
                    ->labelled('Revoke')
                    ->confirm('Revoke this token? Anything using it is refused from its next request.'),
                ActionScreen::row('revoke-owner')
                    ->labelled("Revoke all of the owner's tokens")
                    ->confirm("Revoke every API token this token's owner has?")
                    ->runs(RevokeOwnerTokens::class),
            );
    }
}
