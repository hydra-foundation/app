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

/**
 * Every recorded change to a row, read-only: the log of who changed what is not
 * itself a thing the admin may edit.
 */
final class AuditModule implements ModuleInterface
{
    private const MODULES = [
        'activity' => 'Activity',
        'audit' => 'Audit',
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
                Field::datetime('created_at')->labelled('Changed at')->sortable()->relative(),
                Field::text('username')->labelled('User')->sortable()->searchable()->emptyAs('system'),
                Field::select('module', self::MODULES)->labelled('Module')->sortable()->filterable(),
                Field::text('table_id')->labelled('Row')->sortable()->searchable(),
                Field::text('message')->searchable(),
                Field::text('old_value')->labelled('Before')->truncate(32),
                Field::text('new_value')->labelled('After')->truncate(32),
            )
            ->screens(
                ShowScreen::make()->title('Change'),
                ExportScreen::make(),
            );
    }
}
