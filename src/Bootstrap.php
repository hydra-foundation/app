<?php

declare(strict_types=1);

namespace App;

use App\Config\RouteConfig;
use App\Providers\AppServiceProvider;
use DI\Container as PhpDiContainer;
use Hydra\Core\Application;
use Hydra\Core\Environment;
use Hydra\Kernel\HttpServiceProvider;
use Hydra\Kernel\Kernel;

/**
 * The single composition root, shared by every entrypoint.
 *
 * Both the HTTP front controller (public/index.php) and the console
 * (bin/console) build the same container the same way — the difference is only
 * what they do with it afterwards (run the HTTP kernel, or dispatch a command).
 *
 * The framework wiring — the standard provider stack and the HTTP plumbing —
 * lives in {@see Kernel}/{@see HttpServiceProvider}, so it can't drift between
 * this skeleton and other Hydra consumers: a new framework package joins the
 * stack in the kernel, not here. This file now declares only the app's own
 * policy: which container to use, its route/middleware/controller choices, and
 * its own {@see AppServiceProvider}.
 */
final class Bootstrap
{
    /**
     * Build the container, compose the framework kernel with the app's policy,
     * and register the app provider. Providers are registered (their bindings
     * declared) but NOT booted here: booting is the caller's lifecycle — the HTTP
     * path boots via {@see Application::run()}, the console only resolves bindings.
     */
    public static function application(string $basePath): Application
    {
        $container = new Container(new PhpDiContainer);

        // Read once, here, whether to serve routes from the compiled cache — the
        // one route-cache decision the kernel's HTTP plumbing needs from the app.
        $routeCacheEnabled = RouteConfig::fromEnvironment(new Environment($basePath))->cache;

        return Kernel::application($container, $basePath)
            ->register(new HttpServiceProvider(
                controllers: AppServiceProvider::CONTROLLERS,
                middleware: AppServiceProvider::MIDDLEWARE,
                routeCacheEnabled: $routeCacheEnabled,
                routeCachePath: $basePath . '/bootstrap/cache/routes.php',
            ))
            ->register(new AppServiceProvider);
    }
}
