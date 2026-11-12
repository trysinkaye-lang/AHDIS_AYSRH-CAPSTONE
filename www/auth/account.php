<?php
declare(strict_types=1);

require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/database.php";

function h(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

/* =========================
   BASE URL (supports subfolder)
========================= */
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$baseUrl   = preg_replace('#/auth$#', '', $scriptDir) ?: '';
$LOGIN_URL = $baseUrl . "/auth/login.php";
$SELF_URL  = $baseUrl . "/auth/account.php";

/* =========================
   FLASH (PRG)
========================= */
$msg   = (string)($_SESSION['flash_success'] ?? '');
$error = (string)($_SESSION['flash_error'] ?? '');
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

/* =========================
   CSRF TOKENS
========================= */
if (empty($_SESSION['csrf_user'])) $_SESSION['csrf_user'] = bin2hex(random_bytes(32));
if (empty($_SESSION['csrf_pass'])) $_SESSION['csrf_pass'] = bin2hex(random_bytes(32));
if (empty($_SESSION['csrf_loc']))  $_SESSION['csrf_loc']  = bin2hex(random_bytes(32));
if (empty($_SESSION['csrf_logo'])) $_SESSION['csrf_logo'] = bin2hex(random_bytes(32));

$csrfUser = (string)$_SESSION['csrf_user'];
$csrfPass = (string)$_SESSION['csrf_pass'];
$csrfLoc  = (string)$_SESSION['csrf_loc'];
$csrfLogo = (string)$_SESSION['csrf_logo'];

/* =========================
   CURRENT USER
========================= */
$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header("Location: " . $LOGIN_URL);
    exit;
}

/* =========================
   LOCATION DATA
========================= */
$LOCATIONS = [
    'Bukidnon' => [
        'Baungon','Cabanglasan','Damulog','Dangcagan','Don Carlos','Impasugong','Kadingilan','Kalilangan',
        'Kibawe','Kitaotao','Lantapan','Libona','Malaybalay City','Malitbog','Manolo Fortich','Maramag',
        'Pangantucan','Quezon','San Fernando','Sumilao','Talakag','Valencia City'
    ],
    'Camiguin' => ['Catarman','Guinsiliban','Mahinog','Mambajao','Sagay'],
    'Lanao del Norte' => [
        'Bacolod','Baloi','Baroy','Kapatagan','Kauswagan','Kolambugan','Lala','Linamon','Magsaysay','Maigo',
        'Matungao','Munai','Nunungan','Pantao Ragat','Pantar','Poona Piagapo','Salvador','Sapad',
        'Sultan Naga Dimaporo','Tagoloan','Tangcal','Tubod','Iligan City'
    ],
    'Misamis Occidental' => [
        'Aloran','Baliangao','Bonifacio','Calamba','Clarin','Concepcion','Don Victoriano Chiongbian',
        'Jimenez','Lopez Jaena','Panaon','Plaridel','Sapang Dalaga','Sinacaban','Tudela',
        'Oroquieta City','Ozamiz City','Tangub City'
    ],
    'Misamis Oriental' => [
        'Alubijid','Balingasag','Balingoan','Binuangan','Claveria','Gitagum','Initao','Jasaan','Kinoguitan',
        'Lagonglong','Laguindingan','Libertad','Lugait','Magsaysay','Manticao','Medina','Naawan','Opol',
        'Salay','Sugbongcogon','Tagoloan','Talisayan','Villanueva',
        'El Salvador City','Gingoog City','Cagayan de Oro City'
    ],
];

/* =========================
   PATH HELPERS
========================= */
function appRoot(): string {
    $root = realpath(dirname(__DIR__));
    if ($root !== false) {
        return $root;
    }
    return dirname(__DIR__);
}

function pathJoin(string ...$parts): string {
    $clean = [];
    foreach ($parts as $i => $p) {
        if ($p === '') continue;
        $p = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $p);
        if ($i === 0) {
            $clean[] = rtrim($p, DIRECTORY_SEPARATOR);
        } else {
            $clean[] = trim($p, DIRECTORY_SEPARATOR);
        }
    }
    return implode(DIRECTORY_SEPARATOR, $clean);
}

function publicPathFromAbsolute(string $absPath): string {
    $root = appRoot();
    $abs  = str_replace('\\', '/', $absPath);
    $base = str_replace('\\', '/', $root);
    if (str_starts_with($abs, $base)) {
        $rel = substr($abs, strlen($base));
        return str_replace('\\', '/', $rel ?: '');
    }
    return '';
}

