<?php

declare(strict_types=1);

namespace App\Admin\Sources;

use Hydra\Admin\Contracts\DeleteSourceInterface;
use Hydra\Admin\Contracts\DescribesColumnsInterface;
use Hydra\Admin\Contracts\RowSourceInterface;
use Hydra\Admin\Contracts\SourceInterface;
use Hydra\Admin\Criteria;
use Hydra\Admin\Exceptions\WriteRejected;
use Hydra\Admin\Page;
use Hydra\Admin\SourceDescription;
use Hydra\Auth\ApiTokens;
use Hydra\Auth\Contracts\ApiTokenStoreInterface;
use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\UserProviderInterface;
use Hydra\Database\Contracts\ConnectionInterface;
use Psr\Clock\ClockInterface;

/**
 * Every API token, whoever owns it: the list an admin reaches for when a token
 * leaks and all they hold is the secret. Read with a join, which is why this
 * is written out rather than a TableSource, and written only through the token
 * store, which Settings revokes through too. The hash never leaves this class,
 * so it can't reach a cell, an export or the audit trail.
 *
 * `state` is derived against the app's clock, bound as a value, so the list
 * and ApiTokens::authenticate() agree on which tokens still work.
 */
final class AccessTokenSource implements SourceInterface, RowSourceInterface, DescribesColumnsInterface, DeleteSourceInterface
{
    private const FROM = 'api_tokens t JOIN users u ON u.id = t.user_id';

    private const STATE = "CASE WHEN t.expires_at IS NOT NULL AND t.expires_at <= ? THEN 'expired' ELSE 'active' END";

    /** What a list may be sorted by, and the SQL each key stands for. */
    private const SORTS = [
        'id' => 't.id',
        'owner' => 'u.username',
        'name' => 't.name',
        'created_at' => 't.created_at',
        'last_used_at' => 't.last_used_at',
        'expires_at' => 't.expires_at',
    ];

    /** Searched when the term is not a token; a token is found by its hash alone. */
    private const SEARCHED = ['u.username', 'u.email', 't.name'];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ClockInterface $clock,
        private readonly ApiTokenStoreInterface $tokens,
        private readonly UserProviderInterface $users,
    ) {}

    public function describe(): SourceDescription
    {
        return new SourceDescription(
            table: 'api_tokens',
            columns: ['id', 'user_id', 'owner', 'owner_email', 'name', 'state', 'created_at', 'last_used_at', 'expires_at'],
            sortable: array_keys(self::SORTS),
            searchable: ['owner', 'owner_email', 'name'],
            filterable: ['state', 'created_at'],
            defaultSort: 'created_at',
        );
    }

    public function find(string $id): ?array
    {
        if (!ctype_digit($id)) {
            return null;
        }

        return $this->db->selectOne(
            sprintf('SELECT %s FROM %s WHERE t.id = ?', $this->columns(), self::FROM),
            [$this->now(), (int) $id],
        );
    }

    public function page(Criteria $criteria): Page
    {
        [$where, $params] = $this->conditions($criteria);
        $order = self::SORTS[$criteria->sort ?? ''] ?? self::SORTS['created_at'];

        $total = $this->db->selectOne(
            sprintf('SELECT COUNT(*) AS total FROM %s WHERE %s', self::FROM, $where),
            $params,
        );

        return new Page(
            $this->db->select(
                sprintf(
                    'SELECT %s FROM %s WHERE %s ORDER BY %s %s, t.id %s LIMIT %d OFFSET %d',
                    $this->columns(),
                    self::FROM,
                    $where,
                    $order,
                    $criteria->direction,
                    $criteria->direction,
                    $criteria->perPage,
                    $criteria->offset(),
                ),
                [$this->now(), ...$params],
            ),
            (int) ($total['total'] ?? 0),
            $criteria,
        );
    }

    /** Revoking is deleting: the store is asked, as Settings asks it. */
    public function delete(string $id): void
    {
        if (!$this->tokens->revoke($this->owner($id), $id)) {
            throw self::gone();
        }
    }

    /**
     * Whose token this is, by the id of any one of theirs. A token revoked in
     * the meantime, or whose owner was deleted and took their tokens with them,
     * is refused the same way, since either way there is nothing to revoke.
     */
    public function owner(string $id): AuthenticatableInterface
    {
        $row = $this->find($id);
        $owner = $row === null ? null : $this->users->byIdentifier((int) $row['user_id']);

        return $owner ?? throw self::gone();
    }

    /** @return array{0: string, 1: list<scalar|null>} */
    private function conditions(Criteria $criteria): array
    {
        $clauses = [];
        $params = [];

        if ($criteria->search !== null) {
            $hash = ApiTokens::hashOf($criteria->search);

            if ($hash !== null) {
                $clauses[] = 't.token_hash = ?';
                $params[] = $hash;
            } else {
                $clauses[] = '(' . implode(' OR ', array_map(Criteria::like(...), self::SEARCHED)) . ')';

                foreach (self::SEARCHED as $_) {
                    $params[] = $criteria->searchPattern();
                }
            }
        }

        if (isset($criteria->filters['state'])) {
            $clauses[] = self::STATE . ' = ?';
            $params[] = $this->now();
            $params[] = $criteria->filters['state'];
        }

        // Unix seconds, as the token store writes them.
        if (isset($criteria->ranges['created_at'])) {
            [$clauses[], $bounds] = $criteria->ranges['created_at']->condition('t.created_at', 'U');
            $params = [...$params, ...$bounds];
        }

        return [$clauses === [] ? '1=1' : implode(' AND ', $clauses), $params];
    }

    /** Every column but the hash, which is the point of writing them out. */
    private function columns(): string
    {
        return 't.id, t.user_id, u.username AS owner, u.email AS owner_email, t.name, '
            . self::STATE . ' AS state, t.created_at, t.last_used_at, t.expires_at';
    }

    private static function gone(): WriteRejected
    {
        return WriteRejected::on('id', 'That token has already been revoked.');
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
