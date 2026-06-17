<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Database\PdoConnection;
use App\Entities\User;
use App\Repositories\UserRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * UserRepository over an in-memory sqlite connection — proves the lookups
 * (by id, by username) and the admin listing (all()) hydrate a User and return
 * null on a miss, hermetically and without Docker. This is the app's fulfilment
 * of auth's UserProviderInterface, so the two lookup methods do lookups only —
 * no password handling here.
 *
 * The write methods (create/update/delete) are the admin user-management
 * slice's own reads-and-writes — outside the auth contract — kept in the same
 * hand-written-SQL shape. create() still never HASHES a password: it stores the
 * digest it is handed, so all credential production stays in NativeHasher.
 */
final class UserRepositoryTest extends TestCase
{
    private PDO $pdo;
    private UserRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT \'user\',
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
        $this->pdo->exec("INSERT INTO users (username, password_hash) VALUES ('will', 'hashed-secret')");

        $this->repo = new UserRepository(new PdoConnection($this->pdo));
    }

    public function testByUsernameReturnsTheHydratedUser(): void
    {
        $user = $this->repo->byUsername('will');

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('will', $user->username);
        $this->assertSame('hashed-secret', $user->getAuthPassword());
        $this->assertSame(1, $user->getAuthIdentifier());
    }

    public function testByUsernameReturnsNullForUnknownName(): void
    {
        $this->assertNull($this->repo->byUsername('nobody'));
    }

    public function testByIdentifierRestoresTheUser(): void
    {
        $user = $this->repo->byIdentifier(1);

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('will', $user->username);
    }

    public function testByIdentifierReturnsNullForMissingId(): void
    {
        $this->assertNull($this->repo->byIdentifier(404));
    }

    public function testRoleDefaultsToPlainUser(): void
    {
        // all() is typed list<User>, so role/isAdmin read without narrowing.
        $user = $this->repo->all()[0];

        $this->assertSame('user', $user->role);
        $this->assertFalse($user->isAdmin());
    }

    public function testAllReturnsEveryUserNewestFirst(): void
    {
        $this->pdo->exec("INSERT INTO users (username, password_hash, role) VALUES ('ada', 'x', 'admin')");

        $all = $this->repo->all();

        $this->assertCount(2, $all);
        // Newest first: the admin we just inserted leads.
        $this->assertSame('ada', $all[0]->username);
        $this->assertTrue($all[0]->isAdmin());
        $this->assertSame('will', $all[1]->username);
    }

    public function testCreateInsertsAndReturnsTheNewId(): void
    {
        $id = $this->repo->create('ada', 'digest', 'admin');

        // Newest row, so id 2 (will seeded as 1), and the round-trip hydrates it.
        $this->assertSame(2, $id);
        $created = $this->repo->byIdentifier($id);
        $this->assertInstanceOf(User::class, $created);
        $this->assertSame('ada', $created->username);
        $this->assertSame('digest', $created->getAuthPassword());
        $this->assertTrue($created->isAdmin());
    }

    public function testCreateDefaultsToThePlainUserRole(): void
    {
        $id = $this->repo->create('grace', 'digest');

        $this->assertSame('user', $this->repo->byIdentifier($id)?->role);
    }

    public function testUpdateChangesUsernameAndRoleAndReportsTheRowChanged(): void
    {
        $affected = $this->repo->update(1, 'will-admin', 'admin');

        $this->assertSame(1, $affected);
        $reloaded = $this->repo->byIdentifier(1);
        $this->assertSame('will-admin', $reloaded?->username);
        $this->assertTrue($reloaded->isAdmin());
        // The password digest is untouched by an edit — credentials change elsewhere.
        $this->assertSame('hashed-secret', $reloaded->getAuthPassword());
    }

    public function testUpdateReportsZeroWhenNoRowMatches(): void
    {
        $this->assertSame(0, $this->repo->update(404, 'ghost', 'user'));
    }

    public function testDeleteRemovesTheRowAndReportsTheCount(): void
    {
        $affected = $this->repo->delete(1);

        $this->assertSame(1, $affected);
        $this->assertNull($this->repo->byIdentifier(1));
    }

    public function testDeleteReportsZeroWhenNoRowMatches(): void
    {
        $this->assertSame(0, $this->repo->delete(404));
    }
}
