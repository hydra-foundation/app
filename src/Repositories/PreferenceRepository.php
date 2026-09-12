<?php

declare(strict_types=1);

namespace App\Repositories;

use Hydra\Database\Contracts\ConnectionInterface;

/**
 * One person's settings, as name/value rows. A preference is not a column on
 * users because adding one should not need a migration, and because nothing
 * outside the settings screen ever selects on it.
 */
final class PreferenceRepository
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * Everything set for this user. Absent names are absent rather than null:
     * a caller reads through {@see self::get()}, which supplies the default.
     *
     * @return array<string, string>
     */
    public function all(int|string $userId): array
    {
        $rows = $this->db->select(
            'SELECT name, value FROM user_preferences WHERE user_id = ?',
            [$userId],
        );

        return array_column($rows, 'value', 'name');
    }

    public function get(int|string $userId, string $name, ?string $default = null): ?string
    {
        $row = $this->db->selectOne(
            'SELECT value FROM user_preferences WHERE user_id = ? AND name = ?',
            [$userId, $name],
        );

        return $row['value'] ?? $default;
    }

    /**
     * Written as a delete and an insert rather than an upsert: MariaDB spells
     * that ON DUPLICATE KEY and SQLite spells it ON CONFLICT, and the test
     * suites run against the second. One transaction, so a reader never sees
     * the gap between them.
     */
    public function set(int|string $userId, string $name, string $value): void
    {
        $this->db->transaction(function () use ($userId, $name, $value): void {
            $this->db->execute(
                'DELETE FROM user_preferences WHERE user_id = ? AND name = ?',
                [$userId, $name],
            );

            $this->db->execute(
                'INSERT INTO user_preferences (user_id, name, value) VALUES (?, ?, ?)',
                [$userId, $name, $value],
            );
        });
    }

    public function forget(int|string $userId, string $name): void
    {
        $this->db->execute(
            'DELETE FROM user_preferences WHERE user_id = ? AND name = ?',
            [$userId, $name],
        );
    }
}