/* =========================
   SQLITE AUTO-MIGRATION
========================= */
function ensureUserLocationColumns(PDO $conn): void {
    try {
        $cols = $conn->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
        $have = [];
        foreach ($cols as $c) {
            $have[(string)$c['name']] = true;
        }

        if (!isset($have['province'])) {
            try { $conn->exec("ALTER TABLE users ADD COLUMN province TEXT"); } catch (Throwable $e) {}
        }
        if (!isset($have['municipality'])) {
            try { $conn->exec("ALTER TABLE users ADD COLUMN municipality TEXT"); } catch (Throwable $e) {}
        }
        if (!isset($have['location_locked'])) {
            try { $conn->exec("ALTER TABLE users ADD COLUMN location_locked INTEGER NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
        }
    } catch (Throwable $e) {}
}
ensureUserLocationColumns($conn);

/* =========================
   PROVINCE LOGO TABLE
========================= */
function ensureProvinceLogoTable(PDO $conn): void {
    try {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS province_logos (
                province TEXT PRIMARY KEY,
                logo_path TEXT NOT NULL,
                uploaded_by INTEGER,
                updated_at TEXT NOT NULL
            )
        ");
    } catch (Throwable $e) {}
}
ensureProvinceLogoTable($conn);

function getProvinceLogo(PDO $conn, string $province): string {
    if ($province === '') return '';
    try {
        $st = $conn->prepare("SELECT logo_path FROM province_logos WHERE province = :p LIMIT 1");
        $st->execute([':p' => $province]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? (string)$row['logo_path'] : '';
    } catch (Throwable $e) {
        return '';
    }
}

function fetchUser(PDO $conn, int $userId): ?array {
    $stmt = $conn->prepare("
        SELECT id, username, password_hash,
               COALESCE(province,'') AS province,
               COALESCE(municipality,'') AS municipality,
               COALESCE(location_locked,0) AS location_locked
        FROM users
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $userId]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    return $u ?: null;
}

$user = fetchUser($conn, $userId);
if (!$user) {
    $_SESSION = [];
    session_destroy();
    header("Location: " . $LOGIN_URL);
    exit;
}

/* =========================
   VALIDATION HELPERS
========================= */
function validUsername(string $u): bool {
    return (bool)preg_match('/^[a-zA-Z0-9._-]{4,32}$/', $u);
}

function validPassword(string $p): bool {
    if (strlen($p) < 10) return false;
    if (preg_match('/\s/', $p)) return false;
    if (!preg_match('/[A-Za-z]/', $p)) return false;
    if (!preg_match('/[0-9]/', $p)) return false;
    return true;
}

function validProvinceMunicipality(array $LOCATIONS, string $prov, string $mun): bool {
    if ($prov === '' || $mun === '') return false;
    if (!isset($LOCATIONS[$prov])) return false;
    return in_array($mun, $LOCATIONS[$prov], true);
}

/* =========================
   UPLOAD HELPERS
========================= */
function mimeFromUpload(array $file): string {
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) return '';

    if (class_exists('finfo')) {
        try {
            $fi = new finfo(FILEINFO_MIME_TYPE);
            $m  = $fi->file($tmp);
            if (is_string($m) && $m !== '') {
                return $m;
            }
        } catch (Throwable $e) {}
    }

    if (function_exists('exif_imagetype')) {
        try {
            $type = @exif_imagetype($tmp);
            return match ($type) {
                IMAGETYPE_PNG  => 'image/png',
                IMAGETYPE_JPEG => 'image/jpeg',
                IMAGETYPE_WEBP => 'image/webp',
                default        => '',
            };
        } catch (Throwable $e) {}
    }

    $name = (string)($file['name'] ?? '');
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return match ($ext) {
        'png'         => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'webp'        => 'image/webp',
        default       => '',
    };
}

function ensureWritableDir(string $absDir): array {
    if (!is_dir($absDir)) {
        @mkdir($absDir, 0775, true);
    }

    clearstatcache(true, $absDir);

    if (!is_dir($absDir)) {
        return [false, "Folder could not be created: {$absDir}"];
    }

    @chmod($absDir, 0775);
    clearstatcache(true, $absDir);

    $testFile = pathJoin($absDir, '.write_test_' . bin2hex(random_bytes(4)) . '.tmp');
    $ok = @file_put_contents($testFile, 'ok') !== false;
    if ($ok) {
        @unlink($testFile);
        return [true, ''];
    }

    return [false, "Folder exists but is not writable: {$absDir}"];
}

function saveUploadedFile(string $tmpPath, string $destPath): bool {
    if ($tmpPath === '' || !is_file($tmpPath)) {
        return false;
    }

    $destDir = dirname($destPath);
    if (!is_dir($destDir)) {
        @mkdir($destDir, 0775, true);
    }

    if (function_exists('move_uploaded_file') && @move_uploaded_file($tmpPath, $destPath)) {
        return true;
    }

    if (@rename($tmpPath, $destPath)) {
        return true;
    }

    if (@copy($tmpPath, $destPath)) {
        @unlink($tmpPath);
        return true;
    }

    return false;
}

/* =========================
   HANDLE POST
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    $user = fetchUser($conn, $userId);
    if (!$user) {
        $_SESSION = [];
        session_destroy();
        header("Location: " . $LOGIN_URL);
        exit;
    }

    $mustRequirePassword = in_array($action, ['change_username', 'change_password', 'update_location'], true);

    if ($mustRequirePassword) {
        $currentPass = (string)($_POST['current_password'] ?? '');
        if ($currentPass === "" || !password_verify($currentPass, (string)$user['password_hash'])) {
            $_SESSION['flash_error'] = "Current password is incorrect.";
            header("Location: " . $SELF_URL);
            exit;
        }
    }

    try {
        /* ---------- CHANGE USERNAME ---------- */
        if ($action === 'change_username') {
            $postedToken = (string)($_POST['csrf_user'] ?? '');
            if (!hash_equals((string)($_SESSION['csrf_user'] ?? ''), $postedToken)) {
                $_SESSION['flash_error'] = "Invalid request. Please refresh and try again.";
                header("Location: " . $SELF_URL);
                exit;
            }

            $newUsername = trim((string)($_POST['new_username'] ?? ''));

            if ($newUsername === "") {
                $_SESSION['flash_error'] = "New username is required.";
                header("Location: " . $SELF_URL);
                exit;
            }
            if (!validUsername($newUsername)) {
                $_SESSION['flash_error'] = "Username must be 4-32 chars and can only use letters, numbers, dot (.), underscore (_), and dash (-).";
                header("Location: " . $SELF_URL);
                exit;
            }

            $chk = $conn->prepare("SELECT id FROM users WHERE username = :u AND id <> :id LIMIT 1");
            $chk->execute([':u' => $newUsername, ':id' => $userId]);
            if ($chk->fetch()) {
                $_SESSION['flash_error'] = "Username already taken.";
                header("Location: " . $SELF_URL);
                exit;
            }

            $conn->beginTransaction();
            $upd = $conn->prepare("UPDATE users SET username = :u WHERE id = :id");
            $upd->execute([':u' => $newUsername, ':id' => $userId]);
            $conn->commit();

            $_SESSION['username']  = $newUsername;
            $_SESSION['csrf_user'] = bin2hex(random_bytes(32));
            session_regenerate_id(true);

            $_SESSION['flash_success'] = "Username updated successfully.";
            header("Location: " . $SELF_URL);
            exit;
        }

        /* ---------- CHANGE PASSWORD ---------- */
        if ($action === 'change_password') {
            $postedToken = (string)($_POST['csrf_pass'] ?? '');
            if (!hash_equals((string)($_SESSION['csrf_pass'] ?? ''), $postedToken)) {
                $_SESSION['flash_error'] = "Invalid request. Please refresh and try again.";
                header("Location: " . $SELF_URL);
                exit;
            }

            $new     = (string)($_POST['new_password'] ?? '');
            $confirm = (string)($_POST['confirm_password'] ?? '');

            if ($new === "" || $confirm === "") {
                $_SESSION['flash_error'] = "New password and confirmation are required.";
                header("Location: " . $SELF_URL);
                exit;
            }
            if ($new !== $confirm) {
                $_SESSION['flash_error'] = "New password and confirmation do not match.";
                header("Location: " . $SELF_URL);
                exit;
            }
            if (preg_match('/\s/', $new) || preg_match('/\s/', $confirm)) {
                $_SESSION['flash_error'] = "Password must not contain spaces.";
                header("Location: " . $SELF_URL);
                exit;
            }
            if (!validPassword($new)) {
                $_SESSION['flash_error'] = "Password must be at least 10 characters and include at least 1 letter and 1 number.";
                header("Location: " . $SELF_URL);
                exit;
            }
            if (password_verify($new, (string)$user['password_hash'])) {
                $_SESSION['flash_error'] = "New password must be different from your current password.";
                header("Location: " . $SELF_URL);
                exit;
            }

            $hash = password_hash($new, PASSWORD_DEFAULT);

            $conn->beginTransaction();
            $upd = $conn->prepare("UPDATE users SET password_hash = :h WHERE id = :id");
            $upd->execute([':h' => $hash, ':id' => $userId]);
            $conn->commit();

            session_regenerate_id(true);

            $_SESSION = [];
            if (ini_get("session.use_cookies")) {
                $p = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $p['path'],
                    $p['domain'] ?? '',
                    (bool)($p['secure'] ?? false),
                    (bool)($p['httponly'] ?? true)
                );
            }
            session_destroy();

            header("Location: " . $LOGIN_URL . "?pw_changed=1");
            exit;
        }

        /* ---------- UPDATE LOCATION ---------- */
        if ($action === 'update_location') {
            $postedToken = (string)($_POST['csrf_loc'] ?? '');
            if (!hash_equals((string)($_SESSION['csrf_loc'] ?? ''), $postedToken)) {
                $_SESSION['flash_error'] = "Invalid request. Please refresh and try again.";
                header("Location: " . $SELF_URL);
                exit;
            }

            if ((int)($user['location_locked'] ?? 0) === 1) {
                $_SESSION['flash_error'] = "Location is locked and cannot be changed.";
                header("Location: " . $SELF_URL);
                exit;
            }

            $province     = trim((string)($_POST['province'] ?? ''));
            $municipality = trim((string)($_POST['municipality'] ?? ''));
            $lock         = isset($_POST['location_locked']) ? 1 : 0;

            if (!validProvinceMunicipality($LOCATIONS, $province, $municipality)) {
                $_SESSION['flash_error'] = "Please select a valid Province and Municipality/City.";
                header("Location: " . $SELF_URL);
                exit;
            }

            $conn->beginTransaction();
            $upd = $conn->prepare("
                UPDATE users
                SET province = :p,
                    municipality = :m,
                    location_locked = :l
                WHERE id = :id
            ");
            $upd->execute([
                ':p'  => $province,
                ':m'  => $municipality,
                ':l'  => $lock,
                ':id' => $userId,
            ]);
            $conn->commit();

            $_SESSION['province']            = $province;
            $_SESSION['municipality']        = $municipality;
            $_SESSION['locked_municipality'] = $municipality;
            $_SESSION['csrf_loc']            = bin2hex(random_bytes(32));

            $_SESSION['flash_success'] = $lock
                ? "Location saved and locked successfully."
                : "Location saved successfully.";

            header("Location: " . $SELF_URL);
            exit;
        }

        /* ---------- UPLOAD PROVINCE LOGO ---------- */
        if ($action === 'upload_province_logo') {
            $postedToken = (string)($_POST['csrf_logo'] ?? '');
            if (!hash_equals((string)($_SESSION['csrf_logo'] ?? ''), $postedToken)) {
                $_SESSION['flash_error'] = "Invalid request. Please refresh and try again.";
                header("Location: " . $SELF_URL);
                exit;
            }

            $province = trim((string)($user['province'] ?? ''));
            if ($province === '') {
                $_SESSION['flash_error'] = "Set your Province first before uploading a province logo.";
                header("Location: " . $SELF_URL);
                exit;
            }

            if (!isset($_FILES['province_logo']) || !is_array($_FILES['province_logo'])) {
                $_SESSION['flash_error'] = "No file received.";
                header("Location: " . $SELF_URL);
                exit;
            }

            $f = $_FILES['province_logo'];

            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $err = (int)($f['error'] ?? -1);
                $_SESSION['flash_error'] = "Upload failed (error code: {$err}).";
                header("Location: " . $SELF_URL);
                exit;
            }

            $size = (int)($f['size'] ?? 0);
            $maxBytes = 2 * 1024 * 1024;
            if ($size <= 0) {
                $_SESSION['flash_error'] = "Upload failed: empty file.";
                header("Location: " . $SELF_URL);
                exit;
            }
            if ($size > $maxBytes) {
                $_SESSION['flash_error'] = "Logo is too large. Max 2MB.";
                header("Location: " . $SELF_URL);
                exit;
            }

            $mime = mimeFromUpload($f);
            $allowed = [
                'image/png'  => 'png',
                'image/jpeg' => 'jpg',
                'image/webp' => 'webp',
            ];
            if (!isset($allowed[$mime])) {
                $_SESSION['flash_error'] = "Invalid file type. Please upload PNG, JPG, or WebP.";
                header("Location: " . $SELF_URL);
                exit;
            }

            $projectRoot = appRoot();
            $assetsDir   = pathJoin($projectRoot, 'assets');
            $logosDir    = pathJoin($assetsDir, 'logos');
            $provDir     = pathJoin($logosDir, 'provinces');

            [$dirOk, $dirMsg] = ensureWritableDir($provDir);
            if (!$dirOk) {
                $_SESSION['flash_error'] = "Logo folder is not writable. " . $dirMsg;
                header("Location: " . $SELF_URL);
                exit;
            }

            $oldLogo = getProvinceLogo($conn, $province);

            $ext  = $allowed[$mime];
            $slug = strtolower((string)preg_replace('/[^a-z0-9]+/i', '-', $province));
            $slug = trim($slug, '-');
            if ($slug === '') $slug = 'province';

            $newName = $slug . "-" . bin2hex(random_bytes(8)) . "." . $ext;
            $absOut  = pathJoin($provDir, $newName);
            $relOut  = "/assets/logos/provinces/" . $newName;

            $tmpPath = (string)($f['tmp_name'] ?? '');
            if ($tmpPath === '' || !is_file($tmpPath)) {
                $_SESSION['flash_error'] = "Upload failed: temporary file was not found.";
                header("Location: " . $SELF_URL);
                exit;
            }

            if (!saveUploadedFile($tmpPath, $absOut)) {
                $_SESSION['flash_error'] = "Could not save uploaded file. Check folder permissions or OneDrive restrictions.";
                header("Location: " . $SELF_URL);
                exit;
            }

            @chmod($absOut, 0664);

            $conn->beginTransaction();
            $st = $conn->prepare("
                INSERT INTO province_logos (province, logo_path, uploaded_by, updated_at)
                VALUES (:p, :path, :uid, :ts)
                ON CONFLICT(province) DO UPDATE SET
                    logo_path = excluded.logo_path,
                    uploaded_by = excluded.uploaded_by,
                    updated_at = excluded.updated_at
            ");
            $st->execute([
                ':p'    => $province,
                ':path' => $relOut,
                ':uid'  => $userId,
                ':ts'   => gmdate('c'),
            ]);
            $conn->commit();

            try {
                if ($oldLogo && str_starts_with($oldLogo, "/assets/logos/provinces/")) {
                    $absOld = pathJoin($projectRoot, ltrim($oldLogo, '/\\'));
                    if (is_file($absOld) && realpath($absOld) !== realpath($absOut)) {
                        @unlink($absOld);
                    }
                }
            } catch (Throwable $e) {}

            $_SESSION['province_logo']          = $relOut;
            $_SESSION['province_logo_province'] = $province;
            $_SESSION['csrf_logo']              = bin2hex(random_bytes(32));
            $_SESSION['flash_success']          = "Province logo uploaded successfully.";
            header("Location: " . $SELF_URL);
            exit;
        }

        $_SESSION['flash_error'] = "Invalid action.";
        header("Location: " . $SELF_URL);
        exit;
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $_SESSION['flash_error'] = "Update failed. Please try again.";
        header("Location: " . $SELF_URL);
        exit;
    }
}

