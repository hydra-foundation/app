<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use Hydra\Database\PdoConnection;
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
        // all() is typed list<User>, so role/isAdmin read without narrowing.
        $user = $this->repo->all()[0];

        $this->assertSame('user', $user->role);
        $this->assertFalse($user->isAdmin());
    }

    public function test_all_returns_every_user_newest_first(): void
    {
        $this->pdo->exec("INSERT INTO users (username, password_hash, role) VALUES ('ada', 'x', 'admin')");

        $all = $this->repo->all();

        $this->assertCount(2, $all);
        // Newest first: the admin we just inserted leads.
        $this->assertSame('ada', $all[0]->username);
        $this->assertTrue($all[0]->isAdmin());
        $this->assertSame('will', $all[1]->username);
    }

    public function test_create_inserts_and_returns_the_new_id(): void
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

    public function test_create_defaults_to_the_plain_user_role(): void
    {
        $id = $this->repo->create('grace', 'digest');

        $this->assertSame('user', $this->repo->byIdentifier($id)?->role);
    }

    public function test_update_changes_username_and_role_and_reports_the_row_changed(): void
    {
        $affected = $this->repo->update(1, 'will-admin', 'admin');

        $this->assertSame(1, $affected);
        $reloaded = $this->repo->byIdentifier(1);
        $this->assertSame('will-admin', $reloaded?->username);
        $this->assertTrue($reloaded->isAdmin());
        // The password digest is untouched by an edit — credentials change elsewhere.
        $this->assertSame('hashed-secret', $reloaded->getAuthPassword());
    }

    public function test_update_reports_zero_when_no_row_matches(): void
    {
        $this->assertSame(0, $this->repo->update(404, 'ghost', 'user'));
    }

    public function test_delete_removes_the_row_and_reports_the_count(): void
    {
        $affected = $this->repo->delete(1);

        $this->assertSame(1, $affected);
        $this->assertNull($this->repo->byIdentifier(1));
    }

    public function test_delete_reports_zero_when_no_row_matches(): void
    {
        $this->assertSame(0, $this->repo->delete(404));
    }
}
