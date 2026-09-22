<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Tests\Support\CommandRun;
use App\Tests\Support\Console;
use Hydra\Console\ExitCode;
use App\Console\Commands\MakeUserCommand;
use Hydra\Database\PdoConnection;
use App\Repositories\UserRepository;
use Hydra\Auth\AuthConfig;
use Hydra\Auth\NativeHasher;
use PDO;
use App\Tests\Support\TestSchema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * make:user wired to a real UserRepository (in-memory sqlite) and the real
 * NativeHasher, covering the happy path (a verifiable hash actually lands),
 * the hidden-password confirm/mismatch/length checks, the role guard, and the
 * uniqueness + format rules it shares with the admin form.
 */
#[CoversClass(MakeUserCommand::class)]
final class MakeUserCommandTest extends TestCase
{
    private PDO $pdo;
    private UserRepository $repo;
    private NativeHasher $hasher;

    protected function setUp(): void
    {
        $this->pdo = TestSchema::connect();

        $this->repo = new UserRepository(new PdoConnection($this->pdo));
        // A low work factor keeps the hashing in these tests fast.
        $this->hasher = new NativeHasher(new AuthConfig(hashCost: 4));
    }

    /**
     * @param array<string, mixed> $arguments
     * @param list<string> $inputs answers fed to the interactive prompts
     */
    private function makeUser(array $arguments, array $inputs): CommandRun
    {
        return Console::run(
            new MakeUserCommand($this->repo, $this->hasher),
            $arguments,
            answers: $inputs,
        );
    }

    public function test_creates_a_user_with_a_verifiable_hash(): void
    {
        $run = $this->makeUser(['username' => 'alice'], ['s3cret-password', 's3cret-password']);

        $this->assertSame(ExitCode::Success, $run->code);
        $this->assertStringContainsString("Created user 'alice'", $run->display());

        $user = $this->repo->byUsername('alice');
        $this->assertNotNull($user);
        // The stored digest is a real hash: verifiable, never the plaintext.
        $this->assertNotSame('s3cret-password', $user->getAuthPassword());
        $this->assertTrue($this->hasher->verify('s3cret-password', $user->getAuthPassword()));
    }

    public function test_creates_an_admin_when_role_option_given(): void
    {
        $run = $this->makeUser(['username' => 'root', '--role' => 'admin'], ['longenough', 'longenough']);

        $this->assertSame(ExitCode::Success, $run->code);
        $this->assertTrue($this->repo->byUsername('root')->isAdmin());
    }

    public function test_rejects_an_unknown_role(): void
    {
        $run = $this->makeUser(['username' => 'bob', '--role' => 'superuser'], []);

        $this->assertSame(ExitCode::Failure, $run->code);
        $this->assertStringContainsString('Role must be one of', $run->display());
        $this->assertNull($this->repo->byUsername('bob'));
    }

    public function test_fails_when_passwords_do_not_match(): void
    {
        $run = $this->makeUser(['username' => 'carol'], ['longenough', 'different1']);

        $this->assertSame(ExitCode::Failure, $run->code);
        $this->assertStringContainsString('do not match', $run->display());
        $this->assertNull($this->repo->byUsername('carol'));
    }

    public function test_rejects_a_structurally_invalid_username_argument(): void
    {
        $run = $this->makeUser(['username' => 'no'], []); // too short

        $this->assertSame(ExitCode::Failure, $run->code);
        $this->assertStringContainsString('3', $run->display());
        $this->assertNull($this->repo->byUsername('no'));
    }

    public function test_rejects_a_duplicate_username_argument(): void
    {
        $this->repo->create('will', 'existing-hash');

        $run = $this->makeUser(['username' => 'will'], []);

        $this->assertSame(ExitCode::Failure, $run->code);
        $this->assertStringContainsString('already taken', $run->display());
    }

    public function test_prompts_for_username_when_argument_omitted(): void
    {
        $run = $this->makeUser([], ['dave', 'longenough', 'longenough']);

        $this->assertSame(ExitCode::Success, $run->code);
        $this->assertNotNull($this->repo->byUsername('dave'));
    }
}
