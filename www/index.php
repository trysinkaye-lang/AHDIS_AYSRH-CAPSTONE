<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

$localAppData = (string)getenv('LOCALAPPDATA');
$ahdisBaseDir = ($localAppData !== ''
    ? rtrim($localAppData, "\\/")
    : rtrim(sys_get_temp_dir(), "\\/")) . DIRECTORY_SEPARATOR . 'AHDIS';

if (!is_dir($ahdisBaseDir)) {
    @mkdir($ahdisBaseDir, 0777, true);
}

$ahdisErrorLog = $ahdisBaseDir . DIRECTORY_SEPARATOR . 'php_error.log';

@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('log_errors', '1');
@ini_set('error_log', $ahdisErrorLog);
error_reporting(E_ALL);

ob_start();

try {
    require_once __DIR__ . '/config/session.php';
} catch (Throwable $e) {
    error_log('[AHDIS startup] session bootstrap failed: ' . $e->getMessage());
}

$loginPath = '/auth/login.php';
$loginFile = __DIR__ . '/auth/login.php';

if (!is_file($loginFile)) {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    error_log('[AHDIS startup] Login file missing: ' . $loginFile);
    http_response_code(500);
    exit('Internal Server Error');
}

while (ob_get_level() > 0) {
    @ob_end_clean();
}

if (!headers_sent()) {
    header('Location: ' . $loginPath, true, 302);
}

exit;
