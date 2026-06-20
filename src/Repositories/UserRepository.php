<?php

declare(strict_types=1);

namespace App\Repositories;

use Hydra\Database\Contracts\ConnectionInterface;
use App\Entities\User;
use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\UserProviderInterface;

/**
 * The app's fulfilment of auth's one unbound contract: where users come from.
 *
 * It is the usual Hydra repository shape — a connection and the hand-written SQL
 * the feature needs, no base class — and it does lookups ONLY, never a password
 * check. Verifying the submitted password against
 * the returned user's hash lives entirely in the guard and the hasher, so all
 * credential handling stays in one audited place (the "dumb provider" rule auth
 * is built around). This class just turns an id or a username into a {@see User}.
 */
final class UserRepository implements UserProviderInterface
{
    private const COLUMNS = 'id, username, password_hash, role, created_at';

    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * Every user, newest first — the listing the admin area renders. Outside the
     * auth contract (which is lookups only); this is the app's own read for its
     * own page, the usual hand-written-SQL repository shape.
     *
     * @return list<User>
     */
    public function all(): array
    {
        $rows = $this->db->select('SELECT ' . self::COLUMNS . ' FROM users ORDER BY id DESC');

        return array_map(User::fromRow(...), $rows);
    }

    public function byIdentifier(int|string $id): ?AuthenticatableInterface
    {
        $row = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM users WHERE id = ?',
            [(int) $id],
        );

        return $row === null ? null : User::fromRow($row);
    }

    public function byUsername(string $username): ?AuthenticatableInterface
    {
        $row = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM users WHERE username = ?',
            [$username],
        );

        return $row === null ? null : User::fromRow($row);
    }

    /**
     * Insert a user and return its new id. The admin user-management slice's
     * own write — outside the auth contract. The hash is produced upstream by
     * NativeHasher and passed in already-digested; this method never hashes, so
     * credential production stays in one audited place (the "dumb provider" rule).
     */
    public function create(string $username, string $passwordHash, string $role = 'user'): int
    {
        $this->db->execute(
            'INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)',
            [$username, $passwordHash, $role],
        );

        return $this->db->lastInsertId();
    }

    /**
     * Update a user's username and role, returning the number of rows changed
     * (0 if no such id). The password digest is intentionally left out — a
     * credential change is a separate, audited flow, not part of an admin edit.
     */
    public function update(int $id, string $username, string $role): int
    {
        return $this->db->execute(
            'UPDATE users SET username = ?, role = ? WHERE id = ?',
            [$username, $role, $id],
        );
    }

    /** Delete a user, returning the number of rows removed (0 if no such id). */
    public function delete(int $id): int
    {
        return $this->db->execute('DELETE FROM users WHERE id = ?', [$id]);
    }
}
