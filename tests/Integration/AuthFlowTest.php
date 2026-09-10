<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use Hydra\PhpDi\Container;
use App\Providers\AppServiceProvider;
use App\Tests\Support\ArraySessionServiceProvider;
use App\Tests\Support\TestAdminProvider;
use App\Tests\Support\TestHttpProvider;
use App\Tests\Support\TestSchema;
use Hydra\Auth\AuthConfig;
use Hydra\Auth\AuthServiceProvider;
use Hydra\Authorization\AuthorizationServiceProvider;
use Hydra\Auth\Contracts\HasherInterface;
use Hydra\Core\Application;
use App\Tests\Support\FixedSignerServiceProvider;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Environment;
use Hydra\Csrf\CsrfGuard;
use Hydra\Http\HtmxResponse;
use Hydra\Database\Contracts\ConnectionInterface;
use Hydra\Database\PdoConnection;
use Hydra\Nyholm\NyholmServiceProvider;
use Hydra\Session\Contracts\SessionLifecycleInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The auth slice end-to-end through the real composition root: the login form,
 * a failed attempt (generic 422, no session), a successful attempt (302 →
 * /admin, which lands on the dashboard), the htmx directive variant,
 * logout, and the guard rejecting the protected route for an anonymous visitor.
 *
 * Backed by an in-memory sqlite swap for MariaDB (the ConnectionInterface seam),
 * plus a seeded user hashed by the real bound hasher at a cheap cost so the suite
 * stays fast.
 */
final class AuthFlowTest extends TestCase
{
    private const USERNAME = 'will';
    private const PASSWORD = 'correct-horse-battery-staple';

    private ContainerInterface $container;

    protected function setUp(): void
    {
        $container = Container::create();
        $container->instance(ContainerInterface::class, $container);
        $container->instance(Environment::class, new Environment(__DIR__));

        (new Application($container))
            ->register(new ArraySessionServiceProvider)
            ->register(new NyholmServiceProvider)
            // Fixed-key signer for the CSRF guard (no APP_KEY in this .env-less env).
            ->register(new FixedSignerServiceProvider)
            ->register(TestHttpProvider::make())
            ->register(new AuthServiceProvider)
            ->register(new AuthorizationServiceProvider)
            ->register(new AppServiceProvider)
            ->register(TestAdminProvider::make())
            ->boot();

        // Cheap bcrypt cost so the handful of hashes this test does stay fast.
        // Set before the hasher first resolves, so it builds with this config.
        $container->instance(AuthConfig::class, new AuthConfig(hashCost: 4));

        // Swap MariaDB for in-memory sqlite — the ConnectionInterface seam means
        // the repository and guard wiring are untouched.
        $pdo = TestSchema::connect();
        $container->instance(ConnectionInterface::class, new PdoConnection($pdo));

        // Seed one user, hashed by the very hasher the guard will verify against.
        $hash = $container->get(HasherInterface::class)->hash(self::PASSWORD);
        // An admin: the seeded account reaches every module, gated or not.
        $pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)')
            ->execute([self::USERNAME, $hash, 'admin']);

