<?php

declare(strict_types=1);

namespace App\Entities;

use Hydra\Auth\Contracts\HasEmailInterface;

/**
 * An account, as the rest of the app sees it. Readonly and hydrated from a row,
 * so what reaches a template or an ability is a snapshot of the database rather
 * than a handle that can write back to it.
 */
final readonly class User implements HasEmailInterface
{
    public function __construct(
        public int $id,
        public string $username,
        public string $email,
        public string $passwordHash,
        public Role $role,
        public string $createdAt,
        public ?string $emailVerifiedAt = null,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            username: (string) $row['username'],
            email: (string) $row['email'],
            passwordHash: (string) $row['password_hash'],
            role: Role::coerce($row['role'] ?? null),
            createdAt: (string) $row['created_at'],
            emailVerifiedAt: isset($row['email_verified_at']) ? (string) $row['email_verified_at'] : null,
        );
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->emailVerifiedAt !== null;
    }

    public function getAuthIdentifier(): int
    {
        return $this->id;
    }

    public function getAuthPassword(): string
    {
        return $this->passwordHash;
    }

    public function getAuthEmail(): string
    {
        return $this->email;
    }
}
