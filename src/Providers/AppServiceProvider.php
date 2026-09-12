<?php

declare(strict_types=1);

namespace App\Providers;

use App\Admin\Modules\{ActivityModule, DashboardModule, SettingsModule, UsersModule};
use App\Config\{AppConfig, CspConfig, DbConfig, LogConfig, RouteConfig};
use App\Controllers\{AdminController, AuthController, HomeController};
use App\Http\Middleware\{RecordActivityMiddleware, RedirectUnauthenticatedMiddleware};
use App\Http\NegotiatingErrorRenderer;
use App\Repositories\{ActivityRepository, UserRepository};
use App\View\{ThemeResolver, Themes};
use Hydra\Admin\AdminServiceProvider;
use Hydra\Auth\Contracts\{GuardInterface, UserProviderInterface};
use Hydra\Auth\Events\{Attempting, LoggedIn, LoggedOut, LoginFailed};
use Hydra\Auth\LogAuthEventsListener;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Environment;
use Hydra\Core\Providers\ServiceProvider;
use Hydra\Csrf\{CsrfGuard, VerifyCsrfTokenMiddleware};
use Hydra\Database\Contracts\ConnectionInterface;
use Hydra\Database\{MigrationRunner, PdoConnection};
use Hydra\Event\ListenerProvider;
use Hydra\Http\Contracts\ErrorRendererInterface;
use Hydra\Http\{
    ClientIpResolver,
    Csp,
    CspMiddleware,
    CspNonce,
    ErrorHandlerMiddleware,
    ForceHttpsMiddleware,
    HtmxRedirectMiddleware,
    ParseBodyMiddleware,
    PlainTextErrorRenderer,
    RequestLoggingMiddleware,
    Responder,
    SecurityHeadersMiddleware,
    TrustedProxies,
};
use Hydra\Log\StreamLogger;
use Hydra\Session\StartSessionMiddleware;
use Hydra\View\Contracts\ViewInterface;
use Hydra\View\PhpView;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Where this application is assembled: every binding, the route sources, the
 * admin modules and the middleware order live here, so the composition of the
 * app can be read in one file instead of inferred from the framework.
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
     * Admin modules, in sidebar order
     */
    public const MODULES = [
        DashboardModule::class,
        UsersModule::class,
        ActivityModule::class,
        SettingsModule::class,
    ];

    /**
     * The app's middleware stack, outermost first
     */
    public const MIDDLEWARE = [
        RequestLoggingMiddleware::class,
        SecurityHeadersMiddleware::class,
        CspMiddleware::class,
        ForceHttpsMiddleware::class,
        ErrorHandlerMiddleware::class,
        HtmxRedirectMiddleware::class,
        ParseBodyMiddleware::class,
        StartSessionMiddleware::class,
        RecordActivityMiddleware::class,
        RedirectUnauthenticatedMiddleware::class,
        VerifyCsrfTokenMiddleware::class,
    ];

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

        $container->singleton(CspConfig::class, function () use ($container) {
            return CspConfig::fromEnvironment($container->get(Environment::class));
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

        $container->singleton(Themes::class, function (): Themes {
            return new Themes(dirname(__DIR__, 2) . '/public/css/themes');
        });

        $container->singleton(ViewInterface::class, function () use ($container) {
            $themes = $container->get(Themes::class);

            return new PhpView(
                dirname(__DIR__, 2) . '/views',
                $container->get(CsrfGuard::class),
                fallbacks: [AdminServiceProvider::views()],
                // Shared rather than passed through every render: the layout
                // needs the palette on every page, admin or not, and no
                // controller should have to remember to hand it over. The
                // resolver is shared and not its answer: building the view must
                // not read the session, or the console cannot build one.
                shared: [
                    'themes' => $themes,
                    'theme' => $container->get(ThemeResolver::class),
                    // The layout arms hx-csp only when a policy is actually
                    // sent. The extension gates htmx on a nonce it recovers
                    // from the response's policy header, so with no header
                    // there is nothing to recover and every swap would be
                    // stripped. Turning CSP off has to turn the gate off with
                    // it, or the switch that exists to rule the policy out
                    // breaks more than it rules out.
                    'csp' => $container->get(CspConfig::class),
                ],
                // Not shared data: the admin package's templates stamp it on
                // every htmx element they render, and a missing nonce has to
                // fail as a named error rather than as an undefined variable.
                cspNonce: $container->get(CspNonce::class),
            );
        });

        $container->singleton(CspMiddleware::class, function () use ($container) {
            $config = $container->get(CspConfig::class);

            $policy = Csp::default()
                // Bootstrap's stylesheet draws its form-control and accordion
                // glyphs from data: SVGs rather than from files.
                ->with('img-src', "'self'", 'data:')
                // Google Fonts answers from two hosts: the @font-face sheet
                // comes from one and the files it names from the other.
                ->with('style-src', "'self'", 'https://fonts.googleapis.com')
                ->with('font-src', "'self'", 'https://fonts.gstatic.com');

            if ($config->reportUri !== '') {
                $policy = $policy->with('report-uri', $config->reportUri);
            }

            return new CspMiddleware(
                $policy,
                $container->get(CspNonce::class),
                $config->enabled,
                $config->reportOnly,
            );
        });

        // One declaration of who may speak for a client, shared by everything
        // that asks: the forwarded scheme, the activity log, and anything that
        // later counts requests per caller.
        $container->singleton(TrustedProxies::class, function () use ($container) {
            return new TrustedProxies($container->get(AppConfig::class)->trustedProxies);
        });

        $container->singleton(ClientIpResolver::class, function () use ($container) {
            return new ClientIpResolver($container->get(TrustedProxies::class));
        });

        $container->singleton(ForceHttpsMiddleware::class, function () use ($container) {
            $config = $container->get(AppConfig::class);
            return new ForceHttpsMiddleware(
                $config->forceHttps,
                $container->get(Responder::class),
                $config->trustForwardedProto,
                $container->get(ClientIpResolver::class),
            );
        });

        $container->singleton(ErrorRendererInterface::class, function () use ($container) {
            return new NegotiatingErrorRenderer(
                $container->get(Responder::class),
                new PlainTextErrorRenderer($container->get(Responder::class)),
            );
        });

        $container->singleton(RecordActivityMiddleware::class, function () use ($container) {
            return new RecordActivityMiddleware(
                new ActivityRepository($container->get(ConnectionInterface::class)),
                $container->get(GuardInterface::class),
                $container->get(LoggerInterface::class),
                $container->get(ClientIpResolver::class),
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
