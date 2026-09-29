<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Repositories\SignInRepository;
use App\Repositories\UserRepository;
use App\Tests\Support\TestSchema;
use DateTimeImmutable;
use DateTimeZone;
use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\SignInStoreInterface;
use Hydra\Auth\Testing\SignInStoreContractTestCase;
use Hydra\Database\PdoConnection;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

#[CoversClass(SignInRepository::class)]
final class SignInRepositoryTest extends SignInStoreContractTestCase
{
    private PDO $pdo;

    private SignInRepository $store;

    private UserRepository $users;

    protected function setUp(): void
    {
        $this->pdo = TestSchema::connect();
        $this->pdo->exec("INSERT INTO users (username, email, password_hash) VALUES ('will', 'will@example.com', 'h'), ('ada', 'ada@example.com', 'h')");

        $db = new PdoConnection($this->pdo);
        $this->users = new UserRepository($db);
        $this->store = new SignInRepository($db);
    }

    protected function store(): SignInStoreInterface
    {
        return $this->store;
    }

    protected function user(): AuthenticatableInterface
    {
        return $this->users->byIdentifier(1) ?? throw new RuntimeException('not seeded');
    }

    protected function otherUser(): AuthenticatableInterface
    {
        return $this->users->byIdentifier(2) ?? throw new RuntimeException('not seeded');
    }

    public function test_times_are_stored_as_unix_seconds_whatever_the_zone(): void
    {
        $this->store->create(str_repeat('a', 32), $this->user(), new DateTimeImmutable('2026-09-29 12:00:00', new DateTimeZone('America/Vancouver')));

        $this->assertSame(
            (new DateTimeImmutable('2026-09-29 19:00:00 UTC'))->getTimestamp(),
            (int) $this->pdo->query('SELECT last_seen_at FROM sign_ins')->fetchColumn(),
        );
    }
}
