<?php

declare(strict_types=1);

namespace Auxilium\Utilities;

use Auxilium\ServiceInteractions\SQLiteInteractions;
use DateTimeImmutable;
use DateTimeZone;

final class AdminAuthenticationUtilities
{
    private const SESSION_KEY   = 'aux_status_admin';
    private const SESSION_NAME  = 'AUX_STATUS_SESSION';
    private const IDLE_TIMEOUT  = 3600 * 5;   // 5h of not doing anything ends the session

    public static function StartSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE)
        {
            return;
        }

        session_name(self::SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    public static function AttemptLogin(string $username, string $password): bool
    {
        $db = new SQLiteInteractions();

        $user = $db->query_read_one(
            "SELECT id, username, password_hash, display_name, is_active FROM admin_users WHERE username = :username",
            [
                ':username' => $username,
            ]
        );

        if ($user === null || (int) $user['is_active'] !== 1)
        {
            password_verify($password, '$2y$12$uwu');
            return false;
        }

        if (!password_verify($password, $user['password_hash']))
        {
            return false;
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT))
        {
            $db->query_update(
                "UPDATE admin_users SET password_hash = :hash WHERE id = :id",
                [
                    ':hash' => password_hash($password, PASSWORD_DEFAULT),
                    ':id'   => (int) $user['id'],
                ]
            );
        }

        self::StartSession();

        session_regenerate_id(true);

        $_SESSION[self::SESSION_KEY] = [
            'userId'      => (int) $user['id'],
            'username'    => $user['username'],
            'displayName' => $user['display_name'],
            'loggedInAt'  => time(),
            'lastSeenAt'  => time(),
        ];

        return true;
    }

    public static function IsAuthenticated(): bool
    {
        self::StartSession();

        $session = $_SESSION[self::SESSION_KEY] ?? null;

        if (!is_array($session) || !isset($session['userId'], $session['lastSeenAt']))
        {
            return false;
        }

        if (time() - (int) $session['lastSeenAt'] > self::IDLE_TIMEOUT)
        {
            self::Logout();
            return false;
        }

        $_SESSION[self::SESSION_KEY]['lastSeenAt'] = time();
        return true;
    }

    public static function RequireAuthentication(): void
    {
        if (self::IsAuthenticated())
        {
            return;
        }

        $target = $_SERVER['REQUEST_URI'] ?? '/admin';

        self::StartSession();
        $_SESSION['aux_status_login_redirect'] = $target;

        NavigationUtilities::Redirect(target: "/admin/login");
    }

    public static function CurrentUser(): ?array
    {
        return self::IsAuthenticated() ? $_SESSION[self::SESSION_KEY] : null;
    }

    public static function Logout(): void
    {
        self::StartSession();

        $_SESSION = [];

        if (ini_get('session.use_cookies'))
        {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                [
                    'expires'  => time() - 42000,
                    'path'     => $params['path'],
                    'domain'   => $params['domain'],
                    'secure'   => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => $params['samesite'] ?? 'Strict',
                ]
            );
        }

        session_destroy();
    }

    public static function Token(): string
    {
        self::StartSession();

        if (empty($_SESSION['aux_status_csrf']))
        {
            $_SESSION['aux_status_csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['aux_status_csrf'];
    }

    public static function ValidateToken(?string $token): bool
    {
        self::StartSession();

        $expected = $_SESSION['aux_status_csrf'] ?? '';

        return $expected !== ''
            && is_string($token)
            && hash_equals($expected, $token);
    }

    public static function CreateUser(string $username, string $password, string $displayName): int
    {
        $db = new SQLiteInteractions();

        return $db->query_insert(
            "INSERT INTO admin_users (created_at_utc, username, password_hash, display_name, is_active)
                    VALUES (:created_at, :username, :hash, :display_name, 1);",
            [
                ':created_at'   => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                ':username'     => $username,
                ':hash'         => password_hash($password, PASSWORD_DEFAULT),
                ':display_name' => $displayName,
            ]
        );
    }
}
