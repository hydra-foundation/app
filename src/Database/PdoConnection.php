<?php

declare(strict_types=1);

namespace App\Database;

use PDO;

/**
 * PDO-backed {@see ConnectionInterface}.
 *
 * Wraps a configured PDO handle and prepares every statement, so all values
 * reach the driver as bound parameters — the connection has no string-built
 * SQL path. The PDO is constructed elsewhere (the service provider) so this
 * class stays driver-agnostic and trivially testable against sqlite.
 */
final class PdoConnection implements ConnectionInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function select(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        /** @var list<array<string, mixed>> */
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function selectOne(string $sql, array $params = []): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function execute(string $sql, array $params = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount();
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }
}
