<?php

declare(strict_types=1);

namespace App\Admin\Modules;

use App\Admin\Sources\SessionSource;
use App\Authorization\AccessAdmin;
use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Screens\DeleteScreen;
use Hydra\Admin\Screens\ShowScreen;
use Hydra\Admin\Surface;

/**
 * Every browser signed in, whoever's it is, beside the API tokens under
 * Access: both are ways into an account, and "someone else is in my account"
 * is a question about both.
 */
final class SessionsModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('sessions')
            ->title('Sessions')
            ->tabOf('access')
            ->ability(AccessAdmin::class)
            ->source(SessionSource::class)
            ->perPage(25)
            ->defaultSort('last_seen_at', 'desc')
            ->gone('That sign-in has ended.')
            ->fields(
                Field::id()->labelled('Sign-in')->onlyOn(Surface::Show),
                Field::text('owner')->sortable()->searchable(),
                Field::text('owner_email')->labelled('Email')->onlyOn(Surface::Show),
                Field::text('you')->labelled('')->emptyAs(''),
                Field::text('ip')->labelled('IP address')->searchable(),
                Field::text('user_agent')->labelled('Browser')->truncate(60, Surface::List),
                Field::datetime('created_at')->labelled('Signed in')->sortable()->relative(),
                Field::datetime('last_seen_at')->labelled('Last seen')->sortable()->relative(),
            )
            ->screens(
                ShowScreen::make()->title('Sign-in'),
                DeleteScreen::make()
                    ->labelled('Revoke')
                    ->confirm('Sign this browser out? It is sent to sign in on its next request.')
                    ->when(static fn (array $row): bool => $row['you'] === ''),
            );
    }
}
