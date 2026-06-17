<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Providers\ServiceProvider;
use Hydra\Session\Contracts\SessionInterface;
use Hydra\Session\Contracts\SessionLifecycleInterface;
use Hydra\Session\Stores\ArraySessionStore;

/**
 * Test counterpart to {@see \Hydra\Session\SessionServiceProvider}: binds the
 * in-memory {@see ArraySessionStore} behind both session contracts, as one
 * shared instance.
 *
 * Integration tests drive the real pipeline in-process, so the production
 * NativeSessionStore would call session_start() and emit header warnings under
 * PHPUnit's failOnWarning. Swapping in the array backend sidesteps that — and is
 * itself proof that the SessionInterface seam is genuinely backend-agnostic.
 */
final class ArraySessionServiceProvider extends ServiceProvider
{
    public function register(ContainerInterface $container): void
    {
        $store = new ArraySessionStore;
        $container->instance(SessionInterface::class, $store);
        $container->instance(SessionLifecycleInterface::class, $store);
    }
}
