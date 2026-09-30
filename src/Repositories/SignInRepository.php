<?php

declare(strict_types=1);

namespace App\Repositories;

use DateTimeImmutable;
use DateTimeZone;
use Hydra\Admin\Live\ModuleChanges;
use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\SignInStoreInterface;
use Hydra\Auth\SignIn;
use Hydra\Database\Contracts\ConnectionInterface;

/**
 * Sign-ins over the sign_ins table, one row per browser signed in. Deleting a
 * row is revoking that sign-in. Times are unix seconds, as in api_tokens, so
 * the database's session time zone never moves them.
 */
final class SignInRepository implements SignInStoreInterface
{
    private const COLUMNS = 'id, user_id, created_at, last_seen_at, ip, user_agent';

    /**
     * @param ModuleChanges $changes tells open Sessions lists that a sign-in
     *        began or ended. Never on a touch: every request makes one, and a
     *        list that refetched on each would refetch without end. No id
     *        travels: a sign-in's id is what a session holds to name it.
     */
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ModuleChanges $changes = new ModuleChanges,
    ) {}

    public function create(string $id, AuthenticatableInterface $user, DateTimeImmutable $at): SignIn
    {
        $this->db->execute(
            'INSERT INTO sign_ins (id, user_id, created_at, last_seen_at) VALUES (?, ?, ?, ?)',
            [$id, $this->userId($user), $at->getTimestamp(), $at->getTimestamp()],
        );
        $this->changes->publish('sessions');

        return new SignIn($id, $this->userId($user), self::at($at->getTimestamp()), self::at($at->getTimestamp()));
    }

    public function find(string $id): ?SignIn
    {
        $row = $this->db->selectOne('SELECT ' . self::COLUMNS . ' FROM sign_ins WHERE id = ?', [$id]);

        return $row === null ? null : self::signIn($row);
    }

    public function forUser(AuthenticatableInterface $user): array
    {
        $rows = $this->db->select(
            'SELECT ' . self::COLUMNS . ' FROM sign_ins WHERE user_id = ? ORDER BY last_seen_at DESC, created_at DESC',
            [$this->userId($user)],
        );

        return array_map(self::signIn(...), $rows);
    }

    public function touch(string $id, DateTimeImmutable $at, ?string $ip, ?string $userAgent): void
    {
        $this->db->execute(
            'UPDATE sign_ins SET last_seen_at = ?, ip = ?, user_agent = ? WHERE id = ?',
            [$at->getTimestamp(), $ip, $userAgent, $id],
        );
    }

    public function revoke(string $id): bool
    {
        $revoked = $this->db->execute('DELETE FROM sign_ins WHERE id = ?', [$id]) > 0;

        if ($revoked) {
            $this->changes->publish('sessions');
        }

        return $revoked;
    }

    public function revokeAll(AuthenticatableInterface $user, ?string $except = null): int
    {
        $revoked = $except === null
            ? $this->db->execute('DELETE FROM sign_ins WHERE user_id = ?', [$this->userId($user)])
            : $this->db->execute('DELETE FROM sign_ins WHERE user_id = ? AND id <> ?', [$this->userId($user), $except]);

        if ($revoked > 0) {
            $this->changes->publish('sessions');
        }

        return $revoked;
    }

    /** Not published: what it deletes is already outside the window the list shows. */
    public function prune(DateTimeImmutable $before): int
    {
        return $this->db->execute('DELETE FROM sign_ins WHERE last_seen_at < ?', [$before->getTimestamp()]);
    }

    /** @param array<string, mixed> $row */
    private static function signIn(array $row): SignIn
    {
        return new SignIn(
            (string) $row['id'],
            (int) $row['user_id'],
            self::at((int) $row['created_at']),
            self::at((int) $row['last_seen_at']),
            $row['ip'] === null ? null : (string) $row['ip'],
            $row['user_agent'] === null ? null : (string) $row['user_agent'],
        );
    }

    private static function at(int $seconds): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $seconds))->setTimezone(new DateTimeZone(date_default_timezone_get()));
    }

    private function userId(AuthenticatableInterface $user): int
    {
        return (int) $user->getAuthIdentifier();
    }
}
