<?php
declare(strict_types=1);

require_once __DIR__ . "/../config/session.php";

if (empty($_SESSION['user_id'])) {
    return;
}

$uriPath = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
if (preg_match('#/auth/login\.php$#i', str_replace('\\', '/', $uriPath))) {
    return;
}

if (function_exists('appBaseUrl')) {
    $lockBaseUrl = appBaseUrl();
} else {
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $lockBaseUrl = preg_replace(
        '#/(auth|config|dashboard|profiling|reports|import(?: file)?|includes|view profile)$#i',
        '',
        $scriptDir
    ) ?: '';
}

$lockUrl = rtrim((string)$lockBaseUrl, '/') . '/auth/lock.php';
?>
<script>
(function(){
  if (window.AHDISInactivityLockLoaded) return;
  window.AHDISInactivityLockLoaded = true;

  const lockUrl = <?= json_encode($lockUrl, JSON_UNESCAPED_SLASHES) ?>;
  const timeoutMs = 60000;
  const events = ["mousemove", "mousedown", "click", "keydown", "scroll", "touchstart"];
  let timer = null;
  let locked = false;

  function lockNow(){
    if (locked) return;
    locked = true;
    const returnTo = window.location.pathname + window.location.search + window.location.hash;
    window.location.href = lockUrl
      + "?reason=inactive&return_to="
      + encodeURIComponent(returnTo);
  }

  function resetTimer(){
    if (locked) return;
    window.clearTimeout(timer);
    timer = window.setTimeout(lockNow, timeoutMs);
  }

  events.forEach(function(eventName){
    window.addEventListener(eventName, resetTimer, { passive: true });
  });

  document.addEventListener("visibilitychange", function(){
    if (!document.hidden) resetTimer();
  });

  resetTimer();
})();
</script>
