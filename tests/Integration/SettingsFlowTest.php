<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Providers\AppServiceProvider;
use App\Repositories\PreferenceRepository;
use App\Tests\Support\ArraySessionServiceProvider;
use App\Tests\Support\FixedSignerServiceProvider;
use App\Tests\Support\TestAdminProvider;
use App\Tests\Support\TestHttpProvider;
use App\Tests\Support\TestSchema;
use Hydra\Auth\AuthConfig;
use Hydra\Auth\AuthServiceProvider;
use Hydra\Auth\Contracts\HasherInterface;
use Hydra\Authorization\AuthorizationServiceProvider;
use Hydra\Core\Application;
use Hydra\Core\Contracts\ContainerInterface;
use Hydra\Core\Environment;
use Hydra\Csrf\CsrfGuard;
use Hydra\Database\Contracts\ConnectionInterface;
use Hydra\Database\PdoConnection;
use Hydra\Nyholm\NyholmServiceProvider;
use Hydra\PhpDi\Container;
use Hydra\Session\Contracts\SessionLifecycleInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The settings module end-to-end: categories that are screens rather than tab
 * panes, a preference that belongs to one person, and a theme that has to reach
 * the <html> element a frame swap cannot touch.
 */
final class SettingsFlowTest extends TestCase
{
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
            ->register(new FixedSignerServiceProvider)
            ->register(TestHttpProvider::make())
            ->register(new AuthServiceProvider)
            ->register(new AuthorizationServiceProvider)
            ->register(new AppServiceProvider)
            ->register(TestAdminProvider::make())
            ->boot();

        $container->instance(AuthConfig::class, new AuthConfig(hashCost: 4));

        $pdo = TestSchema::connect();
        $container->instance(ConnectionInterface::class, new PdoConnection($pdo));

        $hash = $container->get(HasherInterface::class)->hash(self::PASSWORD);
        $insert = $pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)');
        $insert->execute(['boss', $hash, 'admin']);
        $insert->execute(['clerk', $hash, 'user']);

        $this->container = $container;
    }

    public function test_settings_are_reachable_by_anyone_signed_in(): void
    {
        // A person's own preferences are not an admin function; the modules that
        // read other people's rows are the ones that require the role.
        $this->login('clerk');

        $this->assertSame(200, $this->handle('GET', '/admin/settings')->getStatusCode());
        $this->assertSame(200, $this->handle('GET', '/admin/settings/appearance')->getStatusCode());
    }

    public function test_a_category_is_a_screen_of_its_own(): void
    {
        $this->login('boss');
        $body = $this->body('GET', '/admin/settings/appearance');

        // Linkable and steppable: the crumb trail names it and the strip is
        // links, so the back button works between categories.
        $this->assertStringContainsString('Admin / Application / Settings / Appearance', $this->crumbs($body));
        $this->assertStringContainsString('href="/admin/settings"', $body);
        $this->assertStringContainsString('class="settings-tab active"', $body);
    }

    public function test_the_picker_offers_every_palette_on_disk(): void
    {
        $this->login('boss');
        $body = $this->body('GET', '/admin/settings/appearance');

        foreach (['paper', 'graphite'] as $theme) {
            $this->assertStringContainsString('value="' . $theme . '"', $body);
        }

        // Paper is nobody's saved choice yet; it is the fallback, and the
        // picker has to agree with the page it is rendered on.
        $this->assertMatchesRegularExpression('~value="paper"\s+checked~', $body);
    }

    public function test_choosing_a_theme_keeps_it(): void
    {
        $this->login('boss');
        $this->assertSame(200, $this->save('graphite')->getStatusCode());

        $this->assertSame('graphite', $this->preferences()->get($this->id('boss'), 'theme'));
        $this->assertStringContainsString('data-theme="graphite"', $this->body('GET', '/admin/settings'));
    }

    public function test_the_saved_fragment_carries_the_palette_the_page_must_repaint_in(): void
    {
        $this->login('boss');

        // Only #admin-frame is swapped and data-theme lives on <html>, so the
        // fragment has to declare the new palette for the page to follow it.
        $frame = (string) $this->save('graphite', frame: true)->getBody();

        $this->assertStringContainsString('id="admin-theme" data-theme="graphite"', $frame);
        $this->assertStringNotContainsString('<!doctype html>', $frame);
        $this->assertStringContainsString('Saved', $frame);
    }

    public function test_a_theme_that_is_not_on_disk_is_refused(): void
    {
        $this->login('boss');
        $this->save('graphite');

        $response = $this->save('../../etc/passwd');

        // Accepting it would leave the page unstyled and the setting stuck on a
        // palette no stylesheet answers for.
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('graphite', $this->preferences()->get($this->id('boss'), 'theme'));
    }

    public function test_a_preference_belongs_to_one_person(): void
    {
        $this->login('boss');
        $this->save('graphite');

        $this->container->get(SessionLifecycleInterface::class)->start();
        $this->handle('POST', '/logout');
        $this->login('clerk');

        $this->assertStringContainsString('data-theme="paper"', $this->body('GET', '/admin/settings'));
    }

    public function test_a_signed_out_page_takes_the_fallback(): void
    {
        // Nobody to ask. The login screen still has to be styled.
        $this->assertStringContainsString('data-theme="paper"', $this->body('GET', '/login'));
    }

    private function save(string $theme, bool $frame = false): ResponseInterface
    {
        $headers = $frame ? ['HX-Request' => 'true', 'HX-Target' => 'div#admin-frame'] : [];

        return $this->handle('POST', '/admin/settings/appearance', $headers, ['theme' => $theme]);
    }

    private function preferences(): PreferenceRepository
    {
        return new PreferenceRepository($this->container->get(ConnectionInterface::class));
    }

    private function id(string $username): int
    {
        $row = $this->container->get(ConnectionInterface::class)
            ->selectOne('SELECT id FROM users WHERE username = ?', [$username]);

        return (int) $row['id'];
    }

    /** The breadcrumb trail as a visitor reads it. */
    private function crumbs(string $body): string
    {
        preg_match_all('~<li class="breadcrumb-item[^"]*">(.*?)</li>~s', $body, $matches);

        return implode(' / ', array_map(
            static fn (string $crumb): string => trim(strip_tags($crumb)),
            $matches[1],
        ));
    }

    /** @param array<string, string> $headers */
    private function body(string $method, string $path, array $headers = []): string
    {
        $response = $this->handle($method, $path, $headers);
        $this->assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }

    /**
     * @param array<string, string>     $headers
     * @param array<string, mixed>|null $body
     */
    private function handle(string $method, string $path, array $headers = [], ?array $body = null): ResponseInterface
    {
        $request = (new Psr17Factory)->createServerRequest($method, $path);

        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
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

    private function login(string $username): void
    {
        $response = $this->handle('POST', '/login', [], [
            'username' => $username,
            'password' => self::PASSWORD,
        ]);

        $this->assertSame(302, $response->getStatusCode());
    }
}
