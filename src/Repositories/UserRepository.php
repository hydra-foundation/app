<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Entities\Role;
use App\Entities\User;
use Hydra\Auth\Contracts\UserProviderInterface;
use Hydra\Database\Contracts\ConnectionInterface;

/**
 * Every query against the users table, and the app's answer to the auth
 * package's user provider. Hands back a User rather than the bare contract the
 * interface promises, so code that knows about roles does not have to ask what
 * it just received.
 */
final class UserRepository implements UserProviderInterface
{
    private const COLUMNS = 'id, username, password_hash, role, created_at';

    public function __construct(private readonly ConnectionInterface $db) {}

    public function byIdentifier(int|string $id): ?User
    {
        $row = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM users WHERE id = ?',
            [(int) $id],
        );

        return $row === null ? null : User::fromRow($row);
    }

    public function byUsername(string $username): ?User
    {
        $row = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM users WHERE username = ?',
            [$username],
        );

        return $row === null ? null : User::fromRow($row);
    }

    /** Returns the id of the inserted row. */
    public function create(string $username, string $passwordHash, Role $role = Role::DEFAULT): int
    {
        $this->db->execute(
            'INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)',
            [$username, $passwordHash, $role->value],
        );

        return (int) $this->db->lastInsertId();
    }
}
