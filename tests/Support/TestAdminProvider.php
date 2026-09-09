<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Providers\AppServiceProvider;
use Hydra\Admin\AdminServiceProvider;
use Hydra\Auth\AuthenticateMiddleware;

/**
 * The admin backend, configured for the integration harnesses exactly as
 * Bootstrap configures it. The harnesses hand-roll their provider stack, so this
 * keeps the module list, prefix and middleware from drifting across them — the
 * same reason {@see TestHttpProvider} exists.
 */
final class TestAdminProvider
{
    public static function make(): AdminServiceProvider
    {
        return new AdminServiceProvider(
            modules: AppServiceProvider::MODULES,
            prefix: '/admin',
            middleware: [AuthenticateMiddleware::class],
        );
    }
}
