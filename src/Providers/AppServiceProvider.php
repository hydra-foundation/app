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
use Hydra\Database\Contracts\ConnectionInterface;
use Hydra\Database\MigrationRunner;
use Hydra\Database\PdoConnection;
use Hydra\Http\ForceHttpsMiddleware;
use Hydra\Http\RequestLoggingMiddleware;
use Hydra\Http\SecurityHeadersMiddleware;
use Hydra\Log\StreamLogger;
use Hydra\Nyholm\NyholmRequestProvider;
use Hydra\View\PhpView;
use Hydra\View\Contracts\ViewInterface;
use PDO;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Contracts\KernelInterface;
use Hydra\Core\Environment;
use Hydra\Core\Providers\ServiceProvider;
use Hydra\Http\Contracts\EmitterInterface;
use Hydra\Http\ErrorHandlerMiddleware;
use Hydra\Http\Contracts\ServerRequestProviderInterface;
use Hydra\Http\Emitter;
use Hydra\Http\HttpKernel;
use Hydra\Http\Pipeline;
use Hydra\Http\Responder;
use Hydra\Http\Router;
use Hydra\Http\RouteCache;
use Hydra\Http\RouteScanner;
use Hydra\Session\StartSessionMiddleware;
use Hydra\Csrf\CsrfGuard;
use Hydra\Csrf\VerifyCsrfTokenMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Controllers scanned for #[Route] attributes. Public because the route
     * cache console command (App\Console\Commands\RouteCacheCommand) compiles
     * the very same set — one list, two readers, no drift.
     */
    public const CONTROLLERS = [
        HomeController::class,
        AuthController::class,
        AdminController::class,
    ];

    /**
     * The app's middleware stack, outermost first. Each entry is a class-string
     * resolved through the container, so middleware get full dependency
     * injection. The order here is the order requests travel inward.
     */
    private const MIDDLEWARE = [
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
        $container->singleton(RouteConfig::class, function () use ($container) {
            return RouteConfig::fromEnvironment($container->get(Environment::class));
        });

        // The compiled route cache, bound once so the web path (read-only) and
        // the route:cache / route:cache:clear console commands (the writers)
        // agree on a single artifact location.
        $container->singleton(RouteCache::class, function () {
            return new RouteCache(dirname(__DIR__, 2) . '/bootstrap/cache/routes.php');
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
        // engine swappable, like the ViewInterface binding above.
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

        // nyholm's Psr17Factory implements every PSR-17 factory interface.
        $container->singleton(Psr17Factory::class, fn () => new Psr17Factory);
        $container->singleton(ResponseFactoryInterface::class, fn () => $container->get(Psr17Factory::class));
        $container->singleton(StreamFactoryInterface::class, fn () => $container->get(Psr17Factory::class));

        // Capture the incoming request from PHP globals (nyholm behind our seam).
        $container->singleton(ServerRequestProviderInterface::class, function () use ($container) {
            return NyholmRequestProvider::create($container->get(Psr17Factory::class));
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

        // Send the response.
        $container->singleton(EmitterInterface::class, fn () => new Emitter);

        // Response helper shared by controllers (via the base Controller).
        $container->singleton(Responder::class, function () use ($container) {
            return new Responder(
                $container->get(ResponseFactoryInterface::class),
                $container->get(StreamFactoryInterface::class),
            );
        });

        // Https-upgrade middleware. Bound explicitly because its $enabled flag is
        // a plain bool (the package stays free of the app's config type), so it
        // can't be autowired — the app supplies FORCE_HTTPS here. Once bound it's
        // just another class-string in the MIDDLEWARE stack.
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

        // Router, populated from controller #[Route] attributes. Routing misses
        // (404/405) are thrown as HttpExceptions and rendered by the pipeline's
        // ErrorHandlerMiddleware, so the router needs no response factory.
        $container->singleton(Router::class, function () use ($container) {
            $router = new Router($container);
            $router->loadRoutes($this->compileRoutes($container));
            return $router;
        });

        // The application handler: the middleware pipeline wrapping the router.
        // The stack is declared as class-strings (self::MIDDLEWARE) and resolved
        // here through the container, so adding a layer is a one-line edit.
        $container->singleton(RequestHandlerInterface::class, function () use ($container) {
            $middleware = array_map(
                fn (string $class) => $container->get($class),
                self::MIDDLEWARE,
            );

            return new Pipeline($middleware, $container->get(Router::class));
        });

        // The HTTP kernel Application resolves and runs.
        $container->singleton(KernelInterface::class, function () use ($container) {
            return new HttpKernel(
                $container->get(ServerRequestProviderInterface::class),
                $container->get(RequestHandlerInterface::class),
                $container->get(EmitterInterface::class),
            );
        });
    }

    /**
     * The compiled route definitions for the Router. This path is READ-ONLY: it
     * never writes the cache during a request — writing belongs to the
     * `route:cache` console command, run at deploy time. With ROUTE_CACHE off
     * (the default — dev wants #[Route] edits to take effect at once) it scans
     * the controllers on every boot. With it on it loads the cached file, or
     * falls back to a live scan when the cache is cold (a forgotten
     * `route:cache` degrades to uncached-but-correct, never broken).
     *
     * @return list<array{method: string, path: string, handler: array{0: class-string, 1: string}, middleware: list<class-string>}>
     */
    private function compileRoutes(ContainerInterface $container): array
    {
        $scan = static fn (): array => (new RouteScanner)->scan(self::CONTROLLERS);

        if (!$container->get(RouteConfig::class)->cache) {
            return $scan();
        }

        return $container->get(RouteCache::class)->load() ?? $scan();
    }

    public function boot(ContainerInterface $container): void {}
}
