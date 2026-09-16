<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entities\Audit;
use App\Repositories\AuditRepository;
use App\Tests\Support\TestSchema;
use Hydra\Database\Contracts\ConnectionInterface;
use Hydra\Database\PdoConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The audit log's write side, and in particular its clipping.
 *
 * Clipping is the one thing here the rest of the suite structurally cannot
 * check: sqlite stores an over-long value in a VARCHAR without complaint, while
 * MariaDB in strict mode rejects the INSERT outright. Every assertion below on a
 * length is standing in for a write that would fail in production and pass
 * everywhere else.
 */
#[CoversClass(AuditRepository::class)]
final class AuditRepositoryTest extends TestCase
{
    private ConnectionInterface $db;
    private AuditRepository $repository;

    protected function setUp(): void
    {
        $this->db = new PdoConnection(TestSchema::connect());
        $this->repository = new AuditRepository($this->db);
    }

    public function test_a_change_is_recorded_column_for_column(): void
    {
        $this->repository->record(new Audit(
            'users',
            '7',
            '{"role":"user"}',
            '{"role":"admin"}',
            3,
            'boss',
            'promoted',
        ));

        $row = $this->row();
        $this->assertSame('users', $row['module']);
        $this->assertSame('7', $row['table_id']);
        $this->assertSame('{"role":"user"}', $row['old_value']);
        $this->assertSame('{"role":"admin"}', $row['new_value']);
        $this->assertSame(3, (int) $row['user_id']);
        $this->assertSame('boss', $row['username']);
        $this->assertSame('promoted', $row['message']);
    }

    public function test_a_change_made_outside_a_session_has_no_user(): void
    {
        // A console command or a migration writes rows too, and user_id is
        // ON DELETE SET NULL besides, so both halves have to accept null.
        $this->repository->record(new Audit('users', '7', null, null, null, null, null));

        $row = $this->row();
        $this->assertNull($row['user_id']);
        $this->assertNull($row['username']);
        $this->assertNull($row['message']);
    }

    public function test_a_username_longer_than_the_column_is_clipped_rather_than_refused(): void
    {
        $this->repository->record(new Audit('users', '7', null, null, 1, str_repeat('a', 100), null));

        $this->assertSame(64, mb_strlen((string) $this->row()['username']));
    }

    public function test_the_varchar_columns_are_clipped_to_their_own_widths(): void
    {
        $this->repository->record(new Audit(
            str_repeat('t', 300),
            str_repeat('i', 300),
            null,
            null,
            1,
            null,
            str_repeat('m', 300),
        ));

        $row = $this->row();
        $this->assertSame(255, mb_strlen((string) $row['module']));
        $this->assertSame(255, mb_strlen((string) $row['table_id']));
        $this->assertSame(255, mb_strlen((string) $row['message']));
    }

    public function test_clipping_counts_characters_and_not_bytes(): void
    {
        // The columns are utf8mb4 and MariaDB measures a VARCHAR in characters,
        // so a byte-wise cut would both under-fill the column and be free to
        // land inside a character.
        $this->repository->record(new Audit('users', '7', null, null, 1, str_repeat('é', 100), null));

        $username = (string) $this->row()['username'];
        $this->assertSame(64, mb_strlen($username));
        $this->assertSame(str_repeat('é', 64), $username);
    }

    public function test_the_before_and_after_values_are_recorded_whole(): void
    {
        // They are TEXT, and they are the thing being recorded: a silently
        // shortened one is a worse record than a large one.
        $value = str_repeat('v', 5000);
        $this->repository->record(new Audit('users', '7', $value, $value, 1, null, null));

        $row = $this->row();
        $this->assertSame(5000, mb_strlen((string) $row['old_value']));
        $this->assertSame(5000, mb_strlen((string) $row['new_value']));
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        $row = $this->db->selectOne('SELECT * FROM audit ORDER BY id DESC');
        $this->assertNotNull($row);

        return $row;
    }
}
