<?php

declare(strict_types=1);

namespace App\Repositories;

use DateTimeImmutable;
use Hydra\Admin\Notifications\Notice;
use Hydra\Admin\Notifications\NotificationStoreInterface;
use Hydra\Admin\Notifications\StoredNotification;
use Hydra\Database\Contracts\ConnectionInterface;

/**
 * Each user's notices, in the notifications table. Every query names the user,
 * which is what keeps one user's bell out of another's: an id alone is never
 * enough to read or mark a row.
 */
final class NotificationRepository implements NotificationStoreInterface
{
    private const COLUMNS = 'id, kind, title, body, url, created_at, read_at';

    public function __construct(private readonly ConnectionInterface $db) {}

    public function add(int|string $userId, Notice $notice, DateTimeImmutable $at): string
    {
        $this->db->execute(
            'INSERT INTO notifications (user_id, kind, title, body, url, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, $notice->kind, $notice->title, $notice->body, $notice->url, $at->getTimestamp()],
        );

        return $this->db->lastInsertId();
    }

    public function latest(int|string $userId, int $limit): array
    {
        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT ' . max(0, $limit),
            [$userId],
        );

        return array_map(self::fromRow(...), $rows);
    }

    public function unreadCount(int|string $userId): int
    {
        return (int) ($this->db->selectOne(
            'SELECT COUNT(*) AS unread FROM notifications WHERE user_id = ? AND read_at IS NULL',
            [$userId],
        )['unread'] ?? 0);
    }

    public function markRead(int|string $userId, string $id, DateTimeImmutable $at): ?StoredNotification
    {
        if (!ctype_digit($id)) {
            return null;
        }

        // Only while unread, so a notice read twice keeps the first time.
        $this->db->execute(
            'UPDATE notifications SET read_at = ? WHERE id = ? AND user_id = ? AND read_at IS NULL',
            [$at->getTimestamp(), $id, $userId],
        );

        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . ' FROM notifications WHERE id = ? AND user_id = ?', [$id, $userId]);

        return $row === null ? null : self::fromRow($row);
    }

    public function markAllRead(int|string $userId, DateTimeImmutable $at): int
    {
        return $this->db->execute(
            'UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL',
            [$at->getTimestamp(), $userId],
        );
    }

    /** Deletes notices read before $before, and says how many. Unread ones are kept however old. */
    public function prune(DateTimeImmutable $before): int
    {
        return $this->db->execute('DELETE FROM notifications WHERE read_at IS NOT NULL AND read_at < ?', [$before->getTimestamp()]);
    }

    /** @param array<string, mixed> $row */
    private static function fromRow(array $row): StoredNotification
    {
        return new StoredNotification(
            (string) $row['id'],
            new Notice(
                (string) $row['title'],
                $row['body'] === null ? null : (string) $row['body'],
                $row['url'] === null ? null : (string) $row['url'],
                (string) $row['kind'],
            ),
            (new DateTimeImmutable)->setTimestamp((int) $row['created_at']),
            $row['read_at'] === null ? null : (new DateTimeImmutable)->setTimestamp((int) $row['read_at']),
        );
    }
}
