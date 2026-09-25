<?php

declare(strict_types=1);

namespace App\Repositories;

use DateTimeImmutable;
use DateTimeZone;
use Hydra\Auth\ApiToken;
use Hydra\Auth\Contracts\ApiTokenStoreInterface;
use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Database\Contracts\ConnectionInterface;

/**
 * Personal API tokens over the api_tokens table. Times are unix seconds, as in
 * jobs, so the database's session time zone never moves them.
 */
final class ApiTokenRepository implements ApiTokenStoreInterface
{
    private const COLUMNS = 'id, user_id, name, token_hash, created_at, expires_at, last_used_at';

    public function __construct(private readonly ConnectionInterface $db) {}

    public function create(
        AuthenticatableInterface $user,
        string $name,
        string $hash,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $expiresAt,
    ): ApiToken {
        $this->db->execute(
            'INSERT INTO api_tokens (user_id, name, token_hash, created_at, expires_at) VALUES (?, ?, ?, ?, ?)',
            [$this->id($user), $name, $hash, $createdAt->getTimestamp(), $expiresAt?->getTimestamp()],
        );

        return new ApiToken((int) $this->db->lastInsertId(), $this->id($user), $name, $hash, $createdAt, $expiresAt);
    }

    public function findByHash(string $hash): ?ApiToken
    {
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . ' FROM api_tokens WHERE token_hash = ?', [$hash]);

        return $row === null ? null : self::token($row);
    }

    public function forUser(AuthenticatableInterface $user): array
    {
        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' FROM api_tokens WHERE user_id = ? ORDER BY created_at DESC, id DESC',
            [$this->id($user)],
        );

        return array_map(self::token(...), $rows);
    }

    public function touch(int|string $id, DateTimeImmutable $at): void
    {
        $this->db->execute('UPDATE api_tokens SET last_used_at = ? WHERE id = ?', [$at->getTimestamp(), (int) $id]);
    }

    public function revoke(AuthenticatableInterface $user, int|string $id): bool
    {
        return $this->db->execute('DELETE FROM api_tokens WHERE id = ? AND user_id = ?', [(int) $id, $this->id($user)]) > 0;
    }

    public function revokeAll(AuthenticatableInterface $user): int
    {
        return $this->db->execute('DELETE FROM api_tokens WHERE user_id = ?', [$this->id($user)]);
    }

    /** @param array<string, mixed> $row */
    private static function token(array $row): ApiToken
    {
        return new ApiToken(
            (int) $row['id'],
            (int) $row['user_id'],
            (string) $row['name'],
            (string) $row['token_hash'],
            self::at((int) $row['created_at']),
            self::time($row['expires_at']),
            self::time($row['last_used_at']),
        );
    }

    private static function time(mixed $seconds): ?DateTimeImmutable
    {
        return $seconds === null ? null : self::at((int) $seconds);
    }

    private static function at(int $seconds): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $seconds))->setTimezone(new DateTimeZone(date_default_timezone_get()));
    }

    private function id(AuthenticatableInterface $user): int
    {
        return (int) $user->getAuthIdentifier();
    }
}
