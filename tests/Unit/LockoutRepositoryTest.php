<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Repositories\LockoutRepository;
use App\Tests\Support\TestSchema;
use DateTimeImmutable;
use DateTimeZone;
use Hydra\Database\PdoConnection;
use Hydra\Throttle\Contracts\LockoutStoreInterface;
use Hydra\Throttle\Lockout;
use Hydra\Throttle\Testing\LockoutStoreContractTestCase;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(LockoutRepository::class)]
final class LockoutRepositoryTest extends LockoutStoreContractTestCase
{
    private PDO $pdo;

    private LockoutRepository $store;

    protected function setUp(): void
    {
        $this->pdo = TestSchema::connect();
        $this->store = new LockoutRepository(new PdoConnection($this->pdo));
    }

    protected function store(): LockoutStoreInterface
    {
        return $this->store;
    }

    public function test_times_are_stored_as_unix_seconds_whatever_the_zone(): void
    {
        $zone = new DateTimeZone('America/Vancouver');
        $this->store->record(new Lockout('login', 'a', new DateTimeImmutable('2026-09-29 12:00:00', $zone), new DateTimeImmutable('2026-09-29 12:10:00', $zone), 5, 600));

        $this->assertSame(
            (new DateTimeImmutable('2026-09-29 19:10:00 UTC'))->getTimestamp(),
            (int) $this->pdo->query('SELECT until FROM rate_limit_lockouts')->fetchColumn(),
        );
    }
}
