<?php

declare(strict_types=1);

namespace App\Admin\Sources;

use App\Entities\Role;
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
*
* password_hash is deliberately absent from the columns: it is written here and
* never read back into a screen.
*/
final class UserSource extends TableSource implements UpdateSourceInterface, CreateSourceInterface, DeleteSourceInterface
{
    public function __construct(
        ConnectionInterface $db,
        private readonly GuardInterface $guard,
        private readonly HasherInterface $hasher,
    ) {
        parent::__construct(
            $db,
            table: 'users',
            columns: ['id', 'username', 'role', 'created_at'],
            sortable: ['id', 'username', 'role', 'created_at'],
            searchable: ['username'],
            filterable: ['role'],
        );
    }

    public function create(array $data): string
    {
        $username = trim((string) ($data['username'] ?? ''));

        if ($this->isTaken($username)) {
            throw WriteRejected::on('username', 'That username is already taken.');
        }

        $sql = sprintf('INSERT INTO %s (username, role, password_hash)
            VALUES (?, ?, ?)', $this->table);
        $this->db->execute(
            $sql,
            [
                $username,
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

        if ($this->isTaken($username, $key)) {
            throw WriteRejected::on('username', 'That username is already taken.');
        }

        $columns = ['username = ?', 'role = ?'];
        $params = [$username, $this->role($data)];

        if (($data['password'] ?? '') !== '') {
            $columns[] = 'password_hash = ?';
            $params[] = $this->hasher->hash((string) $data['password']);
        }

        $params[] = $key;

        $sql = sprintf('UPDATE %s
            SET %s
            WHERE id=?', $this->table, implode(', ', $columns));
        $this->db->execute($sql, $params);
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

    /** Whether the name is in use, ignoring the row that already holds it. */
    private function isTaken(string $username, ?int $except = null): bool
    {
        $sql = $except === null
            ? sprintf('SELECT id
                FROM %s
                WHERE username=?', $this->table)
            : sprintf('SELECT id
                FROM %s
                WHERE username=?
                AND id<>?', $this->table);
        $params = $except === null ? [$username] : [$username, $except];

        return $this->db->selectOne($sql, $params) !== null;
    }
}
