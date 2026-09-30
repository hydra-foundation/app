<?php

declare(strict_types=1);

namespace App\Admin\Sources;

use Hydra\Admin\Sources\TableSource;
use Hydra\Database\Contracts\ConnectionInterface;

/**
 * Mail module data contract: sent_mail, read-only. Rows are written by
 * {@see \App\Listeners\RecordSentMailListener} and nothing else.
 */
final class SentMailSource extends TableSource
{
    public function __construct(ConnectionInterface $db)
    {
        parent::__construct(
            $db,
            table: 'sent_mail',
            columns: ['id', 'sent_at', 'transport', 'from_address', 'to_addresses', 'cc_addresses', 'bcc_addresses', 'subject', 'text_body', 'html_body'],
            sortable: ['id', 'sent_at', 'transport', 'to_addresses', 'subject'],
            searchable: ['to_addresses', 'subject'],
        );
    }
}
