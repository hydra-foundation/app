<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Console\Commands\MakeUserCommand;
use Hydra\Database\PdoConnection;
use App\Repositories\UserRepository;
use Hydra\Auth\AuthConfig;
use Hydra\Auth\NativeHasher;
use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * make:user wired to a real UserRepository (in-memory sqlite) and the real
 * NativeHasher — covering the happy path (a verifiable hash actually lands),
 * the hidden-password confirm/mismatch/length checks, the role guard, and the
 * uniqueness + format rules it shares with the admin form.
 */
final class MakeUserCommandTest extends TestCase
{
    private PDO $pdo;
    private UserRepository $repo;
    private NativeHasher $hasher;

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

        $this->repo = new UserRepository(new PdoConnection($this->pdo));
        // A low work factor keeps the hashing in these tests fast.
        $this->hasher = new NativeHasher(new AuthConfig(hashCost: 4));
    }

    /** @param list<string> $inputs answers fed to the interactive prompts */
    private function makeUser(array $arguments, array $inputs): CommandTester
    {
        $tester = new CommandTester(new MakeUserCommand($this->repo, $this->hasher));
        $tester->setInputs($inputs);
        $tester->execute($arguments);

        return $tester;
    }

    public function testCreatesAUserWithAVerifiableHash(): void
    {
        $tester = $this->makeUser(['username' => 'alice'], ['s3cret-password', 's3cret-password']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString("Created user 'alice'", $tester->getDisplay());

        $user = $this->repo->byUsername('alice');
        $this->assertNotNull($user);
        // The stored digest is a real hash — verifiable, never the plaintext.
        $this->assertNotSame('s3cret-password', $user->getAuthPassword());
        $this->assertTrue($this->hasher->verify('s3cret-password', $user->getAuthPassword()));
    }

    public function testCreatesAnAdminWhenRoleOptionGiven(): void
    {
        $tester = $this->makeUser(['username' => 'root', '--role' => 'admin'], ['longenough', 'longenough']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertTrue($this->repo->byUsername('root')->isAdmin());
    }

    public function testRejectsAnUnknownRole(): void
    {
        $tester = $this->makeUser(['username' => 'bob', '--role' => 'superuser'], []);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Role must be user or admin', $tester->getDisplay());
        $this->assertNull($this->repo->byUsername('bob'));
    }

    public function testFailsWhenPasswordsDoNotMatch(): void
    {
        $tester = $this->makeUser(['username' => 'carol'], ['longenough', 'different1']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('do not match', $tester->getDisplay());
        $this->assertNull($this->repo->byUsername('carol'));
    }

    public function testRejectsAStructurallyInvalidUsernameArgument(): void
    {
        $tester = $this->makeUser(['username' => 'no'], []); // too short

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('3', $tester->getDisplay());
        $this->assertNull($this->repo->byUsername('no'));
    }

    public function testRejectsADuplicateUsernameArgument(): void
    {
        $this->repo->create('will', 'existing-hash');

        $tester = $this->makeUser(['username' => 'will'], []);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('already taken', $tester->getDisplay());
    }

    public function testPromptsForUsernameWhenArgumentOmitted(): void
    {
        $tester = $this->makeUser([], ['dave', 'longenough', 'longenough']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertNotNull($this->repo->byUsername('dave'));
    }
}
