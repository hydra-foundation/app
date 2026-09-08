<?php

declare(strict_types=1);

namespace App;

use App\Config\RouteConfig;
use App\Providers\AppServiceProvider;
use Hydra\Core\Application;
use Hydra\Core\Environment;
use Hydra\Core\Security\SignerServiceProvider;
use Hydra\Kernel\HttpServiceProvider;
use Hydra\Kernel\Kernel;
use Hydra\Nyholm\NyholmServiceProvider;
use Hydra\PhpDi\Container;

/**
 * Bootstrap
 *
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
            ->register(new HttpServiceProvider(
                controllers: AppServiceProvider::CONTROLLERS,
                middleware: AppServiceProvider::MIDDLEWARE,
                routeCacheEnabled: $routeCacheEnabled,
                routeCachePath: $basePath . '/bootstrap/cache/routes.php',
            ))
            ->register(new AppServiceProvider);
    }
}
