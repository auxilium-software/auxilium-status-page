<?php

declare(strict_types=1);

use Auxilium\Utilities\AdminAuthenticationUtilities;
use Auxilium\Utilities\NavigationUtilities;

require_once __DIR__ . "/../../vendor/autoload.php";

AdminAuthenticationUtilities::StartSession();

if ($_SERVER["REQUEST_METHOD"] !== "POST")
{
    NavigationUtilities::Redirect(target: "/admin");
}

$token = $_POST["csrf_token"] ?? null;

if (!AdminAuthenticationUtilities::ValidateToken(is_string($token) ? $token : null))
{
    NavigationUtilities::Redirect(target: "/admin");
}

AdminAuthenticationUtilities::Logout();

NavigationUtilities::Redirect(target: "/admin/login");
