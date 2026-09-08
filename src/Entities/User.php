<?php

declare(strict_types=1);

namespace App\Entities;

use Hydra\Auth\Contracts\AuthenticatableInterface;

/**
 * User entity
 *
 * Models an application user
 */
final readonly class User implements AuthenticatableInterface
{
    public function __construct(
        public int $id,
        public string $username,
        public string $passwordHash,
        public string $role,
        public string $createdAt,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            username: (string) $row['username'],
            passwordHash: (string) $row['password_hash'],
            // Absent column defaults to a plain user — keeps older rows/tables safe.
            role: (string) ($row['role'] ?? 'user'),
            createdAt: (string) $row['created_at'],
        );
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function getAuthIdentifier(): int|string
    {
        return $this->id;
    }

    public function getAuthPassword(): string
    {
        return $this->passwordHash;
    }
}
