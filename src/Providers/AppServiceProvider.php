<?php

declare(strict_types=1);

namespace App\Providers;

use App\Config\AppConfig;
use App\Config\DbConfig;
use App\Config\LogConfig;
use App\Config\RouteConfig;
use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\HomeController;
use App\Http\Middleware\RedirectUnauthenticatedMiddleware;
use App\Repositories\UserRepository;
use Hydra\Auth\Contracts\UserProviderInterface;
use Hydra\Auth\Events\Attempting;
use Hydra\Auth\Events\LoggedIn;
use Hydra\Auth\Events\LoggedOut;
use Hydra\Auth\Events\LoginFailed;
use Hydra\Auth\LogAuthEventsListener;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Environment;
use Hydra\Core\Providers\ServiceProvider;
use Hydra\Csrf\CsrfGuard;
use Hydra\Csrf\VerifyCsrfTokenMiddleware;
use Hydra\Database\Contracts\ConnectionInterface;
use Hydra\Database\MigrationRunner;
use Hydra\Database\PdoConnection;
use App\Http\NegotiatingErrorRenderer;
use Hydra\Event\ListenerProvider;
use Hydra\Http\Contracts\ErrorRendererInterface;
use Hydra\Http\ErrorHandlerMiddleware;
use Hydra\Http\PlainTextErrorRenderer;
use Hydra\Http\ForceHttpsMiddleware;
use Hydra\Http\ParseBodyMiddleware;
use Hydra\Http\RequestLoggingMiddleware;
use Hydra\Http\Responder;
use Hydra\Http\SecurityHeadersMiddleware;
use Hydra\Log\StreamLogger;
use Hydra\Session\StartSessionMiddleware;
use Hydra\View\Contracts\ViewInterface;
use Hydra\View\PhpView;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Application service provider
 *
 * DI container registration
 * The framework plumbing
 */
final class AppServiceProvider extends ServiceProvider
{
    /**
     * Controllers scanned for #[Route] attributes
     */
    public const CONTROLLERS = [
        HomeController::class,
        AuthController::class,
        AdminController::class,
    ];

    /**
     * The app's middleware stack, outermost first
     */
    public const MIDDLEWARE = [
        RequestLoggingMiddleware::class,
        SecurityHeadersMiddleware::class,
        ForceHttpsMiddleware::class,
        ErrorHandlerMiddleware::class,
        ParseBodyMiddleware::class,
        StartSessionMiddleware::class,
        RedirectUnauthenticatedMiddleware::class,
        VerifyCsrfTokenMiddleware::class,
    ];

    /**
     * Register application classes and interfaces
     */
    public function register(ContainerInterface $container): void
    {
        $container->singleton(AppConfig::class, function () use ($container) {
            return AppConfig::fromEnvironment($container->get(Environment::class));
        });

        $container->singleton(LogConfig::class, function () use ($container) {
            return LogConfig::fromEnvironment($container->get(Environment::class));
        });

        $container->singleton(DbConfig::class, function () use ($container) {
            return DbConfig::fromEnvironment($container->get(Environment::class));
        });

        $container->singleton(RouteConfig::class, function () use ($container) {
            return RouteConfig::fromEnvironment($container->get(Environment::class));
        });

        $container->singleton(PDO::class, function () use ($container) {
            $config = $container->get(DbConfig::class);
            return new PDO($config->dsn(), $config->username, $config->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        });

        $container->singleton(ConnectionInterface::class, function () use ($container) {
            return new PdoConnection($container->get(PDO::class));
        });

        $container->singleton(MigrationRunner::class, function () use ($container) {
            return new MigrationRunner(
                $container->get(PDO::class),
                dirname(__DIR__, 2) . '/database/migrations',
                $container->get(DbConfig::class)->driver,
            );
        });

        $container->singleton(UserProviderInterface::class, function () use ($container) {
            return new UserRepository($container->get(ConnectionInterface::class));
        });

        $container->singleton(LoggerInterface::class, function () use ($container) {
            $path = $container->get(LogConfig::class)->path;
            $stream = @fopen($path, 'a') ?: fopen('php://stderr', 'w');
            return new StreamLogger($stream);
        });

        $container->singleton(ViewInterface::class, function () use ($container) {
            return new PhpView(dirname(__DIR__, 2) . '/views', $container->get(CsrfGuard::class));
        });

        $container->singleton(ForceHttpsMiddleware::class, function () use ($container) {
            $config = $container->get(AppConfig::class);
            return new ForceHttpsMiddleware(
                $config->forceHttps,
                $container->get(Responder::class),
                $config->trustForwardedProto,
            );
        });

        $container->singleton(ErrorRendererInterface::class, function () use ($container) {
            return new NegotiatingErrorRenderer(
                $container->get(Responder::class),
                new PlainTextErrorRenderer($container->get(Responder::class)),
            );
        });

        $container->singleton(ErrorHandlerMiddleware::class, function () use ($container) {
            return new ErrorHandlerMiddleware(
                $container->get(ErrorRendererInterface::class),
                $container->get(AppConfig::class)->debug,
                $container->get(LoggerInterface::class),
            );
        });
    }

    /**
     * Register the application event listeners
     */
    public function boot(ContainerInterface $container): void
    {
        $listeners = $container->get(ListenerProvider::class);
        $audit = new LogAuthEventsListener($container->get(LoggerInterface::class));

        $listeners->listen(Attempting::class, [$audit, 'onAttempting']);
        $listeners->listen(LoginFailed::class, [$audit, 'onFailed']);
        $listeners->listen(LoggedIn::class, [$audit, 'onLoggedIn']);
        $listeners->listen(LoggedOut::class, [$audit, 'onLoggedOut']);
    }
}
