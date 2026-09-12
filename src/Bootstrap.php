<?php

declare(strict_types=1);

namespace App;

use App\Config\RouteConfig;
use App\Providers\AppServiceProvider;
use Hydra\Cache\CacheServiceProvider;
use Hydra\Core\Application;
use Hydra\Core\Environment;
use Hydra\Admin\AdminServiceProvider;
use Hydra\Auth\AuthenticateMiddleware;
use Hydra\Core\Security\SignerServiceProvider;
use Hydra\Kernel\HttpServiceProvider;
use Hydra\Kernel\Kernel;
use Hydra\Nyholm\NyholmServiceProvider;
use Hydra\PhpDi\Container;

/**
 * Configures application DI container and environment
 * Returns a Kernel application
 */
final class Bootstrap
{
    public static function application(string $basePath): Application
    {
        $container = Container::create();
        $environment = new Environment($basePath);
        $routeCacheEnabled = RouteConfig::fromEnvironment($environment)->cache;
        return Kernel::application($container, $environment)
            ->register(new NyholmServiceProvider)
            ->register(new SignerServiceProvider)
            ->register(new CacheServiceProvider)
            ->register(new HttpServiceProvider(
                controllers: AppServiceProvider::CONTROLLERS,
                middleware: AppServiceProvider::MIDDLEWARE,
                routeCacheEnabled: $routeCacheEnabled,
                routeCachePath: $basePath . '/bootstrap/cache/routes.php',
            ))
            ->register(new AppServiceProvider)
            ->register(new AdminServiceProvider(
                modules: AppServiceProvider::MODULES,
                prefix: '/admin',
                middleware: [AuthenticateMiddleware::class],
            ));
    }
}
