<?php

declare(strict_types=1);

namespace App\Repositories;

use DateTimeImmutable;
use DateTimeZone;
use Hydra\Database\Contracts\ConnectionInterface;
use Hydra\Throttle\Contracts\LockoutStoreInterface;
use Hydra\Throttle\Lockout;

/**
 * Lockouts over rate_limit_lockouts, one row per client a policy is refusing.
 * Times are unix seconds, as in api_tokens, so the database's session time
 * zone never moves them. REPLACE is the upsert both MySQL and SQLite speak.
 */
final class LockoutRepository implements LockoutStoreInterface
{
    private const COLUMNS = 'policy, identity, locked_at, until, lock_limit, lock_window';

    public function __construct(private readonly ConnectionInterface $db) {}

    public function record(Lockout $lockout): void
    {
        $this->db->execute(
            'REPLACE INTO rate_limit_lockouts (' . self::COLUMNS . ') VALUES (?, ?, ?, ?, ?, ?)',
            [
                $lockout->policy,
                $lockout->identity,
                $lockout->lockedAt->getTimestamp(),
                $lockout->until->getTimestamp(),
                $lockout->limit,
                $lockout->window,
            ],
        );
    }

    public function active(DateTimeImmutable $now): array
    {
        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' FROM rate_limit_lockouts WHERE until > ? ORDER BY until ASC, policy ASC, identity ASC',
            [$now->getTimestamp()],
        );

        return array_map(self::lockout(...), $rows);
    }

    public function find(string $policy, string $identity): ?Lockout
    {
        $row = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM rate_limit_lockouts WHERE policy = ? AND identity = ?',
            [$policy, $identity],
        );

        return $row === null ? null : self::lockout($row);
    }

    public function forget(string $policy, string $identity): bool
    {
        return $this->db->execute('DELETE FROM rate_limit_lockouts WHERE policy = ? AND identity = ?', [$policy, $identity]) > 0;
    }

    public function prune(DateTimeImmutable $before): int
    {
        return $this->db->execute('DELETE FROM rate_limit_lockouts WHERE until < ?', [$before->getTimestamp()]);
    }

    /** @param array<string, mixed> $row */
    private static function lockout(array $row): Lockout
    {
        return new Lockout(
            (string) $row['policy'],
            (string) $row['identity'],
            self::at((int) $row['locked_at']),
            self::at((int) $row['until']),
            (int) $row['lock_limit'],
            (int) $row['lock_window'],
        );
    }

    private static function at(int $seconds): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $seconds))->setTimezone(new DateTimeZone(date_default_timezone_get()));
    }
}
