<?php

declare(strict_types=1);

namespace App\Admin\Modules;

use App\Admin\Sources\AuditSource;
use App\Authorization\AccessAdmin;
use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Screens\ExportScreen;
use Hydra\Admin\Screens\ShowScreen;
use Hydra\Admin\Surface;

/**
 * Every recorded change to a row, read-only: the log of who changed what is not
 * itself a thing the admin may edit.
 *
 * The before and after values are declared but kept off the table, the way
 * activity keeps agent and referer off it: they are the reason to open a row,
 * not something a row has to stay readable while carrying.
 *
 * TABLES is a written list rather than the distinct table_name values, because a
 * filter has to offer its options before the first row exists. It names the
 * tables the admin can write, so it grows when a writable module does.
 */
final class AuditModule implements ModuleInterface
{
    private const TABLES = [
        'activity' => 'Activity',
        'user_preferences' => 'User Preferences',
        'users' => 'Users',
    ];

    public function define(): Definition
    {
        return Definition::make('audit')
            ->title('Audit')
            ->group('Monitoring')
            ->icon('journal-text')
            ->ability(AccessAdmin::class)
            ->source(AuditSource::class)
            ->perPage(25)
            ->defaultSort('id', 'desc')
            ->fields(
                Field::id()->labelled('ID')->sortable(),
                Field::datetime('created_at')->labelled('Changed at')->sortable(),
                Field::text('username')->labelled('User')->sortable()->searchable()->emptyAs('system'),
                Field::select('table_name', self::TABLES)->labelled('Table')->sortable()->filterable(),
                Field::text('table_id')->labelled('Row')->sortable()->searchable(),
                Field::text('message')->searchable(),
                Field::text('old_value')->labelled('Before')->hiddenOn(Surface::List),
                Field::text('new_value')->labelled('After')->hiddenOn(Surface::List),
            )
            ->screens(
                ShowScreen::make()->title('Change'),
                ExportScreen::make(),
            );
    }
}
