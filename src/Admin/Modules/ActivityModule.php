<?php

declare(strict_types=1);

namespace App\Admin\Modules;

use App\Admin\Sources\ActivitySource;
use App\Authorization\AccessAdmin;
use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Screens\ShowScreen;
use Hydra\Admin\Surface;

/**
 * Activity module
 *
 * The request log every visitor writes to. The two heaviest columns — agent and
 * referer — are declared but kept off the table: they belong to the show screen,
 * not to a row that has to stay readable.
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
            ->group('Administration')
            ->ability(AccessAdmin::class)
            ->source(ActivitySource::class)
            ->perPage(25)
            ->defaultSort('id', 'desc')
            ->fields(
                Field::id()->labelled('ID')->sortable(),
                Field::datetime('created_at')->labelled('Created at')->sortable(),
                Field::text('username')->labelled('User')->sortable()->searchable()->emptyAs('guest'),
                Field::select('method', self::METHODS)->sortable()->filterable(),
                Field::text('path')->labelled('URI')->sortable()->searchable()
                    ->decorate(static fn(mixed $value, array $row): string => ($row['query'] ?? '') === ''
                        ? (string) $value
                        : $value . '?' . $row['query']),
                Field::select('status', self::STATUSES)->sortable()->filterable(),
                Field::text('duration_ms')->labelled('Time')->sortable()
                    ->format(static fn(mixed $value): string => $value . ' ms'),
                Field::text('ip')->labelled('IP')->sortable()->searchable(),
                Field::text('user_agent')->labelled('Agent')->hiddenOn(Surface::List),
                Field::text('referer')->hiddenOn(Surface::List),
            )
            ->screens(ShowScreen::make()->title('Request'));
    }
}
