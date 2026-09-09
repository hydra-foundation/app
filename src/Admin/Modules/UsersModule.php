<?php

declare(strict_types=1);

namespace App\Admin\Modules;

use App\Admin\Sources\UserSource;
use App\Authorization\AccessAdmin;
use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Surface;

/**
 * Users module
 */
final class UsersModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('users')
            ->title('Users')
            ->ability(AccessAdmin::class)
            ->source(UserSource::class)
            ->perPage(15)
            ->defaultSort('id', 'desc')
            ->fields(
                Field::id()->sortable()->hiddenOn(Surface::Form),
                Field::text('username')->sortable()->searchable(),
                Field::select('role', ['user' => 'User', 'admin' => 'Admin'])->sortable()->filterable(),
                Field::datetime('created_at')->sortable()->hiddenOn(Surface::Form),
            );
    }
}
