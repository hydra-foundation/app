<?php

declare(strict_types=1);

namespace App\Admin\Sources;

use App\Auth\SignInWindow;
use Hydra\Admin\Contracts\DeleteSourceInterface;
use Hydra\Admin\Contracts\DescribesColumnsInterface;
use Hydra\Admin\Contracts\RowSourceInterface;
use Hydra\Admin\Contracts\SourceInterface;
use Hydra\Admin\Criteria;
use Hydra\Admin\Exceptions\WriteRejected;
use Hydra\Admin\Page;
use Hydra\Admin\SourceDescription;
use Hydra\Auth\Contracts\SignInStoreInterface;
use Hydra\Auth\SessionGuard;
use Hydra\Database\Contracts\ConnectionInterface;

/**
 * Every live sign-in, whoever's it is: sign_ins joined to its owner, read only
 * within the {@see SignInWindow}, since one idle past it signs nobody in. The
 * row id is the sign-in's own, which grants nothing without the session that
 * holds it; the PHP session id is never read. `you` marks the sign-in making
 * this request, from the guard.
 *
 * Revoking is deleting, through the sign-in store. The admin's own sign-in is
 * refused here as well as having no button: ending it from a list would sign
 * them out mid-click, and Sign out already says so plainly.
 */
final class SessionSource implements SourceInterface, RowSourceInterface, DescribesColumnsInterface, DeleteSourceInterface
{
    private const FROM = 'sign_ins s JOIN users u ON u.id = s.user_id';

    private const COLUMNS = 's.id, s.user_id, u.username AS owner, u.email AS owner_email, s.ip, s.user_agent, s.created_at, s.last_seen_at';

    /** What a list may be sorted by, and the SQL each key stands for. */
    private const SORTS = [
        'owner' => 'u.username',
        'created_at' => 's.created_at',
        'last_seen_at' => 's.last_seen_at',
    ];

    private const SEARCHED = ['u.username', 'u.email', 's.ip'];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly SignInWindow $window,
        private readonly SessionGuard $guard,
        private readonly SignInStoreInterface $signIns,
    ) {}

    public function describe(): SourceDescription
    {
        return new SourceDescription(
            table: 'sign_ins',
            columns: ['id', 'user_id', 'owner', 'owner_email', 'you', 'ip', 'user_agent', 'created_at', 'last_seen_at'],
            sortable: array_keys(self::SORTS),
            searchable: ['owner', 'owner_email', 'ip'],
            filterable: [],
            defaultSort: 'last_seen_at',
        );
    }

    public function find(string $id): ?array
    {
        $row = $this->db->selectOne(
            sprintf('SELECT %s FROM %s WHERE s.id = ? AND s.last_seen_at >= ?', self::COLUMNS, self::FROM),
            [$id, $this->since()],
        );

        return $row === null ? null : $this->marked($row);
    }

    public function page(Criteria $criteria): Page
    {
        [$where, $params] = $this->conditions($criteria);
        $order = self::SORTS[$criteria->sort ?? ''] ?? self::SORTS['last_seen_at'];

        $total = $this->db->selectOne(
            sprintf('SELECT COUNT(*) AS total FROM %s WHERE %s', self::FROM, $where),
            $params,
        );

        $rows = $this->db->select(
            sprintf(
                'SELECT %s FROM %s WHERE %s ORDER BY %s %s, s.id %s LIMIT %d OFFSET %d',
                self::COLUMNS,
                self::FROM,
                $where,
                $order,
                $criteria->direction,
                $criteria->direction,
                $criteria->perPage,
                $criteria->offset(),
            ),
            $params,
        );

        return new Page(array_map($this->marked(...), $rows), (int) ($total['total'] ?? 0), $criteria);
    }

    public function delete(string $id): void
    {
        if ($id === $this->guard->signIn()?->id) {
            throw WriteRejected::on('id', 'That is your own sign-in; use Sign out instead.');
        }

        if (!$this->signIns->revoke($id)) {
            throw WriteRejected::on('id', 'That sign-in has already ended.');
        }
    }

    /** @return array{0: string, 1: list<scalar|null>} */
    private function conditions(Criteria $criteria): array
    {
        $clauses = ['s.last_seen_at >= ?'];
        $params = [$this->since()];

        if ($criteria->search !== null) {
            $clauses[] = '(' . implode(' OR ', array_map(Criteria::like(...), self::SEARCHED)) . ')';

            foreach (self::SEARCHED as $_) {
                $params[] = $criteria->searchPattern();
            }
        }

        return [implode(' AND ', $clauses), $params];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function marked(array $row): array
    {
        $row['you'] = $row['id'] === $this->guard->signIn()?->id ? 'This is you' : '';

        return $row;
    }

    private function since(): int
    {
        return $this->window->since()->getTimestamp();
    }
}
