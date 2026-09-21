<?php

declare(strict_types=1);

namespace App\Admin\Modules;

use App\Admin\Sources\ActivitySource;
use App\Authorization\AccessAdmin;
use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Screens\ExportScreen;
use Hydra\Admin\Screens\ShowScreen;

/**
 * The request log every visitor writes to. The two heaviest columns (agent and
 * referer) are truncated rather than hidden: a prefix and a title attribute is
 * enough to tell a browser from a bot without opening the row, and the whole
 * value is still one hover away.
 */
final class ActivityModule implements ModuleInterface
{
    private const METHODS = [
        'GET' => 'GET',
        'POST' => 'POST',
        'PUT' => 'PUT',
        'PATCH' => 'PATCH',
        'DELETE' => 'DELETE',
    ];

    private const STATUSES = [
        '200' => '200 OK',
        '302' => '302 Redirect',
        '400' => '400 Bad request',
        '403' => '403 Forbidden',
        '404' => '404 Not found',
        '422' => '422 Invalid',
        '500' => '500 Error',
    ];

    public function define(): Definition
    {
        return Definition::make('activity')
            ->title('Activity')
            ->group('Monitoring')
            ->icon('activity')
            ->ability(AccessAdmin::class)
            ->source(ActivitySource::class)
            ->perPage(25)
            ->defaultSort('id', 'desc')
            ->fields(
                Field::id()->labelled('ID')->sortable(),
                Field::datetime('created_at')->labelled('Created at')->sortable()->relative(),
                Field::text('username')->labelled('User')->sortable()->searchable()->emptyAs('guest'),
                Field::select('method', self::METHODS)->sortable()->filterable(),
                Field::text('path')->labelled('URI')->sortable()->searchable()
                    ->decorate(static fn (mixed $value, array $row): string => ($row['query'] ?? '') === ''
                        ? (string) $value
                        : $value . '?' . $row['query']),
                Field::select('status', self::STATUSES)->sortable()->filterable(),
                Field::number('duration_ms')->labelled('Time')->sortable()
                    ->grouped()->suffix(' ms'),
                Field::text('ip')->labelled('IP')->sortable()->searchable(),
                Field::text('user_agent')->labelled('Agent')->truncate(32),
                Field::text('referer')->truncate(32),
            )
            ->screens(
                ShowScreen::make()->title('Request'),
                ExportScreen::make(),
            );
    }
}
