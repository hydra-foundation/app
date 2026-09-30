<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Admin\Actions\FlushCache;
use DateTimeImmutable;
use Hydra\Admin\Exceptions\WriteRejected;
use Hydra\Cache\ArrayStore;
use Hydra\Cache\CacheConfig;
use Hydra\Cache\Contracts\StoreInterface;
use Hydra\Core\Testing\FrozenClock;
use Hydra\Throttle\Lockout;
use Hydra\Throttle\Testing\ArrayLockoutStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The rate limiter counts in the cache, so emptying it lets everyone it was
 * refusing back in. The flush says so, and ends their lockout records, so Rate
 * limits never lists a lockout nothing enforces any more.
 */
#[CoversClass(FlushCache::class)]
final class FlushCacheTest extends TestCase
{
    private const NOW = '2026-09-30 12:00:00';

    private ArrayStore $store;
    private ArrayLockoutStore $lockouts;

    protected function setUp(): void
    {
        $this->store = new ArrayStore;
        $this->lockouts = new ArrayLockoutStore;
    }

    public function test_it_empties_the_cache(): void
    {
        $this->store->put('update-check', 'stale');

        $this->assertSame('Cache flushed.', $this->action()->run());
        $this->assertNull($this->store->get('update-check'));
    }

    public function test_it_ends_every_active_lockout_and_says_how_many(): void
    {
        $this->lockouts->record($this->lockout('login', '203.0.113.7', '+5 minutes'));
        $this->lockouts->record($this->lockout('login-account', 'ada', '+9 minutes'));

        $said = $this->action()->run();

        $this->assertSame('Cache flushed. 2 rate-limited clients were let back in.', $said);
        $this->assertSame([], $this->lockouts->active(new DateTimeImmutable(self::NOW)));
        $ended = $this->lockouts->find('login', '203.0.113.7');
        $this->assertNotNull($ended);
        $this->assertSame(strtotime(self::NOW), $ended->until->getTimestamp(), 'ended now, so Rate limits lists it as Ended');
        $this->assertSame(strtotime(self::NOW . ' -1 minute'), $ended->lockedAt->getTimestamp(), 'the rest of the record is kept');
    }

    public function test_one_client_is_said_in_the_singular(): void
    {
        $this->lockouts->record($this->lockout('login', '203.0.113.7', '+5 minutes'));

        $this->assertSame('Cache flushed. 1 rate-limited client was let back in.', $this->action()->run());
    }

    public function test_a_lockout_that_already_ended_is_left_alone(): void
    {
        $this->lockouts->record($this->lockout('login', '203.0.113.7', '-2 minutes'));

        $this->assertSame('Cache flushed.', $this->action()->run());
        $this->assertSame(
            strtotime(self::NOW . ' -2 minutes'),
            $this->lockouts->find('login', '203.0.113.7')?->until->getTimestamp(),
        );
    }

    public function test_an_app_with_no_lockout_store_still_flushes(): void
    {
        $this->store->put('k', 'v');

        $said = (new FlushCache($this->store, new CacheConfig(CacheConfig::REDIS, prefix: 'app:'), new FrozenClock(self::NOW)))->run();

        $this->assertSame('Cache flushed.', $said);
        $this->assertNull($this->store->get('k'));
    }

    public function test_a_per_worker_store_is_refused(): void
    {
        $this->store->put('k', 'v');

        try {
            (new FlushCache($this->store, new CacheConfig(CacheConfig::ARRAY), new FrozenClock(self::NOW), $this->lockouts))->run();
            $this->fail('Expected a refusal.');
        } catch (WriteRejected $rejected) {
            $this->assertSame('CACHE_STORE=array holds nothing between workers; there is no shared cache to flush.', $rejected->summary());
        }

        $this->assertSame('v', $this->store->get('k'));
    }

    public function test_a_flush_the_store_refuses_says_why_and_ends_no_lockouts(): void
    {
        $this->lockouts->record($this->lockout('login', '203.0.113.7', '+5 minutes'));
        $refusing = new class extends ArrayStoreDouble {
            public function flush(): void
            {
                throw new RuntimeException('Refusing to flush a RedisStore with no prefix.');
            }
        };

        try {
            (new FlushCache($refusing, new CacheConfig(CacheConfig::REDIS), new FrozenClock(self::NOW), $this->lockouts))->run();
            $this->fail('Expected a refusal.');
        } catch (WriteRejected $rejected) {
            $this->assertSame('Refusing to flush a RedisStore with no prefix.', $rejected->summary());
        }

        $this->assertCount(1, $this->lockouts->active(new DateTimeImmutable(self::NOW)), 'the counters are still there, so the lockout still holds');
    }

    private function action(): FlushCache
    {
        return new FlushCache($this->store, new CacheConfig(CacheConfig::REDIS, prefix: 'app:'), new FrozenClock(self::NOW), $this->lockouts);
    }

    private function lockout(string $policy, string $identity, string $until): Lockout
    {
        return new Lockout(
            $policy,
            $identity,
            new DateTimeImmutable(self::NOW . ' -1 minute'),
            new DateTimeImmutable(self::NOW . ' ' . $until),
            5,
            600,
        );
    }
}

/** Every StoreInterface method, for a double that overrides one. */
abstract class ArrayStoreDouble implements StoreInterface
{
    public function get(string $key): mixed
    {
        return null;
    }

    public function put(string $key, mixed $value, int $ttl = 0): void {}

    public function forget(string $key): void {}

    public function increment(string $key, int $by = 1, int $ttl = 0): int
    {
        return $by;
    }

    public function ttl(string $key): int
    {
        return 0;
    }

    public function flush(): void {}
}
