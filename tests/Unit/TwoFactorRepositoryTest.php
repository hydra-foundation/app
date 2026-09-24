<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Repositories\TwoFactorRepository;
use App\Repositories\UserRepository;
use App\Tests\Support\TestSchema;
use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\TwoFactorStoreInterface;
use Hydra\Auth\Testing\TwoFactorStoreContractTestCase;
use Hydra\Core\Security\Encrypter;
use Hydra\Database\PdoConnection;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

#[CoversClass(TwoFactorRepository::class)]
final class TwoFactorRepositoryTest extends TwoFactorStoreContractTestCase
{
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    private PDO $pdo;

    private TwoFactorRepository $store;

    private UserRepository $users;

    protected function setUp(): void
    {
        $this->pdo = TestSchema::connect();
        $this->pdo->exec("INSERT INTO users (username, email, password_hash) VALUES ('will', 'will@example.com', 'h'), ('ada', 'ada@example.com', 'h')");

        $db = new PdoConnection($this->pdo);
        $this->users = new UserRepository($db);
        $this->store = new TwoFactorRepository($db, new Encrypter(str_repeat('k', 32)));
    }

    protected function store(): TwoFactorStoreInterface
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

    public function test_the_secret_is_not_stored_in_the_clear(): void
    {
        $this->store->enable($this->user(), self::SECRET, []);

        $stored = (string) $this->pdo->query('SELECT two_factor_secret FROM users WHERE id = 1')->fetchColumn();

        $this->assertStringNotContainsString(self::SECRET, $stored);
    }

    public function test_a_secret_moved_to_another_row_does_not_decrypt_there(): void
    {
        $this->store->enable($this->user(), self::SECRET, []);
        $this->pdo->exec('UPDATE users SET two_factor_secret = (SELECT two_factor_secret FROM users WHERE id = 1) WHERE id = 2');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_PREVIOUS_KEYS');

        $this->store->secret($this->otherUser());
    }

    public function test_enabled_at_is_set_on_enable_and_cleared_on_disable(): void
    {
        $this->assertNull($this->store->enabledAt($this->user()));

        $this->store->enable($this->user(), self::SECRET, []);
        $this->assertNotNull($this->store->enabledAt($this->user()));

        $this->store->disable($this->user());
        $this->assertNull($this->store->enabledAt($this->user()));
    }
}
