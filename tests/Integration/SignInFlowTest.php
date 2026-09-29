<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entities\Role;
use App\Tests\Support\TestApp;
use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\HasherInterface;
use Hydra\Auth\Contracts\SignInStoreInterface;
use Hydra\Auth\Contracts\UserProviderInterface;
use Hydra\Auth\SessionGuard;
use Hydra\Auth\SignIn;
use Hydra\Http\Testing\Client;
use Hydra\Session\Contracts\SessionInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Every sign-in is a row: written at login with where it came from, checked
 * on every request after, and gone when it is signed out. Deleting the row is
 * how a sign-in is revoked. "The next request" is {@see TestApp::nextGuard()},
 * since the app's own guard holds its user for the whole test.
 */
#[CoversNothing]
final class SignInFlowTest extends TestCase
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

    public function test_signing_in_is_recorded_with_where_it_came_from(): void
    {
        $this->http->from('203.0.113.7')
            ->post('/login', ['username' => 'clerk', 'password' => TestApp::PASSWORD], ['User-Agent' => 'Firefox/140'])
            ->assertStatus(302);

        $signIns = $this->signIns(2);
        $this->assertCount(1, $signIns);
        $this->assertSame('203.0.113.7', $signIns[0]->ip);
        $this->assertSame('Firefox/140', $signIns[0]->userAgent);
    }

    public function test_the_next_request_is_still_signed_in(): void
    {
        $this->app->login('clerk')->assertStatus(302);

        $this->assertSame(2, $this->app->nextGuard()->user()?->getAuthIdentifier());
        $this->assertSame($this->signIns(2)[0]->id, $this->app->nextGuard()->signIn()?->id);
    }

    public function test_deleting_the_row_signs_that_browser_out(): void
    {
        $this->app->login('clerk')->assertStatus(302);

        $this->app->db()->execute('DELETE FROM sign_ins WHERE user_id = 2');

        $this->assertNull($this->app->nextGuard()->user());
    }

    public function test_revoking_one_sign_in_leaves_the_others(): void
    {
        $this->app->login('clerk')->assertStatus(302);
        $other = $this->app->get(SignInStoreInterface::class)->create(str_repeat('b', 32), $this->user(2), new \DateTimeImmutable);

        $this->app->get(SignInStoreInterface::class)->revoke($other->id);

        $this->assertSame(2, $this->app->nextGuard()->user()?->getAuthIdentifier());
    }

    public function test_signing_out_removes_the_row(): void
    {
        $this->app->login('clerk')->assertStatus(302);

        $this->http->post('/logout')->assertStatus(302);

        $this->assertSame([], $this->signIns(2));
    }

    /** The deploy that adds the table must sign nobody out. */
    public function test_a_session_signed_in_before_the_table_existed_is_adopted(): void
    {
        $session = $this->app->get(SessionInterface::class);
        $session->start();
        (new SessionGuard($session, $this->app->get(UserProviderInterface::class), $this->app->get(HasherInterface::class)))
            ->login($this->user(2));
        $this->assertSame([], $this->signIns(2));

        $this->assertSame(2, $this->app->nextGuard()->user()?->getAuthIdentifier());
        $this->assertCount(1, $this->signIns(2));
        $this->assertSame(2, $this->app->nextGuard()->user()?->getAuthIdentifier());
        $this->assertCount(1, $this->signIns(2));
    }

    public function test_changing_your_password_keeps_this_sign_in_and_ends_the_others(): void
    {
        $elsewhere = $this->app->get(SignInStoreInterface::class)->create(str_repeat('e', 32), $this->user(2), new \DateTimeImmutable);
        $this->app->login('clerk')->assertStatus(302);

        $this->http->post('/admin/settings/account', [
            'current_password' => TestApp::PASSWORD,
            'password' => 'a-brand-new-passphrase',
            'password_confirmation' => 'a-brand-new-passphrase',
        ])->assertOk();

        $left = $this->signIns(2);
        $this->assertCount(1, $left);
        $this->assertNotSame($elsewhere->id, $left[0]->id);
        $this->assertSame(2, $this->app->nextGuard()->user()?->getAuthIdentifier());
    }

    /** @return list<SignIn> */
    private function signIns(int $id): array
    {
        return $this->app->get(SignInStoreInterface::class)->forUser($this->user($id));
    }

    private function user(int $id): AuthenticatableInterface
    {
        return $this->app->get(UserProviderInterface::class)->byIdentifier($id) ?? throw new RuntimeException('not seeded');
    }
}
