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
use Hydra\Event\ListenerProvider;
use Hydra\Http\ErrorHandlerMiddleware;
use Hydra\Http\ForceHttpsMiddleware;
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
 * The app's own service provider: only what is genuinely this application's
 * policy. The framework plumbing (PSR-17 factories, request provider, emitter,
 * responder, router, pipeline, kernel) lives in {@see \Hydra\Kernel\HttpServiceProvider}
 * and the standard provider stack in {@see \Hydra\Kernel\Kernel} — so none of that
 * boilerplate can drift between this skeleton and other Hydra consumers.
 *
 * What stays here: config value objects, the data layer, the app-supplied user
 * provider, the view, the logger, the two config-needing middleware, and the
 * app's event listeners. Everything the kernel binds is resolved from the
 * container by these bindings exactly as before.
 */
final class AppServiceProvider extends ServiceProvider
{
    /**
     * Controllers scanned for #[Route] attributes. Public because two readers
     * consume the same list with no drift: the kernel's HttpServiceProvider
     * (passed in at Bootstrap) and the route:cache console command.
     */
    public const CONTROLLERS = [
        HomeController::class,
        AuthController::class,
        AdminController::class,
    ];

    /**
     * The app's middleware stack, outermost first. Each entry is a class-string
     * the kernel's pipeline resolves through the container, so middleware get full
     * dependency injection. The order here is the order requests travel inward.
     * Public because Bootstrap hands it to the kernel's HttpServiceProvider.
     */
    public const MIDDLEWARE = [
        // Writes one access-log line per request once a response exists. Sits
        // outermost so it times the whole pipeline and always sees a final
        // status — the error handler beneath turns any throwable into a 500, so
        // even a failed request returns here with something real to log.
        RequestLoggingMiddleware::class,
        // Stamps conservative security headers (nosniff, frame-options,
        // referrer-policy) onto every outgoing response. Outermost of the
        // decorators so even the error handler's 500 and the https 301 — both
        // produced inside it — carry the headers. Only decorates, never throws.
        SecurityHeadersMiddleware::class,
        // Upgrades insecure requests to https with a 301 (and emits HSTS on
        // secure ones) when FORCE_HTTPS is on; a no-op otherwise. Runs before
        // the error handler and session so we don't do work for a request we're
        // about to redirect. Honours X-Forwarded-Proto for proxy-terminated TLS.
        ForceHttpsMiddleware::class,
        // Converts any uncaught Throwable into a 500 and logs it before
        // responding, so it must wrap all the application work below it.
        ErrorHandlerMiddleware::class,
        // Opens the session on the way in and saves it on the way out, so a
        // controller is handed an already-started session. Sits inside the
        // error handler so a session save failure still becomes a clean 500.
        StartSessionMiddleware::class,
        // Rejects any unsafe request (POST/PUT/PATCH/DELETE) without a valid CSRF
        // token with a 403. Sits inside the session middleware because it reads
        // the token from the started session. Autowires from CsrfGuard, which in
        // turn autowires from the session binding — no provider to register.
        VerifyCsrfTokenMiddleware::class,
        // Maps auth's 401 (thrown by the per-route AuthenticateMiddleware) to a
        // redirect to /login — a 302 for browsers, an HX-Redirect for htmx. Sits
        // innermost so it wraps only the router: it catches AuthenticationException
        // and lets every other HttpException pass out to the error handler.
        RedirectUnauthenticatedMiddleware::class,
    ];

