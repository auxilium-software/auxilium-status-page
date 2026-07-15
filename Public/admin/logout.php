<?php

declare(strict_types=1);

use Auxilium\Utilities\AdminAuthenticationUtilities;

require_once __DIR__ . "/../../vendor/autoload.php";

AdminAuthenticationUtilities::StartSession();

if ($_SERVER["REQUEST_METHOD"] !== "POST")
{
    header("Location: /admin");
    exit;
}

$token = $_POST["csrf_token"] ?? null;

if (!AdminAuthenticationUtilities::ValidateToken(is_string($token) ? $token : null))
{
    header("Location: /admin");
    exit;
}

AdminAuthenticationUtilities::Logout();

header("Location: /admin/login");
exit;
