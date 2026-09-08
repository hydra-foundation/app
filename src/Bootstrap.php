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
 * ---
 *
 * What does it do?
 * - Creates an application DI container
 * - Sets up the environment (ie, .env settings)
 *
 * Returns a Kernel application
 *   - Registers several services for the Kernel
 *     - Nyholm (PSR7/17 request and response)
 *     - Signer (Binds .env APP_KEY)
 *     - Http (Controllers and middleware)
 *     - App (Framework plumbing)
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
