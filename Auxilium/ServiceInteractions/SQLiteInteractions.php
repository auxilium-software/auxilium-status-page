<?php

declare(strict_types=1);

namespace Auxilium\ServiceInteractions;

use Auxilium\Utilities\ConfigurationUtilities;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

final class SQLiteInteractions
{
    private PDO $pdo;

    public function __construct(?string $filePath = null)
    {
        $filePath ??= ConfigurationUtilities::GetUserConfiguration()["SQLite"]["DatabaseFilePath"];

        try {
            $this->pdo = new PDO("sqlite:$filePath", options: [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException(
                "Unable to open SQLite database at '$filePath': {$e->getMessage()}",
                previous: $e
            );
        }

        $this->pdo->exec("PRAGMA foreign_keys = ON");
        $this->pdo->exec("PRAGMA busy_timeout = 5000");
    }

    public function query_insert(string $query, array $params = []): int
    {
        $this->run($query, $params);
        return (int)$this->pdo->lastInsertId();
    }

    private function run(string $query, array $params): PDOStatement
    {
        $stmt = $this->pdo->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, $this->paramType($value));
        }
        $stmt->execute();
        return $stmt;
    }

    private function paramType(mixed $value): int
    {
        return match (true) {
            is_int($value) => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_BOOL,
            is_null($value) => PDO::PARAM_NULL,
            default => PDO::PARAM_STR,
        };
    }

    public function query_update(string $query, array $params = []): int
    {
        return $this->run($query, $params)->rowCount();
    }

    public function query_read(string $query, array $params = []): array
    {
        return $this->run($query, $params)->fetchAll();
    }

    public function query_read_one(string $query, array $params = []): ?array
    {
        $row = $this->run($query, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function query_scalar(string $query, array $params = []): mixed
    {
        $value = $this->run($query, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    public function transaction(callable $work): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $work($this);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
