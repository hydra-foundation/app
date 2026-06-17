<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Database\PdoConnection;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * PdoConnection against a real (in-memory sqlite) PDO — the seam's contract:
 * prepared select/selectOne/execute and lastInsertId, no driver-specific code.
 */
final class PdoConnectionTest extends TestCase
{
    private PdoConnection $db;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');

        $this->db = new PdoConnection($pdo);
    }

    public function testExecuteReturnsAffectedRowsAndSelectReadsThemBack(): void
    {
        $affected = $this->db->execute('INSERT INTO widgets (name) VALUES (?)', ['cog']);
        $this->assertSame(1, $affected);

        $rows = $this->db->select('SELECT id, name FROM widgets');
        $this->assertSame([['id' => 1, 'name' => 'cog']], $rows);
    }

    public function testSelectReturnsEmptyArrayWhenNoRows(): void
    {
        $this->assertSame([], $this->db->select('SELECT * FROM widgets'));
    }

    public function testSelectOneReturnsFirstRowOrNull(): void
    {
        $this->assertNull($this->db->selectOne('SELECT * FROM widgets WHERE id = ?', [99]));

        $this->db->execute('INSERT INTO widgets (name) VALUES (?)', ['sprocket']);

        $this->assertSame(
            ['id' => 1, 'name' => 'sprocket'],
            $this->db->selectOne('SELECT id, name FROM widgets WHERE id = ?', [1]),
        );
    }

    public function testLastInsertIdReflectsMostRecentInsert(): void
    {
        $this->db->execute('INSERT INTO widgets (name) VALUES (?)', ['a']);
        $this->db->execute('INSERT INTO widgets (name) VALUES (?)', ['b']);

        $this->assertSame(2, $this->db->lastInsertId());
    }

    public function testParametersAreBoundNotInterpolated(): void
    {
        // A value with SQL metacharacters round-trips intact — proof it's bound.
        $payload = "Robert'); DROP TABLE widgets;--";
        $this->db->execute('INSERT INTO widgets (name) VALUES (?)', [$payload]);

        $this->assertSame($payload, $this->db->selectOne('SELECT name FROM widgets WHERE id = 1')['name']);
    }
}
