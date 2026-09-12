<?php

declare(strict_types=1);

namespace App\Entities;

use Hydra\Auth\Contracts\AuthenticatableInterface;

/**
 * An account, as the rest of the app sees it. Readonly and hydrated from a row,
 * so what reaches a template or an ability is a snapshot of the database rather
 * than a handle that can write back to it.
 */
final readonly class User implements AuthenticatableInterface
{
    public function __construct(
        public int $id,
        public string $username,
        public string $passwordHash,
        public Role $role,
        public string $createdAt,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            username: (string) $row['username'],
            passwordHash: (string) $row['password_hash'],
            role: Role::coerce($row['role'] ?? null),
            createdAt: (string) $row['created_at'],
        );
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function getAuthIdentifier(): int
    {
        return $this->id;
    }

    public function getAuthPassword(): string
    {
        return $this->passwordHash;
    }
}
