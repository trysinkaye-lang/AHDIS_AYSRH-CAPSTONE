<?php
declare(strict_types=1);

require_once __DIR__ . "/../config/session.php";

require_once __DIR__ . "/../config/database.php";

/* =========================
   SAFE HELPERS
========================= */
if (!function_exists('sb_h')) {
    function sb_h($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('sb_normalize_path')) {
    function sb_normalize_path(string $path): string {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#/+#', '/', $path) ?? $path;

        if ($path === '') {
            return '/';
        }

        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        if (strlen($path) > 1) {
            $path = rtrim($path, '/');
        }

        return $path;
    }
}

if (!function_exists('sb_detect_base_url')) {
    function sb_detect_base_url(): string {
        $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? (string)$_SERVER['DOCUMENT_ROOT'] : '';
        $appRoot = realpath(__DIR__ . '/..');

        if ($docRoot === '' || $appRoot === false) {
            return '';
        }

        $docRootReal = realpath($docRoot);
        if ($docRootReal !== false) {
            $docRoot = $docRootReal;
        }

        $docRoot = str_replace('\\', '/', $docRoot);
        $appRoot = str_replace('\\', '/', $appRoot);

        $docRoot = rtrim($docRoot, '/');
        $appRoot = rtrim($appRoot, '/');

        if ($appRoot === $docRoot) {
            return '';
        }

        if (str_starts_with($appRoot, $docRoot . '/')) {
            return substr($appRoot, strlen($docRoot));
        }

        return '';
    }
}

if (!function_exists('sb_current_path_full')) {
    function sb_current_path_full(): string {
        $uri  = (string)($_SERVER['REQUEST_URI'] ?? '');
        $path = (string)parse_url($uri, PHP_URL_PATH);
        $path = $path !== '' ? $path : $uri;
        return sb_normalize_path($path);
    }
}

if (!function_exists('sb_current_path_rel')) {
    function sb_current_path_rel(string $baseUrl): string {
        $current = sb_current_path_full();
        $baseUrl = trim(str_replace('\\', '/', $baseUrl));

        if ($baseUrl === '' || $baseUrl === '/') {
            return sb_normalize_path($current);
        }

        $baseUrl = sb_normalize_path($baseUrl);

        if ($baseUrl !== '/' && str_starts_with($current, $baseUrl . '/')) {
            $current = substr($current, strlen($baseUrl));
        } elseif ($baseUrl !== '/' && $current === $baseUrl) {
            $current = '/';
        }

        return sb_normalize_path($current);
    }
}

if (!function_exists('sb_path_matches')) {
    function sb_path_matches(string $current, string $needle): bool {
        $current = sb_normalize_path($current);
        $needle  = sb_normalize_path($needle);

        if ($current === $needle) {
            return true;
        }

        return str_starts_with($current, $needle . '/');
    }
}

if (!function_exists('sb_active')) {
    function sb_active(string $baseUrl, array $needles): bool {
        $current = sb_current_path_rel($baseUrl);

        foreach ($needles as $needle) {
            if (sb_path_matches($current, (string)$needle)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('sb_url')) {
    function sb_url(string $baseUrl, string $path): string {
        $baseUrl = rtrim(str_replace('\\', '/', $baseUrl), '/');
        $path    = '/' . ltrim(str_replace('\\', '/', $path), '/');
        return ($baseUrl === '' ? '' : $baseUrl) . $path;
    }
}

/* =========================
   USER
========================= */
$usernameRaw = (string)($_SESSION['username'] ?? 'Admin');
$username    = sb_h($usernameRaw);

/* =========================
   BASE URL
========================= */
$baseUrl = sb_detect_base_url();

/* =========================
   USER AVATAR FALLBACK
========================= */
$avatarLetter = 'A';
if ($usernameRaw !== '') {
    if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
        $avatarLetter = mb_strtoupper(mb_substr($usernameRaw, 0, 1, 'UTF-8'), 'UTF-8');
    } else {
        $avatarLetter = strtoupper(substr($usernameRaw, 0, 1));
    }
}

/* =========================
   PROJECT BRAND BANNER
========================= */
$projectBrandRel  = "/assets/img/project2528.png";
$projectBrandFile = __DIR__ . "/.." . $projectBrandRel;
$projectBrandUrl  = sb_url($baseUrl, "assets/img/project2528.png");
$projectBrandVer  = is_file($projectBrandFile) ? (string)filemtime($projectBrandFile) : (string)time();
$projectBrandSrc  = $projectBrandUrl . "?v=" . rawurlencode($projectBrandVer);

/* =========================
   PROVINCE LOGO (FOOTER)
========================= */
$provinceLogoUrl = '';
$userId = (int)($_SESSION['user_id'] ?? 0);

if (isset($conn) && $conn instanceof PDO && $userId > 0) {
    try {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS province_logos (
                province TEXT PRIMARY KEY,
                logo_path TEXT NOT NULL,
                uploaded_by INTEGER,
                updated_at TEXT NOT NULL
            )
        ");

        $stmtUser = $conn->prepare("
            SELECT COALESCE(province, '') AS province
            FROM users
            WHERE id = :id
            LIMIT 1
        ");
        $stmtUser->execute([':id' => $userId]);
        $userRow = $stmtUser->fetch(PDO::FETCH_ASSOC);

        $province = $userRow ? (string)($userRow['province'] ?? '') : '';

        if ($province !== '') {
            $stmtLogo = $conn->prepare("
                SELECT logo_path
                FROM province_logos
                WHERE province = :province
                LIMIT 1
            ");
            $stmtLogo->execute([':province' => $province]);
            $logoRow = $stmtLogo->fetch(PDO::FETCH_ASSOC);

            $logoPath = $logoRow ? (string)($logoRow['logo_path'] ?? '') : '';

            if ($logoPath !== '' && str_starts_with($logoPath, '/assets/logos/provinces/')) {
                $absLogo = rtrim(__DIR__ . "/..", "/\\") . $logoPath;
                $ver = is_file($absLogo) ? (string)@filemtime($absLogo) : (string)time();
                $provinceLogoUrl = sb_url($baseUrl, ltrim($logoPath, '/')) . '?v=' . rawurlencode($ver);
            }
        }
    } catch (Throwable $e) {
        // ignore safely
    }
}

/* =========================
   NAV LINKS
========================= */
$dashboardHref     = sb_url($baseUrl, 'dashboard/index.php');
$addProfileHref    = sb_url($baseUrl, 'profiling/add.php');
$viewProfilesHref  = sb_url($baseUrl, 'view profile/view.php');
$exportHref        = sb_url($baseUrl, 'profiling/export_excel.php');
$importHref        = sb_url($baseUrl, 'import file/index.php');
$reportsHref       = sb_url($baseUrl, 'reports/index.php');
$accountHref       = sb_url($baseUrl, 'auth/account.php');
$logoutHref        = sb_url($baseUrl, 'auth/logout.php');
?>
<script>
(function(){
    const sidebarKey = 'ahdis.sidebar.collapsed';
    let collapsed = false;
    try {
        const stored = localStorage.getItem(sidebarKey);
        collapsed = stored === null
            ? !!(window.matchMedia && window.matchMedia('(max-width: 900px)').matches)
            : stored === '1';
    } catch (e) {}
    document.body.classList.toggle('sidebar-collapsed', collapsed);
})();
</script>
<style>
:root{
    --sb-w: 252px;
    --sb-w-open: 252px;
    --sb-w-collapsed: 82px;

    --sb-page-bg: #f3f6fb;
    --sb-shell-bg: linear-gradient(180deg, #f8fbff 0%, #eef4fb 100%);
    --sb-card: rgba(255,255,255,.88);
    --sb-card-strong: #ffffff;
    --sb-border: #d9e4f2;
    --sb-border-soft: rgba(217,228,242,.75);

    --sb-text: #11233f;
    --sb-text-soft: #5f6f86;
    --sb-muted: #7b8aa0;

    --sb-hover: #eef4ff;
    --sb-active: linear-gradient(135deg, #e9f1ff 0%, #e9ecff 100%);
    --sb-accent: #264aa8;
    --sb-accent-2: #db89c8;
    --sb-accent-deep: #17356f;

    --sb-shadow: 0 14px 34px rgba(22, 52, 109, .09);
    --sb-shadow-soft: 0 6px 18px rgba(32, 59, 119, .07);
}

html, body{
    height:100%;
    margin:0;
    background:var(--sb-page-bg);
    font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
    overflow-x:hidden;
}

.app-shell{
    --sb-w: var(--sb-w-open);
    display:flex;
    min-height:100vh;
    width:100%;
}

.app-shell.sidebar-collapsed,
body.sidebar-collapsed .app-shell{
    --sb-w: var(--sb-w-collapsed);
}

.sidebar{
    position:fixed;
    top:0;
    left:0;
    width:var(--sb-w);
    height:100vh;
    padding:8px;
    box-sizing:border-box;
    z-index:100;
    background:transparent;
    transition:width 180ms ease;
}

.sb-card{
    height:100%;
    display:flex;
    flex-direction:column;
    padding:12px;
    box-sizing:border-box;
    border-radius:22px;
    background:var(--sb-shell-bg);
    border:1px solid var(--sb-border-soft);
    box-shadow:var(--sb-shadow);
    position:relative;
    overflow:hidden;
    transition:background-color 120ms ease, border-color 120ms ease, box-shadow 120ms ease;
}

.sb-card::before{
    content:"";
    position:absolute;
    inset:0 0 auto 0;
    height:150px;
    background:
        radial-gradient(circle at top left, rgba(37,99,235,.13), transparent 46%),
        radial-gradient(circle at top right, rgba(219,137,200,.16), transparent 40%);
    pointer-events:none;
}

.app-main{
    margin-left:var(--sb-w);
    width:calc(100vw - var(--sb-w));
    max-width:calc(100vw - var(--sb-w));
    min-width:0;
    min-height:100vh;
    overflow-y:auto;
    overflow-x:hidden;
    padding:24px;
    box-sizing:border-box;
    display:flex;
    flex-direction:column;
    transition:none;
}

.page-content{
    flex:1 0 auto;
    min-height:0;
}

.sb-brand{
    position:relative;
    z-index:1;
    margin-bottom:10px;
}

.sb-topbar{
    position:relative;
    z-index:2;
    display:flex;
    align-items:center;
    justify-content:flex-end;
    min-height:38px;
    margin-bottom:8px;
}

.sb-toggle{
    width:38px;
    height:38px;
    border-radius:11px;
    border:1px solid var(--sb-border);
    background:#ffffff;
    color:var(--sb-accent-deep);
    display:inline-grid;
    place-items:center;
    cursor:pointer;
    box-shadow:var(--sb-shadow-soft);
    transition:background-color 120ms ease, border-color 120ms ease, color 120ms ease;
}

.sb-toggle:hover{
    background:var(--sb-hover);
    border-color:#cfe0ff;
}

.sb-brand-banner{
    width:100%;
    height:100px;
    border-radius:16px;
    overflow:hidden;
    border:1px solid var(--sb-border);
    background:
        linear-gradient(180deg, rgba(255,255,255,.96), rgba(248,250,255,.94));
    box-shadow:var(--sb-shadow-soft);
    padding:10px;
    box-sizing:border-box;
    display:flex;
    align-items:center;
    justify-content:center;
    min-height:100px;
}

#projectBrandBanner{
    width:100% !important;
    max-width:100% !important;
    height:auto !important;
    max-height:78px !important;
    object-fit:contain !important;
    display:block !important;
}

.sb-nav-wrap{
    position:relative;
    z-index:1;
    flex:1 1 auto;
    display:flex;
    flex-direction:column;
    min-height:0;
    overflow-x:hidden;
}

.sb-nav{
    display:flex;
    flex-direction:column;
    gap:5px;
    margin-top:0;
}

.sidebar a{
    color:var(--sb-text);
    text-decoration:none;
}

.sb-section-label{
    display:flex;
    align-items:center;
    gap:8px;
    margin:3px 10px 0;
    font-size:10px;
    font-weight:700;
    color:var(--sb-muted);
    text-transform:uppercase;
    letter-spacing:.10em;
}

.sb-section-label::before{
    content:"";
    width:18px;
    height:1px;
    background:var(--sb-border);
    display:block;
}

.sb-divider{
    margin:7px 8px 0;
    border-top:1px solid var(--sb-border);
}

.sidebar .sb-nav > a.sb-link,
.sidebar .sb-nav > a.sb-link:hover,
.sidebar .sb-nav > a.sb-link:focus,
.sidebar .sb-nav > a.sb-link:focus-visible,
.sidebar .sb-nav > a.sb-link:active,
.sidebar .sb-nav > a.sb-link.active{
    display:flex !important;
    align-items:center !important;
    gap:10px !important;
    min-height:44px !important;
    height:44px !important;
    padding:0 12px !important;
    margin:0 !important;
    box-sizing:border-box !important;
    font-size:13.5px !important;
    font-weight:700 !important;
    line-height:1 !important;
    letter-spacing:normal !important;
    text-transform:none !important;
    border-radius:12px !important;
    border:1px solid transparent !important;
    outline:none !important;
    text-decoration:none !important;
    transition:
        background-color 120ms ease,
        border-color 120ms ease,
        color 120ms ease,
        opacity 120ms ease !important;
    box-shadow:none !important;
    filter:none !important;
    transform:none !important;
    animation:none !important;
    color:var(--sb-text) !important;
    background:transparent !important;
    position:relative;
    overflow:hidden;
    -webkit-tap-highlight-color:transparent;
}

.sidebar .sb-nav > a.sb-link::after{
    content:"";
    position:absolute;
    left:0;
    top:8px;
    bottom:8px;
    width:3px;
    border-radius:0 8px 8px 0;
    background:transparent;
    transition:none;
}

.sidebar .sb-nav > a.sb-link:hover{
    background:var(--sb-hover) !important;
    border-color:#deebff !important;
    transform:none !important;
}

.sidebar .sb-nav > a.sb-link:active,
.sidebar .sb-nav > a.sb-link:focus,
.sidebar .sb-nav > a.sb-link:focus-visible{
    transform:none !important;
    animation:none !important;
    box-shadow:none !important;
    filter:none !important;
}

.sidebar .sb-nav > a.sb-link.active{
    background:var(--sb-active) !important;
    color:var(--sb-accent) !important;
    border-color:#cfe0ff !important;
    font-weight:700 !important;
    box-shadow:none !important;
    transform:none !important;
}

.sidebar .sb-nav > a.sb-link.active::after{
    background:linear-gradient(180deg, #2958d6 0%, #d784c6 100%);
}

.sb-icon{
    width:19px;
    height:19px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    flex:0 0 19px;
    color:inherit;
    opacity:.95;
}

.sb-icon svg{
    width:19px;
    height:19px;
    display:block;
}

.sb-link-text{
    flex:1 1 auto;
    font-weight:1000;
    white-space:nowrap;
    overflow:hidden;
    transition:opacity 120ms ease;
}

.sb-footer{
    position:relative;
    z-index:1;
    margin-top:10px;
    padding:10px;
    border-radius:15px;
    background:linear-gradient(180deg, rgba(255,255,255,.96), rgba(247,250,255,.94));
    border:1px solid var(--sb-border);
    box-shadow:var(--sb-shadow-soft);
    display:flex;
    align-items:flex-start;
    justify-content:flex-start;
    gap:10px;
    flex-wrap:nowrap;
}

.sb-avatar{
    width:42px;
    height:42px;
    border-radius:13px;
    flex:0 0 42px;
    background:
        linear-gradient(180deg, #ffffff 0%, #f4f8ff 100%);
    border:1px solid var(--sb-border);
    display:grid;
    place-items:center;
    overflow:hidden;
    box-sizing:border-box;
    font-weight:700;
    font-size:18px;
    color:var(--sb-accent);
    box-shadow:inset 0 1px 0 rgba(255,255,255,.75);
}

.sb-avatar img{
    width:100%;
    height:100%;
    object-fit:contain;
    background:#fff;
    padding:4px;
    display:block;
    box-sizing:border-box;
}

.sb-user{
    min-width:0;
    flex:1 1 auto;
    display:flex;
    flex-direction:column;
    justify-content:flex-start;
}

.sb-user .label{
    font-size:10.5px;
    color:var(--sb-muted);
    font-weight:600;
    margin-bottom:1px;
    letter-spacing:.02em;
}

.sb-user .name{
    font-size:13.5px;
    line-height:1.2;
    font-weight:700;
    color:var(--sb-text);
    overflow-wrap:anywhere;
    margin-bottom:0;
}

.sb-user-actions{
    display:flex;
    align-items:center;
    justify-content:flex-start;
    gap:5px;
    flex-wrap:wrap;
    margin-top:6px;
}

.sb-logout-btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:5px;
    min-height:28px;
    padding:0 9px;
    border-radius:9px;
    text-decoration:none;
    background:#fff1f2;
    color:#be123c;
    border:1px solid #fecdd3;
    font-size:11.5px;
    font-weight:700;
    white-space:nowrap;
    transition:background-color 120ms ease, border-color 120ms ease, color 120ms ease;
}

.sb-logout-btn:hover{
    background:#ffe4e6;
    border-color:#fda4af;
}

.sb-logout-icon{
    display:none;
    width:32px;
    height:32px;
    border-radius:12px;
    border:1px solid #fecdd3;
    background:#fff1f2;
    color:#be123c;
    align-items:center;
    justify-content:center;
    text-decoration:none;
    transition:background-color 120ms ease, border-color 120ms ease, color 120ms ease;
}

.sb-logout-icon:hover{
    background:#ffe4e6;
    border-color:#fda4af;
}

.app-shell.sidebar-collapsed .sidebar,
body.sidebar-collapsed .app-shell .sidebar{
    padding:8px;
}

.app-shell.sidebar-collapsed .sb-card,
body.sidebar-collapsed .app-shell .sb-card{
    padding:8px;
    border-radius:20px;
}

.app-shell.sidebar-collapsed .sb-topbar,
body.sidebar-collapsed .app-shell .sb-topbar{
    justify-content:center;
}

.app-shell.sidebar-collapsed .sb-brand,
body.sidebar-collapsed .app-shell .sb-brand{
    margin-bottom:8px;
}

.app-shell.sidebar-collapsed .sb-brand-banner,
body.sidebar-collapsed .app-shell .sb-brand-banner{
    height:52px;
    min-height:52px;
    padding:5px;
    border-radius:13px;
}

.app-shell.sidebar-collapsed #projectBrandBanner,
body.sidebar-collapsed .app-shell #projectBrandBanner{
    max-height:38px !important;
}

.app-shell.sidebar-collapsed .sidebar .sb-nav > a.sb-link,
.app-shell.sidebar-collapsed .sidebar .sb-nav > a.sb-link:hover,
.app-shell.sidebar-collapsed .sidebar .sb-nav > a.sb-link:focus,
.app-shell.sidebar-collapsed .sidebar .sb-nav > a.sb-link:focus-visible,
.app-shell.sidebar-collapsed .sidebar .sb-nav > a.sb-link:active,
.app-shell.sidebar-collapsed .sidebar .sb-nav > a.sb-link.active,
body.sidebar-collapsed .app-shell .sidebar .sb-nav > a.sb-link,
body.sidebar-collapsed .app-shell .sidebar .sb-nav > a.sb-link:hover,
body.sidebar-collapsed .app-shell .sidebar .sb-nav > a.sb-link:focus,
body.sidebar-collapsed .app-shell .sidebar .sb-nav > a.sb-link:focus-visible,
body.sidebar-collapsed .app-shell .sidebar .sb-nav > a.sb-link:active,
body.sidebar-collapsed .app-shell .sidebar .sb-nav > a.sb-link.active{
    justify-content:center !important;
    gap:0 !important;
    padding:0 !important;
    width:44px !important;
    min-height:44px !important;
    height:44px !important;
    align-self:center !important;
}

.app-shell.sidebar-collapsed .sb-link-text,
.app-shell.sidebar-collapsed .sb-section-label,
.app-shell.sidebar-collapsed .sb-logout-btn span,
body.sidebar-collapsed .app-shell .sb-link-text,
body.sidebar-collapsed .app-shell .sb-section-label,
body.sidebar-collapsed .app-shell .sb-logout-btn span{
    opacity:0;
    max-width:0;
    width:0;
    overflow:hidden;
    pointer-events:none;
}

.app-shell.sidebar-collapsed .sb-user,
body.sidebar-collapsed .app-shell .sb-user{
    display:none !important;
}

.app-shell.sidebar-collapsed .sb-section-label,
body.sidebar-collapsed .app-shell .sb-section-label{
    margin:6px 0;
    height:1px;
}

.app-shell.sidebar-collapsed .sb-section-label::before,
body.sidebar-collapsed .app-shell .sb-section-label::before{
    width:100%;
}

.app-shell.sidebar-collapsed .sb-divider,
body.sidebar-collapsed .app-shell .sb-divider{
    margin:7px 4px;
}

.app-shell.sidebar-collapsed .sb-footer,
body.sidebar-collapsed .app-shell .sb-footer{
    width:100%;
    justify-content:center;
    align-items:center;
    flex-direction:column;
    padding:6px;
    gap:5px;
    border-radius:15px;
    margin-top:8px;
    box-sizing:border-box;
}

.app-shell.sidebar-collapsed .sb-avatar,
body.sidebar-collapsed .app-shell .sb-avatar{
    width:38px;
    height:38px;
    flex-basis:38px;
    border-radius:12px;
    font-size:16px;
}

.app-shell.sidebar-collapsed .sb-logout-icon,
body.sidebar-collapsed .app-shell .sb-logout-icon{
    display:inline-flex;
}

@media (max-width: 900px){
    :root{
        --sb-w:var(--sb-w-collapsed);
    }

    .app-shell:not(.sidebar-collapsed){
        --sb-w:min(var(--sb-w-open), 78vw);
    }

    .sidebar{
        position:fixed;
        width:var(--sb-w);
        height:100vh;
        padding:8px;
    }

    .sb-card{
        height:100%;
    }

    .app-main{
        margin-left:var(--sb-w);
        width:calc(100vw - var(--sb-w));
        max-width:calc(100vw - var(--sb-w));
        min-height:100vh;
        padding:14px;
    }
}

@media (max-width: 640px){
    .sidebar .sb-nav > a.sb-link,
    .sidebar .sb-nav > a.sb-link:hover,
    .sidebar .sb-nav > a.sb-link:focus,
    .sidebar .sb-nav > a.sb-link:focus-visible,
    .sidebar .sb-nav > a.sb-link:active,
    .sidebar .sb-nav > a.sb-link.active{
        min-height:44px !important;
        height:44px !important;
        padding:0 12px !important;
        border-radius:12px !important;
    }

    .sb-footer{
        align-items:flex-start;
    }

    .sb-avatar{
        width:42px;
        height:42px;
        flex-basis:42px;
        border-radius:13px;
    }

    .sb-user .name{
        font-size:13.5px;
    }
}
</style>

<div class="app-shell">
    <aside class="sidebar">
        <div class="sb-card">

            <div class="sb-topbar">
                <button class="sb-toggle" id="sidebarToggle" type="button" aria-label="Toggle sidebar" aria-expanded="true" title="Toggle sidebar">
                    <svg viewBox="0 0 24 24" width="21" height="21" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
                        <path d="M4 7h16"></path>
                        <path d="M4 12h16"></path>
                        <path d="M4 17h16"></path>
                    </svg>
                </button>
            </div>

            <div class="sb-brand">
                <div class="sb-brand-banner">
                    <img
                        id="projectBrandBanner"
                        data-role="project-brand"
                        src="<?= sb_h($projectBrandSrc) ?>"
                        alt="PROJECT 25@28"
                    >
                </div>
            </div>

            <div class="sb-nav-wrap">
                <nav class="sb-nav">
                    <a
                        class="sb-link <?= sb_active($baseUrl, ['/dashboard', '/dashboard/index.php']) ? 'active' : '' ?>"
                        href="<?= sb_h($dashboardHref) ?>"
                        title="Dashboard"
                    >
                        <span class="sb-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M3 13.5L12 5l9 8.5"></path>
                                <path d="M5 11.5V20h14v-8.5"></path>
                            </svg>
                        </span>
                        <span class="sb-link-text">Dashboard</span>
                    </a>

                    <a
                        class="sb-link <?= sb_active($baseUrl, ['/profiling/add', '/profiling/add.php']) ? 'active' : '' ?>"
                        href="<?= sb_h($addProfileHref) ?>"
                        title="Add Profile"
                    >
                        <span class="sb-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M12 5v14"></path>
                                <path d="M5 12h14"></path>
                            </svg>
                        </span>
                        <span class="sb-link-text">Add Profile</span>
                    </a>

                    <a
                        class="sb-link <?= sb_active($baseUrl, [
                            '/view profile',
                            '/view profile/view.php',
                            '/view profile/adolescent_view.php',
                            '/view profile/encounter_view.php'
                        ]) ? 'active' : '' ?>"
                        href="<?= sb_h($viewProfilesHref) ?>"
                        title="View Profiles"
                    >
                        <span class="sb-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v2"></path>
                                <circle cx="9" cy="7" r="4"></circle>
                                <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                            </svg>
                        </span>
                        <span class="sb-link-text">View Profiles</span>
                    </a>

                    <a
                        class="sb-link <?= sb_active($baseUrl, ['/profiling/export_excel', '/profiling/export_excel.php']) ? 'active' : '' ?>"
                        href="<?= sb_h($exportHref) ?>"
                        title="Export File"
                    >
                        <span class="sb-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                <path d="M7 10l5 5 5-5"></path>
                                <path d="M12 15V3"></path>
                            </svg>
                        </span>
                        <span class="sb-link-text">Export File</span>
                    </a>

                    <a
                        class="sb-link <?= sb_active($baseUrl, [
                            '/import file',
                            '/import file/index.php',
                            '/import file/import_data.php',
                            '/import file/import_backup.php'
                        ]) ? 'active' : '' ?>"
                        href="<?= sb_h($importHref) ?>"
                        title="Import File"
                    >
                        <span class="sb-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                <path d="M17 8l-5-5-5 5"></path>
                                <path d="M12 3v12"></path>
                            </svg>
                        </span>
                        <span class="sb-link-text">Import File</span>
                    </a>

                    <a
                        class="sb-link <?= sb_active($baseUrl, [
                            '/reports',
                            '/reports/index.php',
                            '/reports/report_view.php',
                            '/reports/report_table.php',
                            '/reports/report_graph.php',
                            '/reports/report_export_excel.php',
                            '/reports/report_export_png.php'
                        ]) ? 'active' : '' ?>"
                        href="<?= sb_h($reportsHref) ?>"
                        title="Reports"
                    >
                        <span class="sb-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M4 19h16"></path>
                                <path d="M7 16V8"></path>
                                <path d="M12 16V5"></path>
                                <path d="M17 16v-4"></path>
                            </svg>
                        </span>
                        <span class="sb-link-text">Reports</span>
                    </a>

                    <div class="sb-divider"></div>
                    <div class="sb-section-label">Settings</div>

                    <a
                        class="sb-link <?= sb_active($baseUrl, ['/auth/account', '/auth/account.php']) ? 'active' : '' ?>"
                        href="<?= sb_h($accountHref) ?>"
                        title="Account Settings"
                    >
                        <span class="sb-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="3"></circle>
                                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06A1.65 1.65 0 0 0 15 19.4a1.65 1.65 0 0 0-1 .6 1.65 1.65 0 0 1-2 0 1.65 1.65 0 0 0-1-.6 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-.6-1 1.65 1.65 0 0 1 0-2 1.65 1.65 0 0 0 .6-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6c.36 0 .7-.13 1-.36a1.65 1.65 0 0 1 2 0c.3.23.64.36 1 .36a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9c0 .36.13.7.36 1a1.65 1.65 0 0 1 0 2c-.23.3-.36.64-.36 1z"></path>
                            </svg>
                        </span>
                        <span class="sb-link-text">Account Settings</span>
                    </a>
                </nav>
            </div>

            <div class="sb-footer">
                <div class="sb-avatar" id="loggedInAvatar" data-role="user-avatar">
                    <?php if ($provinceLogoUrl !== ''): ?>
                        <img src="<?= sb_h($provinceLogoUrl) ?>" alt="Province Logo">
                    <?php else: ?>
                        <?= sb_h($avatarLetter) ?>
                    <?php endif; ?>
                </div>

                <div class="sb-user">
                    <div class="label">Logged in as</div>
                    <div class="name"><?= $username ?></div>

                    <div class="sb-user-actions">
                        <a href="<?= sb_h($logoutHref) ?>" class="sb-logout-btn">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                                <path d="M16 17l5-5-5-5"></path>
                                <path d="M21 12H9"></path>
                            </svg>
                            <span>Logout</span>
                        </a>
                    </div>
                </div>

                <a href="<?= sb_h($logoutHref) ?>" class="sb-logout-icon" title="Logout" aria-label="Logout">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                        <path d="M16 17l5-5-5-5"></path>
                        <path d="M21 12H9"></path>
                    </svg>
                </a>
            </div>

        </div>
    </aside>

    <main class="app-main">
        <div class="page-content">

