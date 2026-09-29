<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Auth\SignInWindow;
use DateTimeImmutable;
use Hydra\Core\Testing\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** How recently a sign-in must have been seen to still be one. */
#[CoversClass(SignInWindow::class)]
final class SignInWindowTest extends TestCase
{
    public function test_live_means_seen_within_the_idle_limit_and_a_minute(): void
    {
        $window = new SignInWindow(new FrozenClock('2026-09-29 10:00:00'), idleSeconds: 1440);

        $this->assertEquals(new DateTimeImmutable('2026-09-29 09:35:00'), $window->since());
    }

    public function test_the_idle_limit_is_phps_unless_one_is_given(): void
    {
        $window = new SignInWindow(new FrozenClock('2026-09-29 10:00:00'));
        $idle = (int) ini_get('session.gc_maxlifetime');

        $this->assertEquals(
            (new DateTimeImmutable('2026-09-29 10:00:00'))->modify('-' . ($idle + 60) . ' seconds'),
            $window->since(),
        );
    }
}
