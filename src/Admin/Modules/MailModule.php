<?php

declare(strict_types=1);

namespace App\Admin\Modules;

use App\Admin\Actions\SendTestEmail;
use App\Admin\Sources\SentMailSource;
use App\Authorization\AccessAdmin;
use Hydra\Admin\Contracts\ModuleInterface;
use Hydra\Admin\Definition;
use Hydra\Admin\Field;
use Hydra\Admin\Screens\ActionScreen;
use Hydra\Admin\Screens\ShowScreen;
use Hydra\Admin\Surface;
use Hydra\View\HtmlView;

/**
 * Mail that went out and what it said, read-only, and a button that says
 * whether the transport works. A send that failed is a failed job, with its
 * retry, and is not listed here a second time.
 *
 * Bodies are shown as text, HTML included: a stored message is whatever the
 * app put in it, and rendering it would hand the admin page to that markup.
 */
final class MailModule implements ModuleInterface
{
    public function define(): Definition
    {
        return Definition::make('mail')
            ->title('Mail')
            ->group('Administration')
            ->icon('envelope')
            ->ability(AccessAdmin::class)
            ->source(SentMailSource::class)
            ->perPage(25)
            ->defaultSort('id', 'desc')
            ->gone('That message is no longer in the log.')
            ->fields(
                Field::id()->labelled('ID')->onlyOn(Surface::Show),
                Field::text('from_address')->labelled('From')->onlyOn(Surface::Show),
                Field::text('to_addresses')->labelled('To')->sortable()->searchable()->truncate(48, Surface::List),
                Field::text('cc_addresses')->labelled('Cc')->onlyOn(Surface::Show)->emptyAs('None'),
                Field::text('bcc_addresses')->labelled('Bcc')->onlyOn(Surface::Show)->emptyAs('None'),
                Field::text('subject')->sortable()->searchable()->truncate(64, Surface::List)->emptyAs('(no subject)'),
                Field::text('transport')->sortable(),
                Field::datetime('sent_at')->labelled('Sent')->sortable()->relative(),
                Field::text('text_body')->labelled('Text')->onlyOn(Surface::Show)->emptyAs('None')->format(self::preformatted(...)),
                Field::text('html_body')->labelled('HTML')->onlyOn(Surface::Show)->emptyAs('None')->format(self::preformatted(...)),
            )
            ->screens(
                ShowScreen::make()->title('Message'),
                ActionScreen::module('test')
                    ->labelled('Send a test email')
                    ->confirm('Send a test email to your own address now?')
                    ->runs(SendTestEmail::class),
            );
    }

    /** A body, with the line breaks that make it readable, and never as markup. */
    private static function preformatted(mixed $value): HtmlView
    {
        return new HtmlView('<pre class="mb-0 small">' . htmlspecialchars((string) $value, ENT_QUOTES) . '</pre>');
    }
}
