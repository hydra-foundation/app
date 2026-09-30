<?php

declare(strict_types=1);

namespace App\Admin\Actions;

use Hydra\Admin\Contracts\ModuleActionInterface;
use Hydra\Admin\Exceptions\WriteRejected;
use Hydra\Cache\CacheConfig;
use Hydra\Cache\Contracts\StoreInterface;
use Hydra\Throttle\Contracts\LockoutStoreInterface;
use Hydra\Throttle\Lockout;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * Empties the cache, from System Health's Cache card: every key under the
 * store's prefix, and nothing outside it.
 *
 * The rate limiter counts in this cache, so a flush lets everyone it was
 * refusing back in. That is said, and their lockout records are ended in the
 * same press: Rate limits must never list a client as locked out once the
 * counter locking them out is gone. They stay listed as Ended, for the fifteen
 * minutes Rate limits keeps an ended lockout.
 */
final class FlushCache implements ModuleActionInterface
{
    public function __construct(
        private readonly StoreInterface $store,
        private readonly CacheConfig $config,
        private readonly ClockInterface $clock,
        private readonly ?LockoutStoreInterface $lockouts = null,
    ) {}

    public function run(): string
    {
        if ($this->config->driver === CacheConfig::ARRAY) {
            throw WriteRejected::on('cache', 'CACHE_STORE=array holds nothing between workers; there is no shared cache to flush.');
        }

        try {
            $this->store->flush();
        } catch (Throwable $e) {
            // The store's own words: no prefix configured, or Redis not answering.
            throw WriteRejected::on('cache', $e->getMessage());
        }

        $released = $this->endLockouts();

        return match ($released) {
            0 => 'Cache flushed.',
            1 => 'Cache flushed. 1 rate-limited client was let back in.',
            default => "Cache flushed. {$released} rate-limited clients were let back in.",
        };
    }

    /** Each lockout still in force, recorded again as ending now. */
    private function endLockouts(): int
    {
        if ($this->lockouts === null) {
            return 0;
        }

        $now = $this->clock->now();
        $active = $this->lockouts->active($now);

        foreach ($active as $lockout) {
            $this->lockouts->record(new Lockout(
                $lockout->policy,
                $lockout->identity,
                $lockout->lockedAt,
                $now,
                $lockout->limit,
                $lockout->window,
            ));
        }

        return count($active);
    }
}
