<?php

declare(strict_types=1);

namespace App\Entities;

use Hydra\Auth\Contracts\AuthenticatableInterface;

/**
 * A single user row, as a typed readonly object — the app's "noun" for the auth
 * package's identity mechanism.
 *
 * It implements {@see AuthenticatableInterface} so the guard can stash its id in
 * the session and verify a login against its stored hash, without auth ever
 * seeing the rest of the model (the username, timestamps). Like every Hydra
 * entity it hydrates from a raw row via {@see fromRow()}: loose result arrays
 * never leave the repository.
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

    /**
     * Whether this account holds the admin role. The role is the app's own data
     * (a users-table column), read here so an authorization ability can ask a
     * plain question without the gate package ever knowing what "admin" means.
     */
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
