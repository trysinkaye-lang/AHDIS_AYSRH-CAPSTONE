<?php
declare(strict_types=1);

/*
|-----------------------------------------
| HEADER FILE (DEPLOY-SAFE + DYNAMIC TITLE)
|-----------------------------------------
| - Starts session safely
| - Computes $baseUrl for root, subfolder, and PHP Desktop
| - Supports dynamic page title via $pageTitle
| - Uses deployment-safe asset paths
| - Supports folders with spaces like /view profile and /import file
*/

require_once __DIR__ . "/../config/session.php";

/**
 * Base URL builder
 * - Works for domain root: https://site.com/
 * - Works for subfolder:  https://site.com/ahdis/
 * - Works for PHP Desktop: http://127.0.0.1:xxxxx/
 * - Removes known app subfolders from current script path
 */
if (!function_exists('appBaseUrl')) {
    function appBaseUrl(): string
    {
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        $scriptDir = rtrim($scriptDir, '/');

        $folders = [
            'dashboard',
            'profiling',
            'auth',
            'includes',
            'config',
            'reports',
            'view profile',
            'import file',
        ];

        foreach ($folders as $folder) {
            $pattern = '#/' . preg_quote($folder, '#') . '$#';
            $scriptDir = preg_replace($pattern, '', $scriptDir) ?? $scriptDir;
        }

        if ($scriptDir === '/' || $scriptDir === '\\' || $scriptDir === '.') {
            return '';
        }

        return rtrim($scriptDir, '/');
    }
}

$baseUrl = appBaseUrl();

/**
 * Dynamic Page Title
 * - Can be overridden per page
 */
$pageTitle = $pageTitle ?? 'Adolescent Mother Monitoring System';
$htmlClass = trim((string)($htmlClass ?? ''));
$bodyClass = trim((string)($bodyClass ?? ''));

/**
 * Safe asset URL helper
 */
if (!function_exists('assetUrl')) {
    function assetUrl(string $path): string
    {
        global $baseUrl;

        $path = '/' . ltrim(str_replace('\\', '/', $path), '/');
        return $baseUrl . $path;
    }
}
?>
<!DOCTYPE html>
<html lang="en"<?= $htmlClass !== '' ? ' class="' . htmlspecialchars($htmlClass, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> | Adolescent Mother Monitoring System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="stylesheet" href="<?= htmlspecialchars(assetUrl('/assets/css/bootstrap.min.css'), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(assetUrl('/assets/css/style.css'), ENT_QUOTES, 'UTF-8') ?>">
    <?php require_once __DIR__ . "/ui_consistency.php"; ?>

    <script src="<?= htmlspecialchars(assetUrl('/assets/js/chart.umd.min.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
    <script src="<?= htmlspecialchars(assetUrl('/assets/js/html2canvas.local.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
</head>
<body<?= $bodyClass !== '' ? ' class="' . htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