        $this->container = $container;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $body
     */
    private function handle(string $method, string $path, array $headers = [], ?array $body = null): ResponseInterface
    {
        $request = (new Psr17Factory)->createServerRequest($method, $path);

        // Supply the session's CSRF token on unsafe methods so these tests
        // exercise auth, not the CSRF guard (covered in CsrfFlowTest).
        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            // Mint the token inside a started session window, the way a
            // rendered form would; the pipeline's session save() closes it.
            $this->container->get(SessionLifecycleInterface::class)->start();
            $request = $request->withHeader('X-CSRF-Token', $this->container->get(CsrfGuard::class)->token());
        }

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }

        return $this->container->get(RequestHandlerInterface::class)->handle($request);
    }

    public function test_login_page_renders_the_form(): void
    {
        $response = $this->handle('GET', '/login');

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('name="username"', $body);
        $this->assertStringContainsString('name="password"', $body);
        $this->assertStringContainsString('name="_token"', $body);
    }

    public function test_protected_route_redirects_anonymous_browser_to_login(): void
    {
        $response = $this->handle('GET', '/admin');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaderLine('Location'));
    }

    public function test_protected_route_signals_login_to_htmx(): void
    {
        $response = $this->handle('GET', '/admin', ['HX-Request' => 'true']);

        // A 302 would be followed by fetch and the login page swapped into one
        // element, so the redirect travels as a directive htmx 4 will act on.
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('/login', HtmxResponse::directive($response, 'redirect'));
    }

    public function test_wrong_password_is_rejected_generically_without_logging_in(): void
    {
        $response = $this->handle('POST', '/login', [], [
            'username' => self::USERNAME,
            'password' => 'wrong',
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('match our records', (string) $response->getBody());

        // Still anonymous: the protected route bounces us.
        $bounced = $this->handle('GET', '/admin');
        $this->assertSame(302, $bounced->getStatusCode());
        $this->assertSame('/login', $bounced->getHeaderLine('Location'));
    }

    public function test_empty_fields_show_required_errors(): void
    {
        $response = $this->handle('POST', '/login', [], ['username' => '', 'password' => '']);

        $this->assertSame(422, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('Enter your username.', $body);
        $this->assertStringContainsString('Enter your password.', $body);
        // Bootstrap only reveals .invalid-feedback next to an .is-invalid control.
        $this->assertStringContainsString('id="username" class="form-control is-invalid"', $body);
        $this->assertStringContainsString('<span id="usernameFeedback" class="invalid-feedback">', $body);
    }

    public function test_failed_htmx_login_returns_only_the_form(): void
    {
        $response = $this->handle('POST', '/login', ['HX-Request' => 'true'], [
            'username' => self::USERNAME,
            'password' => 'wrong',
        ]);

        $body = (string) $response->getBody();
        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('match our records', $body);
        // 'credentials' matches no field, so it needs the form-level alert.
        $this->assertStringContainsString('class="alert alert-danger"', $body);
        // The swap target is the form, so the layout must not come with it.
        $this->assertStringNotContainsString('<!doctype html>', $body);
        $this->assertStringStartsWith('<form id="login-form"', trim($body));
    }

    public function test_successful_login_redirects_and_grants_the_protected_page(): void
    {
        $login = $this->handle('POST', '/login', [], [
            'username' => self::USERNAME,
            'password' => self::PASSWORD,
        ]);

        $this->assertSame(302, $login->getStatusCode());
        $this->assertSame('/admin', $login->getHeaderLine('Location'));

        // The admin root names the landing module rather than rendering itself.
        $root = $this->handle('GET', '/admin');
        $this->assertSame(302, $root->getStatusCode());
        $this->assertSame('/admin/dashboard', $root->getHeaderLine('Location'));

        // The session now carries the login, so the guarded page renders.
        $dashboard = $this->handle('GET', '/admin/dashboard');
        $this->assertSame(200, $dashboard->getStatusCode());
        $this->assertStringContainsString(self::USERNAME, (string) $dashboard->getBody());
    }

    public function test_htmx_login_signals_redirect(): void
    {
        $response = $this->handle('POST', '/login', ['HX-Request' => 'true'], [
            'username' => self::USERNAME,
            'password' => self::PASSWORD,
        ]);

        // Not a 204: htmx 4 skips the body of one, and the sign-in button would
        // do nothing at all.
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('/admin', HtmxResponse::directive($response, 'redirect'));
    }

    public function test_logout_ends_the_session(): void
    {
        $this->handle('POST', '/login', [], [
            'username' => self::USERNAME,
            'password' => self::PASSWORD,
        ]);
        $this->assertSame(200, $this->handle('GET', '/admin/dashboard')->getStatusCode());

        $logout = $this->handle('POST', '/logout');
        $this->assertSame(302, $logout->getStatusCode());
        $this->assertSame('/login', $logout->getHeaderLine('Location'));

        // Back to anonymous: the guard bounces the protected route again.
        $bounced = $this->handle('GET', '/admin/dashboard');
        $this->assertSame(302, $bounced->getStatusCode());
        $this->assertSame('/login', $bounced->getHeaderLine('Location'));
    }

    public function test_expired_session_post_redirects_to_login_instead_of403(): void
    {
        // The user loaded a form, their session then expired (or the cookie was
        // cleared), and they submit: the fresh session knows no CSRF token, so
        // the token check fails. A GUEST failing the check must land on the
        // login page — not a bare 403. Build the request by hand to bypass the
        // helper's automatic valid-token header.
        $request = (new Psr17Factory)->createServerRequest('POST', '/login')
            ->withParsedBody([
                'username' => self::USERNAME,
                'password' => self::PASSWORD,
                '_token' => 'token-from-the-expired-session',
            ]);

        $response = $this->container->get(RequestHandlerInterface::class)->handle($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaderLine('Location'));
    }

    public function test_expired_session_htmx_post_signals_login(): void
    {
        $request = (new Psr17Factory)->createServerRequest('POST', '/logout')
            ->withHeader('HX-Request', 'true')
            ->withHeader('X-CSRF-Token', 'token-from-the-expired-session');

        $response = $this->container->get(RequestHandlerInterface::class)->handle($request);

        $this->assertSame('/login', HtmxResponse::directive($response, 'redirect'));
    }

    public function test_authenticated_user_with_bad_token_still_gets403(): void
    {
        // A LIVE session with a wrong token is a real CSRF failure — the
        // redirect policy applies to guests only.
        $this->handle('POST', '/login', [], [
            'username' => self::USERNAME,
            'password' => self::PASSWORD,
        ]);

        $request = (new Psr17Factory)->createServerRequest('POST', '/logout')
            ->withHeader('X-CSRF-Token', 'not-the-real-token');
        $response = $this->container->get(RequestHandlerInterface::class)->handle($request);

        $this->assertSame(403, $response->getStatusCode());
    }
}