<script>
(function(){
    const fixedSrc = <?= json_encode($projectBrandSrc) ?>;
    const sidebarKey = 'ahdis.sidebar.collapsed';
    const shell = document.querySelector('.app-shell');
    const toggle = document.getElementById('sidebarToggle');

    function isSmallScreen(){
        return !!(window.matchMedia && window.matchMedia('(max-width: 900px)').matches);
    }

    function getStoredSidebarState(){
        try {
            return localStorage.getItem(sidebarKey);
        } catch (e) {
            return null;
        }
    }

    function storeSidebarState(collapsed){
        try {
            localStorage.setItem(sidebarKey, collapsed ? '1' : '0');
        } catch (e) {}
    }

    function applySidebarState(collapsed, persist){
        if (!shell) return;
        shell.classList.toggle('sidebar-collapsed', collapsed);
        document.body.classList.toggle('sidebar-collapsed', collapsed);
        if (toggle) {
            toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            toggle.setAttribute('title', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
            toggle.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
        }
        if (persist) storeSidebarState(collapsed);
    }

    const storedSidebarState = getStoredSidebarState();
    applySidebarState(storedSidebarState === null ? isSmallScreen() : storedSidebarState === '1', false);

    if (toggle && shell) {
        toggle.addEventListener('click', function(){
            applySidebarState(!shell.classList.contains('sidebar-collapsed'), true);
        });
    }

    if (window.matchMedia) {
        const mq = window.matchMedia('(max-width: 900px)');
        const onScreenChange = function(){
            if (getStoredSidebarState() === null) {
                applySidebarState(isSmallScreen(), false);
            }
        };
        if (mq.addEventListener) mq.addEventListener('change', onScreenChange);
        else if (mq.addListener) mq.addListener(onScreenChange);
    }

    function lockProjectBrand(){
        const img = document.getElementById('projectBrandBanner') || document.querySelector('img[data-role="project-brand"]');
        if (!img) return;

        if (img.getAttribute('src') !== fixedSrc) {
            img.setAttribute('src', fixedSrc);
        }

        if (img.getAttribute('srcset')) {
            img.removeAttribute('srcset');
        }
    }

    lockProjectBrand();
    document.addEventListener('DOMContentLoaded', lockProjectBrand);

    const target = document.querySelector('.sb-brand-banner') || document.body;
    if (target && window.MutationObserver) {
        const obs = new MutationObserver(lockProjectBrand);
        obs.observe(target, {
            subtree: true,
            childList: true,
            attributes: true,
            attributeFilter: ['src', 'srcset']
        });
    }

    setTimeout(lockProjectBrand, 250);
    setTimeout(lockProjectBrand, 900);
})();
</script>