/* =========================
   FRESH FOR DISPLAY
========================= */
$user = fetchUser($conn, $userId) ?: $user;

$csrfUser = (string)($_SESSION['csrf_user'] ?? $csrfUser);
$csrfPass = (string)($_SESSION['csrf_pass'] ?? $csrfPass);
$csrfLoc  = (string)($_SESSION['csrf_loc']  ?? $csrfLoc);
$csrfLogo = (string)($_SESSION['csrf_logo'] ?? $csrfLogo);

$province = (string)($user['province'] ?? '');
$provinceLogo = getProvinceLogo($conn, $province);

$_SESSION['province_logo']          = $provinceLogo ?: '';
$_SESSION['province_logo_province'] = $province ?: '';

$provinceLogoUrl = '';
if ($provinceLogo) {
    $absLogo = pathJoin(appRoot(), ltrim($provinceLogo, '/\\'));
    $v = is_file($absLogo) ? (string)@filemtime($absLogo) : (string)time();
    $provinceLogoUrl = $baseUrl . $provinceLogo . "?v=" . rawurlencode($v);
}

require_once __DIR__ . "/../includes/header.php";
require_once __DIR__ . "/../includes/sidebar.php";
?>

<style>
.account-wrap{
    max-width:1220px;
    margin:0 auto;
    padding:0 0 32px;
}

