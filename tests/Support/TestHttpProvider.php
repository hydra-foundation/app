<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Providers\AppServiceProvider;
use Hydra\Kernel\HttpServiceProvider;

/**
 * The kernel's HTTP plumbing, configured for the integration harnesses the way
 * production configures it — the app's real controller and middleware lists —
 * but with the route cache off (tests scan live) and a throwaway cache path.
 *
 * The harnesses hand-roll their provider stack (to swap in the array session and
 * an in-memory database), so they can't call Bootstrap; this keeps the one
 * HttpServiceProvider line from being copied — and drifting — across all of them.
 */
final class TestHttpProvider
{
    public static function make(): HttpServiceProvider
    {
        return new HttpServiceProvider(
            controllers: AppServiceProvider::CONTROLLERS,
            middleware: AppServiceProvider::MIDDLEWARE,
            routeCacheEnabled: false,
            routeCachePath: '/dev/null',
        );
    }
}
