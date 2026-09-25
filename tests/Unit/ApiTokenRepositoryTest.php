<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Repositories\ApiTokenRepository;
use App\Repositories\UserRepository;
use App\Tests\Support\TestSchema;
use DateTimeImmutable;
use Hydra\Auth\Contracts\ApiTokenStoreInterface;
use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Testing\ApiTokenStoreContractTestCase;
use Hydra\Database\PdoConnection;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

#[CoversClass(ApiTokenRepository::class)]
final class ApiTokenRepositoryTest extends ApiTokenStoreContractTestCase
{
    private PDO $pdo;

    private ApiTokenRepository $store;

    private UserRepository $users;

    protected function setUp(): void
    {
        $this->pdo = TestSchema::connect();
        $this->pdo->exec("INSERT INTO users (username, email, password_hash) VALUES ('will', 'will@example.com', 'h'), ('ada', 'ada@example.com', 'h')");

        $db = new PdoConnection($this->pdo);
        $this->users = new UserRepository($db);
        $this->store = new ApiTokenRepository($db);
    }

    protected function store(): ApiTokenStoreInterface
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
        $this->store->create(
            $this->user(),
            'CLI',
            hash('sha256', 'a'),
            new DateTimeImmutable('2026-09-25 12:00:00', new \DateTimeZone('America/Vancouver')),
            null,
        );

        $this->assertSame(
            (new DateTimeImmutable('2026-09-25 19:00:00 UTC'))->getTimestamp(),
            (int) $this->pdo->query('SELECT created_at FROM api_tokens')->fetchColumn(),
        );
    }
}
