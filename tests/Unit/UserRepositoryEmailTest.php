<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Repositories\UserRepository;
use App\Tests\Support\TestSchema;
use Hydra\Auth\Contracts\EmailUserProviderInterface;
use Hydra\Auth\Testing\EmailUserProviderContractTestCase;
use Hydra\Database\PdoConnection;
use PHPUnit\Framework\Attributes\CoversClass;

/** The repository against auth's published contract for lookup by address. */
#[CoversClass(UserRepository::class)]
final class UserRepositoryEmailTest extends EmailUserProviderContractTestCase
{
    protected function emailProvider(): EmailUserProviderInterface
    {
        $pdo = TestSchema::connect();
        $pdo->exec("INSERT INTO users (username, email, password_hash) VALUES ('will', 'will@example.com', 'x'), ('grace', 'grace@example.com', 'x')");

        return new UserRepository(new PdoConnection($pdo));
    }

    protected function knownEmail(): string
    {
        return 'will@example.com';
    }
}
