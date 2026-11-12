<?php
declare(strict_types=1);

/**
 * /auth/login.php (FULL UPDATED - DESKTOP SAFE + HARDENED)
 * ✅ DEV_MODE shows fatal errors (shutdown handler)
 * ✅ Prevents redeclare crashes (h, throttleIfNeeded)
 * ✅ Validates $conn is PDO
 * ✅ Hardened return_to (prevents open redirect / path escape)
 * ✅ Sticky username on error
 */

$localAppData = (string)getenv('LOCALAPPDATA');
$logBase = ($localAppData !== ''
  ? rtrim($localAppData, "\\/")
  : rtrim(sys_get_temp_dir(), "\\/")) . DIRECTORY_SEPARATOR . 'AHDIS';

if (!is_dir($logBase)) {
  @mkdir($logBase, 0777, true);
}

@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('log_errors', '1');
@ini_set('error_log', $logBase . DIRECTORY_SEPARATOR . 'php_error.log');
error_reporting(E_ALL);

require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../config/database.php";

/* ✅ Prevent redeclare */
if (!function_exists('h')) {
  function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('ahdisLoginRedirect')) {
  function ahdisLoginRedirect(string $url): void {
    if (!headers_sent()) {
      while (ob_get_level() > 0) {
        @ob_end_clean();
      }
      header("Location: " . $url, true, 302);
    } else {
      error_log('LOGIN REDIRECT ERROR: Headers already sent before redirect to ' . $url);
    }
    exit;
  }
}

/* ✅ Ensure PDO exists */
if (!isset($conn) || !($conn instanceof PDO)) {
  error_log('LOGIN ERROR: $conn is not a PDO instance. Check config/database.php');
  http_response_code(500);
  exit('Internal Server Error');
}

/* =========================
   BASE URL (supports subfolders like /cpd10)
   ========================= */
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$baseUrl   = preg_replace('#/(auth|config|dashboard|profiling|includes)$#', '', $scriptDir) ?: '';
$LOGIN_URL = $baseUrl . "/auth/login.php";
$LOCK_URL = $baseUrl . "/auth/lock.php";
$DASHBOARD_URL = $baseUrl . "/dashboard/index.php";

/* Prevent caching */
if (!headers_sent()) {
  header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
  header("Pragma: no-cache");
  header("Expires: 0");
}

$errors = [];
$usernameError = false;
$passwordError = false;
$usernameSticky = "";

$addLoginError = static function (string $message) use (&$errors): void {
  if (!in_array($message, $errors, true)) {
    $errors[] = $message;
  }
};

/* Optional success message */
$pwChangedMsg = "";
if (!empty($_GET['pw_changed'])) {
  $pwChangedMsg = "Password changed successfully. Please log in again.";
}

/* CSRF token */
if (empty($_SESSION['csrf_login'])) {
  $_SESSION['csrf_login'] = bin2hex(random_bytes(32));
}
$csrfLogin = (string)$_SESSION['csrf_login'];

/* Return-to support (HARDENED) */
$returnToRaw = (string)($_GET['return_to'] ?? '');
$returnToRaw = trim(str_replace(["\r", "\n"], '', $returnToRaw));

$returnTo = '';
if ($returnToRaw !== '') {
  // must be a relative path starting with '/'
  if (isset($returnToRaw[0]) && $returnToRaw[0] === '/' && strpos($returnToRaw, '//') === false) {
    // must stay within this app baseUrl (prevents redirecting outside subfolder install)
    // Examples allowed:
    // - if baseUrl="" then "/dashboard/index.php" is fine
    // - if baseUrl="/cpd10" then "/cpd10/dashboard/index.php" is fine
    if ($baseUrl === '') {
      $returnTo = $returnToRaw;
    } else {
      if ($returnToRaw === $baseUrl || str_starts_with($returnToRaw, $baseUrl . '/')) {
        $returnTo = $returnToRaw;
      }
    }
  }
}

/* Already logged in */
if (!empty($_SESSION['user_id'])) {
  if (!empty($_SESSION['session_locked'])) {
    ahdisLoginRedirect($LOCK_URL);
  }
  ahdisLoginRedirect($DASHBOARD_URL);
}

