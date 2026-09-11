<?php

declare(strict_types=1);

namespace App\Repositories;

use Hydra\Database\Contracts\ConnectionInterface;
use App\Entities\Role;
use App\Entities\User;
use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\UserProviderInterface;

/**
 * User repository
 *
 * Database queries
 */
final class UserRepository implements UserProviderInterface
{
    private const COLUMNS = 'id, username, password_hash, role, created_at';

    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * Get user by id
     */
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
     * Insert a user and return its new id
     */
    public function create(string $username, string $passwordHash, Role $role = Role::DEFAULT): int
    {
        $this->db->execute(
            'INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)',
            [$username, $passwordHash, $role->value],
        );

        return (int) $this->db->lastInsertId();
    }
}
