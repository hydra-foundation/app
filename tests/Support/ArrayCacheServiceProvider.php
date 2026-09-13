<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Hydra\Cache\ArrayStore;
use Hydra\Cache\Contracts\StoreInterface;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Providers\ServiceProvider;

/**
 * Test counterpart to {@see \Hydra\Cache\CacheServiceProvider}: binds the
 * in-memory store rather than connecting to Redis. The harnesses need a real
 * store because the rate limiter counts into one on every request, and a
 * per-process counter is exactly right here, where the process is the whole
 * application. Each test builds its own container, so each starts with an
 * empty budget.
 */
final class ArrayCacheServiceProvider extends ServiceProvider
{
    public function register(ContainerInterface $container): void
    {
        $container->instance(StoreInterface::class, new ArrayStore);
    }
}
