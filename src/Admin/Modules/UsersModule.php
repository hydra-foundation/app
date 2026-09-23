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
use Hydra\Admin\Link;
use Hydra\Admin\Screens\DeleteScreen;
use Hydra\Admin\Screens\ExportScreen;
use Hydra\Admin\Screens\FormScreen;
use Hydra\Admin\Screens\ShowScreen;
use Hydra\Validation\Rules\Email;
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
            ->links(...$this->roles())
            ->fields(
                Field::id()->labelled('ID')->sortable(),
                Field::text('username')->sortable()->searchable(),
                Field::text('email')->sortable()->searchable(),
                Field::select('role', Role::options())->sortable(),
                Field::datetime('created_at')->labelled("Created")->sortable()->relative(),
            )
            ->screens(
                ShowScreen::make()->title('User'),
                FormScreen::create()->title('New user')->inputs(
                    Input::text('username')->required('Enter a username.')
                        ->rules(new MinLength(3), new MaxLength(64)),
                    Input::email('email')->required('Enter an email address.')
                        ->rules(new Email, new MaxLength(255)),
                    Input::select('role', Role::options()),
                    Input::password('password')->required('Set a password.')
                        ->rules(new MinLength(8)),
                ),
                FormScreen::edit()->title('Edit user')->inputs(
                    Input::text('username')->required('Enter a username.')
                        ->rules(new MinLength(3), new MaxLength(64)),
                    Input::email('email')->required('Enter an email address.')
                        ->rules(new Email, new MaxLength(255)),
                    Input::select('role', Role::options()),
                    Input::password('password')
                        ->rules(new MinLength(8))
                        ->help('Leave blank to keep the current password.'),
                ),
                DeleteScreen::make()->confirm('Delete this user? This cannot be undone.'),
                ExportScreen::make(),
            );
    }

    /**
     * A link per role, above the table, in place of a toolbar select over the
     * same column. Built from the enum rather than written out, so adding a
     * case stays the only edit — the same bargain Role::options() already makes
     * with the select and the console.
     *
     * @return list<Link>
     */
    private function roles(): array
    {
        $links = [Link::make('All')];

        foreach (Role::options() as $value => $label) {
            $links[] = Link::make($label)->where('role', (string) $value);
        }

        return $links;
    }
}
