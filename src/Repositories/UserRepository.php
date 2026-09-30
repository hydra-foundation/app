<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Entities\Role;
use App\Entities\User;
use Hydra\Auth\Contracts\EmailUserProviderInterface;
use Hydra\Auth\Contracts\UserProviderInterface;
use Hydra\Broadcast\Contracts\BroadcasterInterface;
use Hydra\Database\Contracts\ConnectionInterface;

/**
 * Every query against the users table, and the app's answer to the auth
 * package's user provider. Hands back a User rather than the bare contract the
 * interface promises, so code that knows about roles does not have to ask what
 * it just received.
 */
final class UserRepository implements UserProviderInterface, EmailUserProviderInterface
{
    private const COLUMNS = 'id, username, email, email_verified_at, password_hash, role, created_at, avatar, avatar_name';

    /**
     * @param BroadcasterInterface|null $broadcaster tells open Users lists a
     *        row changed. Every write through here publishes, so a new way to
     *        change a user cannot forget to; the admin's own writes go through
     *        UserSource and are published by the admin.
     */
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ?BroadcasterInterface $broadcaster = null,
    ) {}

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

    /** Case-insensitive under the column's collation, the same as the unique key. */
    public function byEmail(string $email): ?User
    {
        if ($email === '') {
            return null;
        }

        $row = $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM users WHERE email = ?',
            [$email],
        );

        return $row === null ? null : User::fromRow($row);
    }

    /** Returns the id of the inserted row. */
    public function create(string $username, string $email, string $passwordHash, Role $role = Role::DEFAULT): int
    {
        $this->db->execute(
            'INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)',
            [$username, $email, $passwordHash, $role->value],
        );

        $id = (int) $this->db->lastInsertId();
        $this->changed($id);

        return $id;
    }

    /** Spends every outstanding reset token for this user, since each is bound to the old hash. */
    public function updatePassword(int $id, string $passwordHash): void
    {
        $this->db->execute('UPDATE users SET password_hash = ? WHERE id = ?', [$passwordHash, $id]);
        $this->changed($id);
    }

    /**
     * A qualified storage key and the name it was uploaded under, or null to
     * clear both. The file itself is the caller's to store or delete.
     */
    public function updateAvatar(int $id, ?string $avatar, ?string $name = null): void
    {
        $this->db->execute(
            'UPDATE users SET avatar = ?, avatar_name = ? WHERE id = ?',
            [$avatar, $avatar === null ? null : $name, $id],
        );
        $this->changed($id);
    }

    /**
     * Only while the account still holds $from, so one link cannot apply twice.
     * The new address counts as verified: the link that got here was sent to it.
     */
    public function changeEmail(int $id, string $from, string $to): bool
    {
        return $this->changedIf($id, $this->db->execute(
            'UPDATE users SET email = ?, email_verified_at = CURRENT_TIMESTAMP WHERE id = ? AND email = ?',
            [$to, $id, $from],
        ) > 0);
    }

    /**
     * Only while the address is still the one the link was sent to, so an
     * address changed between opening the link and this write stays unverified.
     */
    public function markVerified(int $id, string $email): bool
    {
        return $this->changedIf($id, $this->db->execute(
            'UPDATE users SET email_verified_at = CURRENT_TIMESTAMP WHERE id = ? AND email = ? AND email_verified_at IS NULL',
            [$id, $email],
        ) > 0);
    }

    /** Open Users lists refetch. The id only: a broadcast never carries the row. */
    private function changed(int $id): void
    {
        $this->broadcaster?->publish('module.users', 'changed', ['id' => $id]);
    }

    private function changedIf(int $id, bool $happened): bool
    {
        if ($happened) {
            $this->changed($id);
        }

        return $happened;
    }
}
