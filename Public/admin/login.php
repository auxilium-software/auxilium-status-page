<?php

declare(strict_types=1);

use Auxilium\TwigHandling\PageBuilder;
use Auxilium\Utilities\AdminAuthenticationUtilities;

require_once __DIR__ . "/../../vendor/autoload.php";

AdminAuthenticationUtilities::StartSession();

if (AdminAuthenticationUtilities::IsAuthenticated())
{
    header("Location: /admin");
    exit;
}

$errorMessage = null;

if ($_SERVER["REQUEST_METHOD"] === "POST")
{
    $username = trim((string) ($_POST["username"] ?? ""));
    $password = (string) ($_POST["password"] ?? "");
    $token    = $_POST["csrf_token"] ?? null;

    if (!AdminAuthenticationUtilities::ValidateToken(is_string($token) ? $token : null))
    {
        $errorMessage = "Your session expired. Please try again.";
    }
    elseif ($username === "" || $password === "")
    {
        $errorMessage = "Please enter both a username and a password.";
    }
    elseif (AdminAuthenticationUtilities::AttemptLogin($username, $password))
    {
        $redirect = $_SESSION["aux_status_login_redirect"] ?? "/admin";
        unset($_SESSION["aux_status_login_redirect"]);

        if (!is_string($redirect) || !str_starts_with($redirect, "/") || str_starts_with($redirect, "//"))
        {
            $redirect = "/admin";
        }

        header("Location: " . $redirect);
        exit;
    }
    else
    {
        $errorMessage = "Invalid username or password.";
    }
}

PageBuilder::Render(
    template: "/Pages/admin/login.html.twig",
    variables: [
        "CsrfToken"    => AdminAuthenticationUtilities::Token(),
        "ErrorMessage" => $errorMessage,
    ],
);
