<?php

declare(strict_types=1);

use Auxilium\Database\MigrationRunner;
use Auxilium\ServiceInteractions\SQLiteInteractions;
use Auxilium\Utilities\ConfigurationUtilities;

require_once __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI !== 'cli')
{
    http_response_code(404);
    exit;
}

$databaseFilePath = ConfigurationUtilities::GetUserConfiguration()['SQLite']['DatabaseFilePath'] ?? throw new Exception("Config element (SQLite->DatabaseFilePath) not found.");

$runner = new MigrationRunner(
    db:                  new SQLiteInteractions(),
    migrationsDirectory: __DIR__ . '/../Migrations',
    databaseFilePath:    $databaseFilePath,
);

fwrite(STDOUT, "Current schema version: " . $runner->GetCurrentVersion() . "\n");
fwrite(STDOUT, "Latest available:       " . $runner->GetLatestAvailableVersion() . "\n");

try
{
    $applied = $runner->Migrate();
}
catch (Throwable $e)
{
    fwrite(STDERR, "MIGRATION FAILED: {$e->getMessage()}\n");
    fwrite(STDERR, "The database was left untouched (last migration rolled back). A .bak copy was taken before the run.\n");
    exit(1);
}

if ($applied === [])
{
    fwrite(STDOUT, "Already up to date. Nothing to apply.\n");
}
else
{
    fwrite(STDOUT, "Applied " . count($applied) . " migration(s):\n");
    foreach ($applied as $entry)
    {
        fwrite(STDOUT, "  - $entry\n");
    }
    fwrite(STDOUT, "Now at version " . $runner->GetCurrentVersion() . ".\n");
}

exit(0);