.account-hero{
    position:relative;
    overflow:hidden;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:18px;
    padding:22px;
    border-radius:20px;
    border:1px solid #d8e3f1;
    background:linear-gradient(135deg, #17356f 0%, #2450a8 62%, #c95cae 100%);
    box-shadow:0 14px 32px rgba(22,52,109,.12);
    margin-bottom:16px;
    color:#fff;
}

.account-hero::after{
    display:none;
}

.account-hero-left{
    display:flex;
    align-items:center;
    gap:16px;
    min-width:0;
    position:relative;
    z-index:1;
}

.account-hero-right{
    display:flex;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
    justify-content:flex-end;
    position:relative;
    z-index:1;
}

.account-badge{
    width:88px;
    height:88px;
    border-radius:20px;
    border:1px solid rgba(255,255,255,.24);
    background:rgba(255,255,255,.14);
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    flex:0 0 auto;
    overflow:hidden;
    box-shadow:0 10px 24px rgba(0,0,0,.12);
}

.account-badge svg{
    width:36px;
    height:36px;
}

.account-badge img{
    width:100%;
    height:100%;
    object-fit:contain;
    padding:10px;
    background:#fff;
    box-sizing:border-box;
}

.account-badge-upload{
    user-select:none;
    transition:border-color 120ms ease, background-color 120ms ease;
}

.account-badge-upload:hover{
    border-color:rgba(255,255,255,.38);
    background:rgba(255,255,255,.18);
}

.account-badge-disabled{
    opacity:.58;
}

.account-title{
    margin:0;
    font-weight:900;
    letter-spacing:0;
    font-size:28px;
    line-height:1.12;
    color:#fff;
}

.account-sub{
    margin:7px 0 0;
    color:rgba(255,255,255,.9);
    font-size:14px;
    line-height:1.45;
    font-weight:700;
}

.account-chip{
    display:inline-flex;
    align-items:center;
    gap:8px;
    min-height:38px;
    padding:8px 12px;
    border-radius:999px;
    border:1px solid rgba(255,255,255,.22);
    background:rgba(255,255,255,.13);
    color:#fff;
    font-size:12px;
    white-space:nowrap;
    font-weight:700;
}

.account-chip b{
    font-weight:900;
}

.account-chip svg{
    width:16px;
    height:16px;
    color:rgba(255,255,255,.88);
    flex:0 0 auto;
}

.account-alert{
    padding:13px 15px;
    border-radius:14px;
    border:1px solid #dbe4f0;
    background:#fff;
    margin-bottom:14px;
    box-shadow:0 8px 18px rgba(15,23,42,.05);
    font-weight:700;
}

.account-alert strong{
    font-weight:900;
    margin-right:6px;
}

.account-alert.danger{
    border-color:#fecaca;
    background:#fff5f5;
    color:#991b1b;
}

.account-alert.success{
    border-color:#bbf7d0;
    background:#f0fdf4;
    color:#166534;
}

.account-grid{
    display:grid;
    grid-template-columns:repeat(2, minmax(0,1fr));
    gap:16px;
    align-items:stretch;
}

.account-card{
    position:relative;
    min-width:0;
    display:flex;
    flex-direction:column;
    height:100%;
    overflow:visible;
    background:#fff;
    border:1px solid #dce6f2;
    border-radius:18px;
    box-shadow:0 12px 28px rgba(15,23,42,.07);
    padding:18px;
}

.account-card::before{
    display:none;
}

.account-card-location{
    grid-column:1 / -1;
}

.account-card-head{
    display:flex;
    gap:12px;
    align-items:center;
    padding-bottom:14px;
    margin-bottom:16px;
    border-bottom:1px solid #edf2f7;
}

.account-card-head .icon{
    width:42px;
    height:42px;
    border-radius:14px;
    border:1px solid #dbe4f0;
    background:#f4f8ff;
    display:grid;
    place-items:center;
    color:#264aa8;
    flex:0 0 auto;
    overflow:hidden;
}

.account-card-head .icon svg{
    width:20px;
    height:20px;
}

.account-card-head h6{
    margin:0;
    font-weight:900;
    color:#11233f;
    font-size:16px;
    letter-spacing:0;
}

.account-card-head p{
    margin:4px 0 0;
    color:#66758c;
    font-size:13px;
    line-height:1.45;
}

.account-form{
    display:flex;
    flex-direction:column;
    gap:14px;
    flex:1 1 auto;
}

.account-card:not(.account-card-location) .account-form .account-btn{
    margin-top:auto;
}

.account-form .field{
    display:flex;
    flex-direction:column;
    gap:7px;
}

.account-form .hint{
    margin-top:1px;
    color:#718096;
    font-size:12px;
    font-weight:700;
    line-height:1.35;
}

.account-card .form-label{
    font-size:12px;
    color:#314155;
    font-weight:900;
    margin:0;
    letter-spacing:0;
}

.account-card .form-control{
    width:100%;
    min-height:44px;
    border-radius:12px;
    border:1px solid #d4dfec;
    background:#fff !important;
    padding:10px 13px;
    font-size:14px;
    font-weight:700;
    color:#11233f;
    outline:none;
    transition:border-color 120ms ease, box-shadow 120ms ease, background-color 120ms ease;
    box-sizing:border-box;
}

.account-card .form-control:focus{
    border-color:#2563eb;
    box-shadow:0 0 0 3px rgba(37,99,235,.12) !important;
}

.account-card select.form-control{
    appearance:none;
    background-image:
        linear-gradient(45deg, transparent 50%, #667085 50%),
        linear-gradient(135deg, #667085 50%, transparent 50%);
    background-position:
        calc(100% - 18px) calc(50% - 3px),
        calc(100% - 12px) calc(50% - 3px);
    background-size:6px 6px, 6px 6px;
    background-repeat:no-repeat;
    padding-right:40px;
}

.account-card .form-control[disabled]{
    background:#f6f8fb !important;
    color:#697386;
    cursor:not-allowed;
}

.form-row-2{
    display:grid;
    grid-template-columns:repeat(2, minmax(0,1fr));
    gap:14px;
}

.pw-wrap{
    position:relative;
    display:flex;
    align-items:center;
}

.pw-wrap .form-control{
    padding-right:48px;
}

.pw-toggle{
    position:absolute;
    right:6px;
    top:4px;
    bottom:4px;
    width:36px;
    border-radius:10px;
    border:1px solid #d9e4f2;
    background:#fff;
    display:grid;
    place-items:center;
    color:#667085;
    cursor:pointer;
    transform:none;
    transition:background-color 120ms ease, border-color 120ms ease, color 120ms ease;
}

.pw-toggle:hover{
    background:#f5f8fe;
    color:#11233f;
    border-color:#c9d8ee;
}

.pw-toggle[disabled]{
    opacity:.55;
    cursor:not-allowed;
}

.pw-toggle svg{
    width:18px;
    height:18px;
}

.account-lock-banner{
    display:flex;
    align-items:flex-start;
    gap:10px;
    padding:12px 14px;
    border-radius:14px;
    border:1px solid #fed7aa;
    background:#fff7ed;
    color:#9a3412;
    margin:0 0 14px;
    box-shadow:none;
    font-size:13px;
    line-height:1.4;
}

.account-lock-banner svg{
    width:19px;
    height:19px;
    flex:0 0 auto;
    margin-top:1px;
}

.lock-check{
    display:grid;
    grid-template-columns:18px minmax(0,1fr);
    align-items:flex-start;
    gap:10px;
    padding:12px 14px;
    border-radius:14px;
    border:1px solid #dce5f1;
    background:#f8fbff;
}

.lock-check input[type="checkbox"]{
    width:16px;
    height:16px;
    margin:2px 0 0;
    accent-color:#2958d6;
}

.lock-check input[type="checkbox"][disabled]{
    cursor:not-allowed;
}

.lock-check-text{
    display:flex;
    flex-direction:column;
    gap:4px;
    min-width:0;
}

.lock-check-text strong{
    font-size:13px;
    color:#11233f;
    font-weight:900;
}

.lock-check-text span{
    font-size:12px;
    color:#6d7b90;
    line-height:1.35;
    font-weight:700;
}

.account-btn{
    min-height:44px !important;
    border:none !important;
    border-radius:12px !important;
    font-weight:900 !important;
    padding:0 16px !important;
    font-size:13px !important;
    letter-spacing:0;
    background:#214fbf !important;
    box-shadow:0 10px 20px rgba(38,74,168,.16);
    transition:background-color 120ms ease, box-shadow 120ms ease, opacity 120ms ease;
    color:#fff !important;
    transform:none !important;
}

.account-btn:hover{
    background:#1d46a8 !important;
    box-shadow:0 12px 22px rgba(38,74,168,.18);
    transform:none !important;
}

.account-btn:disabled{
    opacity:.55;
    cursor:not-allowed;
    box-shadow:none;
}

.w-100{
    width:100%;
}

.account-card-location .account-btn{
    width:auto !important;
    min-width:172px;
    align-self:flex-start;
}

.account-note{
    margin-top:0;
    padding:11px 13px;
    border-radius:12px;
    background:#f8fbff;
    border:1px dashed #d8e4f5;
    color:#65758b;
    font-size:12px;
    font-weight:700;
    line-height:1.45;
}

@media (max-width: 1100px){
    .account-grid{
        grid-template-columns:1fr;
    }

    .account-card{
        height:auto;
    }
}

@media (max-width: 860px){
    .account-hero{
        flex-direction:column;
        align-items:flex-start;
    }

    .account-hero-right{
        width:100%;
        justify-content:flex-start;
    }

    .account-chip{
        white-space:normal;
        justify-content:flex-start;
    }
}

@media (max-width: 640px){
    .account-wrap{
        padding:0 0 24px;
    }

    .account-hero{
        padding:18px;
        border-radius:18px;
    }

    .account-hero-left{
        align-items:flex-start;
    }

    .account-badge{
        width:72px;
        height:72px;
        border-radius:18px;
    }

    .account-badge svg{
        width:30px;
        height:30px;
    }

    .account-badge img{
        padding:8px;
    }

    .account-title{
        font-size:24px;
    }

    .form-row-2{
        grid-template-columns:1fr;
    }

    .account-card{
        border-radius:16px;
        padding:16px;
    }

    .account-card-head{
        align-items:flex-start;
    }

    .account-card-location .account-btn{
        width:100% !important;
    }
}
</style>

<div class="account-wrap">
    <div class="account-hero">
        <div class="account-hero-left">
            <form id="logoForm"
                  method="post"
                  action="<?= h($SELF_URL) ?>"
                  enctype="multipart/form-data"
                  style="margin:0; padding:0;">
                <input type="hidden" name="csrf_logo" value="<?= h($csrfLogo) ?>">
                <input type="hidden" name="action" value="upload_province_logo">

                <?php $canUploadLogo = ((string)$province !== ''); ?>

                <input
                    id="provinceLogoInput"
                    type="file"
                    name="province_logo"
                    accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp"
                    style="display:none;"
                    <?= $canUploadLogo ? '' : 'disabled' ?>
                >

                <label
                    for="<?= $canUploadLogo ? 'provinceLogoInput' : '' ?>"
                    class="account-badge account-badge-upload <?= $canUploadLogo ? '' : 'account-badge-disabled' ?>"
                    title="<?= $canUploadLogo ? 'Click to upload province logo' : 'Set your Province first to upload logo' ?>"
                    style="cursor: <?= $canUploadLogo ? 'pointer' : 'not-allowed' ?>;"
                >
                    <?php if ($provinceLogoUrl): ?>
                        <img src="<?= h($provinceLogoUrl) ?>" alt="Province Logo">
                    <?php else: ?>
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Z" stroke="currentColor" stroke-width="2"/>
                            <path d="M20 20a8 8 0 1 0-16 0" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    <?php endif; ?>
                </label>
            </form>

            <div>
                <h3 class="account-title">Account Settings</h3>
                <p class="account-sub">Manage your username, password, location, and province logo.</p>
            </div>
        </div>

        <div class="account-hero-right">
            <span class="account-chip" title="Signed in user">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Z" stroke="currentColor" stroke-width="2"/>
                    <path d="M20 20a8 8 0 1 0-16 0" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
                <span>Username: <b><?= h((string)$user['username']) ?></b></span>
            </span>

            <span class="account-chip" title="Your location">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M12 21s7-5.1 7-11a7 7 0 1 0-14 0c0 5.9 7 11 7 11Z" stroke="currentColor" stroke-width="2"/>
                    <path d="M12 10a2 2 0 1 0-2-2 2 2 0 0 0 2 2Z" stroke="currentColor" stroke-width="2"/>
                </svg>
                <span>
                    Location:
                    <b><?= h(trim(($user['province'] ?? '') . ' - ' . ($user['municipality'] ?? '')) ?: 'Not set') ?></b>
                    <?php if ((int)($user['location_locked'] ?? 0) === 1): ?>
                        <span style="margin-left:6px; color:#fff5c2; font-weight:900;">(Locked)</span>
                    <?php endif; ?>
                </span>
            </span>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="account-alert danger"><strong>Oops.</strong> <?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($msg): ?>
        <div class="account-alert success"><strong>Success.</strong> <?= h($msg) ?></div>
    <?php endif; ?>

    <div class="account-grid">
        <div class="account-card">
            <div class="account-card-head">
                <div class="icon">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Z" stroke="currentColor" stroke-width="2"/>
                        <path d="M20 20a8 8 0 1 0-16 0" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </div>
                <div>
                    <h6>Change Username</h6>
                    <p>Update your sign-in name. Your current password is required for verification.</p>
                </div>
            </div>

            <form method="post" class="account-form" autocomplete="off" action="<?= h($SELF_URL) ?>">
                <input type="hidden" name="csrf_user" value="<?= h($csrfUser) ?>">
                <input type="hidden" name="action" value="change_username">
                <input type="hidden" name="username" value="<?= h((string)$user['username']) ?>" autocomplete="username">

                <div class="field">
                    <label class="form-label">Current Username</label>
                    <input class="form-control" value="<?= h((string)$user['username']) ?>" disabled>
                </div>

                <div class="field">
                    <label class="form-label">New Username</label>
                    <input class="form-control" name="new_username" placeholder="Enter new username" required autocomplete="username">
                    <div class="hint">Allowed: letters, numbers, dot, underscore, and dash. Length must be 4-32 characters.</div>
                </div>

                <div class="field">
                    <label class="form-label">Current Password</label>
                    <div class="pw-wrap">
                        <input type="password" class="form-control" name="current_password" autocomplete="current-password" required>
                        <button type="button" class="pw-toggle" data-toggle="pw" aria-label="Show or hide password">
                            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z" stroke="currentColor" stroke-width="2"/>
                                <path d="M12 15a3 3 0 1 0-3-3 3 3 0 0 0 3 3Z" stroke="currentColor" stroke-width="2"/>
                            </svg>
                        </button>
                    </div>
                    <div class="hint">Enter your current password to confirm this change.</div>
                </div>

                <button class="btn btn-primary w-100 account-btn" type="submit">Update Username</button>
            </form>
        </div>

        <div class="account-card">
            <div class="account-card-head">
                <div class="icon">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M7 11V8a5 5 0 0 1 10 0v3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <path d="M6 11h12v10H6V11Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                        <path d="M12 15v3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </div>
                <div>
                    <h6>Change Password</h6>
                    <p>Use a stronger password to keep your account secure. You will be signed out after saving.</p>
                </div>
            </div>

            <form method="post" class="account-form" autocomplete="off" action="<?= h($SELF_URL) ?>">
                <input type="hidden" name="csrf_pass" value="<?= h($csrfPass) ?>">
                <input type="hidden" name="action" value="change_password">
                <input type="hidden" name="username" value="<?= h((string)$user['username']) ?>" autocomplete="username">

                <div class="field">
                    <label class="form-label">Current Password</label>
                    <div class="pw-wrap">
                        <input type="password" class="form-control" name="current_password" autocomplete="current-password" required>
                        <button type="button" class="pw-toggle" data-toggle="pw" aria-label="Show or hide password">
                            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z" stroke="currentColor" stroke-width="2"/>
                                <path d="M12 15a3 3 0 1 0-3-3 3 3 0 0 0 3 3Z" stroke="currentColor" stroke-width="2"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="field">
                    <label class="form-label">New Password</label>
                    <div class="pw-wrap">
                        <input type="password" class="form-control" name="new_password" autocomplete="new-password" pattern="^\S+$" title="Password must not contain spaces." required>
                        <button type="button" class="pw-toggle" data-toggle="pw" aria-label="Show or hide password">
                            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z" stroke="currentColor" stroke-width="2"/>
                                <path d="M12 15a3 3 0 1 0-3-3 3 3 0 0 0 3 3Z" stroke="currentColor" stroke-width="2"/>
                            </svg>
                        </button>
                    </div>
                    <div class="hint">Minimum of 10 characters, at least 1 letter and 1 number, and no spaces.</div>
                </div>

                <div class="field">
                    <label class="form-label">Confirm New Password</label>
                    <div class="pw-wrap">
                        <input type="password" class="form-control" name="confirm_password" autocomplete="new-password" pattern="^\S+$" title="Password must not contain spaces." required>
                        <button type="button" class="pw-toggle" data-toggle="pw" aria-label="Show or hide password">
                            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z" stroke="currentColor" stroke-width="2"/>
                                <path d="M12 15a3 3 0 1 0-3-3 3 3 0 0 0 3 3Z" stroke="currentColor" stroke-width="2"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <button class="btn btn-primary w-100 account-btn" type="submit">Update Password</button>
            </form>
        </div>

        <div class="account-card account-card-location">
            <div class="account-card-head">
                <div class="icon">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M12 21s7-5.1 7-11a7 7 0 1 0-14 0c0 5.9 7 11 7 11Z" stroke="currentColor" stroke-width="2"/>
                        <path d="M12 10a2 2 0 1 0-2-2 2 2 0 0 0 2 2Z" stroke="currentColor" stroke-width="2"/>
                    </svg>
                </div>
                <div>
                    <h6>Location Settings</h6>
                    <p>Select your Province and Municipality/City. Once locked, the location can no longer be edited.</p>
                </div>
            </div>

            <?php $locked = ((int)($user['location_locked'] ?? 0) === 1); ?>

            <?php if ($locked): ?>
                <div class="account-lock-banner">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M7 11V8a5 5 0 0 1 10 0v3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <path d="M6 11h12v10H6V11Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                    </svg>
                    <div>
                        <strong>Location Locked.</strong><br>
                        Your saved location is already final and can no longer be changed.
                    </div>
                </div>
            <?php endif; ?>

            <form method="post" class="account-form" autocomplete="off" action="<?= h($SELF_URL) ?>">
                <input type="hidden" name="csrf_loc" value="<?= h($csrfLoc) ?>">
                <input type="hidden" name="action" value="update_location">
                <input type="hidden" name="username" value="<?= h((string)$user['username']) ?>" autocomplete="username">

                <div class="form-row-2">
                    <div class="field">
                        <label class="form-label">Province</label>
                        <select class="form-control" id="province" name="province" required <?= $locked ? 'disabled' : '' ?>>
                            <option value="">-- Select Province --</option>
                            <?php foreach (array_keys($LOCATIONS) as $prov): ?>
                                <option value="<?= h($prov) ?>" <?= ((string)$user['province'] === $prov) ? 'selected' : '' ?>>
                                    <?= h($prov) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label class="form-label">Municipality / City</label>
                        <select class="form-control" id="municipality" name="municipality" required <?= $locked ? 'disabled' : '' ?>>
                            <option value="">-- Select Municipality/City --</option>
                        </select>
                    </div>
                </div>

                <div class="field">
                    <label class="lock-check">
                        <input type="checkbox" name="location_locked" value="1" <?= $locked ? 'checked disabled' : '' ?>>
                        <span class="lock-check-text">
                            <strong>Lock my location</strong>
                            <span>Enable this only when your Province and Municipality/City are final. After locking, changes will be permanently disabled.</span>
                        </span>
                    </label>
                </div>

                <div class="field">
                    <label class="form-label">Current Password</label>
                    <div class="pw-wrap">
                        <input type="password" class="form-control" name="current_password" autocomplete="current-password" required <?= $locked ? 'disabled' : '' ?>>
                        <button type="button" class="pw-toggle" data-toggle="pw" aria-label="Show or hide password" <?= $locked ? 'disabled' : '' ?>>
                            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z" stroke="currentColor" stroke-width="2"/>
                                <path d="M12 15a3 3 0 1 0-3-3 3 3 0 0 0 3 3Z" stroke="currentColor" stroke-width="2"/>
                            </svg>
                        </button>
                    </div>
                    <div class="hint">Your password is required before saving location changes.</div>
                </div>

                <button class="btn btn-primary w-100 account-btn" type="submit" <?= $locked ? 'disabled' : '' ?>>
                    Save Location
                </button>

                <div class="account-note">
                    Province logo upload depends on the saved Province. After setting your Province, you can click the top badge to upload a province logo.
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function(){
    document.querySelectorAll('[data-toggle="pw"]').forEach(btn => {
        btn.addEventListener('click', () => {
            if (btn.hasAttribute('disabled')) return;
            const wrap = btn.closest('.pw-wrap');
            const input = wrap ? wrap.querySelector('input') : null;
            if (!input || input.hasAttribute('disabled')) return;
            input.type = (input.type === 'password') ? 'text' : 'password';
        });
    });

    document.querySelectorAll('form.account-form').forEach(form => {
        form.addEventListener('submit', event => {
            const action = form.querySelector('input[name="action"]')?.value || '';
            if (action !== 'change_password') return;

            const fields = [
                form.querySelector('input[name="new_password"]'),
                form.querySelector('input[name="confirm_password"]')
            ].filter(Boolean);

            for (const field of fields) {
                if (/\s/.test(field.value || '')) {
                    field.setCustomValidity('Password must not contain spaces.');
                    field.reportValidity();
                    field.focus();
                    event.preventDefault();
                    return;
                }
                field.setCustomValidity('');
            }
        });
    });

    const LOC = <?= json_encode($LOCATIONS, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const provinceEl = document.getElementById('province');
    const muniEl = document.getElementById('municipality');

    if (provinceEl && muniEl) {
        const currentProvince = <?= json_encode((string)$user['province']) ?>;
        const currentMuni     = <?= json_encode((string)$user['municipality']) ?>;

        function fillMunicipalities(prov, selectedValue){
            muniEl.innerHTML = '<option value="">-- Select Municipality/City --</option>';
            if (!prov || !LOC[prov]) return;

            LOC[prov].forEach(name => {
                const opt = document.createElement('option');
                opt.value = name;
                opt.textContent = name;
                if (selectedValue && selectedValue === name) opt.selected = true;
                muniEl.appendChild(opt);
            });
        }

        fillMunicipalities(provinceEl.value || currentProvince, currentMuni);

        provinceEl.addEventListener('change', () => {
            if (provinceEl.hasAttribute('disabled')) return;
            fillMunicipalities(provinceEl.value, '');
        });
    }

    const logoInput = document.getElementById('provinceLogoInput');
    const logoForm  = document.getElementById('logoForm');
    if (logoInput && logoForm) {
        logoInput.addEventListener('change', () => {
            if (!logoInput.files || !logoInput.files.length) return;
            logoForm.submit();
        });
    }

    const provinceLogoUrl = <?= json_encode($provinceLogoUrl) ?>;
    if (provinceLogoUrl) {
        const candidates = [
            '.sidebar .brand img',
            '.sidebar .logo img',
            '.sidebar img.sidebar-logo',
            '#sidebar img',
            '.app-sidebar img',
            '.sidebar img'
        ];

        let updated = false;

        function setLogo(img){
            if (!img) return;
            img.src = provinceLogoUrl;
            img.alt = 'Province Logo';
            img.style.objectFit = 'contain';
            updated = true;
        }

        candidates.forEach(sel => {
            document.querySelectorAll(sel).forEach(img => {
                const s = (img.getAttribute('src') || '').toLowerCase();
                if (s.includes('tool.png') || s.includes('cpd') || s.includes('logo') || s.includes('img/')) {
                    setLogo(img);
                }
            });
        });

        if (!updated) {
            document.querySelectorAll('img').forEach(img => {
                const rect = img.getBoundingClientRect();
                const s = (img.getAttribute('src') || '').toLowerCase();
                const inSidebar = img.closest('.sidebar, #sidebar, .app-sidebar');
                if (inSidebar && (s.includes('tool.png') || s.includes('cpd') || s.includes('logo'))) {
                    setLogo(img);
                } else if (inSidebar && rect.width <= 80 && rect.height <= 80) {
                    setLogo(img);
                }
            });
        }
    }
})();
</script>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
