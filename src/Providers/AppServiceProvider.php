<?php

declare(strict_types=1);

namespace App\Providers;

use App\Admin\Actions\FlushCache;
use App\Admin\Modules\{AccessModule, ActivityModule, AuditModule, DashboardModule, FailedJobsModule, FilesModule, JobsModule, LogsModule, MailModule, RateLimitsModule, ScheduledRunsModule, SchedulerModule, SessionsModule, SettingsModule, SystemHealthModule, UsersModule};
use App\Config\{AppConfig, CspConfig, DbConfig, LogConfig, RouteConfig};
use App\Controllers\Api\MeController;
use App\Controllers\StreamTokenController;
use App\Controllers\{AdminController, AuthController, EmailChangeController, EmailVerificationController, HomeController, PasswordResetController, TwoFactorChallengeController};
use App\Http\Middleware\{RecordActivityMiddleware, RedirectUnauthenticatedMiddleware};
use App\Listeners\AuditAccountEventsListener;
use App\Listeners\AuditAdminEventsListener;
use App\Listeners\MailAddressChangesListener;
use App\Listeners\MailRecoveryCodeUseListener;
use App\Listeners\PublishQueueChanges;
use App\Listeners\RecordSentMailListener;
use App\Repositories\{ActivityRepository, ApiTokenRepository, AuditRepository, LockoutRepository, NotificationRepository, SentMailRepository, SignInRepository, TwoFactorRepository, UserRepository};
use App\Tasks\PruneLockouts;
use App\Tasks\PruneNotifications;
use App\Tasks\PruneSentMail;
use App\Tasks\PruneSignIns;
use App\View\{Avatars, ThemeResolver, Themes, TimezoneResolver, Timezones, VerificationBanner};
use Hydra\Admin\AdminServiceProvider;
use Hydra\Admin\Contracts\TimezoneInterface;
use Hydra\Admin\Events\AdminEvent;
use Hydra\Admin\Live\ModuleChanges;
use Hydra\Admin\LogAdminEventsListener;
use Hydra\Admin\Notifications\NotificationStoreInterface;
use Hydra\Admin\Updates\UpdateCheck;
use Hydra\Auth\AuthenticateBearerMiddleware;
use Hydra\Auth\TrackSignInMiddleware;
use Hydra\Auth\Contracts\{ApiTokenStoreInterface, GuardInterface, SignInStoreInterface, TwoFactorStoreInterface, UserProviderInterface};
use Hydra\Auth\Events\{Attempting, EmailVerified, LoggedIn, LoggedOut, LoginFailed, PasswordReset, PasswordResetLinkSent, RecoveryCodeUsed, TwoFactorChallenged, TwoFactorFailed};
use Hydra\Auth\LogAuthEventsListener;
use Hydra\Broadcast\TopicPolicy;
use Hydra\Cache\CacheConfig;
use Hydra\Cache\CacheHealthCheck;
use Hydra\Cache\Contracts\StoreInterface;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Contracts\ExceptionReporterInterface;
use Hydra\Core\Environment;
use Hydra\Core\Providers\ServiceProvider;
use Hydra\Core\Security\Signer;
use Hydra\Core\Versions;
use Hydra\Csrf\{CsrfGuard, Honeypot, VerifyCsrfTokenMiddleware};
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
use Hydra\Log\{ContextualLogger, FanOutLogger, RedactingLogger, StreamLogger};
use Hydra\Mail\Events\MessageSent;
use Hydra\Queue\Contracts\QueueInterface;
use Hydra\Queue\Events\QueueChanged;
use Hydra\Queue\{DatabaseQueue, Worker};
use Hydra\Scheduler\Contracts\RunLogInterface;
use Hydra\Scheduler\DatabaseRunLog;
use Hydra\Scheduler\PruneScheduledRuns;
use Hydra\Scheduler\Schedule;
use Hydra\Throttle\Contracts\LockoutStoreInterface;
use Hydra\Session\StartSessionMiddleware;
use Hydra\Throttle\RateLimitMiddleware;
use Hydra\Seo\Image;
use Hydra\Seo\SiteMeta;
use Hydra\View\Assets;
use Hydra\View\Contracts\ViewInterface;
use Hydra\View\PhpView;
use PDO;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use InvalidArgumentException;
use RuntimeException;

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
        StreamTokenController::class,
    ];

    /**
     * Admin modules, in sidebar order
     */
    public const MODULES = [
        DashboardModule::class,
        SystemHealthModule::class,
        UsersModule::class,
        AccessModule::class,
        SessionsModule::class,
        RateLimitsModule::class,
        MailModule::class,
        FilesModule::class,
        ActivityModule::class,
        AuditModule::class,
        LogsModule::class,
        SchedulerModule::class,
        ScheduledRunsModule::class,
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
        // After both, so it knows whether this is a sign-in or a token; it
        // writes on the way out, so the request that signs in is recorded too.
        TrackSignInMiddleware::class,
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
                events: $container->get(EventDispatcherInterface::class),
            );
        });

        $container->singleton(QueueInterface::class, function () use ($container) {
            return $container->get(DatabaseQueue::class);
        });

        $container->singleton(DatabaseRunLog::class, function () use ($container) {
            return new DatabaseRunLog($container->get(ConnectionInterface::class), $container->get(ClockInterface::class));
        });

        $container->singleton(RunLogInterface::class, function () use ($container) {
            return $container->get(DatabaseRunLog::class);
        });

        $container->singleton(Worker::class, function () use ($container) {
            return new Worker(
                $container->get(DatabaseQueue::class),
                $container,
                $container->get(LoggerInterface::class),
                reporter: $this->reporter($container),
                events: $container->get(EventDispatcherInterface::class),
            );
        });

        // Bound by hand, not autowired: PHP-DI passes an optional constructor
        // parameter by, so an autowired repository would never be handed the
        // admin's ModuleChanges, and make:user and the settings screens would
        // change users without a single open list hearing of it.
        $container->singleton(UserRepository::class, function () use ($container) {
            return new UserRepository(
                $container->get(ConnectionInterface::class),
                $container->get(ModuleChanges::class),
            );
        });

        $container->singleton(UserProviderInterface::class, function () use ($container) {
            return $container->get(UserRepository::class);
        });

        $container->singleton(TwoFactorStoreInterface::class, function () use ($container) {
            return $container->get(TwoFactorRepository::class);
        });

        // The bell's store. Bound, so the admin serves the bell's routes;
        // one instance for both names, so the prune and the bell agree.
        $container->singleton(NotificationRepository::class, function () use ($container) {
            return new NotificationRepository($container->get(ConnectionInterface::class));
        });

        $container->singleton(NotificationStoreInterface::class, function () use ($container) {
            return $container->get(NotificationRepository::class);
        });

        $container->singleton(ApiTokenStoreInterface::class, function () use ($container) {
            return $container->get(ApiTokenRepository::class);
        });

        // Bound by hand, like UserRepository: autowiring would skip the
        // optional ModuleChanges, and Sessions, Mail and Audit would never
        // hear of the rows written outside the admin.
        $container->singleton(SignInRepository::class, function () use ($container) {
            return new SignInRepository(
                $container->get(ConnectionInterface::class),
                $container->get(ModuleChanges::class),
            );
        });

        $container->singleton(SentMailRepository::class, function () use ($container) {
            return new SentMailRepository(
                $container->get(ConnectionInterface::class),
                $container->get(ModuleChanges::class),
            );
        });

        $container->singleton(AuditRepository::class, function () use ($container) {
            return new AuditRepository(
                $container->get(ConnectionInterface::class),
                $container->get(ModuleChanges::class),
            );
        });

        // Bound, the guard records every sign-in and checks it on each request,
        // so one can be listed and revoked; TrackSignInMiddleware says when and
        // from where it was last seen.
        $container->singleton(SignInStoreInterface::class, function () use ($container) {
            return $container->get(SignInRepository::class);
        });

        // Bound, the rate limiter records each client it starts refusing, so
        // Administration › Rate limits can list them and let them back in.
        $container->singleton(LockoutStoreInterface::class, function () use ($container) {
            return $container->get(LockoutRepository::class);
        });

        // Built by hand: autowiring fills an optional argument with its default,
        // and a flush that cannot see the lockouts would leave Rate limits
        // listing clients the emptied counters no longer refuse.
        $container->singleton(FlushCache::class, function () use ($container) {
            return new FlushCache(
                $container->get(StoreInterface::class),
                $container->get(CacheConfig::class),
                $container->get(ClockInterface::class),
                $container->bound(LockoutStoreInterface::class) ? $container->get(LockoutStoreInterface::class) : null,
            );
        });

        $container->singleton(LoggerInterface::class, function () use ($container) {
            return self::logger($container->get(LogConfig::class), $container->get(RequestId::class));
        });

        $container->singleton(RequestId::class, fn () => new RequestId);

        $container->singleton(SiteMeta::class, function () use ($container): SiteMeta {
            $app = $container->get(AppConfig::class);

            return self::siteMeta($app->url, $app->name);
        });

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
                    'avatars' => $container->get(Avatars::class),
                ],
                // The layout links its stylesheets and scripts through
                // asset(), so their names carry their content's hash and nginx
                // can keep them a year (see docker/nginx/default.conf).
                assets: new Assets(dirname(__DIR__, 2) . '/public'),
                // What $this->honeypot() prints in a public form. No skeleton
                // form uses it: login and password reset are the forms a
                // password manager fills, and a lockout there costs more than
                // the spam it would stop.
                honeypot: $container->get(Honeypot::class),
            );
        });

        // Bound by hand: its limits are optional constructor arguments, which
        // autowiring would skip, and the signer has to be the app's, under
        // APP_KEY, for a start time to survive a key rotation.
        $container->singleton(Honeypot::class, function () use ($container) {
            return new Honeypot($container->get(Signer::class), $container->get(ClockInterface::class));
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
        $schedule = $container->get(Schedule::class);
        $schedule->run(PruneScheduledRuns::class)->dailyAt('03:00');
        $schedule->run(PruneSignIns::class)->hourly();
        $schedule->run(PruneLockouts::class)->dailyAt('03:10');
        $schedule->run(PruneSentMail::class)->dailyAt('03:20');
        $schedule->run(PruneNotifications::class)->dailyAt('03:30');
        $schedule->drain(Worker::class)->everyMinute()->for(5);

        // Who may listen to what: a topic registered nowhere is heard by
        // nobody. `demo` is the home page's example; any signed-in user.
        $container->get(TopicPolicy::class)->allow('demo', static fn (): bool => true);

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

        // Every message a transport accepted, queued or sent in the request,
        // for Administration › Mail. Resolved at dispatch, like the audit.
        $listeners->listen(MessageSent::class, static function (MessageSent $event) use ($container): void {
            ($container->get(RecordSentMailListener::class))($event);
        });

        // Jobs and Failed jobs, whose rows the admin never writes. Resolved at
        // dispatch, like the audit.
        $listeners->listen(QueueChanged::class, static function (QueueChanged $event) use ($container): void {
            ($container->get(PublishQueueChanges::class))($event);
        });

        $listeners->listen(PasswordReset::class, static function (PasswordReset $event) use ($container): void {
            $container->get(AuditAccountEventsListener::class)->onPasswordReset($event);
        });

        $listeners->listen(RecoveryCodeUsed::class, static function (RecoveryCodeUsed $event) use ($container): void {
            $container->get(AuditAccountEventsListener::class)->onRecoveryCodeUsed($event);
        });
    }

    /**
     * Error tracking is opt-in: bind an ExceptionReporterInterface in a
     * provider and every fault the log records is reported to it as well.
     */
    /**
     * The file the admin reads and, unless turned off, stderr for the container.
     * A file that cannot be opened falls back to stderr alone.
     */
    public static function logger(LogConfig $config, RequestId $requestId, string $stderr = 'php://stderr'): LoggerInterface
    {
        $created = $config->isFile() && !is_file($config->path);
        $file = @fopen($config->path, 'a');
        $streams = $file === false ? [] : [$file];

        // php-fpm (www-data) and the scheduler (root) append to the same file,
        // and whichever creates it would otherwise lock the other out.
        if ($file !== false && $created) {
            @chmod($config->path, 0666);
        }

        if ($file === false || $config->alsoStderr()) {
            $streams[] = fopen($stderr, 'a');
        }

        $loggers = array_map(static fn ($stream): StreamLogger => new StreamLogger($stream), array_filter($streams));

        return new RedactingLogger(new ContextualLogger(
            count($loggers) === 1 ? $loggers[0] : new FanOutLogger(...$loggers),
            fn (): array => array_filter(['request_id' => $requestId->get()]),
        ));
    }

    private function reporter(ContainerInterface $container): ?ExceptionReporterInterface
    {
        return $container->bound(ExceptionReporterInterface::class)
            ? $container->get(ExceptionReporterInterface::class)
            : null;
    }

    /**
     * What every public page's head shares: the site's address and name, and
     * the image a link preview shows when a page has none of its own. Static so
     * the tests build it the same way from pinned values, since APP_URL is
     * process environment that other tests rewrite.
     */
    public static function siteMeta(string $url, string $name): SiteMeta
    {
        try {
            return new SiteMeta(
                baseUrl: $url,
                siteName: $name,
                defaultImage: new Image('/icons/android-chrome-512x512.png', 512, 512, $name),
            );
        } catch (InvalidArgumentException) {
            // SiteMeta names its own argument; whoever reads this edits .env.
            throw new RuntimeException(sprintf(
                'APP_URL must be the site\'s scheme and host, like https://example.com, with no path or trailing slash; got "%s".',
                $url,
            ));
        }
    }
}
