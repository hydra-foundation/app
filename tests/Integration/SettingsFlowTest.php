<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entities\Role;
use App\Repositories\PreferenceRepository;
use App\Tests\Support\TestApp;
use Hydra\Http\Testing\Client;
use Hydra\Http\Testing\TestResponse;
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
    private TestApp $app;

    private Client $http;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
        $this->app->seed('boss', Role::Admin);
        $this->app->seed('clerk', Role::User);

        $this->http = $this->app->http();
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
        return new PreferenceRepository($this->app->db());
    }

    private function id(string $username): int
    {
        $row = $this->app->db()
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
        $this->app->login($username)->assertStatus(302);
    }
}
