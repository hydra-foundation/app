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
use Hydra\Admin\RowId;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Auth\Contracts\HasherInterface;
use Hydra\Database\Contracts\ConnectionInterface;

/**
* Users module data contract
*/
final class UserSource implements SourceInterface, RowSourceInterface, UpdateSourceInterface, CreateSourceInterface, DeleteSourceInterface
{
    private const TABLE = 'users';
    private const COLUMNS = 'id, username, role, created_at';
    private const SORTABLE = ['id', 'username', 'role', 'created_at'];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly GuardInterface $guard,
        private readonly HasherInterface $hasher,
    ) {}

    public function find(string $id): ?array
    {
        $key = RowId::int($id);
        $sql = sprintf("SELECT %s 
            FROM %s 
            WHERE id=?", self::COLUMNS, self::TABLE);
        return $key === null ? null : $this->db->selectOne($sql, [$key]);
    }

    public function page(Criteria $criteria): Page
    {
        [$where, $params] = $this->conditions($criteria);
        $order = in_array($criteria->sort, self::SORTABLE, true) ? $criteria->sort : 'id';
        $sql = sprintf("SELECT COUNT(*) as total
            FROM %s
            WHERE %s", self::TABLE, $where);
        $total = $this->db->selectOne($sql, $params);
        $sql = sprintf(
            "SELECT %s
            FROM %s
            WHERE %s
            ORDER BY %s %s
            LIMIT %s OFFSET %s",
            self::COLUMNS,
            self::TABLE,
            $where,
            $order,
            $criteria->direction,
            $criteria->perPage,
            $criteria->offset()
        );
        return new Page(
            $this->db->select($sql, $params),
            (int) ($total['total'] ?? 0),
            $criteria,
        );
    }

    public function create(array $data): string
    {
        $username = trim((string) ($data['username'] ?? ''));

        if ($this->isTaken($username)) {
            throw WriteRejected::on('username', 'That username is already taken.');
        }

        $sql = sprintf('INSERT INTO %s (username, role, password_hash) 
            VALUES (?, ?, ?)', self::TABLE);
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
            WHERE id=?', self::TABLE, implode(', ', $columns));
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
            WHERE id=?', self::TABLE);
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
                WHERE username=?', self::TABLE)
            : sprintf('SELECT id 
                FROM %s 
                WHERE username=? 
                AND id<>?', self::TABLE);
        $params = $except === null ? [$username] : [$username, $except];

        return $this->db->selectOne($sql, $params) !== null;
    }

    /** @return array{0: string, 1: list<string>} */
    private function conditions(Criteria $criteria): array
    {
        $clauses = [];
        $params = [];

        if ($criteria->search !== null) {
            $clauses[] = Criteria::like('username');
            $params[] = $criteria->searchPattern();
        }

        if (isset($criteria->filters['role'])) {
            $clauses[] = 'role = ?';
            $params[] = $criteria->filters['role'];
        }

        return [$clauses === [] ? '1=1' : implode(' AND ', $clauses), $params];
    }
}
