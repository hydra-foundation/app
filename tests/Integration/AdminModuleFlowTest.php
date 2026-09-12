<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Providers\AppServiceProvider;
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
use Hydra\Http\HtmxResponse;
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
 * The users admin module end-to-end: the route it compiles to, the ability it
 * declares, and the three depths htmx can ask a screen to render at.
 */
final class AdminModuleFlowTest extends TestCase
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

        // Enough extra rows to push the 15-per-page module onto a second page.
        foreach (range(1, 20) as $n) {
            $insert->execute([sprintf('temp%02d', $n), $hash, 'user']);
        }

        $this->container = $container;
    }

    public function test_the_module_compiles_to_a_route_that_anonymous_visitors_cannot_reach(): void
    {
        $response = $this->handle('GET', '/admin/users');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaderLine('Location'));
    }

    public function test_a_module_ability_keeps_signed_in_non_admins_out(): void
    {
        $this->login('clerk');

        $this->assertSame(403, $this->handle('GET', '/admin/users')->getStatusCode());
    }

    public function test_an_admin_sees_the_first_page_of_the_table(): void
    {
        $this->login('boss');
        $body = $this->body('GET', '/admin/users');

        $this->assertStringContainsString('<title>Users · Admin</title>', $body);
        $this->assertStringContainsString('id="admin-frame"', $body);
        $this->assertStringContainsString('id="admin-body"', $body);
        $this->assertSame(['Admin', 'Administration', 'Users'], $this->crumbs($body));
        $this->assertStringContainsString('Showing 1–15 of 22', $body);
        $this->assertStringContainsString('>temp20</td>', $body);
        $this->assertStringNotContainsString('password_hash', $body);
    }

    public function test_a_create_screen_opens_a_blank_form(): void
    {
        $this->login('boss');
        $body = $this->body('GET', '/admin/users/new');

        $this->assertStringContainsString('<title>New user · Admin</title>', $body);
        $this->assertSame(['Admin', 'Administration', 'Users', 'New'], $this->crumbs($body));
        $this->assertMatchesRegularExpression('/breadcrumb-item active">\s*New\s*<\/li>/', $body);
        $this->assertStringContainsString('hx-post="/admin/users/new"', $body);
        $this->assertStringContainsString('value=""', $body);
        // Apply saves and stays; a row that does not exist yet has nowhere to stay.
        $this->assertStringNotContainsString('value="apply"', $body);
    }

    public function test_a_create_screen_writes_the_row_and_opens_it(): void
    {
        $this->login('boss');
        $response = $this->handle('POST', '/admin/users/new', [], [
            'username' => 'newcomer',
            'role' => 'user',
            'password' => 'correct-horse',
        ]);

        // 22 seeded rows, so the row just written is 23: the id create() returned.
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/users/23', $response->getHeaderLine('Location'));
        $this->assertStringContainsString('>newcomer</td>', $this->body('GET', '/admin/users?q=newcomer'));
    }

    public function test_an_htmx_create_hands_back_the_row_it_wrote(): void
    {
        $this->login('boss');
        $response = $this->handle('POST', '/admin/users/new', ['HX-Request' => 'true', 'HX-Target' => 'div#admin-frame'], [
            'username' => 'newcomer',
            'role' => 'user',
            'password' => 'correct-horse',
        ]);
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('/admin/users/23', HtmxResponse::directive($response, 'push-url'));
        $this->assertStringContainsString('Created', $body);
        // The show screen for that row, not the table it is one line of.
        $this->assertStringContainsString('>newcomer</dd>', $body);
        $this->assertStringContainsString('hx-get="/admin/users/23/edit"', $body);
    }

    public function test_a_create_screen_can_require_what_the_edit_screen_leaves_optional(): void
    {
        $this->login('boss');
        $response = $this->handle('POST', '/admin/users/new', [], [
            'username' => 'newcomer',
            'role' => 'user',
            'password' => '',
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Set a password.', (string) $response->getBody());
        $this->assertStringContainsString('Nothing to show.', $this->body('GET', '/admin/users?q=newcomer'));
    }

    public function test_the_source_rejects_a_name_another_row_already_holds(): void
    {
        $this->login('boss');
        $response = $this->handle('POST', '/admin/users/new', [], [
            'username' => 'clerk',
            'role' => 'user',
            'password' => 'correct-horse',
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('already taken', (string) $response->getBody());
    }

    public function test_the_list_offers_the_way_to_a_new_row(): void
    {
        $this->login('boss');

        $this->assertStringContainsString('hx-get="/admin/users/new"', $this->body('GET', '/admin/users'));
    }

    public function test_a_show_screen_reads_one_row(): void
    {
        $this->login('boss');
        $body = $this->body('GET', '/admin/users/1');

        $this->assertStringContainsString('<title>User · Admin</title>', $body);
        $this->assertSame(['Admin', 'Administration', 'Users', '1'], $this->crumbs($body));
        $this->assertStringContainsString('>boss</dd>', $body);
        $this->assertStringContainsString('>Admin</dd>', $body);
        $this->assertStringNotContainsString('password_hash', $body);
        $this->assertStringContainsString('hx-get="/admin/users/1/edit"', $body);
    }

    public function test_a_show_screen_is_a_404_when_nothing_has_that_id(): void
    {
        $this->login('boss');

        $this->assertSame(404, $this->handle('GET', '/admin/users/999')->getStatusCode());
    }

    public function test_the_table_offers_a_way_into_each_row(): void
    {
        $this->login('boss');
        $body = $this->body('GET', '/admin/users');

        // One of each per row, for the 15 rows this page holds.
        $this->assertSame(15, substr_count($body, '>View</a>'));
        $this->assertSame(15, substr_count($body, '>Edit</a>'));
        $this->assertStringContainsString('hx-get="/admin/users/22"', $body);
    }

    public function test_a_delete_removes_the_row_and_returns_to_the_list(): void
    {
        $this->login('boss');
        $response = $this->handle('POST', '/admin/users/2/delete');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/users', $response->getHeaderLine('Location'));
        $this->assertStringContainsString('Nothing to show.', $this->body('GET', '/admin/users?q=clerk'));
    }

    public function test_an_htmx_delete_hands_back_the_list_it_would_have_fetched(): void
    {
        $this->login('boss');
        $response = $this->handle('POST', '/admin/users/2/delete', [
            'HX-Request' => 'true',
            'HX-Target' => 'div#admin-frame',
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('/admin/users', HtmxResponse::directive($response, 'push-url'));
        $this->assertStringContainsString('Deleted', (string) $response->getBody());
        $this->assertStringNotContainsString('>clerk</td>', (string) $response->getBody());
    }

    public function test_a_delete_comes_back_to_the_view_of_the_list_it_was_made_from(): void
    {
        $this->login('boss');
        $response = $this->handle('POST', '/admin/users/2/delete', [
            'HX-Request' => 'true',
            'HX-Target' => 'div#admin-frame',
            'HX-Current-URL' => 'http://localhost/admin/users?q=temp&sort=username&dir=asc&page=2',
        ]);
        $body = (string) $response->getBody();

        $this->assertSame(
            '/admin/users?q=temp&sort=username&dir=asc&page=2',
            urldecode((string) HtmxResponse::directive($response, 'push-url')),
        );
        // The search it came back to is the search it was sent from.
        $this->assertStringContainsString('value="temp"', $body);
        $this->assertStringContainsString('>temp16</td>', $body);
        $this->assertStringNotContainsString('>temp01</td>', $body);
    }

    public function test_a_delete_that_empties_the_last_page_falls_back_to_the_new_end(): void
    {
        $this->login('boss');
        // 22 rows, 15 to a page: page 2 holds seven, and one search holds one.
        $response = $this->handle('POST', '/admin/users/22/delete', [
            'HX-Request' => 'true',
            'HX-Target' => 'div#admin-frame',
            'HX-Current-URL' => 'http://localhost/admin/users?q=temp20&page=2',
        ]);

        // The page it was on is gone; the rest of the view it was asked for is not.
        $this->assertSame(
            '/admin/users?q=temp20&sort=id&dir=desc',
            urldecode((string) HtmxResponse::directive($response, 'push-url')),
        );
        $this->assertStringContainsString('Nothing to show.', (string) $response->getBody());
    }

    public function test_a_write_sent_without_htmx_lands_on_the_modules_own_view(): void
    {
        $this->login('boss');
        $response = $this->handle('POST', '/admin/users/2/delete');

        $this->assertSame('/admin/users', $response->getHeaderLine('Location'));
    }

    public function test_the_source_refuses_to_delete_the_account_doing_the_deleting(): void
    {
        $this->login('boss');
        $response = $this->handle('POST', '/admin/users/1/delete');
        $body = (string) $response->getBody();

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('alert-danger', $body);
        $this->assertStringContainsString('You cannot delete the account you are signed in as.', $body);
        // A refusal is not a redirect: the row it refused to remove is still there.
        $this->assertStringContainsString('>boss</td>', $this->body('GET', '/admin/users?q=boss'));
    }

    public function test_a_delete_screen_is_a_post_or_it_is_nothing(): void
    {
        $this->login('boss');

        // The path is real, the method is not: anything that crawls links gets 405.
        $this->assertSame(405, $this->handle('GET', '/admin/users/2/delete')->getStatusCode());
    }

    public function test_both_the_table_and_the_show_screen_offer_a_way_to_remove_a_row(): void
    {
        $this->login('boss');

        $list = $this->body('GET', '/admin/users');
        $this->assertSame(15, substr_count($list, '>Delete</button>'));
        $this->assertStringContainsString('hx-post="/admin/users/22/delete"', $list);
        $this->assertStringContainsString('hx-confirm="Delete this user? This cannot be undone."', $list);

        $show = $this->body('GET', '/admin/users/2');
        $this->assertStringContainsString('hx-post="/admin/users/2/delete"', $show);
    }

    public function test_an_edit_screen_hangs_below_its_module(): void
    {
        $this->login('boss');
        $body = $this->body('GET', '/admin/users/1/edit');

        $this->assertStringContainsString('<title>Edit user · Admin</title>', $body);
        $this->assertSame(['Admin', 'Administration', 'Users', 'Edit 1'], $this->crumbs($body));
        $this->assertStringContainsString('>Users</a>', $body);
        $this->assertStringContainsString('Edit 1', $body);
    }

    public function test_a_breadcrumb_link_swaps_the_frame_rather_than_reloading(): void
    {
        $this->login('boss');
        $body = $this->body('GET', '/admin/users/1/edit');

        $this->assertStringContainsString('hx-get="/admin/users"', $body);
        $this->assertStringContainsString('hx-get="/admin/dashboard"', $body);
        $this->assertStringNotContainsString('hx-get="/admin"', $body);
    }

    public function test_the_admin_root_redirects_to_the_landing_module(): void
    {
        $this->login('boss');
        $response = $this->handle('GET', '/admin');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/dashboard', $response->getHeaderLine('Location'));
    }

    public function test_a_page_module_needs_no_source_and_is_open_to_any_signed_in_user(): void
    {
        $this->login('clerk');
        $body = $this->body('GET', '/admin/dashboard');

        $this->assertStringContainsString('Dashboard', $body);
        $this->assertStringContainsString('Newest accounts', $body);
        $this->assertStringContainsString('Signed in as <strong>clerk</strong>', $body);
        $this->assertSame(['Admin', 'Overview', 'Dashboard'], $this->crumbs($body));
    }

    public function test_the_presenter_supplies_the_page_its_numbers(): void
    {
        $this->login('boss');
        $body = $this->body('GET', '/admin/dashboard');

        // 22 seeded accounts, one of them an admin. One tile per role, labelled
        // from the enum, so the plain-user tile carries the other 21.
        $this->assertMatchesRegularExpression('/Users<\/div>\s*<div class="stat-value">22</', $body);
        $this->assertMatchesRegularExpression('/Admin<\/div>\s*<div class="stat-value">1</', $body);
        $this->assertMatchesRegularExpression('/User<\/div>\s*<div class="stat-value">21</', $body);
    }

    public function test_the_sidebar_shows_only_the_modules_the_visitor_may_reach(): void
    {
        $this->login('clerk');
        $body = $this->body('GET', '/admin/dashboard');

        $this->assertStringContainsString('/admin/dashboard', $body);
        $this->assertStringNotContainsString('/admin/users', $body);
    }

    public function test_a_path_below_a_module_with_no_screen_there_is_not_a_route(): void
    {
        $this->login('boss');

        $this->assertSame(404, $this->handle('GET', '/admin/dashboard/trends')->getStatusCode());
    }

    public function test_an_unknown_slug_under_the_prefix_is_not_a_route(): void
    {
        $this->login('boss');

        $this->assertSame(404, $this->handle('GET', '/admin/invoices')->getStatusCode());
    }

    public function test_search_filter_and_sort_travel_in_the_query_string(): void
    {
        $this->login('boss');

        $searched = $this->body('GET', '/admin/users?q=clerk');
        $this->assertStringContainsString('Showing 1–1 of 1', $searched);
        $this->assertStringContainsString('clerk', $searched);

        $filtered = $this->body('GET', '/admin/users?role=admin');
        $this->assertStringContainsString('Showing 1–1 of 1', $filtered);

        $sorted = $this->body('GET', '/admin/users?sort=username&dir=asc');
        $this->assertLessThan(strpos($sorted, '>clerk<'), strpos($sorted, '>boss<'));
    }

    /**
     * Criteria::searchPattern() escapes the term's own wildcards so a search
     * cannot ask for a full scan, and the LIKE has to name the escape character
     * for that to mean anything: SQLite assumes none, so without the ESCAPE
     * clause the backslash is matched literally and an underscore, ordinary in a
     * username, finds nothing.
     */
    public function test_a_search_term_containing_a_wildcard_matches_it_literally(): void
    {
        $this->login('boss');

        $this->handle('POST', '/admin/users/new', [], [
            'username' => 'ada_lovelace',
            'role' => 'user',
            'password' => 'correct-horse',
        ]);

        $found = $this->body('GET', '/admin/users?q=ada_lovelace');
        $this->assertStringContainsString('>ada_lovelace</td>', $found);
        $this->assertStringContainsString('Showing 1–1 of 1', $found);

        // The other half of the same guard: a bare wildcard is a search for the
        // character, not a request for every row in the table.
        $this->assertStringContainsString('Nothing to show.', $this->body('GET', '/admin/users?q=%25'));
    }

    public function test_an_undeclared_sort_column_is_ignored(): void
    {
        $this->login('boss');

        $this->assertSame(200, $this->handle('GET', '/admin/users?sort=password_hash')->getStatusCode());
    }

    public function test_the_second_page_is_its_own_url(): void
    {
        $this->login('boss');

        $this->assertStringContainsString('Showing 16–22 of 22', $this->body('GET', '/admin/users?page=2'));
    }

    public function test_htmx_swaps_only_the_body_when_the_table_is_targeted(): void
    {
        $this->login('boss');
        $body = $this->body('GET', '/admin/users?page=2', ['HX-Request' => 'true', 'HX-Target' => 'div#admin-body']);

        $this->assertStringNotContainsString('<html', $body);
        $this->assertStringNotContainsString('admin-sidebar', $body);
        $this->assertStringNotContainsString('breadcrumb', $body);
        $this->assertStringContainsString('Showing 16–22 of 22', $body);
    }

    public function test_htmx_swaps_the_frame_when_the_sidebar_navigates(): void
    {
        $this->login('boss');
        $body = $this->body('GET', '/admin/users', ['HX-Request' => 'true', 'HX-Target' => 'div#admin-frame']);

        $this->assertStringNotContainsString('<html', $body);
        $this->assertStringNotContainsString('admin-sidebar', $body);
        $this->assertStringContainsString('breadcrumb', $body);
        $this->assertStringContainsString('id="admin-body"', $body);
    }

    public function test_a_frame_swap_carries_the_title_and_an_out_of_band_sidebar(): void
    {
        $this->login('boss');
        $body = $this->body('GET', '/admin/users', ['HX-Request' => 'true', 'HX-Target' => 'div#admin-frame']);

        // htmx reads the title out of a head block and applies it to the tab.
        $this->assertStringContainsString('<head><title>Users · Admin</title></head>', $body);

        // The sidebar lives outside the swapped frame, so the active item rides
        // along out of band.
        $this->assertStringContainsString('id="admin-nav" hx-swap-oob="true"', $body);
        $this->assertMatchesRegularExpression('/nav-link active"\s+href="\/admin\/users"/', $body);
        $this->assertMatchesRegularExpression('/nav-link"\s+href="\/admin\/dashboard"/', $body);
    }

    public function test_a_body_swap_leaves_the_sidebar_alone(): void
    {
        $this->login('boss');
        $body = $this->body('GET', '/admin/users?page=2', ['HX-Request' => 'true', 'HX-Target' => 'div#admin-body']);

        $this->assertStringNotContainsString('hx-swap-oob', $body);
        $this->assertStringNotContainsString('<head>', $body);
    }

    public function test_an_htmx_request_with_no_known_target_still_renders_the_whole_page(): void
    {
        $this->login('boss');
        $body = $this->body('GET', '/admin/users', ['HX-Request' => 'true', 'HX-Target' => 'div#somewhere-else']);

        $this->assertStringContainsString('<html', $body);
        $this->assertStringContainsString('admin-sidebar', $body);
    }

    public function test_a_body_swap_carries_the_sort_state_the_toolbar_reaches_for(): void
    {
        $this->login('boss');

        $full = $this->body('GET', '/admin/users');
        $this->assertStringContainsString('hx-include="#admin-sort-state"', $full);
        $this->assertSame(1, substr_count($full, 'name="sort"'));

        $sorted = $this->body('GET', '/admin/users?sort=username&dir=asc', [
            'HX-Request' => 'true',
            'HX-Target' => 'div#admin-body',
        ]);
        $this->assertStringContainsString('id="admin-sort-state"', $sorted);
        $this->assertStringContainsString('name="sort" value="username"', $sorted);
        $this->assertStringContainsString('name="dir" value="asc"', $sorted);
    }

    /**
     * The trail as a visitor reads it, link or not: a group contributes a crumb
     * with no page behind it.
     *
     * @return list<string>
     */
    private function crumbs(string $body): array
    {
        preg_match_all('~<li class="breadcrumb-item[^"]*">(.*?)</li>~s', $body, $matches);

        return array_map(
            static fn (string $crumb): string => trim(strip_tags($crumb)),
            $matches[1],
        );
    }

    /** @param array<string, string> $headers */
    private function body(string $method, string $path, array $headers = []): string
    {
        $response = $this->handle($method, $path, $headers);
        $this->assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }

    /**
     * @param array<string, string> $headers
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
