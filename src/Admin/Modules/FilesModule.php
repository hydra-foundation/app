<?php

declare(strict_types=1);

namespace App\Admin\Modules;

use App\Authorization\AccessAdmin;
use Hydra\Admin\Widgets\Readable;
use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Files\DeleteOrphans;
use Hydra\Admin\Files\FileSource;
use Hydra\Admin\Screens\ActionScreen;
use Hydra\Admin\Screens\DeleteScreen;
use Hydra\Admin\Screens\ShowScreen;
use Hydra\Admin\Surface;
use Hydra\View\HtmlView;

/**
 * Every file the app has stored, on both disks: what it is, how much room it
 * takes, and what uses it. A file nothing uses is an orphan once it is a day
 * old, and those can be cleared out together. A file something still uses is
 * removed from the row that uses it, never from here.
 */
final class FilesModule implements ModuleInterface
{
    private const KINDS = [
        'image' => 'Image',
        'pdf' => 'PDF',
        'text' => 'Text',
        'archive' => 'Archive',
        'other' => 'Other',
    ];

    private const DISKS = [
        'private' => 'Private',
        'public' => 'Public',
    ];

    private const STATUSES = [
        FileSource::IN_USE => 'In use',
        FileSource::ORPHAN => 'Orphan',
        FileSource::NEW => 'New',
    ];

    public function define(): Definition
    {
        return Definition::make('files')
            ->title('Files')
            ->group('Administration')
            ->icon('folder2-open')
            ->ability(AccessAdmin::class)
            ->source(FileSource::class)
            ->perPage(25)
            ->defaultSort('modified_at', 'desc')
            ->gone('That file is no longer on either disk.')
            ->fields(
                Field::id()->labelled('ID')->onlyOn(Surface::Show),
                Field::image('preview')->labelled('')->nameFrom('name')->fallbackIcon('file-earmark'),
                Field::text('name')->sortable()->searchable(),
                Field::select('kind', self::KINDS)->labelled('Type')->filterable(),
                Field::number('size')->sortable()
                    ->format(static fn (mixed $value): string => (string) Readable::bytes((float) $value)),
                Field::select('disk', self::DISKS)->filterable(),
                Field::datetime('modified_at')->labelled('Modified')->sortable()->relative()->filterable(),
                Field::select('status', self::STATUSES)->filterable()
                    ->format(self::status(...), Surface::List),
                Field::file('download')->labelled('Download')->nameFrom('name')->onlyOn(Surface::Show),
                Field::text('key')->onlyOn(Surface::Show),
                Field::text('type')->labelled('MIME type')->onlyOn(Surface::Show),
                Field::text('used_by')->labelled('Used by')->onlyOn(Surface::Show)
                    ->emptyAs('nothing')->format(self::usedBy(...)),
            )
            ->screens(
                ShowScreen::make()->title('File'),
                DeleteScreen::make()->confirm('Delete this file? This cannot be undone.'),
                ActionScreen::module('delete-orphans')
                    ->labelled('Delete orphans')
                    ->confirm('Delete every orphaned file? This cannot be undone.')
                    ->runs(DeleteOrphans::class),
            );
    }

    /**
     * "In use (2)" when more than one row uses it.
     *
     * @param array<string, mixed> $row
     */
    private static function status(mixed $value, array $row): string
    {
        $label = self::STATUSES[(string) $value] ?? (string) $value;
        $uses = (int) ($row['uses'] ?? 0);

        return $uses > 1 ? "{$label} ({$uses})" : $label;
    }

    /** Each row that uses the file, linked to it where it has a screen. */
    private static function usedBy(mixed $value): string|HtmlView
    {
        if (!is_array($value) || $value === []) {
            return '';
        }

        $items = array_map(static function (mixed $use): string {
            $label = htmlspecialchars((string) (is_array($use) ? ($use['label'] ?? '') : ''), ENT_QUOTES);
            $url = is_array($use) && is_string($use['url'] ?? null) ? $use['url'] : null;

            return '<li>' . ($url === null ? $label : '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . $label . '</a>') . '</li>';
        }, $value);

        return new HtmlView('<ul class="list-unstyled mb-0">' . implode('', $items) . '</ul>');
    }
}
