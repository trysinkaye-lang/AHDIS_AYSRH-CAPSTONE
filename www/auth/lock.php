<?php
declare(strict_types=1);

require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../config/database.php";

$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$baseUrl = preg_replace(
    '#/(auth|config|dashboard|profiling|reports|import(?: file)?|includes|view profile)$#i',
    '',
    $scriptDir
) ?: '';

$loginUrl = rtrim($baseUrl, '/') . '/auth/login.php';
$dashboardUrl = rtrim($baseUrl, '/') . '/dashboard/index.php';

if (!function_exists('ahdisLockH')) {
    function ahdisLockH(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('ahdisLockRedirect')) {
    function ahdisLockRedirect(string $url): void
    {
        if (!headers_sent()) {
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
            header('Location: ' . $url, true, 302);
        } else {
            error_log('LOCK REDIRECT ERROR: Headers already sent before redirect to ' . $url);
        }
        exit;
    }
}

if (!function_exists('ahdisDestroyLockedSession')) {
    function ahdisDestroyLockedSession(): void
    {
        $_SESSION = [];

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

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
}

if (!function_exists('ahdisLockReturnTo')) {
    function ahdisLockReturnTo(string $raw, string $baseUrl, string $fallback): string
    {
        $raw = trim(str_replace(["\r", "\n"], '', $raw));
        if ($raw === '' || !isset($raw[0]) || $raw[0] !== '/' || str_starts_with($raw, '//')) {
            return $fallback;
        }

        $parts = parse_url($raw);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            return $fallback;
        }

        $path = str_replace('\\', '/', (string)($parts['path'] ?? ''));
        if ($path === '' || str_starts_with($path, '//')) {
            return $fallback;
        }

        if ($baseUrl !== '' && $path !== $baseUrl && !str_starts_with($path, $baseUrl . '/')) {
            return $fallback;
        }

        if (preg_match('#/auth/(?:lock|login|logout)\.php$#i', $path)) {
            return $fallback;
        }

        return $raw;
    }
}

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

$requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    ahdisLockRedirect($loginUrl);
}

if ($requestMethod === 'GET') {
    $incomingReturnTo = ahdisLockReturnTo((string)($_GET['return_to'] ?? ''), $baseUrl, '');
    $storedReturnTo = ahdisLockReturnTo((string)($_SESSION['lock_return_to'] ?? ''), $baseUrl, '');

    $_SESSION['session_locked'] = true;
    $_SESSION['locked_user_id'] = $userId;
    $_SESSION['locked_username'] = (string)($_SESSION['username'] ?? '');
    $_SESSION['locked_at'] = (int)($_SESSION['locked_at'] ?? time());

    if ($incomingReturnTo !== '') {
        $_SESSION['lock_return_to'] = $incomingReturnTo;
    } elseif ($storedReturnTo === '') {
        $_SESSION['lock_return_to'] = $dashboardUrl;
    }
}

$lockedUserId = (int)($_SESSION['locked_user_id'] ?? 0);
if (empty($_SESSION['session_locked']) || $lockedUserId <= 0 || $lockedUserId !== $userId) {
    ahdisLockRedirect($dashboardUrl);
}

$errors = [];
$passwordError = false;
$account = null;

try {
    $stmt = $conn->prepare(
        'SELECT id, username, password_hash, role, status, municipality_id
         FROM users
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $lockedUserId]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    error_log('LOCK ACCOUNT ERROR: ' . $e->getMessage());
    $errors[] = 'Unable to verify this account right now. Please try again.';
}

if (!$account) {
    ahdisDestroyLockedSession();
    ahdisLockRedirect($loginUrl);
}

$accountUsername = (string)($account['username'] ?? ($_SESSION['locked_username'] ?? ''));
$accountStatus = strtolower(trim((string)($account['status'] ?? 'active')));
$passwordHash = (string)($account['password_hash'] ?? '');
$_SESSION['locked_username'] = $accountUsername;

if (empty($_SESSION['csrf_unlock'])) {
    $_SESSION['csrf_unlock'] = bin2hex(random_bytes(32));
}
$csrfUnlock = (string)$_SESSION['csrf_unlock'];

