<?php

declare(strict_types=1);

namespace App\Repositories;

use DateTimeImmutable;
use Hydra\Database\Contracts\ConnectionInterface;
use Hydra\Mail\Address;
use Hydra\Mail\Events\MessageSent;

/**
 * The write side of sent_mail, one row per message a transport accepted. The
 * admin reads it through {@see \App\Admin\Sources\SentMailSource}. Times are
 * unix seconds, as in api_tokens, so the database's session time zone never
 * moves them.
 *
 * Address lists are stored as the reader wants them, "Name <email>, …", since
 * nothing queries a single recipient but the search box, which matches text.
 * The bodies are stored whole: a preview that is not what was sent is no
 * preview. They are pruned instead (PruneSentMail).
 */
final class SentMailRepository
{
    private const COLUMNS = 'id, sent_at, transport, from_address, to_addresses, cc_addresses, bcc_addresses, subject, text_body, html_body';

    public function __construct(private readonly ConnectionInterface $db) {}

    public function record(MessageSent $sent, DateTimeImmutable $at): void
    {
        $message = $sent->message;
        $from = $message->getFrom();

        $this->db->execute(
            'INSERT INTO sent_mail (sent_at, transport, from_address, to_addresses, cc_addresses, bcc_addresses, subject, text_body, html_body)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $at->getTimestamp(),
                mb_substr($sent->transport, 0, 16),
                mb_substr($from === null ? '' : self::address($from), 0, 255),
                self::addresses($message->getTo()),
                self::addresses($message->getCc()),
                self::addresses($message->getBcc()),
                $message->getSubject(),
                $message->getText(),
                $message->getHtml(),
            ],
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT ' . self::COLUMNS . ' FROM sent_mail WHERE id = ?', [$id]);
    }

    /** @return int how many messages sent before $before were deleted */
    public function prune(DateTimeImmutable $before): int
    {
        return $this->db->execute('DELETE FROM sent_mail WHERE sent_at < ?', [$before->getTimestamp()]);
    }

    /** @param list<Address> $addresses */
    private static function addresses(array $addresses): string
    {
        return implode(', ', array_map(self::address(...), $addresses));
    }

    private static function address(Address $address): string
    {
        return $address->name === '' ? $address->email : "{$address->name} <{$address->email}>";
    }
}
