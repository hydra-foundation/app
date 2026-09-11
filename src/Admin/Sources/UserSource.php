<?php

declare(strict_types=1);

namespace App\Admin\Sources;

use App\Entities\Role;
use Hydra\Admin\Contracts\CreateSourceInterface;
use Hydra\Admin\Contracts\DeleteSourceInterface;
use Hydra\Admin\Contracts\RowSourceInterface;
use Hydra\Admin\Contracts\SourceInterface;
use Hydra\Admin\Contracts\UpdateSourceInterface;
use Hydra\Admin\Exceptions\WriteRejected;
use Hydra\Admin\Criteria;
use Hydra\Admin\Page;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Database\Contracts\ConnectionInterface;

/**
 * User source
 *
 * The admin's read and write side for the users table. Criteria arrives already
 * whitelisted against the module's fields; the ORDER BY column is checked again
 * here so this class is safe to call from anywhere, not only from a screen.
 *
 * Write policy lives here rather than in the form screen: a blank password means
 * "keep the current one" on an update, a name is unique against every row but
 * the one being written, and nobody deletes the account they are signed in as.
 * All three are facts about this table, not about forms.
 */
final class UserSource implements SourceInterface, RowSourceInterface, UpdateSourceInterface, CreateSourceInterface, DeleteSourceInterface
{
    private const COLUMNS = 'id, username, role, created_at';
    private const SORTABLE = ['id', 'username', 'role', 'created_at'];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly GuardInterface $guard,
    ) {}

    public function page(Criteria $criteria): Page
    {
        [$where, $params] = $this->conditions($criteria);
        $order = in_array($criteria->sort, self::SORTABLE, true) ? $criteria->sort : 'id';
        $total = $this->db->selectOne("SELECT COUNT(*) AS total FROM users {$where}", $params);

        return new Page(
            $this->db->select(
                'SELECT ' . self::COLUMNS . " FROM users {$where}"
                . " ORDER BY {$order} {$criteria->direction}"
                . " LIMIT {$criteria->perPage} OFFSET {$criteria->offset()}",
                $params,
            ),
            (int) ($total['total'] ?? 0),
            $criteria,
        );
    }

    public function find(string $id): ?array
    {
        return $this->db->selectOne(
            'SELECT ' . self::COLUMNS . ' FROM users WHERE id = ?',
            [(int) $id],
        );
    }

    public function create(array $data): string
    {
        $username = trim((string) ($data['username'] ?? ''));

        if ($this->isTaken($username)) {
            throw WriteRejected::on('username', 'That username is already taken.');
        }

        $this->db->execute(
            'INSERT INTO users (username, role, password_hash) VALUES (?, ?, ?)',
            [
                $username,
                $this->role($data),
                password_hash((string) ($data['password'] ?? ''), PASSWORD_DEFAULT),
            ],
        );

        return (string) $this->db->lastInsertId();
    }

    public function update(string $id, array $data): void
    {
        $username = trim((string) ($data['username'] ?? ''));

        if ($this->isTaken($username, $id)) {
            throw WriteRejected::on('username', 'That username is already taken.');
        }

        $columns = ['username = ?', 'role = ?'];
        $params = [$username, $this->role($data)];

        if (($data['password'] ?? '') !== '') {
            $columns[] = 'password_hash = ?';
            $params[] = password_hash((string) $data['password'], PASSWORD_DEFAULT);
        }

        $params[] = (int) $id;

        $this->db->execute(
            'UPDATE users SET ' . implode(', ', $columns) . ' WHERE id = ?',
            $params,
        );
    }

    public function delete(string $id): void
    {
        if ((string) $this->guard->id() === $id) {
            throw WriteRejected::on('id', 'You cannot delete the account you are signed in as.');
        }

        $this->db->execute('DELETE FROM users WHERE id = ?', [(int) $id]);
    }

    /**
     * The role to store: the default when the form omitted it, never a value
     * the select never offered. Rejected rather than coerced so a tampered
     * post fails loudly instead of quietly saving something else.
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
    private function isTaken(string $username, ?string $except = null): bool
    {
        $row = $except === null
            ? $this->db->selectOne('SELECT id FROM users WHERE username = ?', [$username])
            : $this->db->selectOne('SELECT id FROM users WHERE username = ? AND id <> ?', [$username, (int) $except]);

        return $row !== null;
    }

    /** @return array{0: string, 1: list<string>} */
    private function conditions(Criteria $criteria): array
    {
        $clauses = [];
        $params = [];

        if ($criteria->search !== null) {
            $clauses[] = 'username LIKE ?';
            $params[] = '%' . $criteria->search . '%';
        }

        if (isset($criteria->filters['role'])) {
            $clauses[] = 'role = ?';
            $params[] = $criteria->filters['role'];
        }

        return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $params];
    }
}
