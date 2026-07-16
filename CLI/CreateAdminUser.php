<?php

declare(strict_types=1);

use Auxilium\Utilities\AdminAuthenticationUtilities;

require_once __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI !== 'cli')
{
    http_response_code(404);
    die();
}

$username    = $argv[1] ?? null;
$displayName = $argv[2] ?? null;

if ($username === null || $displayName === null)
{
    fwrite(STDERR, "Usage: php CLI/CreateAdminUser.php <username> \"<display name>\"\n");
    exit(1);
}

echo "Password: ";

// windows doesn't have `stty` so we just put up with visible prompts
$isWindows = str_starts_with(strtoupper(PHP_OS_FAMILY), 'WIN');

if (!$isWindows)
{
    shell_exec('stty -echo');
}

$password = trim((string) fgets(STDIN));

if (!$isWindows)
{
    shell_exec('stty echo');
}

echo "\n";

if (strlen($password) < 12)
{
    fwrite(STDERR, "Password must be at least 12 characters.\n");
    exit(1);
}

$id = AdminAuthenticationUtilities::CreateUser($username, $password, $displayName);

echo "Created admin user '$username' (id $id).\n";
