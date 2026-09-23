<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Admin\Sources\UserSource;
use App\Repositories\UserRepository;
use App\Tests\Support\TestSchema;
use Hydra\Admin\Contracts\SourceInterface;
use Hydra\Admin\Testing\WritableSourceContractTestCase;
use Hydra\Admin\Exceptions\WriteRejected;
use Hydra\Auth\Contracts\GuardInterface;
use Hydra\Auth\Testing\FakeGuard;
use Hydra\Auth\Testing\FakeHasher;
use Hydra\Auth\Testing\FakeUser;
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
        // Two roles rather than one: role is the column the source filters by,
        // and a fixture where every row holds the same value cannot tell a
        // filter that ran from a filter that was dropped.
        $users = ['ada' => 'admin', 'grace' => 'admin', 'alan' => 'user', 'edsger' => 'user', 'barbara' => 'user'];

        foreach ($users as $username => $role) {
            $this->pdo->prepare('INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)')
                ->execute([$username, "{$username}@example.com", 'hashed-secret', $role]);
        }

        $db = new PdoConnection($this->pdo);
        $this->source = new UserSource($db, $this->guard(), new FakeHasher, new UserRepository($db));
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

    /** @return array<string, string> */
    protected function filterValues(): array
    {
        return ['role' => 'admin'];
    }

    protected function newRow(): array
    {
        return ['username' => 'linus', 'email' => 'linus@example.com', 'role' => 'user', 'password' => 'a-long-enough-secret'];
    }

    protected function editedRow(): array
    {
        return ['username' => 'ada-the-second', 'email' => 'ada2@example.com', 'role' => 'admin'];
    }

    protected function readBack(array $data): array
    {
        // The digest is written and never returned: COLUMNS does not name it,
        // and a case insisting the password came back would be asking the
        // source for the defect.
        unset($data['password']);

        return $data;
    }

    public function test_create_rejects_an_address_in_use_whatever_the_case(): void
    {
        $this->expectExceptionObject(WriteRejected::on('email', 'That email address is already in use.'));

        $this->source->create(['username' => 'linus', 'email' => 'ADA@example.com', 'password' => 'secret-enough']);
    }

    public function test_update_rejects_another_accounts_address(): void
    {
        $this->expectExceptionObject(WriteRejected::on('email', 'That email address is already in use.'));

        $this->source->update('1', ['username' => 'ada', 'email' => 'grace@example.com']);
    }

    public function test_an_unchanged_address_stays_verified(): void
    {
        $this->pdo->exec("UPDATE users SET email_verified_at = '2026-01-01 00:00:00' WHERE id = 1");

        $this->source->update('1', ['username' => 'ada-renamed', 'email' => 'ada@example.com', 'role' => 'admin']);

        $this->assertSame('2026-01-01 00:00:00', $this->verifiedAt(1));
    }

    public function test_a_changed_address_must_be_verified_again(): void
    {
        // Verification proved a mailbox, and this is a different one.
        $this->pdo->exec("UPDATE users SET email_verified_at = '2026-01-01 00:00:00' WHERE id = 1");

        $this->source->update('1', ['username' => 'ada', 'email' => 'ada@elsewhere.test', 'role' => 'admin']);

        $this->assertNull($this->verifiedAt(1));
    }

    private function verifiedAt(int $id): ?string
    {
        $statement = $this->pdo->prepare('SELECT email_verified_at FROM users WHERE id = ?');
        $statement->execute([$id]);

        return $statement->fetchColumn() ?: null;
    }

    /** Signed in as an id the fixture does not hold, so no row here is protected. */
    private function guard(): GuardInterface
    {
        return FakeGuard::signedInAs(new FakeUser(99));
    }
}
