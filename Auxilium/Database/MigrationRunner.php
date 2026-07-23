<?php

declare(strict_types=1);

namespace Auxilium\Database;

use Auxilium\ServiceInteractions\SQLiteInteractions;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final class MigrationRunner
{
    private readonly PDO $pdo;

    /**
     * @var resource|null
     */
    private $lockHandle = null;

    public function __construct(
        private readonly SQLiteInteractions $db,
        private readonly string $migrationsDirectory,
        private readonly string $databaseFilePath,
    ) {
        $this->pdo = $db->pdo();
    }

    public function GetCurrentVersion(): int
    {
        $statement = $this->pdo->query('PRAGMA user_version');
        $version   = (int) $statement->fetchColumn();
        $statement->closeCursor();

        return $version;
    }

    public function GetLatestAvailableVersion(): int
    {
        $migrations = $this->DiscoverMigrations();

        return $migrations === [] ? 0 : (int) array_key_last($migrations);
    }

    public function Migrate(): array
    {
        $this->AcquireLock();

        try
        {
            $this->pdo->query('PRAGMA journal_mode = WAL')->fetchAll();
            $this->EnsureMigrationsTable();

            $current  = $this->GetCurrentVersion();
            $pending  = array_filter(
                $this->DiscoverMigrations(),
                static fn (int $version): bool => $version > $current,
                ARRAY_FILTER_USE_KEY
            );

            if ($pending === [])
            {
                return [];
            }

            $this->Backup();

            $applied = [];
            foreach ($pending as $version => $file)
            {
                $this->ApplyOne($version, $file);
                $applied[] = $version . ' — ' . basename($file);
            }

            return $applied;
        }
        finally
        {
            $this->ReleaseLock();
        }
    }

    public function AssertUpToDate(): void
    {
        $current = $this->GetCurrentVersion();
        $latest  = $this->GetLatestAvailableVersion();

        if ($current < $latest)
        {
            throw new RuntimeException(
                "Database schema is behind: file is at version $current, this build expects $latest. "
                . "Run the migration runner before serving."
            );
        }

        if ($current > $latest)
        {
            throw new RuntimeException(
                "Database schema ($current) is newer than this build expects ($latest). "
                . "This image is older than the database; deploy a matching or newer image."
            );
        }
    }










    private function ApplyOne(int $version, string $path): void
    {
        $sql = file_get_contents($path);

        if ($sql === false)
        {
            throw new RuntimeException("Could not read migration file: $path");
        }

        $this->ValidateMigrationSql($sql, $path);

        $this->pdo->exec('PRAGMA foreign_keys = OFF');

        try
        {
            $this->pdo->beginTransaction();

            foreach ($this->SplitStatements($sql) as $statement)
            {
                $this->pdo->exec($statement);
            }

            $check = $this->pdo->query('PRAGMA foreign_key_check');
            $violations = $check->fetchAll();
            $check->closeCursor();

            if ($violations !== [])
            {
                throw new RuntimeException(
                    count($violations) . ' foreign key violation(s) left by the migration'
                );
            }

            $this->pdo->exec("PRAGMA user_version = $version");

            $stmt = $this->pdo->prepare(
                'INSERT INTO _schema_migrations
                (version, name, applied_at_utc)
             VALUES
                (:version, :name, :applied_at)'
            );

            $stmt->execute([
                ':version'    => $version,
                ':name'       => basename($path),
                ':applied_at' => (new DateTimeImmutable(
                    'now',
                    new DateTimeZone('UTC')
                ))->format('Y-m-d H:i:s'),
            ]);

            $this->pdo->commit();
        }
        catch (Throwable $e)
        {
            if ($this->pdo->inTransaction())
            {
                try
                {
                    $this->pdo->rollBack();
                }
                catch (Throwable)
                {
                    // Preserve the original migration error.
                }
            }

            throw new RuntimeException(
                "Migration '" . basename($path)
                . "' failed and was rolled back: {$e->getMessage()}",
                previous: $e
            );
        }
        finally
        {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    private function DiscoverMigrations(): array
    {
        $files = glob(rtrim($this->migrationsDirectory, '/') . '/*.sql');

        if ($files === false)
        {
            throw new RuntimeException("Cannot read migrations directory: {$this->migrationsDirectory}");
        }

        $migrations = [];

        foreach ($files as $path)
        {
            $name = basename($path);

            if (!preg_match('/^(\d+)_/', $name, $matches))
            {
                throw new RuntimeException(
                    "Migration '$name' must start with a zero-padded number, e.g. 0002_add_thing.sql"
                );
            }

            $version = (int) $matches[1];

            if ($version < 1)
            {
                throw new RuntimeException("Migration '$name' has version < 1; numbering starts at 0001.");
            }

            if (isset($migrations[$version]))
            {
                throw new RuntimeException(
                    "Duplicate migration version $version: '$name' and '" . basename($migrations[$version]) . "'"
                );
            }

            $migrations[$version] = $path;
        }

        ksort($migrations);

        return $migrations;
    }

    private function SplitStatements(string $sql): array
    {
        $sql = preg_replace('/--[^\n]*/', '', $sql) ?? $sql;
        $sql = preg_replace('#/\*.*?\*/#s', '', $sql) ?? $sql;

        $statements = [];
        foreach (explode(';', $sql) as $fragment)
        {
            $fragment = trim($fragment);
            if ($fragment !== '')
            {
                $statements[] = $fragment;
            }
        }

        return $statements;
    }

    private function EnsureMigrationsTable(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS _schema_migrations (
                version        INTEGER PRIMARY KEY,
                name           TEXT    NOT NULL,
                applied_at_utc TEXT    NOT NULL
            )"
        );
    }

    private function Backup(): void
    {
        if ($this->databaseFilePath === ':memory:')
        {
            return;
        }

        if (!is_file($this->databaseFilePath))
        {
            return;
        }

        $timestamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Ymd-His');

        foreach (['', '-wal', '-shm'] as $suffix)
        {
            $source = $this->databaseFilePath . $suffix;
            if (is_file($source))
            {
                copy($source, $this->databaseFilePath . '.' . $timestamp . '.bak' . $suffix);
            }
        }
    }

    private function AcquireLock(): void
    {
        if ($this->databaseFilePath === ':memory:')
        {
            return;
        }

        $lockPath = $this->databaseFilePath . '.migrate.lock';
        $handle = fopen($lockPath, 'c');

        if ($handle === false)
        {
            throw new RuntimeException("Cannot open migration lock file: $lockPath");
        }

        if (!flock($handle, LOCK_EX | LOCK_NB))
        {
            fclose($handle);
            throw new RuntimeException("Another migration run is already in progress ($lockPath).");
        }

        $this->lockHandle = $handle;
    }

    private function ReleaseLock(): void
    {
        if ($this->lockHandle !== null)
        {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }
}
