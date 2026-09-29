<?php

declare(strict_types=1);

namespace App\Admin\Sources;

use App\Entities\User;
use DateTimeImmutable;
use Hydra\Admin\Contracts\DeleteSourceInterface;
use Hydra\Admin\Contracts\DescribesColumnsInterface;
use Hydra\Admin\Contracts\RowSourceInterface;
use Hydra\Admin\Contracts\SourceInterface;
use Hydra\Admin\Criteria;
use Hydra\Admin\Exceptions\WriteRejected;
use Hydra\Admin\Page;
use Hydra\Admin\SourceDescription;
use Hydra\Auth\Contracts\UserProviderInterface;
use Hydra\Throttle\Contracts\LockoutStoreInterface;
use Hydra\Throttle\Lockout;
use Hydra\Throttle\RateLimiter;
use Psr\Clock\ClockInterface;

/**
 * The clients a rate limit is refusing right now, and those it stopped
 * refusing in the last quarter of an hour, read through the lockout store and
 * never from the cache the counters live in. The ended ones stay because a
 * lockout under a one-minute limit can be over seconds after it began, before
 * anyone has looked. There are only ever a handful, so paging, search and sort
 * happen here rather than in SQL, with the ones still in force first.
 *
 * A row's id is base64url of "policy:identity". Never the identity as it is:
 * an address ending in ".com" at the end of a path is a file to nginx, and an
 * IPv6 address is full of colons. Letting a client back in goes through
 * RateLimiter::release(), which forgets the counter as well as the record.
 */
final class LockoutSource implements SourceInterface, RowSourceInterface, DescribesColumnsInterface, DeleteSourceInterface
{
    /**
     * What each of the app's limits is, in words, and whether its identity is
     * a user id to be shown as a username. A limit missing from here shows its
     * own name, so one added later is listed rather than lost.
     */
    private const POLICIES = [
        'global' => ['Requests from one address', false],
        'login' => ['Sign-in attempts from one address', false],
        'login-account' => ['Sign-in attempts on one account', false],
        'two-factor' => ['Two-factor codes', true],
        'account-password' => ['Password checks in Settings', true],
        'api-token-create' => ['API tokens created', true],
        'password-reset' => ['Reset requests from one address', false],
        'password-reset-address' => ['Reset links to one address', false],
        'verify-email' => ['Verification links', true],
    ];

    /** The limits about signing in, for the Sign-in link. */
    private const SIGN_IN = ['login', 'login-account', 'two-factor'];

    private const SORTS = ['what', 'locked_at', 'until'];

    /** How long an ended lockout stays listed, in seconds. */
    private const RECENT = 900;

    public function __construct(
        private readonly LockoutStoreInterface $lockouts,
        private readonly RateLimiter $limiter,
        private readonly UserProviderInterface $users,
        private readonly ClockInterface $clock,
    ) {}

    public function describe(): SourceDescription
    {
        return new SourceDescription(
            table: 'rate_limit_lockouts',
            columns: ['id', 'policy', 'identity', 'what', 'who', 'budget', 'state', 'locked_at', 'until', 'kind'],
            sortable: self::SORTS,
            searchable: ['who'],
            filterable: ['kind'],
            defaultSort: 'until',
        );
    }

    public function find(string $id): ?array
    {
        [$policy, $identity] = self::decode($id) ?? [null, null];
        $lockout = $policy === null ? null : $this->lockouts->find($policy, $identity);

        return $lockout === null || $lockout->until <= $this->since() ? null : $this->row($lockout);
    }

    public function page(Criteria $criteria): Page
    {
        $rows = array_map($this->row(...), $this->lockouts->active($this->since()));

        if (isset($criteria->filters['kind'])) {
            $rows = array_filter($rows, static fn (array $row): bool => $row['kind'] === $criteria->filters['kind']);
        }

        if ($criteria->search !== null) {
            $needle = mb_strtolower($criteria->search);
            $rows = array_filter($rows, static fn (array $row): bool => str_contains(mb_strtolower((string) $row['who']), $needle));
        }

        $sort = in_array($criteria->sort, self::SORTS, true) ? $criteria->sort : 'until';
        $direction = $criteria->direction === 'desc' ? -1 : 1;
        usort($rows, static fn (array $a, array $b): int => ($a['state'] === 'ended') <=> ($b['state'] === 'ended')
            ?: $direction * ([$a[$sort], $a['id']] <=> [$b[$sort], $b['id']]));

        return new Page(array_slice($rows, $criteria->offset(), $criteria->perPage), count($rows), $criteria);
    }

    /** Letting a client back in: its counter and its record, through the limiter. */
    public function delete(string $id): void
    {
        $row = $this->find($id);

        if ($row === null || $row['state'] === 'ended') {
            throw WriteRejected::on('id', 'That lockout has already ended.');
        }

        $this->limiter->release((string) $row['policy'], (string) $row['identity']);
    }

    /** @return array<string, mixed> */
    private function row(Lockout $lockout): array
    {
        [$label, $byUser] = self::POLICIES[$lockout->policy] ?? [$lockout->policy, false];

        return [
            'id' => rtrim(strtr(base64_encode("{$lockout->policy}:{$lockout->identity}"), '+/', '-_'), '='),
            'policy' => $lockout->policy,
            'identity' => $lockout->identity,
            'what' => $label,
            'who' => $byUser ? $this->username($lockout->identity) : $lockout->identity,
            'budget' => "{$lockout->limit} per " . self::span($lockout->window),
            'state' => $lockout->until > $this->clock->now() ? 'active' : 'ended',
            'locked_at' => $lockout->lockedAt->getTimestamp(),
            'until' => $lockout->until->getTimestamp(),
            'kind' => in_array($lockout->policy, self::SIGN_IN, true) ? 'sign-in' : 'other',
        ];
    }

    /** Lockouts that ended before this are no longer listed. */
    private function since(): DateTimeImmutable
    {
        return $this->clock->now()->modify('-' . self::RECENT . ' seconds');
    }

    /** The account's username, or the id as it was counted when it has gone. */
    private function username(string $id): string
    {
        $user = ctype_digit($id) ? $this->users->byIdentifier((int) $id) : null;

        return $user instanceof User ? $user->username : $id;
    }

    /** @return array{0: string, 1: string}|null */
    private static function decode(string $id): ?array
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/D', $id) !== 1) {
            return null;
        }

        $decoded = base64_decode(strtr($id, '-_', '+/'), true);

        if ($decoded === false || !str_contains($decoded, ':')) {
            return null;
        }

        return explode(':', $decoded, 2) + [1 => ''];
    }

    private static function span(int $seconds): string
    {
        foreach ([86400 => 'day', 3600 => 'hour', 60 => 'minute'] as $unit => $name) {
            if ($seconds % $unit === 0) {
                $n = intdiv($seconds, $unit);

                return $n === 1 ? $name : "{$n} {$name}s";
            }
        }

        return $seconds === 1 ? 'second' : "{$seconds} seconds";
    }
}
