<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Providers\AppServiceProvider;
use App\Repositories\PreferenceRepository;
use Hydra\Cache\Testing\ArrayCacheServiceProvider;
use Hydra\Session\Testing\ArraySessionServiceProvider;
use Hydra\Core\Testing\FixedSignerServiceProvider;
use Hydra\Log\Testing\CapturingLogger;
use Psr\Log\LoggerInterface;
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
use Hydra\Csrf\Testing\CarriesCsrfToken;
use Hydra\Database\Contracts\ConnectionInterface;
use Hydra\Database\PdoConnection;
use Hydra\Http\Testing\Client;
use Hydra\Http\Testing\TestResponse;
use Hydra\Nyholm\NyholmServiceProvider;
use Hydra\PhpDi\Container;
use Hydra\Throttle\ThrottleServiceProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The settings module end-to-end: categories that are screens rather than tab
 * panes, a preference that belongs to one person, and a theme that has to reach
 * the <html> element a frame swap cannot touch.
 */
#[CoversNothing]
final class SettingsFlowTest extends TestCase
{
    private const PASSWORD = 'correct-horse-battery-staple';

    private ContainerInterface $container;

    private Client $http;

    protected function setUp(): void
    {
        $container = Container::create();
        $container->instance(ContainerInterface::class, $container);
        $container->instance(Environment::class, new Environment(__DIR__));

        $app = (new Application($container))
            ->register(new ArraySessionServiceProvider)
            ->register(new NyholmServiceProvider)
            ->register(new FixedSignerServiceProvider)
            ->register(new ArrayCacheServiceProvider)
            ->register(new ThrottleServiceProvider)
            ->register(TestHttpProvider::make())
            ->register(new AuthServiceProvider)
            ->register(new AuthorizationServiceProvider)
            ->register(new AppServiceProvider)
            ->register(TestAdminProvider::make());

        // Before boot(), not after: boot() builds its listeners with whatever
        // LoggerInterface resolves to, so a logger swapped in afterwards hears
        // nothing. In memory rather than stderr, because the real pipeline logs
        // one line per request and those land in the middle of PHPUnit's own
        // output, where they read as failures that are not failures.
        $container->instance(LoggerInterface::class, new CapturingLogger);

        $app->boot();

        $container->instance(AuthConfig::class, new AuthConfig(hashCost: 4));

        $pdo = TestSchema::connect();
        $container->instance(ConnectionInterface::class, new PdoConnection($pdo));

        $hash = $container->get(HasherInterface::class)->hash(self::PASSWORD);
        $insert = $pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)');
        $insert->execute(['boss', $hash, 'admin']);
        $insert->execute(['clerk', $hash, 'user']);

        $this->container = $container;
        $this->http = Client::for($container, [CarriesCsrfToken::for($container)]);
    }

    public function test_settings_are_reachable_by_anyone_signed_in(): void
    {
        // A person's own preferences are not an admin function; the modules that
        // read other people's rows are the ones that require the role.
        $this->login('clerk');

        $this->http->get('/admin/settings')->assertOk();
        $this->http->get('/admin/settings/appearance')->assertOk();
    }

    public function test_a_category_is_a_screen_of_its_own(): void
    {
        $this->login('boss');
        $body = $this->body('/admin/settings/appearance');

        // Linkable and steppable: the crumb trail names it and the strip is
        // links, so the back button works between categories.
        $this->assertStringContainsString('Admin / Application / Settings / Appearance', $this->crumbs($body));
        $this->assertStringContainsString('href="/admin/settings"', $body);
        $this->assertStringContainsString('class="settings-tab active"', $body);
    }

    public function test_the_picker_offers_every_palette_on_disk(): void
    {
        $this->login('boss');
        $body = $this->body('/admin/settings/appearance');

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
        $this->save('graphite')->assertOk();

        $this->assertSame('graphite', $this->preferences()->get($this->id('boss'), 'theme'));
        $this->assertStringContainsString('data-theme="graphite"', $this->body('/admin/settings'));
    }

    public function test_the_saved_fragment_carries_the_palette_the_page_must_repaint_in(): void
    {
        $this->login('boss');

        // Only #admin-frame is swapped and data-theme lives on <html>, so the
        // fragment has to declare the new palette for the page to follow it.
        $this->save('graphite', frame: true)
            ->assertSee('id="admin-theme" data-theme="graphite"')
            ->assertFragment()
            ->assertSee('Saved');
    }

    public function test_a_theme_that_is_not_on_disk_is_refused(): void
    {
        $this->login('boss');
        $this->save('graphite');

        // Accepting it would leave the page unstyled and the setting stuck on a
        // palette no stylesheet answers for.
        $this->save('../../etc/passwd')->assertStatus(422);
        $this->assertSame('graphite', $this->preferences()->get($this->id('boss'), 'theme'));
    }

    public function test_a_preference_belongs_to_one_person(): void
    {
        $this->login('boss');
        $this->save('graphite');

        $this->http->post('/logout')->assertRedirect('/login');
        $this->login('clerk');

        $this->assertStringContainsString('data-theme="paper"', $this->body('/admin/settings'));
    }

    public function test_a_signed_out_page_takes_the_fallback(): void
    {
        // Nobody to ask. The login screen still has to be styled.
        $this->assertStringContainsString('data-theme="paper"', $this->body('/login'));
    }

    private function save(string $theme, bool $frame = false): TestResponse
    {
        $http = $frame ? $this->http->htmx('div#admin-frame') : $this->http;

        return $http->post('/admin/settings/appearance', ['theme' => $theme]);
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

    private function body(string $path): string
    {
        return $this->http->get($path)->assertOk()->body();
    }

    private function login(string $username): void
    {
        $this->http->post('/login', ['username' => $username, 'password' => self::PASSWORD])->assertStatus(302);
    }
}
