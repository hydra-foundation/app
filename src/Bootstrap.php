<?php

declare(strict_types=1);

namespace App;

use DI\Container as PhpDiContainer;
use Hydra\Auth\AuthServiceProvider;
use Hydra\Authorization\AuthorizationServiceProvider;
use Hydra\Core\Application;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Environment;
use Hydra\Session\SessionServiceProvider;
use App\Providers\AppServiceProvider;

/**
 * The single composition root, shared by every entrypoint.
 *
 * Both the HTTP front controller (public/index.php) and the console
 * (bin/console) need the same container with the same providers registered in
 * the same order — the difference is only what they do with it afterwards (run
 * the HTTP kernel, or dispatch a command). Building it in one place keeps the
 * provider sequence from drifting between the two.
 */
final class Bootstrap
{
    /**
     * Build the container and register every provider. Providers are registered
     * (their bindings declared) but NOT booted here: booting is a lifecycle the
     * caller owns — the HTTP path boots via Application::run(), and the console
     * only resolves bindings, which registration alone makes available.
     *
     * Auth registers after the session (its guard reads the started session)
     * and before the app, which supplies the user provider auth depends on.
     */
    public static function application(string $basePath): Application
    {
        $container = new Container(new PhpDiContainer);
        $container->instance(ContainerInterface::class, $container);
        $container->instance(Environment::class, new Environment($basePath));

        return (new Application($container))
            ->register(new SessionServiceProvider)
            ->register(new AuthServiceProvider)
            ->register(new AuthorizationServiceProvider)
            ->register(new AppServiceProvider);
    }
}