/* Simple throttle (guarded) */
$_SESSION['login_fail_count'] = (int)($_SESSION['login_fail_count'] ?? 0);
$_SESSION['login_last_fail']  = (int)($_SESSION['login_last_fail'] ?? 0);

if (!function_exists('throttleIfNeeded')) {
  function throttleIfNeeded(): void {
    $count = (int)($_SESSION['login_fail_count'] ?? 0);
    $last  = (int)($_SESSION['login_last_fail'] ?? 0);

    if ($count >= 2) {
      $delay = min(4, $count);     // seconds
      $since = time() - $last;
      if ($since < $delay) {
        usleep((int)(($delay - $since) * 1000000));
      }
    }
  }
}

/* POST Login */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {

  throttleIfNeeded();

  $postedToken = (string)($_POST['csrf_login'] ?? '');
  if (!hash_equals($csrfLogin, $postedToken)) {
    $addLoginError("Invalid request. Please refresh and try again.");
  } else {

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $usernameSticky = $username;

    if ($username === "") {
      $addLoginError("Username is required.");
      $usernameError = true;
    }

    if ($password === "") {
      $addLoginError("Password is required.");
      $passwordError = true;
    }

    if (empty($errors)) {
      try {
        $stmt = $conn->prepare("
          SELECT id, username, password_hash, role, status, municipality_id
          FROM users
          WHERE LOWER(username) = LOWER(:u)
          LIMIT 1
        ");
        $stmt->execute([':u' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $hash   = (string)($user['password_hash'] ?? '');
        $status = strtolower(trim((string)($user['status'] ?? 'active')));

        if (!$user) {
          $_SESSION['login_fail_count'] = (int)($_SESSION['login_fail_count'] ?? 0) + 1;
          $_SESSION['login_last_fail']  = time();
          $addLoginError("Invalid username.");
          $usernameError = true;
          $addLoginError("Wrong password.");
          $passwordError = true;
        } elseif ($hash === "" || !password_verify($password, $hash)) {
          $_SESSION['login_fail_count'] = (int)($_SESSION['login_fail_count'] ?? 0) + 1;
          $_SESSION['login_last_fail']  = time();
          $addLoginError("Wrong password.");
          $passwordError = true;
        } elseif ($status !== 'active') {
          $_SESSION['login_fail_count'] = (int)($_SESSION['login_fail_count'] ?? 0) + 1;
          $_SESSION['login_last_fail']  = time();
          $addLoginError("Your account is inactive. Please contact the administrator.");
        } else {
          $_SESSION['login_fail_count'] = 0;
          $_SESSION['login_last_fail']  = 0;

          session_regenerate_id(true);

          $_SESSION['user_id']         = (int)$user['id'];
          $_SESSION['username']        = (string)$user['username'];
          $_SESSION['role']            = (string)($user['role'] ?? 'user');
          $_SESSION['municipality_id'] = (int)($user['municipality_id'] ?? 0);

          // rotate CSRF token after login
          $_SESSION['csrf_login'] = bin2hex(random_bytes(32));

          $dest = $DASHBOARD_URL;
          if ($returnTo !== '') $dest = $returnTo;

          ahdisLoginRedirect($dest);
        }
      } catch (Throwable $e) {
        error_log('LOGIN ERROR: ' . $e->getMessage());
        $addLoginError("System error. Please contact admin.");
      }
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Login | AHDIS</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="../assets/css/bootstrap.min.css">
  <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body class="login-bg">
  <main class="login-wrap" aria-label="AHDIS login">
    <section class="login-left" aria-label="Agency branding">
      <div class="logo-card">
        <img src="../assets/css/img/cpd logo.png" alt="CPD Logo">
        <img src="../assets/css/img/Bagong-Pilipinas-logo.png" alt="Bagong Pilipinas Logo">
      </div>

      <div class="agency-text">
        <div class="name">Commission on Population and Development</div>
        <div class="region">Region X</div>
      </div>
    </section>

    <section class="login-right" aria-label="Login form">
      <h1 class="login-title">Adolescent Health and<br>Development Information<br>System</h1>
      <div class="login-subtitle">Profiling Tool for Adolescent Mothers</div>

      <div class="form-shell">
          <?php if ($pwChangedMsg): ?>
            <div class="alert login-alert login-alert-success" role="status"><?= h($pwChangedMsg) ?></div>
          <?php endif; ?>

          <?php if (!empty($errors)): ?>
            <div id="login-error-help" class="alert login-alert login-alert-danger" role="alert">
              <?php foreach ($errors as $msg): ?>
                <div><?= h($msg) ?></div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <form method="POST" autocomplete="on" class="login-form">
            <input type="hidden" name="csrf_login" value="<?= h($csrfLogin) ?>">

            <div class="field">
              <label for="username">Username</label>
              <input id="username" type="text" name="username" required autocomplete="username"
                     class="<?= $usernameError ? 'input-error' : '' ?>"
                     aria-invalid="<?= $usernameError ? 'true' : 'false' ?>"
                     aria-describedby="<?= $usernameError ? 'login-error-help' : '' ?>"
                     value="<?= h($usernameSticky) ?>">
            </div>

            <div class="field">
              <label for="password">Password</label>
              <div class="password-shell">
                <input id="password" type="password" name="password" required autocomplete="current-password"
                       class="<?= $passwordError ? 'input-error' : '' ?>"
                       aria-invalid="<?= $passwordError ? 'true' : 'false' ?>"
                       aria-describedby="<?= $passwordError ? 'login-error-help' : '' ?>">
                <button type="button" class="password-toggle" aria-label="Show password" title="Show password"
                        aria-controls="password" aria-pressed="false">
                  <svg data-password-icon="hidden" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z" stroke="currentColor" stroke-width="2"/>
                    <path d="M12 15a3 3 0 1 0-3-3 3 3 0 0 0 3 3Z" stroke="currentColor" stroke-width="2"/>
                  </svg>
                  <svg data-password-icon="visible" viewBox="0 0 24 24" fill="none" aria-hidden="true" hidden>
                    <path d="m2 2 20 20" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <path d="M6.71 6.71C4.93 7.9 3.57 9.72 3 12c1.73 4.61 5.17 7 9 7 1.48 0 2.88-.36 4.12-1" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <path d="M10.73 5.08A8.6 8.6 0 0 1 12 5c3.83 0 7.27 2.39 9 7a10.4 10.4 0 0 1-1.18 2.22" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <path d="M14.12 14.12a3 3 0 1 1-4.24-4.24" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                  </svg>
                </button>
              </div>
            </div>

            <button class="btn-login" type="submit" data-login-submit>
              <span class="btn-login-text">Login</span>
            </button>
          </form>
        </div>
    </section>
  </main>

  <style>
    :root{
      --login-navy:#0f172a;
      --login-deep:#163a73;
      --login-blue:#1d4ed8;
      --login-pink:#d889c7;
      --login-text:#111827;
      --login-muted:#64748b;
      --login-border:#dbe4f0;
      --login-soft:#f5f8fc;
    }

    *{
      box-sizing:border-box;
    }

    html,
    body.login-bg{
      min-height:100%;
    }

    body.login-bg{
      min-height:100vh;
      margin:0;
      padding:28px;
      display:flex;
      align-items:center;
      justify-content:center;
      color:var(--login-text);
      background:
        radial-gradient(circle at 49% 94%, rgba(238,86,104,.58), transparent 9%),
        radial-gradient(circle at 50% 86%, rgba(247,211,75,.66), transparent 12%),
        radial-gradient(circle at 56% 100%, rgba(41,89,190,.50), transparent 24%),
        linear-gradient(135deg, #f9fbff 0%, #e9f1fb 46%, #c6d7ee 100%);
      background-size:cover;
      font-family:Arial, Helvetica, sans-serif;
      overflow-x:hidden;
    }

    .login-page{
      width:min(1125px, calc(100vw - 56px));
      min-height:668px;
      display:grid;
      grid-template-columns:minmax(380px, 42.3%) minmax(460px, 57.7%);
      border:1px solid rgba(255,255,255,.42);
      border-radius:22px;
      overflow:hidden;
      background:#fff;
      box-shadow:0 28px 90px rgba(35,55,95,.30);
    }

    .login-brand-panel{
      position:relative;
      padding:35px 50px 32px;
      display:flex;
      flex-direction:column;
      justify-content:space-between;
      gap:28px;
      color:#fff;
      background:
        radial-gradient(circle at top right, rgba(255,255,255,.18), transparent 28%),
        linear-gradient(145deg, #12366c 0%, #173d78 58%, #214b8f 100%);
    }

    .brand-topline{
      width:max-content;
      max-width:100%;
      padding:9px 13px;
      border:1px solid rgba(255,255,255,.24);
      border-radius:999px;
      background:rgba(255,255,255,.12);
      font-size:12px;
      font-weight:900;
      letter-spacing:.08em;
      text-transform:uppercase;
    }

    .project-logo-card{
      width:326px;
      max-width:100%;
      min-height:250px;
      padding:34px 28px;
      display:flex;
      align-items:center;
      justify-content:center;
      border-radius:18px;
      background:#fff;
      border:1px solid rgba(255,255,255,.56);
      box-shadow:0 18px 42px rgba(15,23,42,.30);
    }

    .project-logo-card img{
      display:block;
      width:100%;
      height:auto;
      max-height:190px;
      object-fit:contain;
    }

    .brand-copy{
      max-width:470px;
    }

    .brand-copy h1{
      margin:0;
      color:#fff;
      font-size:34px;
      line-height:1.08;
      font-weight:950;
      letter-spacing:0;
    }

    .brand-copy p{
      margin:14px 0 0;
      color:rgba(255,255,255,.86);
      font-size:15px;
      line-height:1.55;
      max-width:390px;
    }

    .security-badge{
      width:max-content;
      max-width:100%;
      display:inline-flex;
      align-items:center;
      gap:9px;
      padding:11px 14px;
      border-radius:14px;
      background:rgba(255,255,255,.13);
      border:1px solid rgba(255,255,255,.20);
      color:#fff;
      font-size:13px;
      font-weight:850;
    }

    .security-dot{
      width:10px;
      height:10px;
      flex:0 0 10px;
      border-radius:50%;
      background:#86efac;
      box-shadow:0 0 0 4px rgba(134,239,172,.18);
    }

    .agency-strip{
      display:flex;
      align-items:center;
      gap:12px;
      padding:12px;
      width:max-content;
      max-width:100%;
      border-radius:18px;
      background:rgba(255,255,255,.94);
      box-shadow:0 14px 28px rgba(15,23,42,.18);
    }

    .agency-strip img{
      display:block;
      height:48px;
      width:auto;
      max-width:120px;
      object-fit:contain;
    }

    .login-card-panel{
      padding:52px 64px;
      display:flex;
      flex-direction:column;
      justify-content:center;
      gap:18px;
      background:
        linear-gradient(180deg, rgba(255,255,255,.94), rgba(248,251,255,.96));
    }

    .login-card{
      width:100%;
      max-width:520px;
      margin:0 auto;
      padding:38px 34px;
      border-radius:26px;
      background:#fff;
      border:0;
      box-shadow:none;
    }

    .login-card-head{
      margin-bottom:30px;
      text-align:center;
    }

    .login-kicker{
      margin:0 0 8px;
      color:var(--login-blue);
      font-size:12px;
      font-weight:950;
      letter-spacing:.08em;
      text-transform:uppercase;
    }

    .login-card-head h2{
      margin:0;
      color:var(--login-navy);
      font-size:34px;
      line-height:1.2;
      font-weight:950;
    }

    .login-card-head p:not(.login-kicker){
      margin:14px 0 0;
      color:var(--login-muted);
      font-size:16px;
      line-height:1.5;
      font-weight:700;
    }

    .form-shell{
      width:100%;
    }

    .login-form{
      display:flex;
      flex-direction:column;
      gap:19px;
      margin:0;
    }

    .field{
      display:flex;
      flex-direction:column;
      gap:8px;
      margin:0;
    }

    .field label{
      margin:0;
      color:#243045;
      font-size:16px;
      font-weight:900;
    }

    .input-shell{
      position:relative;
      display:flex;
      align-items:center;
      min-height:58px;
    }

    .input-mark{
      position:absolute;
      left:14px;
      width:24px;
      height:24px;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      border-radius:8px;
      background:#eef4ff;
      color:var(--login-blue);
      font-size:12px;
      font-weight:950;
      pointer-events:none;
    }

    .field input{
      width:100%;
      height:58px;
      padding:0 18px 0 52px;
      border:1px solid var(--login-border);
      border-radius:14px;
      outline:none;
      background:#fbfdff;
      color:var(--login-text);
      font-size:15px;
      transition:border-color 120ms ease, box-shadow 120ms ease, background-color 120ms ease;
    }

    .password-shell input{
      padding-right:46px;
    }

    .field input:focus{
      border-color:var(--login-blue);
      background:#fff;
      box-shadow:0 0 0 4px rgba(29,78,216,.14);
    }

    .field input.input-error{
      border-color:#dc2626;
      background:#fff7f7;
      box-shadow:0 0 0 4px rgba(220,38,38,.12);
    }

    .password-shell > .password-toggle{
      position:absolute;
      right:8px;
      top:50%;
      bottom:auto;
      width:32px;
      height:32px;
      margin:0;
      padding:0;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      line-height:1;
      transform:translateY(-50%) !important;
      border:0;
      border-radius:8px;
      background:transparent;
      color:#64748b;
      cursor:pointer;
      z-index:1;
    }

    .password-toggle:hover{
      background:#f5f8fc;
      color:var(--login-blue);
    }

    .password-toggle svg{
      width:18px;
      height:18px;
      display:block;
    }

    .password-toggle svg[hidden]{
      display:none;
    }

    .password-toggle:focus-visible,
    .btn-login:focus-visible{
      outline:3px solid rgba(216,137,199,.42);
      outline-offset:3px;
    }

    .login-alert{
      margin:0 0 14px;
      padding:12px 14px;
      border-radius:14px;
      border:1px solid;
      font-size:13px;
      font-weight:800;
      line-height:1.4;
    }

    .login-alert-success{
      border-color:#bbf7d0;
      background:#f0fdf4;
      color:#166534;
    }

    .login-alert-warning{
      border-color:#fde68a;
      background:#fffbeb;
      color:#92400e;
    }

    .login-alert-danger{
      border-color:#fecaca;
      background:#fef2f2;
      color:#991b1b;
    }

    .login-error-text{
      margin:-4px 0 0;
      color:#b91c1c;
      font-size:13px;
      font-weight:850;
    }

    .btn-login{
      width:275px;
      height:56px;
      align-self:center;
      margin-top:-2px;
      border:none;
      border-radius:999px;
      color:#fff;
      background:linear-gradient(135deg, #2b63ec 0%, #1fb3df 100%);
      box-shadow:0 15px 28px rgba(43,99,236,.22);
      font-size:17px;
      font-weight:950;
      cursor:pointer;
      transition:
        background-color 120ms ease,
        color 120ms ease,
        border-color 120ms ease,
        box-shadow 120ms ease,
        opacity 120ms ease;
    }

    .btn-login:hover{
      box-shadow:0 20px 36px rgba(29,78,216,.32);
      filter:brightness(1.02);
    }

    .btn-login.is-loading{
      cursor:wait;
      filter:saturate(.9);
    }

    .login-footer{
      max-width:450px;
      margin:0 auto;
      text-align:center;
      color:#64748b;
      font-size:12px;
      line-height:1.55;
    }

    @media (max-width:980px){
      body.login-bg{
        padding:18px;
        align-items:flex-start;
      }

      .login-page{
        width:min(560px, 100%);
        min-height:0;
        grid-template-columns:1fr;
      }

      .login-brand-panel{
        padding:28px;
        gap:18px;
      }

      .project-logo-card{
        width:240px;
        min-height:160px;
      }

      .brand-copy h1{
        font-size:27px;
      }

      .login-card-panel{
        padding:34px 24px;
      }

      .login-card{
        max-width:520px;
      }
    }

    @media (max-width:560px){
      body.login-bg{
        padding:12px;
      }

      .login-page{
        width:100%;
        border-radius:18px;
      }

      .login-brand-panel,
      .login-card-panel{
        padding:22px;
      }

      .brand-copy h1{
        font-size:23px;
      }

      .brand-copy p{
        font-size:14px;
      }

      .agency-strip{
        width:100%;
        justify-content:center;
      }

      .agency-strip img{
        height:40px;
        max-width:100px;
      }

      .login-card{
        padding:24px 20px;
        border-radius:22px;
      }

      .login-card-head h2{
        font-size:26px;
      }

      .btn-login{
        width:100%;
      }
    }

    /* Reference-matched login UI */
    body.login-bg{
      min-height:100vh;
      margin:0;
      padding:28px !important;
      display:flex !important;
      align-items:center !important;
      justify-content:center !important;
      color:#111827;
      font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif !important;
      background:
        linear-gradient(rgba(238,244,255,.70), rgba(198,216,239,.76)),
        url("../assets/css/img/login.png") no-repeat center center fixed !important;
      background-size:cover !important;
      overflow-x:hidden;
    }

    .login-wrap{
      width:min(940px, calc(100vw - 56px)) !important;
      max-width:940px !important;
      min-height:520px !important;
      height:auto !important;
      max-height:none !important;
      margin:0 auto;
      display:grid !important;
      grid-template-columns:40% 60% !important;
      overflow:hidden !important;
      border-radius:16px !important;
      background:#fff !important;
      box-shadow:0 28px 78px rgba(15,23,42,.32) !important;
    }

    .login-left{
      min-height:0 !important;
      height:auto !important;
      padding:28px 30px 32px !important;
      display:flex !important;
      flex-direction:column;
      align-items:center;
      justify-content:center;
      gap:24px;
      color:#fff;
      background:linear-gradient(180deg,#12366d 0%,#163f7a 100%) !important;
    }

    .logo-card{
      width:258px !important;
      max-width:258px !important;
      min-height:382px !important;
      padding:34px 26px 26px !important;
      display:flex !important;
      flex-direction:column;
      align-items:center;
      justify-content:space-between;
      gap:28px;
      border-radius:14px !important;
      background:#fff !important;
      box-shadow:0 16px 34px rgba(15,23,42,.24) !important;
    }

    .logo-card img{
      display:block;
      width:100% !important;
      height:auto !important;
      object-fit:contain !important;
    }

    .logo-card img:first-child{
      max-width:166px !important;
    }

    .logo-card img:nth-child(2){
      max-width:170px !important;
    }

    .agency-text{
      width:100%;
      max-width:310px;
      margin:0;
      text-align:center;
      color:#eef5ff;
      line-height:1.25;
    }

    .agency-text .name{
      font-size:16px !important;
      font-weight:900;
      letter-spacing:.1px;
    }

    .agency-text .region{
      margin-top:4px;
      color:#eef5ff;
      font-size:14px !important;
      font-weight:500;
      opacity:.95;
    }

    .login-right{
      position:relative;
      min-height:0 !important;
      height:auto !important;
      padding:54px 54px 50px !important;
      display:flex !important;
      flex-direction:column;
      align-items:center;
      justify-content:center;
      gap:0;
      background:#fff !important;
      overflow:visible;
    }

    .login-right::before{
      content:none !important;
      display:none !important;
    }

    .login-title,
    .login-subtitle,
    .form-shell{
      position:relative;
      z-index:1;
    }

    .login-title{
      max-width:460px;
      margin:0 !important;
      text-align:center;
      color:#0f172a !important;
      font-size:30px !important;
      line-height:1.18;
      font-weight:900;
      letter-spacing:.15px;
    }

    .login-subtitle{
      margin:18px 0 18px !important;
      text-align:center;
      color:#687386 !important;
      font-size:16px !important;
      line-height:1.25;
      font-weight:500;
    }

    .form-shell{
      width:100% !important;
      max-width:420px !important;
      margin:0 auto !important;
      padding:0 !important;
      border:0 !important;
      background:transparent !important;
      box-shadow:none !important;
    }

    .login-form{
      width:100% !important;
      display:flex;
      flex-direction:column;
      gap:16px;
      margin:0;
    }

    .field{
      display:flex;
      flex-direction:column;
      gap:7px;
      margin:0 !important;
    }

    .field label{
      margin:0;
      color:#0f172a !important;
      font-size:14px !important;
      font-weight:800 !important;
      line-height:1;
    }

    .field input{
      width:100%;
      height:48px !important;
      padding:0 14px !important;
      border:1px solid #dfe6ef !important;
      border-radius:11px !important;
      background:#fff !important;
      color:#111827;
      font-size:15px;
      font-weight:500;
      outline:none;
      box-shadow:none;
    }

    .password-shell{
      position:relative;
      width:100%;
    }

    .password-shell input{
      padding-right:46px !important;
    }

    .password-shell input::-ms-reveal,
    .password-shell input::-ms-clear{
      display:none;
    }

    .field input:focus{
      border-color:#1d4ed8 !important;
      box-shadow:0 0 0 4px rgba(29,78,216,.12) !important;
    }

    .field input.input-error{
      border-color:#dc2626 !important;
      background:#fff7f7 !important;
      box-shadow:0 0 0 4px rgba(220,38,38,.12) !important;
    }

    .login-alert{
      width:100%;
      margin:0 0 14px;
      padding:12px 14px;
      border-radius:14px;
      border:1px solid;
      font-size:13px;
      font-weight:800;
      line-height:1.4;
    }

    .login-error-text{
      margin:-4px 0 0;
      color:#b91c1c;
      font-size:13px;
      font-weight:850;
    }

    .btn-login{
      width:220px !important;
      height:46px !important;
      align-self:center;
      margin:2px auto 0 !important;
      border:0;
      border-radius:999px !important;
      padding:0 16px !important;
      background:linear-gradient(135deg, #2563eb, #0ea5e9) !important;
      box-shadow:0 16px 28px rgba(37,99,235,.28) !important;
      color:#fff;
      font-size:15px !important;
      font-weight:900;
      cursor:pointer;
    }

    .btn-login:hover{
      box-shadow:0 20px 36px rgba(29,78,216,.30) !important;
      filter:brightness(1.02);
    }

    @media (max-width:950px){
      body.login-bg{
        padding:18px !important;
        align-items:flex-start !important;
      }

      .login-wrap{
        width:min(560px, 100%) !important;
        max-width:560px !important;
        min-height:0 !important;
        height:auto !important;
        grid-template-columns:1fr !important;
      }

      .login-left,
      .login-right{
        min-height:0 !important;
        height:auto !important;
      }

      .login-left{
        padding:28px 22px !important;
      }

      .logo-card{
        width:240px !important;
        max-width:240px !important;
        min-height:320px !important;
        padding:26px 22px !important;
      }

      .logo-card img:first-child{
        max-width:150px !important;
      }

      .logo-card img:nth-child(2){
        max-width:155px !important;
      }

      .login-right{
        padding:36px 24px !important;
      }
    }

    @media (max-width:560px){
      body.login-bg{
        padding:12px !important;
      }

      .login-wrap{
        width:100% !important;
        max-width:100% !important;
        border-radius:14px !important;
      }

      .login-left{
        padding:24px 18px !important;
      }

      .logo-card{
        width:220px !important;
        max-width:220px !important;
        min-height:290px !important;
      }

      .login-right{
        padding:32px 20px !important;
      }

      .login-title{
        font-size:25px !important;
      }

      .login-subtitle{
        font-size:15px !important;
      }

      .form-shell{
        max-width:100% !important;
      }

      .btn-login{
        width:100% !important;
      }
    }
  </style>

  <script>
    (function(){
      const input = document.getElementById('password');
      const toggle = document.querySelector('.password-toggle');
      if (!input || !toggle) return;

      const eyeIcon = toggle.querySelector('[data-password-icon="hidden"]');
      const eyeOffIcon = toggle.querySelector('[data-password-icon="visible"]');

      toggle.addEventListener('pointerdown', function(event){
        event.preventDefault();
      });

      toggle.addEventListener('click', function(event){
        const passwordIsHidden = input.type === 'password';
        const selectionStart = input.selectionStart;
        const selectionEnd = input.selectionEnd;
        const label = passwordIsHidden ? 'Hide password' : 'Show password';

        input.type = passwordIsHidden ? 'text' : 'password';
        toggle.setAttribute('aria-label', label);
        toggle.setAttribute('title', label);
        toggle.setAttribute('aria-pressed', passwordIsHidden ? 'true' : 'false');
        eyeIcon.toggleAttribute('hidden', passwordIsHidden);
        eyeOffIcon.toggleAttribute('hidden', !passwordIsHidden);

        if (event.detail > 0) {
          input.focus({ preventScroll: true });
          if (selectionStart !== null && selectionEnd !== null) {
            input.setSelectionRange(selectionStart, selectionEnd);
          }
        }
      });
    })();
  </script>

</body>
</html>
