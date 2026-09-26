<?php

declare(strict_types=1);

namespace App\Providers;

use App\Admin\Modules\{ActivityModule, AuditModule, DashboardModule, FailedJobsModule, JobsModule, SettingsModule, SystemHealthModule, UsersModule};
use App\Config\{AppConfig, CspConfig, DbConfig, LogConfig, RouteConfig};
use App\Controllers\Api\MeController;
use App\Controllers\{AdminController, AuthController, EmailChangeController, EmailVerificationController, HomeController, PasswordResetController, TwoFactorChallengeController};
use App\Http\Middleware\{RecordActivityMiddleware, RedirectUnauthenticatedMiddleware};
use App\Listeners\AuditAdminEventsListener;
use App\Listeners\MailAddressChangesListener;
use App\Listeners\MailRecoveryCodeUseListener;
use App\Repositories\{ActivityRepository, ApiTokenRepository, TwoFactorRepository, UserRepository};
use App\View\{ThemeResolver, Themes, TimezoneResolver, Timezones, VerificationBanner};
use Hydra\Admin\AdminServiceProvider;
use Hydra\Admin\Contracts\TimezoneInterface;
use Hydra\Admin\Events\AdminEvent;
use Hydra\Admin\LogAdminEventsListener;
use Hydra\Admin\Updates\UpdateCheck;
use Hydra\Auth\AuthenticateBearerMiddleware;
use Hydra\Auth\Contracts\{ApiTokenStoreInterface, GuardInterface, TwoFactorStoreInterface, UserProviderInterface};
use Hydra\Auth\Events\{Attempting, EmailVerified, LoggedIn, LoggedOut, LoginFailed, PasswordReset, PasswordResetLinkSent, RecoveryCodeUsed, TwoFactorChallenged, TwoFactorFailed};
use Hydra\Auth\LogAuthEventsListener;
use Hydra\Cache\CacheHealthCheck;
use Hydra\Cache\Contracts\StoreInterface;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Contracts\ExceptionReporterInterface;
use Hydra\Core\Environment;
use Hydra\Core\Providers\ServiceProvider;
use Hydra\Core\Versions;
use Hydra\Csrf\{CsrfGuard, VerifyCsrfTokenMiddleware};
use Hydra\Database\Contracts\ConnectionInterface;
use Hydra\Database\{DatabaseHealthCheck, MigrationRunner, PdoConnection};
use Hydra\Event\ListenerProvider;
use Hydra\Http\Contracts\ErrorRendererInterface;
use Hydra\Http\Contracts\PathRedactorInterface;
use Hydra\Http\{
    ClientIpResolver,
    CorsConfig,
    CorsMiddleware,
    Csp,
    CspMiddleware,
    CspNonce,
    ErrorHandlerMiddleware,
    ForceHttpsMiddleware,
    HealthMiddleware,
    Maintenance,
    MaintenanceMiddleware,
    HtmxRedirectMiddleware,
    NegotiatingErrorRenderer,
    ParseBodyMiddleware,
    RequestId,
    RequestIdMiddleware,
    RequestLoggingMiddleware,
    Responder,
    SecurityHeadersMiddleware,
    TrustedProxies,
};
use Hydra\Log\{ContextualLogger, RedactingLogger, StreamLogger};
use Hydra\Queue\Contracts\QueueInterface;
use Hydra\Queue\{DatabaseQueue, Worker};
use Hydra\Scheduler\Schedule;
use Hydra\Session\StartSessionMiddleware;
use Hydra\Throttle\RateLimitMiddleware;
use Hydra\View\Contracts\ViewInterface;
use Hydra\View\PhpView;
use PDO;
use Psr\Clock\ClockInterface;
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
        TwoFactorChallengeController::class,
        PasswordResetController::class,
        EmailVerificationController::class,
        EmailChangeController::class,
        AdminController::class,
        MeController::class,
    ];

    /**
     * Admin modules, in sidebar order
     */
    public const MODULES = [
        DashboardModule::class,
        SystemHealthModule::class,
        UsersModule::class,
        ActivityModule::class,
        AuditModule::class,
        JobsModule::class,
        FailedJobsModule::class,
        SettingsModule::class,
    ];

    /**
     * The app's middleware stack, outermost first
     */
    public const MIDDLEWARE = [
        RequestIdMiddleware::class,
        // Ahead of the https redirect, which would answer a load balancer's
        // plain-http probe with a 301, and of the access log it would flood.
        HealthMiddleware::class,
        RequestLoggingMiddleware::class,
        SecurityHeadersMiddleware::class,
        CspMiddleware::class,
        ForceHttpsMiddleware::class,
        // Above the error handler, so a 401 or a 500 stays readable to the page
        // that caused it, and above the limiter, which a preflight never costs.
        CorsMiddleware::class,
        ErrorHandlerMiddleware::class,
        // Ahead of everything a maintenance window is for: the store, the
        // session, the database. Health sits above, so /up still answers.
        MaintenanceMiddleware::class,
        // Inside the error handler, not above it: an unreachable counter store
        // raises, and above the handler that raise has no response to render.
        // Ahead of everything that costs anything, so a refused request never
        // reaches the session, the body parser or the database.
        RateLimitMiddleware::class,
        HtmxRedirectMiddleware::class,
        ParseBodyMiddleware::class,
        StartSessionMiddleware::class,
        // After the session, which a bearer request skips; ahead of everything
        // that asks the guard who this is.
        AuthenticateBearerMiddleware::class,
        RecordActivityMiddleware::class,
        RedirectUnauthenticatedMiddleware::class,
        VerifyCsrfTokenMiddleware::class,
    ];

    public function register(ContainerInterface $container): void
    {
        $container->singleton(AppConfig::class, function () use ($container) {
            return AppConfig::fromEnvironment($container->get(Environment::class));
        });

        $container->singleton(Versions::class, function () {
            return new Versions(dirname(__DIR__, 2));
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

        // PDO's own driver name rather than DB_DRIVER, which may say mariadb.
        $container->singleton(DatabaseQueue::class, function () use ($container) {
            return new DatabaseQueue(
                $container->get(ConnectionInterface::class),
                $container->get(ClockInterface::class),
                $container->get(PDO::class)->getAttribute(PDO::ATTR_DRIVER_NAME),
            );
        });

        $container->singleton(QueueInterface::class, function () use ($container) {
            return $container->get(DatabaseQueue::class);
        });

        $container->singleton(Worker::class, function () use ($container) {
            return new Worker(
                $container->get(DatabaseQueue::class),
                $container,
                $container->get(LoggerInterface::class),
                reporter: $this->reporter($container),
            );
        });

        $container->singleton(UserProviderInterface::class, function () use ($container) {
            return new UserRepository($container->get(ConnectionInterface::class));
        });

        $container->singleton(TwoFactorStoreInterface::class, function () use ($container) {
            return $container->get(TwoFactorRepository::class);
        });

        $container->singleton(ApiTokenStoreInterface::class, function () use ($container) {
            return $container->get(ApiTokenRepository::class);
        });

        $container->singleton(LoggerInterface::class, function () use ($container) {
            $path = $container->get(LogConfig::class)->path;
            $stream = @fopen($path, 'a') ?: fopen('php://stderr', 'w');
            $requestId = $container->get(RequestId::class);

            return new RedactingLogger(new ContextualLogger(
                new StreamLogger($stream),
                fn (): array => array_filter(['request_id' => $requestId->get()]),
            ));
        });

        $container->singleton(RequestId::class, fn () => new RequestId);

        $container->singleton(Themes::class, function (): Themes {
            return new Themes(dirname(__DIR__, 2) . '/public/css/themes');
        });

        $container->singleton(Timezones::class, function () use ($container): Timezones {
            return new Timezones($container->get(AppConfig::class)->timezone);
        });

        // The admin asks for the interface, and nothing but the admin does; the
        // application's own code asks for the class. Bound both ways so that
        // neither has to know about the other's name for it.
        $container->singleton(TimezoneInterface::class, function () use ($container) {
            return $container->get(TimezoneResolver::class);
        });

        $container->singleton(ViewInterface::class, function () use ($container) {
            $themes = $container->get(Themes::class);

            return new PhpView(
                dirname(__DIR__, 2) . '/views',
                // Not shared data: the admin package's templates stamp it on
                // every htmx element they render, and a view that cannot supply
                // one has to fail here rather than on the first screen opened.
                $container->get(CspNonce::class),
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
                    'versions' => $container->get(Versions::class),
                    'updates' => $container->get(UpdateCheck::class),
                    'verification' => $container->get(VerificationBanner::class),
                ],
            );
        });

        // X-Frame-Options is the superseded spelling of frame-ancestors, so the
        // policy answers for it whenever one is sent. With CSP switched off
        // nothing else says it, and the header goes back to carrying the rule
        // on its own.
        $container->singleton(SecurityHeadersMiddleware::class, function () use ($container) {
            return new SecurityHeadersMiddleware(
                $container->get(CspConfig::class)->enabled
                    ? SecurityHeadersMiddleware::WITH_CSP
                    : SecurityHeadersMiddleware::DEFAULTS,
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
                ->with('font-src', "'self'", 'https://fonts.gstatic.com')
                // public/js/qr.js loads the QR library from here, held to one
                // file by its integrity hash.
                ->with('script-src', "'self'", Csp::NONCE, 'https://cdnjs.cloudflare.com');

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

        $container->singleton(CorsConfig::class, function () use ($container) {
            return CorsConfig::fromEnvironment($container->get(Environment::class));
        });

        $container->singleton(ClientIpResolver::class, function () use ($container) {
            return new ClientIpResolver($container->get(TrustedProxies::class));
        });

        // docker/nginx/default.conf overwrites X-Request-Id with its own $request_id.
        $container->singleton(RequestIdMiddleware::class, function () use ($container) {
            return new RequestIdMiddleware(
                $container->get(RequestId::class),
                trustIncoming: true,
                clients: $container->get(ClientIpResolver::class),
            );
        });

        $container->singleton(RequestLoggingMiddleware::class, function () use ($container) {
            return new RequestLoggingMiddleware(
                $container->get(LoggerInterface::class),
                $container->get(PathRedactorInterface::class),
            );
        });

        $container->singleton(Maintenance::class, function () {
            return new Maintenance(dirname(__DIR__, 2) . '/bootstrap/cache/maintenance.json');
        });

        $container->singleton(MaintenanceMiddleware::class, function () use ($container) {
            return new MaintenanceMiddleware(
                $container->get(Maintenance::class),
                $container->get(ErrorRendererInterface::class),
            );
        });

        $container->singleton(HealthMiddleware::class, function () use ($container) {
            return new HealthMiddleware(
                $container->get(Responder::class),
                [
                    new DatabaseHealthCheck($container->get(ConnectionInterface::class)),
                    new CacheHealthCheck($container->get(StoreInterface::class)),
                ],
                $container->get(LoggerInterface::class),
            );
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
            return new NegotiatingErrorRenderer($container->get(Responder::class), '#app-error');
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
                $this->reporter($container),
                $container->get(PathRedactorInterface::class),
            );
        });
    }

    /**
     * Register the application event listeners and the schedule
     */
    public function boot(ContainerInterface $container): void
    {
        $container->get(Schedule::class)->drain(Worker::class)->everyMinute()->for(5);

        $listeners = $container->get(ListenerProvider::class);
        $audit = new LogAuthEventsListener($container->get(LoggerInterface::class));

        $listeners->listen(Attempting::class, [$audit, 'onAttempting']);
        $listeners->listen(LoginFailed::class, [$audit, 'onFailed']);
        $listeners->listen(LoggedIn::class, [$audit, 'onLoggedIn']);
        $listeners->listen(LoggedOut::class, [$audit, 'onLoggedOut']);
        $listeners->listen(PasswordResetLinkSent::class, [$audit, 'onPasswordResetLinkSent']);
        $listeners->listen(PasswordReset::class, [$audit, 'onPasswordReset']);
        $listeners->listen(EmailVerified::class, [$audit, 'onEmailVerified']);
        $listeners->listen(TwoFactorChallenged::class, [$audit, 'onTwoFactorChallenged']);
        $listeners->listen(TwoFactorFailed::class, [$audit, 'onTwoFactorFailed']);
        $listeners->listen(RecoveryCodeUsed::class, [$audit, 'onRecoveryCodeUsed']);

        // One registration against the base class, because the provider matches
        // an event's subtypes: this hears every admin write and every export,
        // including the events the admin grows later. The activity table already
        // records that the request happened; this is what it was for and, for an
        // export, how much of the table left with it.
        $listeners->listen(AdminEvent::class, new LogAdminEventsListener($container->get(LoggerInterface::class)));

        // Resolved at dispatch rather than here: the listener holds a repository
        // holding the connection, and boot() runs before anything that rebinds
        // one. An instance built now would go on writing to whichever database
        // was bound at boot.
        $listeners->listen(AdminEvent::class, static function (AdminEvent $event) use ($container): void {
            ($container->get(AuditAdminEventsListener::class))($event);
        });

        $listeners->listen(AdminEvent::class, static function (AdminEvent $event) use ($container): void {
            ($container->get(MailAddressChangesListener::class))($event);
        });

        $listeners->listen(RecoveryCodeUsed::class, static function (RecoveryCodeUsed $event) use ($container): void {
            ($container->get(MailRecoveryCodeUseListener::class))($event);
        });
    }

    /**
     * Error tracking is opt-in: bind an ExceptionReporterInterface in a
     * provider and every fault the log records is reported to it as well.
     */
    private function reporter(ContainerInterface $container): ?ExceptionReporterInterface
    {
        return $container->bound(ExceptionReporterInterface::class)
            ? $container->get(ExceptionReporterInterface::class)
            : null;
    }
}
