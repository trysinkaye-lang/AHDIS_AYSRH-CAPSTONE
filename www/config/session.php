<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

if (!function_exists('ahdis_ensure_dir')) {
    function ahdis_ensure_dir(string $dir): bool
    {
        if ($dir === '') {
            return false;
        }

        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        if (!is_dir($dir)) {
            return false;
        }

        if (!is_writable($dir)) {
            @chmod($dir, 0777);
        }

        return is_writable($dir);
    }
}

$localAppData = (string)getenv('LOCALAPPDATA');
$ahdisBaseDir = ($localAppData !== ''
    ? rtrim($localAppData, "\\/")
    : rtrim(sys_get_temp_dir(), "\\/")) . DIRECTORY_SEPARATOR . 'AHDIS';

$ahdisSessionDir = $ahdisBaseDir . DIRECTORY_SEPARATOR . 'sessions';
$ahdisTempDir = $ahdisBaseDir . DIRECTORY_SEPARATOR . 'temp';

ahdis_ensure_dir($ahdisBaseDir);
ahdis_ensure_dir($ahdisSessionDir);
ahdis_ensure_dir($ahdisTempDir);

$ahdisErrorLog = $ahdisBaseDir . DIRECTORY_SEPARATOR . 'php_error.log';

@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('log_errors', '1');
@ini_set('error_log', $ahdisErrorLog);
error_reporting(E_ALL);

if (ob_get_level() === 0) {
    ob_start();
}

if (!defined('AHDIS_LOCAL_APPDATA_DIR')) {
    define('AHDIS_LOCAL_APPDATA_DIR', $ahdisBaseDir);
}

if (!defined('AHDIS_SESSION_DIR')) {
    define('AHDIS_SESSION_DIR', $ahdisSessionDir);
}

if (!defined('AHDIS_TEMP_DIR')) {
    define('AHDIS_TEMP_DIR', $ahdisTempDir);
}

if (!defined('AHDIS_PHP_ERROR_LOG')) {
    define('AHDIS_PHP_ERROR_LOG', $ahdisErrorLog);
}

if (session_status() === PHP_SESSION_ACTIVE) {
    return;
}

@ini_set('session.use_strict_mode', '1');
@ini_set('session.use_cookies', '1');
@ini_set('session.use_only_cookies', '1');
@ini_set('session.use_trans_sid', '0');
@ini_set('session.cookie_httponly', '1');
@ini_set('session.cookie_samesite', 'Lax');
@ini_set('session.gc_probability', '1');
@ini_set('session.gc_divisor', '100');
@ini_set('session.gc_maxlifetime', '86400');

session_name('AHDISSESSID');
@session_save_path($ahdisSessionDir);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => false,
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (!@session_start()) {
    error_log('[AHDIS session] Unable to start session. save_path=' . $ahdisSessionDir);
    $_SESSION = [];
    return;
}

if (empty($_SESSION['_init'])) {
    @session_regenerate_id(true);
    $_SESSION['_init'] = 1;
}
