<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Repositories\UserRepository;
use App\Tests\Support\TestSchema;
use Hydra\Broadcast\Envelope;
use Hydra\Broadcast\Testing\FakeBroadcaster;
use Hydra\Database\PdoConnection;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Every write to users outside the admin tells open Users lists to refetch:
 * make:user, a new password or avatar, an email changed or verified. The
 * admin's own writes are published by the admin.
 */
#[CoversClass(UserRepository::class)]
final class UserRepositoryBroadcastTest extends TestCase
{
    private PDO $pdo;
    private FakeBroadcaster $broadcaster;
    private UserRepository $users;
    private int $will;

    protected function setUp(): void
    {
        $this->pdo = TestSchema::connect();
        $this->pdo->exec("INSERT INTO users (username, email, password_hash) VALUES ('will', 'will@example.com', 'x')");
        $this->will = (int) $this->pdo->lastInsertId();
        $this->broadcaster = new FakeBroadcaster;
        $this->users = new UserRepository(new PdoConnection($this->pdo), $this->broadcaster);
    }

    public function test_creating_a_user_publishes_its_id(): void
    {
        $id = $this->users->create('ada', 'ada@example.com', 'x');

        $this->assertPublishedFor($id);
    }

    public function test_a_new_password_publishes(): void
    {
        $this->users->updatePassword($this->will, 'y');

        $this->assertPublishedFor($this->will);
    }

    public function test_a_new_avatar_publishes(): void
    {
        $this->users->updateAvatar($this->will, 'public:a.png', 'a.png');

        $this->assertPublishedFor($this->will);
    }

    public function test_an_email_change_publishes_only_when_it_happened(): void
    {
        $this->assertFalse($this->users->changeEmail($this->will, 'someone-else@example.com', 'new@example.com'));
        $this->broadcaster->assertNothingPublished();

        $this->assertTrue($this->users->changeEmail($this->will, 'will@example.com', 'new@example.com'));
        $this->assertPublishedFor($this->will);
    }

    public function test_a_verification_publishes_only_when_it_happened(): void
    {
        $this->assertTrue($this->users->markVerified($this->will, 'will@example.com'));
        $this->assertPublishedFor($this->will);

        $this->assertFalse($this->users->markVerified($this->will, 'will@example.com'));
        $this->assertCount(1, $this->broadcaster->published());
    }

    public function test_a_write_that_fails_publishes_nothing(): void
    {
        try {
            $this->users->create('will', 'dupe@example.com', 'x');
            $this->fail('A duplicate username must be refused by the table.');
        } catch (PDOException) {
        }

        $this->broadcaster->assertNothingPublished();
    }

    public function test_without_a_broadcaster_writes_work_as_before(): void
    {
        $id = (new UserRepository(new PdoConnection($this->pdo)))->create('ada', 'ada@example.com', 'x');

        $this->assertGreaterThan(0, $id);
    }

    private function assertPublishedFor(int $id): void
    {
        $this->broadcaster->assertPublished('module.users', 'changed', static fn (Envelope $e): bool => $e->data === ['id' => $id], times: 1);
        $this->assertCount(1, $this->broadcaster->published());
    }
}
