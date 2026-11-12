<?php
declare(strict_types=1);

/**
 * /config/auth.php
 *
 * FULL UPDATED VERSION
 * - PHP Desktop safe
 * - Uses local error logging
 * - Avoids malformed header issues
 * - Safer redirects
 * - Supports subfolder deployment
 * - Supports "import file" folder name
 * - Validates logged-in user safely
 */

/* -------------------------
   Error logging
------------------------- */
$local = (string)getenv('LOCALAPPDATA');

$logBase = $local !== ''
    ? (rtrim($local, "\\/") . DIRECTORY_SEPARATOR . 'AHDIS')
    : (rtrim(sys_get_temp_dir(), "\\/") . DIRECTORY_SEPARATOR . 'AHDIS');

if (!is_dir($logBase)) {
    @mkdir($logBase, 0777, true);
}

@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('log_errors', '1');
@ini_set('error_log', $logBase . DIRECTORY_SEPARATOR . 'php_error.log');
error_reporting(E_ALL);

require_once __DIR__ . "/session.php";
require_once __DIR__ . "/database.php";

/* -------------------------
   Helpers
------------------------- */
if (!function_exists('endsWithPath')) {
    function endsWithPath(string $path, string $suffix): bool
    {
        $path = str_replace('\\', '/', $path);
        $suffix = str_replace('\\', '/', $suffix);

        $lp = strlen($path);
        $ls = strlen($suffix);

        if ($ls === 0) {
            return true;
        }
        if ($ls > $lp) {
            return false;
        }

        return substr($path, $lp - $ls) === $suffix;
    }
}

if (!function_exists('safeRedirect')) {
    function safeRedirect(string $url, int $statusCode = 302): void
    {
        if (!headers_sent()) {
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
            header('Location: ' . $url, true, $statusCode);
        } else {
            error_log('REDIRECT ERROR: Headers already sent before redirect to ' . $url);
        }
        exit;
    }
}

if (!function_exists('killSessionAndRedirect')) {
    function killSessionAndRedirect(string $loginUrl): void
    {
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                if (PHP_VERSION_ID >= 70300) {
                    setcookie(session_name(), '', [
                        'expires' => time() - 42000,
                        'path' => $params['path'] ?? '/',
                        'domain' => $params['domain'] ?? '',
                        'secure' => (bool)($params['secure'] ?? false),
                        'httponly' => (bool)($params['httponly'] ?? true),
                        'samesite' => $params['samesite'] ?? 'Lax',
                    ]);
                } else {
                    setcookie(
                        session_name(),
                        '',
                        time() - 42000,
                        $params['path'] ?? '/',
                        $params['domain'] ?? '',
                        (bool)($params['secure'] ?? false),
                        (bool)($params['httponly'] ?? true)
                    );
                }
            }

            @session_destroy();
        }

        safeRedirect($loginUrl);
    }
}

/* -------------------------
   Resolve base URL
------------------------- */
$uriPath = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');

/**
 * Removes known module folders dynamically.
 * Supports:
 * - /auth
 * - /dashboard
 * - /profiling
 * - /reports
 * - /import
 * - /import file
 * - /includes
 * - /view profile
 */
$baseUrl = preg_replace(
    '#/(config|auth|dashboard|profiling|reports|import(?: file)?|includes|view profile)$#i',
    '',
    $scriptDir
);

$baseUrl = $baseUrl ?: '';

$LOGIN_URL = $baseUrl . '/auth/login.php';
$LOCK_URL = $baseUrl . '/auth/lock.php';

/* -------------------------
   Prevent login loop
------------------------- */
if (endsWithPath($uriPath, '/auth/login.php')) {
    return;
}

/* -------------------------
   No cache headers
------------------------- */
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

/* -------------------------
   Check session
------------------------- */
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    safeRedirect($LOGIN_URL);
}

if (!empty($_SESSION['session_locked'])) {
    safeRedirect($LOCK_URL);
}

/* -------------------------
   Ensure DB is available
------------------------- */
if (!isset($conn) || !($conn instanceof PDO)) {
    error_log('AUTH ERROR: $conn is not a valid PDO instance.');
    killSessionAndRedirect($LOGIN_URL);
}

/* -------------------------
   Validate logged-in user
------------------------- */
try {
    $hasStatus = false;

    $colsStmt = $conn->query('PRAGMA table_info(users)');
    $cols = $colsStmt ? ($colsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    foreach ($cols as $c) {
        if (isset($c['name']) && strcasecmp((string)$c['name'], 'status') === 0) {
            $hasStatus = true;
            break;
        }
    }

    if ($hasStatus) {
        $stmt = $conn->prepare('SELECT id, status FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);

        $status = strtolower(trim((string)($u['status'] ?? '')));

        if (!$u || $status !== 'active') {
            killSessionAndRedirect($LOGIN_URL);
        }
    } else {
        $stmt = $conn->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);

        if (!$stmt->fetchColumn()) {
            killSessionAndRedirect($LOGIN_URL);
        }
    }
} catch (Throwable $e) {
    error_log('AUTH ERROR: ' . $e->getMessage());
    killSessionAndRedirect($LOGIN_URL);
}
