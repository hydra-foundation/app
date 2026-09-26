<?php

declare(strict_types=1);

namespace App\Admin\Modules;

use App\Admin\Sources\LogSource;
use App\Authorization\AccessAdmin;
use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Screens\ShowScreen;
use Hydra\Admin\Surface;
use Hydra\View\HtmlView;
use Psr\Log\LogLevel;

/** The end of the application's log file, read-only. */
final class LogsModule implements ModuleInterface
{
    private const LEVELS = [
        LogLevel::EMERGENCY => 'Emergency',
        LogLevel::ALERT => 'Alert',
        LogLevel::CRITICAL => 'Critical',
        LogLevel::ERROR => 'Error',
        LogLevel::WARNING => 'Warning',
        LogLevel::NOTICE => 'Notice',
        LogLevel::INFO => 'Info',
        LogLevel::DEBUG => 'Debug',
    ];

    public function define(): Definition
    {
        return Definition::make('logs')
            ->title('Logs')
            ->group('Monitoring')
            ->icon('journal-text')
            ->ability(AccessAdmin::class)
            ->source(LogSource::class)
            ->perPage(50)
            ->gone('That line is no longer in the part of the log this reads.')
            ->fields(
                Field::id()->labelled('Byte offset')->onlyOn(Surface::Show),
                Field::datetime('time')->relative(),
                Field::select('level', self::LEVELS)->filterable(),
                Field::text('message')->searchable()->truncate(120, Surface::List),
                Field::text('detail')->labelled('Exception')->onlyOn(Surface::Show)
                    ->emptyAs('none')->format(self::preformatted(...)),
                Field::text('context')->onlyOn(Surface::Show)
                    ->emptyAs('none')->format(self::preformatted(...)),
                Field::text('request_id')->labelled('Request ID')->filterable(),
            )
            ->screens(
                ShowScreen::make()->title('Log line'),
            );
    }

    /** A trace or the context, with the line breaks that make it readable. */
    private static function preformatted(mixed $value): HtmlView
    {
        return new HtmlView('<pre class="mb-0 small">' . htmlspecialchars((string) $value, ENT_QUOTES) . '</pre>');
    }
}
