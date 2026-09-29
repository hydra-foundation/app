<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Tasks\PruneSignIns;
use DateTimeImmutable;
use Hydra\Auth\Testing\ArraySignInStore;
use Hydra\Auth\Testing\FakeUser;
use Hydra\Core\Testing\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A sign-in idle longer than PHP keeps a session is one whose session may
 * already be gone; past that, it is deleted, so a browser coming back is signed
 * out whenever the collector last ran. A minute of slack covers the once-a-minute
 * write of "last seen".
 */
#[CoversClass(PruneSignIns::class)]
final class PruneSignInsTest extends TestCase
{
    public function test_a_sign_in_idle_past_the_session_lifetime_and_a_minute_is_deleted(): void
    {
        $store = new ArraySignInStore;
        $store->create(str_repeat('a', 32), new FakeUser(1), new DateTimeImmutable('2026-09-29 09:34:59'));
        $store->create(str_repeat('b', 32), new FakeUser(1), new DateTimeImmutable('2026-09-29 09:35:00'));

        (new PruneSignIns($store, new FrozenClock('2026-09-29 10:00:00'), idleSeconds: 1440))->run();

        $this->assertNull($store->find(str_repeat('a', 32)));
        $this->assertNotNull($store->find(str_repeat('b', 32)), 'seen 25 minutes ago exactly: kept');
    }

    public function test_the_lifetime_is_phps_own_unless_one_is_given(): void
    {
        $store = new ArraySignInStore;
        $lifetime = (int) ini_get('session.gc_maxlifetime');
        $now = new DateTimeImmutable('2026-09-29 10:00:00');
        $store->create(str_repeat('a', 32), new FakeUser(1), $now->modify('-' . ($lifetime + 61) . ' seconds'));
        $store->create(str_repeat('b', 32), new FakeUser(1), $now->modify('-' . ($lifetime + 60) . ' seconds'));

        (new PruneSignIns($store, new FrozenClock($now)))->run();

        $this->assertNull($store->find(str_repeat('a', 32)));
        $this->assertNotNull($store->find(str_repeat('b', 32)));
    }
}
