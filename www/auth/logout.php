<?php
declare(strict_types=1);

require_once __DIR__ . "/../config/session.php";

$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$baseUrl = preg_replace('#/(auth|config|dashboard|profiling|reports|import(?: file)?|includes|view profile)$#i', '', $scriptDir) ?: '';
$LOGIN_URL = rtrim($baseUrl, '/') . "/auth/login.php";

if (!function_exists('ahdisLogoutRedirect')) {
    function ahdisLogoutRedirect(string $url): void
    {
        if (!headers_sent()) {
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
            header("Location: " . $url, true, 302);
        } else {
            error_log('LOGOUT REDIRECT ERROR: Headers already sent before redirect to ' . $url);
        }
        exit;
    }
}

if (!headers_sent()) {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("Expires: 0");
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();

    if (PHP_VERSION_ID >= 70300) {
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $p['path'] ?? '/',
            'domain' => $p['domain'] ?? '',
            'secure' => (bool)($p['secure'] ?? false),
            'httponly' => (bool)($p['httponly'] ?? true),
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    } else {
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $p['path'] ?? '/',
            $p['domain'] ?? '',
            (bool)($p['secure'] ?? false),
            (bool)($p['httponly'] ?? true)
        );
    }
}

@session_destroy();

ahdisLogoutRedirect($LOGIN_URL);
