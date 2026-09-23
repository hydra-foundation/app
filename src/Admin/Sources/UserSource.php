<?php

declare(strict_types=1);

namespace App\Admin\Sources;

use App\Entities\Role;
use App\Repositories\UserRepository;
use Hydra\Admin\Contracts\CreateSourceInterface;
use Hydra\Admin\Contracts\DeleteSourceInterface;
use Hydra\Admin\Contracts\UpdateSourceInterface;
use Hydra\Admin\Exceptions\WriteRejected;
use Hydra\Admin\RowId;
use Hydra\Admin\Sources\TableSource;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Auth\Contracts\HasherInterface;
use Hydra\Database\Contracts\ConnectionInterface;

/**
* Users module data contract. The read side is the declaration below; the write
* side is written out, because what a blank password means, which names collide
* and who may not be deleted are facts about this table rather than boilerplate.
*/
final class UserSource extends TableSource implements UpdateSourceInterface, CreateSourceInterface, DeleteSourceInterface
{
    public function __construct(
        ConnectionInterface $db,
        private readonly GuardInterface $guard,
        private readonly HasherInterface $hasher,
        private readonly UserRepository $users,
    ) {
        parent::__construct(
            $db,
            table: 'users',
            columns: ['id', 'username', 'email', 'role', 'created_at'],
            sortable: ['id', 'username', 'email', 'role', 'created_at'],
            searchable: ['username', 'email'],
            filterable: ['role'],
        );
    }

    public function create(array $data): string
    {
        $username = trim((string) ($data['username'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));

        if ($this->isTaken('username', $username)) {
            throw WriteRejected::on('username', 'That username is already taken.');
        }

        if ($this->isTaken('email', $email)) {
            throw WriteRejected::on('email', 'That email address is already in use.');
        }

        $sql = sprintf('INSERT INTO %s (username, email, role, password_hash)
            VALUES (?, ?, ?, ?)', $this->table);
        $this->db->execute(
            $sql,
            [
                $username,
                $email,
                $this->role($data),
                $this->hasher->hash((string) ($data['password'] ?? '')),
            ],
        );

        return (string) $this->db->lastInsertId();
    }

    public function update(string $id, array $data): void
    {
        $key = RowId::int($id) ?? throw WriteRejected::on('id', 'No user has that id.');
        $username = trim((string) ($data['username'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));

        if ($this->isTaken('username', $username, $key)) {
            throw WriteRejected::on('username', 'That username is already taken.');
        }

        if ($this->isTaken('email', $email, $key)) {
            throw WriteRejected::on('email', 'That email address is already in use.');
        }

        // Ahead of the email assignment: MariaDB reads an already-assigned
        // column's new value in later assignments, SQLite the old one.
        $columns = [
            'email_verified_at = CASE WHEN email = ? THEN email_verified_at ELSE NULL END',
            'email = ?',
            'username = ?',
            'role = ?',
        ];
        $params = [$email, $email, $username, $this->role($data)];

        if (($data['password'] ?? '') !== '') {
            $columns[] = 'password_hash = ?';
            $params[] = $this->hasher->hash((string) $data['password']);
        }

        $params[] = $key;

        $sql = sprintf('UPDATE %s
            SET %s
            WHERE id=?', $this->table, implode(', ', $columns));
        $this->db->execute($sql, $params);

        // A new password ends every session the account has, the one making
        // this edit included when it is the admin's own row.
        if (($data['password'] ?? '') !== '' && (string) $this->guard->id() === (string) $key) {
            $self = $this->users->byIdentifier($key);

            if ($self !== null) {
                $this->guard->refresh($self);
            }
        }
    }

    public function delete(string $id): void
    {
        $key = RowId::int($id) ?? throw WriteRejected::on('id', 'No user has that id.');

        if ((string) $this->guard->id() === $id) {
            throw WriteRejected::on('id', 'You cannot delete the account you are signed in as.');
        }

        $sql = sprintf('DELETE
            FROM %s
            WHERE id=?', $this->table);
        $this->db->execute($sql, [$key]);
    }

    /**
     * The role to store: the default when the form omitted it, never a value
     * the select never offered. Rejected rather than coerced so a tampered
     * post fails loudly instead of quietly saving something else.
     *
     * @param array<string, mixed> $data
     */
    private function role(array $data): string
    {
        $role = trim((string) ($data['role'] ?? ''));

        if ($role === '') {
            return Role::DEFAULT->value;
        }

        return (Role::tryFrom($role) ?? throw WriteRejected::on('role', 'Choose a role from the list.'))->value;
    }

    /**
     * Whether the value is in use, ignoring the row that already holds it.
     *
     * @param 'username'|'email' $column
     */
    private function isTaken(string $column, string $value, ?int $except = null): bool
    {
        $sql = $except === null
            ? sprintf('SELECT id
                FROM %s
                WHERE %s=?', $this->table, $column)
            : sprintf('SELECT id
                FROM %s
                WHERE %s=?
                AND id<>?', $this->table, $column);
        $params = $except === null ? [$value] : [$value, $except];

        return $this->db->selectOne($sql, $params) !== null;
    }
}
