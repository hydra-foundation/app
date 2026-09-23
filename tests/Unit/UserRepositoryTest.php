<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entities\Role;
use App\Entities\User;
use App\Repositories\UserRepository;
use App\Tests\Support\TestSchema;
use Hydra\Auth\Contracts\UserProviderInterface;
use Hydra\Auth\Testing\UserProviderContractTestCase;
use Hydra\Database\PdoConnection;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * UserRepository over an in-memory sqlite connection: the lookups (by id, by
 * username) and the admin listing (all()) hydrate a User and return null on a
 * miss, hermetically and without Docker. This is the app's fulfilment of auth's
 * UserProviderInterface, so it runs the framework's published contract case for
 * that seam as well as its own, and the two lookup methods do lookups only and
 * no password handling. The write methods (create/update/delete) belong to the
 * admin user-management slice rather than to the auth contract, and create()
 * still never HASHES a password: it stores the digest it is handed, so all
 * credential production stays in NativeHasher.
 */
#[CoversClass(UserRepository::class)]
final class UserRepositoryTest extends UserProviderContractTestCase
{
    private PDO $pdo;
    private UserRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = TestSchema::connect();
        $this->pdo->exec("INSERT INTO users (username, email, password_hash) VALUES ('will', 'will@example.com', 'hashed-secret')");

        $this->repo = new UserRepository(new PdoConnection($this->pdo));
    }

    protected function provider(): UserProviderInterface
    {
        return $this->repo;
    }

    protected function knownUsername(): string
    {
        return 'will';
    }

    public function test_by_username_returns_the_hydrated_user(): void
    {
        $user = $this->repo->byUsername('will');

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('will', $user->username);
        $this->assertSame('hashed-secret', $user->getAuthPassword());
        $this->assertSame(1, $user->getAuthIdentifier());
    }

    public function test_by_username_returns_null_for_unknown_name(): void
    {
        $this->assertNull($this->repo->byUsername('nobody'));
    }

    public function test_by_identifier_restores_the_user(): void
    {
        $user = $this->repo->byIdentifier(1);

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('will', $user->username);
    }

    public function test_by_identifier_returns_null_for_missing_id(): void
    {
        $this->assertNull($this->repo->byIdentifier(404));
    }

    public function test_role_defaults_to_plain_user(): void
    {
        // Seeded without an explicit role, so the column default applies.
        $user = $this->repo->byUsername('will');

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame(Role::User, $user->role);
        $this->assertFalse($user->isAdmin());
    }

    public function test_create_inserts_and_returns_the_new_id(): void
    {
        $id = $this->repo->create('ada', 'ada@example.com', 'digest', Role::Admin);

        // Newest row, so id 2 (will seeded as 1), and the round-trip hydrates it.
        $this->assertSame(2, $id);
        $created = $this->repo->byIdentifier($id);
        $this->assertInstanceOf(User::class, $created);
        $this->assertSame('ada', $created->username);
        $this->assertSame('digest', $created->getAuthPassword());
        $this->assertTrue($created->isAdmin());
    }

    public function test_create_defaults_to_the_plain_user_role(): void
    {
        $id = $this->repo->create('grace', 'grace@example.com', 'digest');

        $this->assertSame(Role::User, $this->repo->byIdentifier($id)?->role);
    }

    public function test_by_email_finds_the_user_whatever_the_case(): void
    {
        $id = $this->repo->create('ada', 'ada@example.com', 'digest');

        $this->assertSame($id, $this->repo->byEmail('Ada@Example.com')?->id);
    }

    public function test_an_address_belongs_to_one_account_whatever_the_case(): void
    {
        $this->repo->create('ada', 'ada@example.com', 'digest');

        $this->expectException(\PDOException::class);
        $this->repo->create('imposter', 'ADA@example.com', 'digest');
    }

    public function test_update_password_replaces_the_stored_hash(): void
    {
        $this->repo->updatePassword(1, 'new-digest');

        $this->assertSame('new-digest', $this->repo->byIdentifier(1)?->getAuthPassword());
    }

    public function test_mark_verified_stamps_the_address_it_was_sent_to(): void
    {
        $id = $this->repo->create('ada', 'ada@example.com', 'digest');

        $this->assertTrue($this->repo->markVerified($id, 'ada@example.com'));
        $this->assertTrue($this->repo->byIdentifier($id)?->hasVerifiedEmail());
    }

    public function test_mark_verified_refuses_an_address_changed_since(): void
    {
        $id = $this->repo->create('ada', 'ada@elsewhere.test', 'digest');

        $this->assertFalse($this->repo->markVerified($id, 'ada@example.com'));
        $this->assertFalse($this->repo->byIdentifier($id)?->hasVerifiedEmail());
    }

    public function test_mark_verified_keeps_the_first_stamp(): void
    {
        $id = $this->repo->create('ada', 'ada@example.com', 'digest');
        $this->pdo->exec("UPDATE users SET email_verified_at = '2026-01-01 00:00:00' WHERE id = {$id}");

        $this->assertFalse($this->repo->markVerified($id, 'ada@example.com'));
        $this->assertSame('2026-01-01 00:00:00', $this->repo->byIdentifier($id)?->emailVerifiedAt);
    }
}
