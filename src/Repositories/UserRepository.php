<?php

declare(strict_types=1);

namespace App\Repositories;

use Hydra\Database\Contracts\ConnectionInterface;
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
     * Every user, newest first
     */
    public function all(): array
    {
        $rows = $this->db->select('SELECT ' . self::COLUMNS . ' FROM users ORDER BY id DESC');

        return array_map(User::fromRow(...), $rows);
    }

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
    public function create(string $username, string $passwordHash, string $role = 'user'): int
    {
        $this->db->execute(
            'INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)',
            [$username, $passwordHash, $role],
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * Update a user's username and role
     */
    public function update(int $id, string $username, string $role): int
    {
        return $this->db->execute(
            'UPDATE users SET username = ?, role = ? WHERE id = ?',
            [$username, $role, $id],
        );
    }

    /** Delete a user */
    public function delete(int $id): int
    {
        return $this->db->execute('DELETE FROM users WHERE id = ?', [$id]);
    }
}
