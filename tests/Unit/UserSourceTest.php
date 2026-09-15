<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Admin\Sources\UserSource;
use App\Tests\Support\TestSchema;
use Hydra\Admin\Contracts\SourceInterface;
use Hydra\Admin\Testing\WritableSourceContractTestCase;
use Hydra\Auth\Contracts\AuthenticatableInterface;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Auth\Contracts\HasherInterface;
use Hydra\Database\PdoConnection;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The users table's read and write side, held to the framework's published
 * source contract — the whole ladder, since this source implements every write
 * interface the admin knows about.
 *
 * The signed-in account is somebody the fixture does not hold, so nothing here
 * runs into the source's refusal to delete it; that refusal is policy and has
 * its own test rather than being the contract's business.
 */
#[CoversClass(UserSource::class)]
final class UserSourceTest extends WritableSourceContractTestCase
{
    private PDO $pdo;
    private UserSource $source;

    protected function setUp(): void
    {
        $this->pdo = TestSchema::connect();

        // No name carries a %, a _ or a !, which is what lets the wildcard
        // cases read a match for one of those as the search term reaching the
        // LIKE unescaped rather than as the data really holding it.
        foreach (['ada', 'grace', 'alan', 'edsger', 'barbara'] as $username) {
            $this->pdo->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)')
                ->execute([$username, 'hashed-secret', 'user']);
        }

        $this->source = new UserSource(
            new PdoConnection($this->pdo),
            $this->guard(),
            $this->hasher(),
        );
    }

    protected function source(): SourceInterface
    {
        return $this->source;
    }

    protected function rowCount(): int
    {
        return 5;
    }

    protected function sortColumn(): string
    {
        return 'id';
    }

    protected function searchMatchingSomeRows(): string
    {
        return 'grace';
    }

    protected function newRow(): array
    {
        return ['username' => 'linus', 'role' => 'user', 'password' => 'a-long-enough-secret'];
    }

    protected function editedRow(): array
    {
        return ['username' => 'ada-the-second', 'role' => 'admin'];
    }

    protected function readBack(array $data): array
    {
        // The digest is written and never returned: COLUMNS does not name it,
        // and a case insisting the password came back would be asking the
        // source for the defect.
        unset($data['password']);

        return $data;
    }

    /** A hasher that does not cost anything: what is under test is the source. */
    private function hasher(): HasherInterface
    {
        return new class implements HasherInterface {
            public function hash(string $plain): string
            {
                return 'hashed:' . $plain;
            }

            public function verify(string $plain, string $hash): bool
            {
                return $hash === 'hashed:' . $plain;
            }

            public function needsRehash(string $hash): bool
            {
                return false;
            }
        };
    }

    /** Signed in as an id the fixture does not hold, so no row here is protected. */
    private function guard(): GuardInterface
    {
        return new class implements GuardInterface {
            public function user(): ?AuthenticatableInterface
            {
                return null;
            }

            public function check(): bool
            {
                return true;
            }

            public function id(): int
            {
                return 99;
            }

            public function attempt(string $username, string $password): bool
            {
                return false;
            }

            public function login(AuthenticatableInterface $user): void {}

            public function logout(): void {}
        };
    }
}