    public function register(ContainerInterface $container): void
    {
        // Typed, immutable view of the APP_* settings, built once from the
        // environment. Consumers read a field instead of a magic string.
        $container->singleton(AppConfig::class, function () use ($container) {
            return AppConfig::fromEnvironment($container->get(Environment::class));
        });

        // Logging settings (sink path). Same pattern as AppConfig.
        $container->singleton(LogConfig::class, function () use ($container) {
            return LogConfig::fromEnvironment($container->get(Environment::class));
        });

        // Database settings (DB_* env), same typed-config pattern.
        $container->singleton(DbConfig::class, function () use ($container) {
            return DbConfig::fromEnvironment($container->get(Environment::class));
        });

        // Routing settings (the ROUTE_CACHE toggle), same typed-config pattern.
        // Bootstrap reads this to tell the kernel whether to use the cache; it
        // stays bound for any other consumer that wants the typed view.
        $container->singleton(RouteConfig::class, function () use ($container) {
            return RouteConfig::fromEnvironment($container->get(Environment::class));
        });

        // The raw PDO handle, bound once. Built here (not in PdoConnection) with
        // the assumptions the rest of the data layer relies on: throw on error,
        // fetch associative, real prepares. Two consumers share it — the
        // ConnectionInterface seam below (prepared statements only) and the
        // MigrationRunner (raw DDL via PDO::exec). Binding PDO separately keeps
        // the seam pure while the runner still reaches the same connection.
        $container->singleton(PDO::class, function () use ($container) {
            $config = $container->get(DbConfig::class);
            return new PDO($config->dsn(), $config->username, $config->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        });

        // The database connection behind its seam. Wraps the shared PDO so the
        // connection class stays driver-agnostic. Binding the interface keeps the
        // engine swappable, like the ViewInterface binding below.
        $container->singleton(ConnectionInterface::class, function () use ($container) {
            return new PdoConnection($container->get(PDO::class));
        });

        // The migration runner: applies raw .sql files from database/migrations
        // and tracks them in a `migrations` table. Takes the raw PDO (DDL needs
        // PDO::exec, not the prepared-only seam) and the driver, which it uses to
        // branch the drop-all step of migrate:fresh — the same dialect split
        // DbConfig::dsn() already embraces.
        $container->singleton(MigrationRunner::class, function () use ($container) {
            return new MigrationRunner(
                $container->get(PDO::class),
                dirname(__DIR__, 2) . '/database/migrations',
                $container->get(DbConfig::class)->driver,
            );
        });

        // Fulfils auth's one deliberately-unbound contract: UserProviderInterface.
        // The auth package ships the identity mechanism but cannot know our user
        // storage, so this binding is what lets the guard resolve at all — without
        // it, resolving the guard is a loud container error (never a silent
        // insecure default). A hand-written repository over the connection seam.
        $container->singleton(UserProviderInterface::class, function () use ($container) {
            return new UserRepository($container->get(ConnectionInterface::class));
        });

        // PSR-3 logger. Sink is LOG_PATH (default stderr); an unwritable path
        // falls back to stderr so a bad config can never break booting.
        $container->singleton(LoggerInterface::class, function () use ($container) {
            $path = $container->get(LogConfig::class)->path;
            $stream = @fopen($path, 'a') ?: fopen('php://stderr', 'w');
            return new StreamLogger($stream);
        });

        // Native PHP template renderer behind the ViewInterface seam. The
        // templates live in app/views; binding the interface (not PhpView) keeps
        // the engine swappable — a Twig adapter could replace it here untouched.
        $container->singleton(ViewInterface::class, function () use ($container) {
            return new PhpView(dirname(__DIR__, 2) . '/views', $container->get(CsrfGuard::class));
        });

        // Https-upgrade middleware. Bound explicitly because its $enabled flag is
        // a plain bool (the package stays free of the app's config type), so it
        // can't be autowired — the app supplies FORCE_HTTPS here. The Responder it
        // needs is bound by the kernel's HttpServiceProvider and resolved here.
        $container->singleton(ForceHttpsMiddleware::class, function () use ($container) {
            return new ForceHttpsMiddleware(
                $container->get(AppConfig::class)->forceHttps,
                $container->get(Responder::class),
            );
        });

        // Error handler middleware. Bound explicitly because its $debug flag and
        // logger aren't autowirable; once bound, it's just another class-string
        // in the MIDDLEWARE stack like any other.
        $container->singleton(ErrorHandlerMiddleware::class, function () use ($container) {
            return new ErrorHandlerMiddleware(
                $container->get(Responder::class),
                $container->get(AppConfig::class)->debug,
                $container->get(LoggerInterface::class),
            );
        });
    }

    /**
     * Register the app's event listeners. boot() runs after every provider has
     * registered, so the shared ListenerProvider (from the kernel's
     * EventServiceProvider) and the logger both exist by now. This is the "app
     * supplies the noun" half of the event system: the framework ships the
     * dispatcher and the audit listener; the app decides to wire them up.
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
