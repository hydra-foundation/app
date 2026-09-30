<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entities\Role;
use App\Jobs\SendAddressChangedNotice;
use App\Jobs\SendTwoFactorNotice;
use App\Tests\Support\TestApp;
use Hydra\Admin\Notifications\NotificationStoreInterface;
use Hydra\Mail\Testing\FakeMailer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The account's security notices: mailed as before, and now on the bell too. */
#[CoversClass(SendTwoFactorNotice::class)]
#[CoversClass(SendAddressChangedNotice::class)]
final class SecurityNoticesFlowTest extends TestCase
{
    private TestApp $app;
    private int $user;

    protected function setUp(): void
    {
        $this->app = TestApp::boot();
        $this->user = $this->app->seed('clerk', Role::User);
    }

    /** @return iterable<string, array{array<string, mixed>, string, string|null, string}> */
    public static function twoFactor(): iterable
    {
        yield 'turned on' => [['notice' => SendTwoFactorNotice::ENABLED], 'Two-factor authentication turned on', null, 'two_factor.enabled'];
        yield 'turned off' => [['notice' => SendTwoFactorNotice::DISABLED], 'Two-factor authentication turned off', null, 'two_factor.disabled'];
        yield 'a recovery code used' => [['notice' => SendTwoFactorNotice::RECOVERY_CODE_USED, 'remaining' => 7], 'A recovery code was used', '7 recovery codes left.', 'two_factor.recovery_code_used'];
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('twoFactor')]
    public function test_a_two_factor_notice_is_mailed_and_rings_the_bell(array $payload, string $title, ?string $body, string $kind): void
    {
        $this->app->get(SendTwoFactorNotice::class)->handle(['user' => $this->user, ...$payload]);

        $this->assertCount(1, $this->app->get(FakeMailer::class)->sent());
        [$notice] = $this->app->get(NotificationStoreInterface::class)->latest($this->user, 10);
        $this->assertSame($title, $notice->notice->title);
        $this->assertSame('/admin/settings/security', $notice->notice->url);
        $this->assertSame($kind, $notice->notice->kind);

        if ($body !== null) {
            $this->assertSame($body, $notice->notice->body);
        }
    }

    public function test_an_address_change_is_mailed_to_the_old_address_and_rings_the_bell(): void
    {
        $this->app->get(SendAddressChangedNotice::class)->handle(['user' => $this->user, 'previous' => 'old@example.com']);

        $this->app->get(FakeMailer::class)->assertSentTo('old@example.com');
        [$notice] = $this->app->get(NotificationStoreInterface::class)->latest($this->user, 10);
        $this->assertSame('Your email address changed', $notice->notice->title);
        $this->assertStringContainsString('clerk@', (string) $notice->notice->body);
        $this->assertSame('/admin/settings/account', $notice->notice->url);
        $this->assertSame('account.email_changed', $notice->notice->kind);
    }

    public function test_a_notice_for_a_user_who_is_gone_does_nothing(): void
    {
        $this->app->get(SendTwoFactorNotice::class)->handle(['user' => 999, 'notice' => SendTwoFactorNotice::ENABLED]);

        $this->assertSame([], $this->app->get(FakeMailer::class)->sent());
        $this->assertSame([], $this->app->get(NotificationStoreInterface::class)->latest(999, 10));
    }
}
