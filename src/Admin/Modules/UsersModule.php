<?php

declare(strict_types=1);

namespace App\Admin\Modules;

use App\Admin\Sources\UserSource;
use App\Authorization\AccessAdmin;
use App\Entities\Role;
use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Input;
use Hydra\Admin\Screens\DeleteScreen;
use Hydra\Admin\Screens\FormScreen;
use Hydra\Admin\Screens\ShowScreen;
use Hydra\Validation\Rules\MaxLength;
use Hydra\Validation\Rules\MinLength;

/**
 * This is where you can add/edit users for the backend app.
 */
final class UsersModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('users')
            ->title('Users')
            ->group('Administration')
            ->icon('people')
            ->ability(AccessAdmin::class)
            ->source(UserSource::class)
            ->perPage(15)
            ->defaultSort('id', 'desc')
            ->fields(
                Field::id()->labelled('ID')->sortable(),
                Field::text('username')->sortable()->searchable(),
                Field::select('role', Role::options())->sortable()->filterable(),
                Field::datetime('created_at')->sortable(),
            )
            ->screens(
                ShowScreen::make()->title('User'),
                FormScreen::create()->title('New user')->inputs(
                    Input::text('username')->required('Enter a username.')
                        ->rules(new MinLength(3), new MaxLength(64)),
                    Input::select('role', Role::options()),
                    Input::password('password')->required('Set a password.')
                        ->rules(new MinLength(8)),
                ),
                FormScreen::edit()->title('Edit user')->inputs(
                    Input::text('username')->required('Enter a username.')
                        ->rules(new MinLength(3), new MaxLength(64)),
                    Input::select('role', Role::options()),
                    Input::password('password')
                        ->rules(new MinLength(8))
                        ->help('Leave blank to keep the current password.'),
                ),
                DeleteScreen::make()->confirm('Delete this user? This cannot be undone.'),
            );
    }
}