if ($requestMethod === 'POST') {
    $unlockFailCount = (int)($_SESSION['unlock_fail_count'] ?? 0);
    $unlockLastFail = (int)($_SESSION['unlock_last_fail'] ?? 0);

    if ($unlockFailCount >= 2) {
        $delay = min(4, $unlockFailCount);
        $elapsed = time() - $unlockLastFail;
        if ($elapsed < $delay) {
            usleep((int)(($delay - $elapsed) * 1000000));
        }
    }

    $postedToken = (string)($_POST['csrf_unlock'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if (!hash_equals($csrfUnlock, $postedToken)) {
        $errors[] = 'Invalid request. Please refresh and try again.';
    } elseif ($password === '') {
        $errors[] = 'Password is required.';
        $passwordError = true;
    } elseif ($accountStatus !== 'active') {
        $errors[] = 'This account is inactive. Sign in using another account.';
        $passwordError = true;
    } elseif ($passwordHash === '' || !password_verify($password, $passwordHash)) {
        $_SESSION['unlock_fail_count'] = $unlockFailCount + 1;
        $_SESSION['unlock_last_fail'] = time();
        $errors[] = 'Incorrect password.';
        $passwordError = true;
    } else {
        $returnTo = ahdisLockReturnTo(
            (string)($_SESSION['lock_return_to'] ?? ''),
            $baseUrl,
            $dashboardUrl
        );

        $_SESSION['username'] = $accountUsername;
        $_SESSION['role'] = (string)($account['role'] ?? ($_SESSION['role'] ?? 'user'));
        $_SESSION['municipality_id'] = (int)($account['municipality_id'] ?? ($_SESSION['municipality_id'] ?? 0));

        unset(
            $_SESSION['session_locked'],
            $_SESSION['locked_user_id'],
            $_SESSION['locked_username'],
            $_SESSION['locked_at'],
            $_SESSION['lock_return_to'],
            $_SESSION['unlock_fail_count'],
            $_SESSION['unlock_last_fail'],
            $_SESSION['csrf_unlock']
        );

        session_regenerate_id(true);
        ahdisLockRedirect($returnTo);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>System Locked | AHDIS</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="../assets/css/bootstrap.min.css">
  <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body class="login-bg">
  <main class="login-wrap" aria-label="AHDIS session unlock">
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

    <section class="login-right lock-panel" aria-labelledby="lock-title">
      <div class="lock-symbol" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none">
          <rect x="5" y="10" width="14" height="10" rx="2" stroke="currentColor" stroke-width="2"/>
          <path d="M8 10V7a4 4 0 0 1 8 0v3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg>
      </div>

      <h1 id="lock-title" class="login-title">System Locked</h1>
      <p class="login-subtitle lock-message">
        Your system was locked after a period of inactivity to protect your information.
      </p>

      <div class="lock-account" aria-label="Locked account">
        <span>Signed in as</span>
        <strong><?= ahdisLockH($accountUsername) ?></strong>
      </div>

      <div class="form-shell">
        <?php if (!empty($errors)): ?>
          <div id="unlock-error" class="alert login-alert login-alert-danger" role="alert">
            <?php foreach ($errors as $message): ?>
              <div><?= ahdisLockH($message) ?></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <form method="POST" autocomplete="on" class="login-form">
          <input type="hidden" name="csrf_unlock" value="<?= ahdisLockH($csrfUnlock) ?>">

          <div class="field">
            <label for="password">Password</label>
            <div class="password-shell">
              <input id="password" type="password" name="password" required autofocus
                     autocomplete="current-password"
                     class="<?= $passwordError ? 'input-error' : '' ?>"
                     aria-invalid="<?= $passwordError ? 'true' : 'false' ?>"
                     aria-describedby="<?= $passwordError ? 'unlock-error lock-help' : 'lock-help' ?>">
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
            <p id="lock-help" class="lock-help">Enter the password for this account to continue.</p>
          </div>

          <button class="btn-login" type="submit">Unlock System</button>
        </form>

        <div class="lock-switch">
        </div>
      </div>
    </section>
  </main>

  <style>
    *{
      box-sizing:border-box;
    }

    .lock-panel{
      text-align:left;
    }

    .lock-symbol{
      width:54px;
      height:54px;
      margin:0 auto 14px;
      display:flex;
      align-items:center;
      justify-content:center;
      border-radius:16px;
      background:#eef4ff;
      color:#1d4ed8;
    }

    .lock-symbol svg{
      width:28px;
      height:28px;
      display:block;
    }

    .lock-message{
      width:100%;
      max-width:430px;
      margin-bottom:14px !important;
      line-height:1.45;
    }

    .lock-account{
      width:100%;
      max-width:420px;
      margin:0 auto 18px;
      padding:11px 14px;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      border:1px solid #dbe4f0;
      border-radius:11px;
      background:#f8fafc;
      color:#64748b;
      font-size:13px;
    }

    .lock-account strong{
      overflow:hidden;
      color:#0f172a;
      font-size:14px;
      text-overflow:ellipsis;
      white-space:nowrap;
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
      color:#1d4ed8;
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
    .btn-login:focus-visible,
    .lock-switch a:focus-visible{
      outline:3px solid rgba(216,137,199,.42);
      outline-offset:3px;
    }

    .lock-help{
      margin:0;
      color:#64748b;
      font-size:12px;
      line-height:1.4;
    }

    .lock-switch{
      margin-top:18px;
      text-align:center;
    }

    .lock-switch a{
      color:#1d4ed8;
      font-size:13px;
      font-weight:800;
      text-decoration:none;
    }

    .lock-switch a:hover{
      text-decoration:underline;
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
