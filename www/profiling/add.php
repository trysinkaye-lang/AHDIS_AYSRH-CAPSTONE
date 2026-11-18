<?php
declare(strict_types=1);

/**
 * /profiling/add.php
 * FULL COMPLETE UPDATED
 *
 * FIXES INCLUDED:
 * - ✅ 1-click radio/select logic
 * - ✅ fixed broken HTML structure near Barangay section
 * - ✅ simplified show/hide logic for dependent sections
 * - ✅ corrected enabling/disabling logic for conditional inputs
 * - ✅ keeps snapshot-only mode, legacy edit mode, and new update mode
 * - ✅ keeps hidden present_address auto-build for DB consistency
 * - ✅ UPDATED: header box now uses PROJECT 25@28-inspired gradient
 * - ✅ UPDATED: routing is resilient even if some files are inside "view profile"
 * - ✅ NEW: strict duplicate warning + hard block UI
 * - ✅ NEW: clear message that the file/name already existed
 */

require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/education_options.php";

/* =========================
   HELPERS (safe, no redeclare)
   ========================= */
if (!function_exists('h')) {
  function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('tableExists')) {
  function tableExists(PDO $conn, string $table): bool {
    $stmt = $conn->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:t LIMIT 1");
    $stmt->execute([':t' => $table]);
    return (bool)$stmt->fetchColumn();
  }
}

if (!function_exists('tableColumns')) {
  function tableColumns(PDO $conn, string $table): array {
    try {
      $cols = $conn->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC);
      return array_map(fn($c) => (string)$c['name'], $cols ?: []);
    } catch (Throwable $e) {
      return [];
    }
  }
}

if (!function_exists('pickFirstExistingColumn')) {
  function pickFirstExistingColumn(array $columns, array $candidates): ?string {
    $set = array_flip(array_map('strtolower', $columns));
    foreach ($candidates as $c) {
      if (isset($set[strtolower($c)])) return $c;
    }
    return null;
  }
}

if (!function_exists('lowerSafe')) {
  function lowerSafe(string $s): string {
    $s = trim($s);
    if ($s === '') return '';
    if (function_exists('mb_strtolower')) return mb_strtolower($s, 'UTF-8');
    return strtolower($s);
  }
}

if (!function_exists('normName')) {
  function normName(string $s): string {
    $s = trim($s);
    $s = (string)preg_replace('/\s+/', ' ', $s);
    return $s ?: '';
  }
}

if (!function_exists('muniVariants')) {
  function muniVariants(string $municipality): array {
    $m = normName($municipality);
    if ($m === '') return [];

    $variants = [];
    $variants[] = $m;

    $noCity = preg_replace('/\s*city\s*$/i', '', $m);
    $noCity = normName((string)$noCity);
    if ($noCity !== '') $variants[] = $noCity;

    if ($noCity !== '') $variants[] = normName($noCity . " City");

    if (stripos($m, 'City of ') === 0) {
      $variants[] = normName(substr($m, 8));
      $variants[] = normName(substr($m, 8) . " City");
    } else {
      $variants[] = normName("City of " . $noCity);
    }

    if (stripos($m, 'Municipality of ') === 0) {
      $variants[] = normName(substr($m, 15));
    } else {
      $variants[] = normName("Municipality of " . $noCity);
    }

    $variants = array_values(array_unique(array_filter($variants, fn($x) => $x !== '')));
    return $variants;
  }
}

if (!function_exists('computeAgeFromDob')) {
  function computeAgeFromDob(string $dob): ?int {
    $dob = trim($dob);
    if ($dob === '') return null;

    $dt = null;

    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dob, $m)) {
      $y = (int)$m[1];
      $mo = (int)$m[2];
      $d = (int)$m[3];
      if (checkdate($mo, $d, $y)) $dt = DateTime::createFromFormat('Y-m-d', $dob);
    } elseif (preg_match('/^(0[1-9]|1[0-2])\/(0[1-9]|[12][0-9]|3[01])\/(19|20)\d{2}$/', $dob)) {
      $dt = DateTime::createFromFormat('m/d/Y', $dob);
    }

    if (!$dt) return null;

    $today = new DateTime('today');
    $dt->setTime(0, 0, 0);
    if ($dt > $today) return null;

    $age = (int)$today->diff($dt)->y;
    return $age >= 0 ? $age : null;
  }
}

if (!function_exists('dateValueForInput')) {
  function dateValueForInput(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') return '';

    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
      $y = (int)$m[1];
      $mo = (int)$m[2];
      $d = (int)$m[3];
      return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : '';
    }

    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $raw, $m)) {
      $mo = (int)$m[1];
      $d = (int)$m[2];
      $y = (int)$m[3];
      return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : '';
    }

    $ts = strtotime($raw);
    return $ts === false ? '' : date('Y-m-d', $ts);
  }
}

if (!function_exists('splitNameForForm')) {
  function splitNameForForm(string $full): array {
    $full = normName($full);
    if ($full === '') return ['', '', ''];

    if (strpos($full, ',') !== false) {
      [$last, $rest] = array_map('trim', explode(',', $full, 2));
      $parts = preg_split('/\s+/', trim($rest)) ?: [];
      $first = $parts[0] ?? '';
      $middle = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';
      return [$last, $first, $middle];
    }

    $parts = preg_split('/\s+/', $full) ?: [];
    if (count($parts) === 1) return [$parts[0], '', ''];
    if (count($parts) === 2) return [$parts[1], $parts[0], ''];

    return [
      $parts[count($parts) - 1],
      $parts[0],
      implode(' ', array_slice($parts, 1, -1)),
    ];
  }
}

if (!function_exists('buildFullNameForForm')) {
  function buildFullNameForForm(string $last, string $first, string $middle): string {
    $last = normName($last);
    $first = normName($first);
    $middle = normName($middle);
    $given = trim($first . ($middle !== '' ? ' ' . $middle : ''));
    if ($last !== '' && $given !== '') return $last . ', ' . $given;
    return trim($last . ' ' . $given);
  }
}

/* =========================
   ROUTING / BASE URL
   ========================= */
if (!function_exists('baseUrlFromScript')) {
  function baseUrlFromScript(): string {
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $dir = preg_replace('#/(auth|config|dashboard|profiling|reports|includes|view profile)$#', '', $dir);
    return $dir ?: '';
  }
}

if (!function_exists('buildUrlFromRelative')) {
  function buildUrlFromRelative(string $baseUrl, string $relative): string {
    $relative = trim(str_replace('\\', '/', $relative), '/');
    if ($relative === '') return $baseUrl ?: '/';

    $segments = array_map(
      static fn($seg) => rawurlencode($seg),
      array_values(array_filter(explode('/', $relative), static fn($x) => $x !== ''))
    );

    $path = implode('/', $segments);
    return rtrim($baseUrl, '/') . '/' . $path;
  }
}

if (!function_exists('resolveExistingRoute')) {
  function resolveExistingRoute(string $baseUrl, string $projectRoot, array $candidates): string {
    foreach ($candidates as $relative) {
      $full = rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
      if (is_file($full)) {
        return buildUrlFromRelative($baseUrl, $relative);
      }
    }
    return buildUrlFromRelative($baseUrl, $candidates[0] ?? '');
  }
}

$projectRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$baseUrl = baseUrlFromScript();

$DASHBOARD_URL = resolveExistingRoute($baseUrl, $projectRoot, [
  'dashboard/index.php',
  'index.php',
]);

$ACCOUNT_URL = resolveExistingRoute($baseUrl, $projectRoot, [
  'auth/account.php',
]);

$LOGIN_URL = resolveExistingRoute($baseUrl, $projectRoot, [
  'auth/login.php',
]);

$VIEW_URL = resolveExistingRoute($baseUrl, $projectRoot, [
  'profiling/view.php',
  'view profile/view.php',
]);

$ADOL_VIEW_URL = resolveExistingRoute($baseUrl, $projectRoot, [
  'profiling/adolescent_view.php',
  'view profile/adolescent_view.php',
]);

$SAVE_URL = resolveExistingRoute($baseUrl, $projectRoot, [
  'profiling/save.php',
  'save.php',
]);

$ADD_URL = resolveExistingRoute($baseUrl, $projectRoot, [
  'profiling/add.php',
  'add.php',
]);

/* =========================
   CSRF
   ========================= */
if (empty($_SESSION['csrf_profiling_form'])) {
  $_SESSION['csrf_profiling_form'] = bin2hex(random_bytes(32));
}
$csrfForm = (string)$_SESSION['csrf_profiling_form'];

/* =========================
   MODE FLAGS FROM URL
   ========================= */
$id           = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$encounterId  = isset($_GET['encounter_id']) ? (int)$_GET['encounter_id'] : 0;
$adolescentId = isset($_GET['adolescent_id']) ? (int)$_GET['adolescent_id'] : 0;
$editLatest   = ((int)($_GET['edit_latest'] ?? 0) === 1);
$isSnapshotOnly = ($editLatest && ($id > 0 || $encounterId > 0));

if ($editLatest && $id <= 0 && $encounterId <= 0) {
  $editLatest = false;
}

$row = [];
$prefillFromHistory = false;

/* =========================================================
   AUTO-FIX: Ensure columns exist
   ========================================================= */
if (!function_exists('ensureAdolescentMothersColumn')) {
  function ensureAdolescentMothersColumn(PDO $conn, string $column, string $type = 'TEXT'): void {
    try {
      $cols = tableColumns($conn, 'adolescent_mothers');
      $set  = array_flip(array_map('strtolower', $cols));
      if (isset($set[strtolower($column)])) return;
      $conn->exec("ALTER TABLE adolescent_mothers ADD COLUMN {$column} {$type}");
    } catch (Throwable $e) {
      // silent
    }
  }
}

if (!function_exists('ensureTableColumn')) {
  function ensureTableColumn(PDO $conn, string $table, string $column, string $type = 'TEXT'): void {
    try {
      if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) return;
      if (!tableExists($conn, $table)) return;
      $cols = tableColumns($conn, $table);
      $set  = array_flip(array_map('strtolower', $cols));
      if (isset($set[strtolower($column)])) return;
      $conn->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$type}");
    } catch (Throwable $e) {
      // silent
    }
  }
}

try {
  $conn->exec("
    CREATE TABLE IF NOT EXISTS adolescent_mothers (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      name TEXT,
      present_address TEXT,
      age INTEGER,
      created_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
  ");
} catch (Throwable $e) {}

/* A. Adolescent Mother (Identifying) */
ensureAdolescentMothersColumn($conn, 'locked_municipality', 'TEXT');
ensureAdolescentMothersColumn($conn, 'locked_barangay', 'TEXT');
ensureAdolescentMothersColumn($conn, 'municipality', 'TEXT');
ensureAdolescentMothersColumn($conn, 'barangay', 'TEXT');
ensureAdolescentMothersColumn($conn, 'last_name', 'TEXT');
ensureAdolescentMothersColumn($conn, 'first_name', 'TEXT');
ensureAdolescentMothersColumn($conn, 'middle_name', 'TEXT');
ensureAdolescentMothersColumn($conn, 'sex', 'TEXT');
ensureAdolescentMothersColumn($conn, 'present_address', 'TEXT');
ensureAdolescentMothersColumn($conn, 'place_of_birth', 'TEXT');
ensureAdolescentMothersColumn($conn, 'date_of_birth', 'TEXT');
ensureAdolescentMothersColumn($conn, 'age', 'INTEGER');

/* II Education */
ensureAdolescentMothersColumn($conn, 'education', 'TEXT');
ensureAdolescentMothersColumn($conn, 'educational_attainment', 'TEXT');
ensureAdolescentMothersColumn($conn, 'currently_enrolled', 'TEXT');
ensureAdolescentMothersColumn($conn, 'year_level', 'TEXT');
ensureAdolescentMothersColumn($conn, 'grade_year_level', 'TEXT');
ensureAdolescentMothersColumn($conn, 'last_school_attended_type', 'TEXT');
ensureAdolescentMothersColumn($conn, 'reason_not_attending_school', 'TEXT');
ensureAdolescentMothersColumn($conn, 'reason_for_not_attending_school', 'TEXT');
ensureAdolescentMothersColumn($conn, 'reason_not_attending_school_other', 'TEXT');
ensureAdolescentMothersColumn($conn, 'vocational_training_other', 'TEXT');

/* III Civil */
ensureAdolescentMothersColumn($conn, 'civil_status', 'TEXT');

/* IV Living */
ensureAdolescentMothersColumn($conn, 'living_situation', 'TEXT');
ensureAdolescentMothersColumn($conn, 'living_others', 'TEXT');

/* V Fertility */
ensureAdolescentMothersColumn($conn, 'pregnant', 'TEXT');
ensureAdolescentMothersColumn($conn, 'months_pregnant', 'TEXT');
ensureAdolescentMothersColumn($conn, 'prenatal_care', 'TEXT');
ensureAdolescentMothersColumn($conn, 'prenatal_visits', 'INTEGER');
ensureAdolescentMothersColumn($conn, 'health_concerns', 'TEXT');
ensureAdolescentMothersColumn($conn, 'age_first_pregnancy', 'TEXT');
ensureAdolescentMothersColumn($conn, 'times_pregnant', 'TEXT');
ensureAdolescentMothersColumn($conn, 'living_children', 'TEXT');
ensureAdolescentMothersColumn($conn, 'child_under_one', 'TEXT');
ensureAdolescentMothersColumn($conn, 'family_planning', 'TEXT');

/* VI 4Ps / VII IP */
ensureAdolescentMothersColumn($conn, 'is_4ps', 'TEXT');
ensureAdolescentMothersColumn($conn, 'is_ips', 'TEXT');
ensureAdolescentMothersColumn($conn, 'ethnicity', 'TEXT');

/* VIII Work */
ensureAdolescentMothersColumn($conn, 'currently_working', 'TEXT');
ensureAdolescentMothersColumn($conn, 'monthly_income', 'TEXT');
ensureAdolescentMothersColumn($conn, 'occupation', 'TEXT');

/* IX Parents occupation */
ensureAdolescentMothersColumn($conn, 'father_occupation', 'TEXT');
ensureAdolescentMothersColumn($conn, 'mother_occupation', 'TEXT');

/* X Government Assistance */
ensureAdolescentMothersColumn($conn, 'gov_assistance', 'TEXT');
ensureAdolescentMothersColumn($conn, 'gov_assistance_details', 'TEXT');
ensureAdolescentMothersColumn($conn, 'gov_assistance_other', 'TEXT');

/* Other observations / signatories */
ensureAdolescentMothersColumn($conn, 'other_observations', 'TEXT');
ensureAdolescentMothersColumn($conn, 'interviewed_by', 'TEXT');
ensureAdolescentMothersColumn($conn, 'noted_by', 'TEXT');
ensureAdolescentMothersColumn($conn, 'interview_date', 'TEXT');
ensureAdolescentMothersColumn($conn, 'noted_date', 'TEXT');
ensureAdolescentMothersColumn($conn, 'encoded_by', 'TEXT');
ensureAdolescentMothersColumn($conn, 'date_encoded', 'TEXT');

/* Partner */
ensureAdolescentMothersColumn($conn, 'partner_name', 'TEXT');
ensureAdolescentMothersColumn($conn, 'partner_surname', 'TEXT');
ensureAdolescentMothersColumn($conn, 'partner_first_name', 'TEXT');
ensureAdolescentMothersColumn($conn, 'partner_middle_name', 'TEXT');
ensureAdolescentMothersColumn($conn, 'partner_address', 'TEXT');
ensureAdolescentMothersColumn($conn, 'partner_place_of_birth', 'TEXT');
ensureAdolescentMothersColumn($conn, 'partner_dob', 'TEXT');
ensureAdolescentMothersColumn($conn, 'partner_age', 'INTEGER');
ensureAdolescentMothersColumn($conn, 'partner_education', 'TEXT');
ensureAdolescentMothersColumn($conn, 'partner_currently_enrolled', 'TEXT');
ensureAdolescentMothersColumn($conn, 'partner_year_level', 'TEXT');
ensureAdolescentMothersColumn($conn, 'partner_currently_working', 'TEXT');
ensureAdolescentMothersColumn($conn, 'partner_monthly_income', 'TEXT');
ensureAdolescentMothersColumn($conn, 'partner_occupation', 'TEXT');

ensureAdolescentMothersColumn($conn, 'attended_proctecteen_sppmc', 'TEXT');
ensureAdolescentMothersColumn($conn, 'attended_protecteen_sppmc', 'TEXT');

/* =========================================================
   Ensure NEW tables exist
   ========================================================= */
try {
  $conn->exec("
    CREATE TABLE IF NOT EXISTS adolescents (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      last_name TEXT,
      first_name TEXT,
      middle_name TEXT,
      full_name TEXT,
      date_of_birth TEXT,
      sex TEXT,
      barangay TEXT,
      municipality TEXT,
      present_address TEXT,
      educational_attainment TEXT,
      currently_enrolled TEXT,
      grade_year_level TEXT,
      last_school_attended_type TEXT,
      reason_not_attending_school TEXT,
      reason_for_not_attending_school TEXT,
      reason_not_attending_school_other TEXT,
      vocational_training_other TEXT,
      created_at TEXT DEFAULT CURRENT_TIMESTAMP,
      updated_at TEXT
    );
  ");
  $conn->exec("
    CREATE TABLE IF NOT EXISTS encounters (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      adolescent_id INTEGER NOT NULL,
      encounter_date TEXT NOT NULL,
      status TEXT NOT NULL DEFAULT 'draft',
      pregnant TEXT,
      times_pregnant INTEGER,
      age_first_pregnancy INTEGER,
      living_children INTEGER,
      family_planning TEXT,
      educational_attainment TEXT,
      currently_enrolled TEXT,
      grade_year_level TEXT,
      last_school_attended_type TEXT,
      reason_not_attending_school TEXT,
      reason_for_not_attending_school TEXT,
      reason_not_attending_school_other TEXT,
      vocational_training_other TEXT,
      programs_applied TEXT,
      data_json TEXT,
      encoded_by TEXT,
      date_encoded TEXT,
      created_by_user_id INTEGER,
      created_at TEXT DEFAULT CURRENT_TIMESTAMP,
      verified_by_user_id INTEGER,
      verified_at TEXT
    );
  ");
} catch (Throwable $e) {
  // silent
}

foreach (['adolescents', 'encounters'] as $educationTable) {
  ensureTableColumn($conn, $educationTable, 'educational_attainment', 'TEXT');
  ensureTableColumn($conn, $educationTable, 'currently_enrolled', 'TEXT');
  ensureTableColumn($conn, $educationTable, 'grade_year_level', 'TEXT');
  ensureTableColumn($conn, $educationTable, 'last_school_attended_type', 'TEXT');
  ensureTableColumn($conn, $educationTable, 'reason_not_attending_school', 'TEXT');
  ensureTableColumn($conn, $educationTable, 'reason_for_not_attending_school', 'TEXT');
  ensureTableColumn($conn, $educationTable, 'reason_not_attending_school_other', 'TEXT');
  ensureTableColumn($conn, $educationTable, 'vocational_training_other', 'TEXT');
}
ensureTableColumn($conn, 'encounters', 'encoded_by', 'TEXT');
ensureTableColumn($conn, 'encounters', 'date_encoded', 'TEXT');
ensureTableColumn($conn, 'encounters', 'prenatal_visits', 'INTEGER');

/* =========================
   Load row
   ========================= */
if ($encounterId > 0) {
  try {
    $stmt = $conn->prepare("SELECT * FROM encounters WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $encounterId]);
    $found = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($found) {
      $row = $found;

      $json = (string)($found['data_json'] ?? '');
      if ($json !== '') {
        $tmp = json_decode($json, true);
        if (is_array($tmp)) {
          foreach ($tmp as $k => $v) {
            if (!is_string($k) || $k === '') continue;
            if (is_array($v) || is_object($v)) continue;
            $row[$k] = (string)$v;
          }
        }
      }

      $row['interview_date'] = (string)(
        ($found['encounter_date'] ?? '') !== ''
          ? $found['encounter_date']
          : ($found['interview_date'] ?? '')
      );

      if ($adolescentId <= 0) {
        $adolescentId = (int)($found['adolescent_id'] ?? 0);
      }

      $prefillFromHistory = true;
    } else {
      $encounterId = 0;
      $editLatest = false;
      $isSnapshotOnly = false;
    }
  } catch (Throwable $e) {
    $encounterId = 0;
    $editLatest = false;
    $isSnapshotOnly = false;
  }
} elseif ($id > 0) {
  $stmt = $conn->prepare("SELECT * FROM adolescent_mothers WHERE id = :id LIMIT 1");
  $stmt->execute([':id' => $id]);
  $found = $stmt->fetch(PDO::FETCH_ASSOC);
  if ($found) {
    $row = $found;
  } else {
    $id = 0;
    $editLatest = false;
    $isSnapshotOnly = false;
  }
} elseif ($adolescentId > 0) {
  try {
    $a = $conn->prepare("SELECT * FROM adolescents WHERE id = :id LIMIT 1");
    $a->execute([':id' => $adolescentId]);
    $ad = $a->fetch(PDO::FETCH_ASSOC);

    if ($ad) {
      $row['name']            = (string)($ad['full_name'] ?? '');
      $row['last_name']       = (string)($ad['last_name'] ?? '');
      $row['first_name']      = (string)($ad['first_name'] ?? '');
      $row['middle_name']     = (string)($ad['middle_name'] ?? '');
      $row['date_of_birth']   = (string)($ad['date_of_birth'] ?? '');
      $row['barangay']        = (string)($ad['barangay'] ?? '');
      $row['locked_barangay'] = (string)($ad['barangay'] ?? '');
      $row['present_address'] = (string)($ad['present_address'] ?? '');

      $e = $conn->prepare("
        SELECT data_json
        FROM encounters
        WHERE adolescent_id = :id
        ORDER BY encounter_date DESC, id DESC
        LIMIT 1
      ");
      $e->execute([':id' => $adolescentId]);
      $json = (string)($e->fetchColumn() ?: '');

      if ($json !== '') {
        $tmp = json_decode($json, true);
        if (is_array($tmp)) {
          foreach ($tmp as $k => $v) {
            if (!is_string($k) || $k === '') continue;
            if (is_array($v) || is_object($v)) continue;
            $row[$k] = (string)$v;
          }
          $prefillFromHistory = true;
        }
      }
    } else {
      $adolescentId = 0;
    }
  } catch (Throwable $e) {
    $adolescentId = 0;
  }
}

if ($encounterId > 0 && $adolescentId > 0) {
  try {
    $a = $conn->prepare("SELECT * FROM adolescents WHERE id = :id LIMIT 1");
    $a->execute([':id' => $adolescentId]);
    $ad = $a->fetch(PDO::FETCH_ASSOC);

    if ($ad) {
      if (trim((string)($row['name'] ?? '')) === '') {
        $row['name'] = (string)($ad['full_name'] ?? '');
      }
      if (trim((string)($row['last_name'] ?? '')) === '') {
        $row['last_name'] = (string)($ad['last_name'] ?? '');
      }
      if (trim((string)($row['first_name'] ?? '')) === '') {
        $row['first_name'] = (string)($ad['first_name'] ?? '');
      }
      if (trim((string)($row['middle_name'] ?? '')) === '') {
        $row['middle_name'] = (string)($ad['middle_name'] ?? '');
      }
      if (trim((string)($row['date_of_birth'] ?? '')) === '') {
        $row['date_of_birth'] = (string)($ad['date_of_birth'] ?? '');
      }
      if (trim((string)($row['barangay'] ?? '')) === '') {
        $row['barangay'] = (string)($ad['barangay'] ?? '');
      }
      if (trim((string)($row['locked_barangay'] ?? '')) === '') {
        $row['locked_barangay'] = (string)($ad['barangay'] ?? '');
      }
      if (trim((string)($row['present_address'] ?? '')) === '') {
        $row['present_address'] = (string)($ad['present_address'] ?? '');
      }
    }
  } catch (Throwable $e) {
    // silent
  }
}

if (!function_exists('val')) {
  function val(string $k, string $default = ''): string {
    global $row;
    $v = $row[$k] ?? $default;
    return is_scalar($v) ? (string)$v : $default;
  }
}
if (!function_exists('checkedRadio')) {
  function checkedRadio(string $name, string $value): string {
    global $row;
    return (isset($row[$name]) && (string)$row[$name] === (string)$value) ? 'checked' : '';
  }
}

$prefillEducation = ahdisNormalizeEducationAttainment(
  (string)($row['educational_attainment'] ?? ($row['education'] ?? ''))
);
if ($prefillEducation !== '') {
  $row['education'] = $prefillEducation;
  $row['educational_attainment'] = $prefillEducation;
}

$prefillGradeYear = ahdisNormalizeGradeYearLevel(
  $prefillEducation,
  (string)($row['grade_year_level'] ?? ($row['year_level'] ?? ''))
);
if ($prefillGradeYear !== '') {
  $row['year_level'] = $prefillGradeYear;
  $row['grade_year_level'] = $prefillGradeYear;
} elseif ($prefillEducation === 'No Education') {
  $row['year_level'] = '';
  $row['grade_year_level'] = '';
}

$prefillLastSchool = ahdisNormalizeLastSchoolAttendedType((string)($row['last_school_attended_type'] ?? ''));
if ($prefillLastSchool !== '') {
  $row['last_school_attended_type'] = $prefillLastSchool;
}

$prefillReasonRaw = trim((string)($row['reason_not_attending_school'] ?? ''));
if ($prefillReasonRaw === '') {
  $prefillReasonRaw = trim((string)($row['reason_for_not_attending_school'] ?? ''));
}
$prefillReasons = ahdisNormalizeReasonsNotAttendingSchool($prefillReasonRaw);
if ($prefillReasons) {
  $row['reason_not_attending_school'] = ahdisSerializeReasonsNotAttendingSchool($prefillReasons);
} elseif ($prefillReasonRaw !== '') {
  $row['reason_not_attending_school'] = $prefillReasonRaw;
}

$familyPlanningRaw = trim((string)($row['family_planning'] ?? ''));
$prefillFamilyPlanning = ahdisNormalizeFamilyPlanning($familyPlanningRaw);
$legacyFamilyPlanning = '';
if ($prefillFamilyPlanning !== '') {
  $row['family_planning'] = $prefillFamilyPlanning;
  if (!isset(ahdisFamilyPlanningOptions()[$prefillFamilyPlanning])) {
    $legacyFamilyPlanning = $familyPlanningRaw;
  }
}

/* =========================
   MODE FLAGS
   ========================= */
$isEncounterEdit  = ($encounterId > 0);
$isLegacyEdit     = (!$isEncounterEdit && $id > 0);
$isUpdateExisting = (!$isLegacyEdit && !$isEncounterEdit && $adolescentId > 0);

/* =========================
   DUPLICATE FLAGS FROM SAVE.PHP
   ========================= */
$duplicateFlag   = ((string)($_GET['duplicate'] ?? '') === '1');
$duplicateReason = trim((string)($_GET['reason'] ?? ''));
$duplicateAid    = (int)($_GET['adolescent_id'] ?? 0);

$isDuplicateAddBlock = (
  $duplicateFlag &&
  !$isLegacyEdit &&
  !$isEncounterEdit &&
  !$isUpdateExisting &&
  !$isSnapshotOnly &&
  in_array($duplicateReason, ['already_exists', 'possible_duplicate_name', 'possible_duplicate'], true) &&
  $duplicateAid > 0
);

$duplicateMessage = '';
if ($duplicateFlag && $duplicateReason === 'already_exists') {
  $duplicateMessage = 'Warning: this file or name already existed in the database. You cannot submit this as a new profile.';
} elseif ($duplicateFlag && in_array($duplicateReason, ['possible_duplicate_name', 'possible_duplicate'], true)) {
  $duplicateMessage = 'Warning: possible duplicate detected. This file or name already existed in the database. Please verify first.';
} elseif ($duplicateFlag && $duplicateReason === 'barangay_mismatch') {
  $duplicateMessage = 'This adolescent already exists, but the barangay does not match the existing record.';
} elseif ($duplicateFlag && $duplicateReason === 'adolescent_id_dob_mismatch') {
  $duplicateMessage = 'This adolescent record cannot proceed because the date of birth does not match the existing profile.';
}

/* =========================================================
   BARANGAY SOURCE
   ========================================================= */
if (!function_exists('ensureBarangaysTable')) {
  function ensureBarangaysTable(PDO $conn): void {
    try {
      $conn->exec("
        CREATE TABLE IF NOT EXISTS barangays (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          province TEXT NOT NULL,
          municipality TEXT NOT NULL,
          barangay TEXT NOT NULL
        );
      ");
      $conn->exec("CREATE INDEX IF NOT EXISTS idx_barangays_muni ON barangays(municipality);");
      $conn->exec("CREATE INDEX IF NOT EXISTS idx_barangays_prov_muni ON barangays(province, municipality);");
    } catch (Throwable $e) {
      // silent
    }
  }
}
ensureBarangaysTable($conn);

if (!function_exists('autoSeedBarangaysIfEmpty')) {
  function autoSeedBarangaysIfEmpty(PDO $conn): void {
    try {
      $total = (int)$conn->query("SELECT COUNT(*) FROM barangays")->fetchColumn();
      if ($total > 0) return;

      $seedFile = __DIR__ . "/../storage/seed_barangays_regionx.sql";
      if (!is_file($seedFile)) return;

      $sql = file_get_contents($seedFile);
      if ($sql === false || trim($sql) === '') return;

      $conn->exec($sql);
    } catch (Throwable $e) {
      // silent
    }
  }
}
autoSeedBarangaysIfEmpty($conn);

if (!function_exists('barangayCounts')) {
  function barangayCounts(PDO $conn, string $province, string $municipality): array {
    try {
      $total = (int)$conn->query("SELECT COUNT(*) FROM barangays")->fetchColumn();

      $province = normName($province);
      $municipality = normName($municipality);
      if ($municipality === '') return [$total, 0];

      $cands = muniVariants($municipality);
      if (empty($cands)) return [$total, 0];

      $whereMun = [];
      $params = [];
      foreach ($cands as $i => $m) {
        $whereMun[] = "lower(trim(municipality)) = lower(trim(:m{$i}))";
        $params[":m{$i}"] = $m;
      }
      $where = "(" . implode(" OR ", $whereMun) . ")";
      if ($province !== '') {
        $where .= " AND lower(trim(province)) = lower(trim(:p))";
        $params[":p"] = $province;
      }

      $st = $conn->prepare("SELECT COUNT(*) AS c FROM barangays WHERE $where");
      $st->execute($params);
      $matched = (int)($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);

      return [$total, $matched];
    } catch (Throwable $e) {
      return [0, 0];
    }
  }
}

if (!function_exists('fetchBarangays')) {
  function fetchBarangays(PDO $conn, string $province, string $municipality): array {
    $province = normName($province);
    $municipality = normName($municipality);
    if ($municipality === '') return [];

    $cands = muniVariants($municipality);
    if (empty($cands)) return [];

    try {
      $whereMun = [];
      $params = [];
      foreach ($cands as $i => $m) {
        $whereMun[] = "lower(trim(municipality)) = lower(trim(:m{$i}))";
        $params[":m{$i}"] = $m;
      }
      $where = "(" . implode(" OR ", $whereMun) . ")";
      if ($province !== '') {
        $where .= " AND lower(trim(province)) = lower(trim(:p))";
        $params[":p"] = $province;
      }

      $st = $conn->prepare("
        SELECT barangay
        FROM barangays
        WHERE $where
        ORDER BY barangay ASC
      ");
      $st->execute($params);
      $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
      $out = array_map(fn($r) => (string)($r['barangay'] ?? ''), $rows);
      $out = array_values(array_filter(array_map('trim', $out), fn($x) => $x !== ''));
      if (!empty($out)) return $out;

      $key = lowerSafe($municipality);
      $key = (string)preg_replace('/\s*city\s*$/i', '', $key);
      $key = trim($key);

      if ($key !== '') {
        $params2 = [':k' => '%' . $key . '%'];
        $where2 = "lower(municipality) LIKE :k";
        if ($province !== '') {
          $where2 .= " AND lower(trim(province)) = lower(trim(:p))";
          $params2[':p'] = $province;
        }

        $st2 = $conn->prepare("
          SELECT barangay
          FROM barangays
          WHERE $where2
          ORDER BY barangay ASC
        ");
        $st2->execute($params2);
        $rows2 = $st2->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out2 = array_map(fn($r) => (string)($r['barangay'] ?? ''), $rows2);
        $out2 = array_values(array_filter(array_map('trim', $out2), fn($x) => $x !== ''));
        return $out2;
      }

      return [];
    } catch (Throwable $e) {
      return [];
    }
  }
}

/* =========================
   LOCATION
   ========================= */
$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
  header("Location: " . $LOGIN_URL);
  exit;
}

$lockedProvince     = '';
$lockedMunicipality = '';

try {
  $uCols = tableColumns($conn, 'users');

  $colProvince     = pickFirstExistingColumn($uCols, ['province']);
  $colMunicipality = pickFirstExistingColumn($uCols, ['municipality','city','town']);

  if ($colMunicipality) {
    $sel = [];
    if ($colProvince)     $sel[] = $colProvince . " AS p";
    if ($colMunicipality) $sel[] = $colMunicipality . " AS m";

    $sql = "SELECT " . implode(", ", $sel) . " FROM users WHERE id = :id LIMIT 1";
    $st  = $conn->prepare($sql);
    $st->execute([':id' => $userId]);
    $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $lockedProvince     = normName((string)($u['p'] ?? ''));
    $lockedMunicipality = normName((string)($u['m'] ?? ''));
  }
} catch (Throwable $e) {}

if ($lockedMunicipality === '' && isset($_SESSION['municipality'])) {
  $lockedMunicipality = normName((string)$_SESSION['municipality']);
}
if ($lockedProvince === '' && isset($_SESSION['province'])) {
  $lockedProvince = normName((string)$_SESSION['province']);
}

if ($lockedMunicipality === '') {
  $_SESSION['flash_error'] = "Please set your Province and Municipality in Account Settings before adding a profiling record.";
  header("Location: " . $ACCOUNT_URL);
  exit;
}

$allowedBarangays = fetchBarangays($conn, $lockedProvince, $lockedMunicipality);

if (!empty($allowedBarangays)) {
  $seen = [];
  $tmp = [];
  foreach ($allowedBarangays as $b) {
    $b = trim((string)$b);
    $k = lowerSafe($b);
    if ($k === '') continue;
    if (isset($seen[$k])) continue;
    $seen[$k] = true;
    $tmp[] = $b;
  }
  $allowedBarangays = $tmp;
}

[$brgyTotal, $brgyMatched] = barangayCounts($conn, $lockedProvince, $lockedMunicipality);

/* =========================
   PREFILLS
   ========================= */
$prefMunicipality = $lockedMunicipality;

$prefBarangay = trim(val('locked_barangay'));
if ($prefBarangay === '') $prefBarangay = trim(val('barangay'));

$partnerName = trim(val('partner_name')); if ($partnerName === '') $partnerName = trim(val('p_name'));
$partnerSurname = trim(val('partner_surname')); if ($partnerSurname === '') $partnerSurname = trim(val('partner_last_name'));
if ($partnerSurname === '') $partnerSurname = trim(val('p_surname'));
if ($partnerSurname === '') $partnerSurname = trim(val('p_last_name'));
$partnerFirstName = trim(val('partner_first_name')); if ($partnerFirstName === '') $partnerFirstName = trim(val('p_first_name'));
$partnerMiddleName = trim(val('partner_middle_name')); if ($partnerMiddleName === '') $partnerMiddleName = trim(val('p_middle_name'));
if ($partnerSurname === '' && $partnerFirstName === '' && $partnerMiddleName === '' && $partnerName !== '') {
  [$partnerSurname, $partnerFirstName, $partnerMiddleName] = splitNameForForm($partnerName);
}
$partnerName = buildFullNameForForm($partnerSurname, $partnerFirstName, $partnerMiddleName) ?: $partnerName;
$partnerAddress = trim(val('partner_address')); if ($partnerAddress === '') $partnerAddress = trim(val('p_address'));
$partnerPob = trim(val('partner_place_of_birth')); if ($partnerPob === '') $partnerPob = trim(val('p_place_of_birth'));
$partnerDob = trim(val('partner_dob')); if ($partnerDob === '') $partnerDob = trim(val('p_dob'));
$partnerAge = trim(val('partner_age')); if ($partnerAge === '') $partnerAge = trim(val('p_age'));
$partnerEdu = trim(val('partner_education')); if ($partnerEdu === '') $partnerEdu = trim(val('p_education'));
$partnerEdu = ahdisNormalizeEducationAttainment($partnerEdu) ?: $partnerEdu;
$partnerEnrolled = trim(val('partner_currently_enrolled')); if ($partnerEnrolled === '') $partnerEnrolled = trim(val('p_currently_enrolled'));
$partnerYearLevel = trim(val('partner_year_level')); if ($partnerYearLevel === '') $partnerYearLevel = trim(val('p_year_level'));
$partnerYearOptions = ahdisPartnerEducationLevelOptions($partnerEdu);
$normalizedPartnerYearLevel = ahdisNormalizePartnerGradeYearLevel($partnerEdu, $partnerYearLevel);
if ($normalizedPartnerYearLevel !== '') {
  $partnerYearLevel = $normalizedPartnerYearLevel;
} elseif ($partnerYearLevel !== '' && !in_array($partnerYearLevel, $partnerYearOptions, true)) {
  $partnerYearLevel = '';
}
$partnerWorking = trim(val('partner_currently_working')); if ($partnerWorking === '') $partnerWorking = trim(val('p_currently_working'));
$partnerIncome = trim(val('partner_monthly_income')); if ($partnerIncome === '') $partnerIncome = trim(val('p_monthly_income'));
$partnerOccupation = trim(val('partner_occupation')); if ($partnerOccupation === '') $partnerOccupation = trim(val('p_occupation'));
$dobInputValue = dateValueForInput(val('date_of_birth'));
$partnerDobInputValue = dateValueForInput($partnerDob);
$todayInputMax = date('Y-m-d');
$dateEncodedInputValue = $editLatest ? dateValueForInput(val('date_encoded')) : appToday();

// A New Profile / New Update must identify its encoder independently.
// Never prefill this manual accountability field from prior history or a session user.
if (!$editLatest) {
  $row['encoded_by'] = '';
}

$nameLast = trim(val('last_name'));
$nameFirst = trim(val('first_name'));
$nameMiddle = trim(val('middle_name'));
if ($nameLast === '' && $nameFirst === '') {
  [$nameLast, $nameFirst, $nameMiddle] = splitNameForForm(val('name'));
}
$nameFull = buildFullNameForForm($nameLast, $nameFirst, $nameMiddle);
if ($nameFull === '') $nameFull = trim(val('name'));

/* =========================
   Gov Assistance Prefill
   ========================= */
$govRaw = trim(val('gov_assistance'));
$govSelected = [];
if ($govRaw !== '') {
  $tryJson = json_decode($govRaw, true);
  if (is_array($tryJson)) {
    $govSelected = array_values(array_filter(array_map('trim', array_map('strval', $tryJson))));
  } else {
    $govSelected = array_values(array_filter(array_map('trim', preg_split('/\s*,\s*/', $govRaw) ?: [])));
  }
}
$govSelectedMap = [];
foreach ($govSelected as $x) $govSelectedMap[lowerSafe($x)] = true;

$govDetailsRaw = trim(val('gov_assistance_details'));
$govDetails = [];
if ($govDetailsRaw !== '') {
  $tmp = json_decode($govDetailsRaw, true);
  if (is_array($tmp)) $govDetails = $tmp;
}
if (!function_exists('govSpecVal')) {
  function govSpecVal(string $key, array $govDetails): string {
    return isset($govDetails[$key]) ? (string)$govDetails[$key] : '';
  }
}

/* =========================
   Server-side fallback AGE
   ========================= */
if (trim(val('age')) === '') {
  $a = computeAgeFromDob(val('date_of_birth'));
  if ($a !== null) $row['age'] = (string)$a;
}

/* =========================
   DEFAULT VISIBILITY
   ========================= */
$pregVal = (string)val('pregnant');
$showV1 = ($pregVal === 'Yes');
$prenatalVal = (string)val('prenatal_care');
$showPrenatalVisits = ($showV1 && $prenatalVal === 'Yes');
$livingVal = (string)val('living_situation');
$showLivingOthers = ($livingVal === 'Living with others');
$ipVal = (string)val('is_ips');
$showEthnicity = ($ipVal === 'Yes');
$workVal = (string)val('currently_working');
$showWorkFields = ($workVal === 'Yes');
$showPartnerWorkFields = ($partnerWorking === 'Yes');

$ageNow = trim(val('age'));
$isAgeOutside = false;
if ($ageNow !== '' && ctype_digit((string)$ageNow)) {
  $ai = (int)$ageNow;
  $isAgeOutside = ($ai < 9 || $ai > 19);
}

require_once __DIR__ . "/../includes/header.php";
require_once __DIR__ . "/../includes/sidebar.php";

$badge = "NEW RECORD";
if ($isSnapshotOnly) {
  $badge = "EDIT LATEST";
} elseif ($isLegacyEdit) {
  $badge = "EDIT MODE (LEGACY)";
} elseif ($isUpdateExisting) {
  $badge = $prefillFromHistory ? "NEW UPDATE (PREFILLED)" : "NEW UPDATE";
}
?>
<style>
.am-form{ width:100% !important; max-width:none !important; }
.am-form .form-wrapper{ width:100% !important; max-width:none !important; margin:0 !important; padding:0 !important; }
.am-form .form-card{ width:100% !important; max-width:none !important; }

.am-form{
  --bg: #f4f6fb;
  --card: #ffffff;
  --text: #0f172a;
  --line: rgba(15,23,42,.10);

  --primary: #163a73;
  --primary2:#ec4899;
  --brand-deep: #0b2c6b;
  --brand-blue: #1d4ed8;
  --brand-light: #3b82f6;
  --brand-pink: #ec4899;

  --shadow: 0 18px 55px rgba(15,23,42,.10);
  --shadow2: 0 10px 22px rgba(15,23,42,.08);
  --radius: 18px;
  --radius2: 14px;

  font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
  color: var(--text);
  background:
    radial-gradient(900px 420px at 20% 0%, rgba(22,58,115,.10), transparent 60%),
    radial-gradient(760px 380px at 86% 18%, rgba(236,72,153,.08), transparent 55%),
    linear-gradient(180deg, rgba(255,255,255,.55), rgba(255,255,255,0)),
    var(--bg);
  border-radius: 18px;
  padding: 18px 18px 42px;
}

.am-form .form-card{
  background: var(--card);
  border-radius: var(--radius);
  box-shadow: var(--shadow);
  border: 1px solid var(--line);
  overflow: hidden;
}

..am-form .form-header{
  position: relative;
  padding: 18px 22px;
  color:#fff;
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:12px;
  flex-wrap:wrap;
  background:
    radial-gradient(circle at top right, rgba(255,255,255,.16), transparent 26%),
    linear-gradient(135deg, #0f172a 0%, #163a73 42%, #df82c6 75%, #cd87be 100%);
}

.am-form .form-header > *{
  position: relative;
  z-index: 1;
}

.am-form .form-header .header-left{
  display:flex;
  flex-direction:column;
  align-items:flex-start;
  gap:12px;
  min-width:0;
}

.am-form .form-header-back{
  display:inline-flex;
  align-items:center;
  gap:7px;
  padding:7px 11px;
  border-radius:8px;
  border:1px solid rgba(255,255,255,.30);
  background: rgba(255,255,255,.12);
  color: #ffffff;
  font-weight:700;
  font-size:13px;
  line-height:1.2;
  text-decoration:none;
  transition: background-color .15s ease, border-color .15s ease, transform .15s ease;
}

.am-form .form-header-back:hover{
  background: rgba(255,255,255,.18);
  border-color: rgba(255,255,255,.45);
}

.am-form .form-header-back:focus-visible{
  outline: 3px solid rgba(255,255,255,.45);
  outline-offset: 2px;
}

.am-form .badge{
  background: rgba(255,255,255,.18);
  border: 1px solid rgba(255,255,255,.30);
  padding: 8px 12px;
  border-radius: 999px;
  font-weight: 900;
  font-size: 12px;
  box-shadow: 0 8px 20px rgba(0,0,0,.12);
  backdrop-filter: blur(6px);
}

.am-form .top-actions{
  display:flex; gap:10px; flex-wrap:wrap;
}
.am-form .top-actions a{
  background: rgba(255,255,255,.16);
  border: 1px solid rgba(255,255,255,.28);
  color:#fff;
  padding: 8px 10px;
  border-radius: 999px;
  text-decoration:none;
  font-weight:900;
  font-size:12px;
  box-shadow: 0 8px 20px rgba(0,0,0,.10);
  transition:
    background-color 120ms ease,
    color 120ms ease,
    border-color 120ms ease,
    box-shadow 120ms ease,
    opacity 120ms ease;
}
.am-form .top-actions a:hover{
  background: rgba(255,255,255,.24);
}

.am-form .section{
  padding: 16px 22px;
  border-bottom: 1px solid var(--line);
  background: linear-gradient(180deg, rgba(246,247,251,.60), rgba(255,255,255,0));
}

.am-form .section-title{
  font-size:14px;
  font-weight:900;
  color: var(--primary);
  margin:0 0 12px;
  display:flex;
  align-items:center;
  gap:10px;
}
.am-form .section-title::before{
  content:"";
  width:6px; height:18px;
  background: linear-gradient(180deg, var(--brand-blue), var(--brand-pink));
  border-radius:4px;
}

.am-form .grid-2,
.am-form .grid-3{
  display:grid;
  gap: 12px 16px;
}
.am-form .grid-2{ grid-template-columns: repeat(2, minmax(0,1fr)); }
.am-form .grid-3{ grid-template-columns: repeat(3, minmax(0,1fr)); }
.am-form .accountability-grid{ align-items:start; row-gap:16px; }
.am-form .accountability-field{ min-width:0; }
.am-form .accountability-field .form-control{ min-height:44px; }

@media (min-width:769px){
  .am-form .accountability-field .form-label{
    display:flex;
    align-items:flex-end;
    min-height:32px;
  }
}

.am-form .hint{
  margin-top:6px;
  font-size:12px;
  color: rgba(15,23,42,.78);
}

.am-form .form-label{
  font-weight:800;
  font-size:12.5px;
  margin:0 0 8px;
}

.am-form .form-control{
  border-radius: var(--radius2);
  padding: 11px 12px;
  border: 1px solid rgba(15,23,42,.14);
  width: 100%;
  background: rgba(255,255,255,.98);
  font-size:14px;
  box-sizing: border-box;
}

.am-form .form-control:focus{
  outline: none;
  border-color: rgba(37,99,235,.45);
  box-shadow: 0 0 0 4px rgba(59,130,246,.10);
}

.am-form .form-control.is-invalid,
.am-form .form-control:invalid.is-invalid{
  border-color:#dc2626 !important;
  background:#fff5f5 !important;
  box-shadow:0 0 0 4px rgba(220,38,38,.12);
}

.am-form .form-control.is-invalid:focus{
  border-color:#b91c1c !important;
  box-shadow:0 0 0 4px rgba(220,38,38,.20);
}

.am-form textarea.form-control{
  min-height: 92px;
  resize: vertical;
  line-height: 1.4;
}

.am-form input:disabled,
.am-form select:disabled,
.am-form textarea:disabled{
  opacity: 1 !important;
  filter: none !important;
  background: rgba(255,255,255,.98) !important;
  color: var(--text) !important;
  -webkit-text-fill-color: var(--text) !important;
  cursor: not-allowed;
}

.am-form .form-control[readonly]{
  opacity: 1 !important;
  filter: none !important;
  background: rgba(255,255,255,.98) !important;
  color: var(--text) !important;
  -webkit-text-fill-color: var(--text) !important;
  cursor: default;
}

.am-form .radio-group{
  display:flex;
  flex-wrap:wrap;
  gap:10px;
  margin-top:6px;
}
.am-form .radio-group label{
  display:inline-flex;
  align-items:center;
  gap:10px;
  padding:10px 12px;
  border: 1px solid rgba(15,23,42,.12);
  border-radius:999px;
  background: rgba(248,250,252,.92);
  cursor:pointer;
  font-weight:700;
  box-shadow: var(--shadow2);
}
.am-form .radio-group input{ accent-color: var(--brand-blue); }
.am-form .radio-group label.is-selected{
  background: linear-gradient(135deg, rgba(29,78,216,.12), rgba(236,72,153,.10));
  border-color: rgba(29,78,216,.45);
}
.am-form .radio-group.is-invalid label{
  border-color:rgba(220,38,38,.55);
  background:#fff5f5;
}

.am-form .check-grid{
  display:grid;
  grid-template-columns: repeat(2, minmax(0,1fr));
  gap:10px 14px;
}
.am-form .check-item{
  padding:10px 12px;
  border: 1px solid rgba(15,23,42,.12);
  border-radius: 14px;
  background: rgba(248,250,252,.92);
  box-shadow: var(--shadow2);
  font-weight:800;
}
.am-form .check-item input{ accent-color: var(--brand-blue); }

.am-form .reason-not-attending-grid{
  margin-top:6px;
}
.am-form .reason-not-attending-check{
  display:flex;
  align-items:center;
  gap:10px;
  margin:0;
  cursor:pointer;
  line-height:1.3;
}
.am-form .reason-not-attending-check.is-selected{
  background: linear-gradient(135deg, rgba(29,78,216,.12), rgba(236,72,153,.10));
  border-color: rgba(29,78,216,.45);
}
.am-form .reason-not-attending-grid.is-invalid .reason-not-attending-check{
  border-color:rgba(220,38,38,.55);
  background:#fff5f5;
}

.am-form .gov-assist-title{
  font-size:16px;
  line-height:1.35;
  color:#0b2c6b;
  align-items:flex-start;
}
.am-form .gov-assist-grid{
  grid-template-columns: repeat(2, minmax(260px,1fr));
}
.am-form .gov-assist-item{
  display:flex;
  flex-direction:column;
  gap:10px;
  min-height:118px;
}
.am-form .gov-assist-check{
  display:flex;
  align-items:center;
  gap:10px;
  margin:0;
  font-weight:900;
  line-height:1.25;
}
.am-form .gov-assist-spec-label{
  display:flex;
  align-items:center;
  gap:6px;
  margin:0 0 6px;
  font-size:12px;
  font-weight:900;
  color:#334155;
}
.am-form .req-mark{
  color:#b91c1c;
  font-weight:950;
}
.am-form .gov-spec:disabled,
.am-form .gov-spec.is-disabled{
  background:#e5e7eb !important;
  color:#64748b !important;
  -webkit-text-fill-color:#64748b !important;
  border-color:#cbd5e1 !important;
  cursor:not-allowed;
}
.am-form .field-error{
  margin-top:6px;
  color:#b91c1c;
  font-size:12px;
  font-weight:900;
  display:none;
}
.am-form .field-error.is-visible{
  display:block;
}
.am-form .validation-summary{
  margin-top:12px;
  padding:12px 14px;
  border-radius:14px;
  background:#fef2f2;
  border:1px solid #fecaca;
  color:#991b1b;
  font-size:13px;
  font-weight:900;
  display:none;
}
.am-form .validation-summary.is-visible{
  display:block;
}
.am-form .brgy-search{
  margin-bottom:8px;
}
#govAssistSection > .section-title:not(.gov-assist-title){
  display:none;
}
#govAssistSection > .hint:not(.gov-assist-helper){
  display:none;
}

.am-form .submit-area{
  padding: 16px 22px;
  background: #fff;
  display:flex;
  gap:12px;
  justify-content:flex-end;
  border-top: 1px solid var(--line);
}
.am-form .btn{
  border:none;
  border-radius: 14px;
  padding: 12px 16px;
  font-weight: 900;
  font-size: 14px;
  cursor:pointer;
  text-decoration:none;
  display:inline-flex;
  align-items:center;
}
.am-form .btn-primary{
  background: linear-gradient(135deg, #0b2c6b 0%, #0b2c6b 55%, #0b2c6b 100%);
  color:#fff;
  box-shadow: 0 12px 24px rgba(29,78,216,.22);
}
.am-form .btn-primary:hover{
  filter: brightness(1.03);
}
.am-form .btn-ghost{
  background:#fff;
  border:1px solid rgba(15,23,42,.14);
  color: var(--text);
}

.am-form .btn-soft-link{
  background:#fff;
  border:1px solid rgba(15,23,42,.14);
  color:#0f172a;
  padding:10px 12px;
  border-radius:999px;
  text-decoration:none;
  font-weight:900;
  font-size:12px;
  display:inline-flex;
  align-items:center;
}

.am-form .age-warn{
  margin-top:10px;
  padding:10px 12px;
  border-radius: 14px;
  background: rgba(220,38,38,.08);
  border: 1px solid rgba(220,38,38,.25);
  color: #991b1b;
  font-weight: 900;
  font-size: 12.5px;
}

.am-form .snap-warn{
  margin-top:10px;
  padding:10px 12px;
  border-radius: 14px;
  background: rgba(255,255,255,.14);
  border: 1px solid rgba(255,255,255,.24);
  color: #fff;
  font-weight: 900;
  font-size: 12.5px;
  box-shadow: inset 0 1px 0 rgba(255,255,255,.08);
}

.am-form .duplicate-warn{
  margin-top: 12px;
  padding: 12px 14px;
  border-radius: 14px;
  background: rgba(220,38,38,.10);
  border: 1px solid rgba(220,38,38,.28);
  color: #991b1b;
  font-weight: 900;
  font-size: 13px;
  box-shadow: 0 8px 18px rgba(220,38,38,.08);
}

.am-form .duplicate-actions{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
  margin-top:10px;
}

.am-form .blocked-panel{
  margin-top:14px;
  padding:12px 14px;
  border-radius:14px;
  background: rgba(245,158,11,.10);
  border:1px solid rgba(245,158,11,.28);
  color:#92400e;
  font-weight:800;
  font-size:12.5px;
}

.am-anim{
  display:block;
}
.am-anim.is-hidden{
  display:none !important;
}
.am-anim.is-shown{
  display:block !important;
}

.review-modal{
  position:fixed;
  inset:0;
  z-index:10000;
  display:none;
  align-items:center;
  justify-content:center;
  padding:22px;
  background:rgba(15,23,42,.58);
}
.review-modal.is-open{
  display:flex;
}
.draft-dialog{
  width:min(520px, 100%);
}
.review-dialog{
  width:min(860px, 100%);
  max-height:min(86vh, 760px);
  overflow:auto;
  background:#fff;
  border-radius:18px;
  border:1px solid rgba(15,23,42,.12);
  box-shadow:0 28px 70px rgba(15,23,42,.28);
}
.review-head{
  padding:18px 20px;
  color:#fff;
  background:linear-gradient(135deg, #0f172a 0%, #163a73 70%, #ec4899 100%);
}
.review-title{
  margin:0;
  font-size:18px;
  font-weight:900;
}
.review-subtitle{
  margin:6px 0 0;
  font-size:13px;
  font-weight:700;
  color:rgba(255,255,255,.86);
}
.review-body{
  padding:18px 20px;
}
.review-grid{
  display:grid;
  grid-template-columns:repeat(2, minmax(0,1fr));
  gap:10px 14px;
}
.review-item{
  border:1px solid rgba(15,23,42,.10);
  border-radius:12px;
  padding:10px 12px;
  background:#f8fafc;
}
.review-label{
  display:block;
  font-size:11px;
  font-weight:900;
  color:#64748b;
  text-transform:uppercase;
  letter-spacing:.2px;
}
.review-value{
  display:block;
  margin-top:4px;
  font-size:14px;
  font-weight:800;
  color:#0f172a;
  word-break:break-word;
}
.review-section{
  margin:16px 0 8px;
  font-size:13px;
  font-weight:900;
  color:#163a73;
}
.review-actions{
  display:flex;
  justify-content:flex-end;
  gap:12px;
  padding:16px 20px;
  border-top:1px solid rgba(15,23,42,.10);
  background:#fff;
}

@media (max-width: 768px){
  .am-form{ padding: 12px 12px 32px; }
  .am-form .grid-2,
  .am-form .grid-3{ grid-template-columns: 1fr !important; }
  .am-form .check-grid{ grid-template-columns: 1fr !important; }
  .review-grid{ grid-template-columns:1fr; }
  .review-actions{ flex-direction:column; }
  .review-actions .btn{ width:100%; justify-content:center; }
}
</style>

<div class="am-form">
  <div class="form-wrapper">
    <div class="form-card">
      <div class="form-header">
        <div class="header-left">
          <?php if ($isSnapshotOnly || $isUpdateExisting): ?>
            <a class="form-header-back" href="<?= h($VIEW_URL) ?>" aria-label="Back to View Profiles">
              <span aria-hidden="true">←</span>
              Back to View Profiles
            </a>
          <?php endif; ?>

          <div>
            <h3>Adolescent Mother Profiling Form</h3>

            <?php if ($isUpdateExisting): ?>
            <div class="top-actions" style="margin-top:10px;">
                <a href="<?= h($ADOL_VIEW_URL . '?adolescent_id=' . (int)$adolescentId) ?>">View Profile / History</a>
                <a href="<?= h($ADD_URL) ?>">New Person</a>
              </div>
            <?php endif; ?>

            <?php if ($isSnapshotOnly): ?>
              <div class="snap-warn">
                ⚠ You are editing the <b>Latest Record Only</b>. This will NOT create a new History entry.
              </div>
            <?php endif; ?>

            <?php if ($duplicateMessage !== ''): ?>
              <div class="duplicate-warn">
                <?= h($duplicateMessage) ?>

                <?php if ($duplicateAid > 0): ?>
                  <div class="duplicate-actions">
                    <a class="btn-soft-link" href="<?= h($ADOL_VIEW_URL . '?adolescent_id=' . (int)$duplicateAid) ?>">
                      Open Existing Profile / History
                    </a>
                    <a class="btn-soft-link" href="<?= h($ADD_URL) ?>">
                      Clear Form / New Person
                    </a>
                  </div>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <span class="badge"><?= h($badge) ?></span>
      </div>

      <form
        method="POST"
        action="<?= h($SAVE_URL) ?>"
        autocomplete="off"
        id="amForm"
        data-duplicate-block="<?= $isDuplicateAddBlock ? '1' : '0' ?>"
        data-draft-enabled="<?= $isDuplicateAddBlock ? '0' : '1' ?>"
        data-draft-key="<?= h('ahdis.add_profile.draft.' . (($isLegacyEdit && $id > 0) ? ('legacy-' . (int)$id) : (($encounterId > 0) ? ('encounter-' . (int)$encounterId) : (($adolescentId > 0) ? ('adolescent-' . (int)$adolescentId) : 'new')))) ?>"
        novalidate
      >
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <input type="hidden" name="encounter_id" value="<?= (int)$encounterId ?>">
        <input type="hidden" name="adolescent_id" value="<?= (int)$adolescentId ?>">
        <input type="hidden" name="csrf_token" value="<?= h($csrfForm) ?>">
        <input type="hidden" name="review_confirmed" id="review_confirmed" value="0">

        <?php if ($isSnapshotOnly): ?>
          <input type="hidden" name="edit_latest" value="1">
        <?php endif; ?>

        <input type="hidden" name="locked_municipality" id="locked_municipality" value="<?= h($prefMunicipality) ?>">
        <input type="hidden" name="locked_barangay" id="locked_barangay" value="<?= h($prefBarangay) ?>">
        <input type="hidden" id="present_address" name="present_address" value="<?= h(val('present_address')) ?>">
        <div id="validationSummary" class="validation-summary" role="alert"></div>

        <?php if ($isDuplicateAddBlock): ?>
          <div class="section">
            <div class="blocked-panel">
              This profile cannot continue as a <strong>new add</strong> because the file or name already existed in the database.
              Please open the existing profile and create a <strong>new update</strong> there instead.
            </div>
          </div>
        <?php endif; ?>

        <div class="section">
          <div class="section-title">Part A. Adolescent Mother Information — I. Identifying Information</div>
          <div>
            <input type="hidden" id="name_main" name="name" value="<?= h($nameFull) ?>">
            <div class="grid-2">
              <div>
                <label class="form-label" for="last_name">Surname</label>
                <input
                  id="last_name"
                  class="form-control"
                  name="last_name"
                  required
                  pattern=".*\S.*"
                  placeholder="SURNAME"
                  title="Please enter surname."
                  value="<?= h($nameLast) ?>"
                  data-uppercase="1"
                  <?= $isDuplicateAddBlock ? 'readonly' : '' ?>
                >
              </div>
              <div>
                <label class="form-label" for="first_name">First Name</label>
                <input
                  id="first_name"
                  class="form-control"
                  name="first_name"
                  required
                  pattern=".*\S.*"
                  placeholder="FIRST NAME"
                  title="Please enter first name."
                  value="<?= h($nameFirst) ?>"
                  data-uppercase="1"
                  <?= $isDuplicateAddBlock ? 'readonly' : '' ?>
                >
              </div>
            </div>
            <div style="margin-top:14px">
              <label class="form-label" for="middle_name">Middle Name</label>
              <input
                id="middle_name"
                class="form-control"
                name="middle_name"
                placeholder="MIDDLE NAME"
                value="<?= h($nameMiddle) ?>"
                data-uppercase="1"
                <?= $isDuplicateAddBlock ? 'readonly' : '' ?>
              >
            </div>
            <?php if ($isUpdateExisting): ?>
              <div class="hint" style="font-weight:900;">
                This is a NEW UPDATE for an existing person. Submitting creates a new history record.
              </div>
            <?php elseif ($isDuplicateAddBlock): ?>
              <div class="hint" style="font-weight:900; color:#991b1b;">
                Existing record detected. New add is blocked.
              </div>
            <?php endif; ?>
          </div>

          <div style="margin-top:14px">
            <label class="form-label">Municipality</label>
            <input class="form-control" value="<?= h($prefMunicipality) ?>" readonly>
          </div>

          <div class="grid-2" style="margin-top:14px">
            <div>
              <label class="form-label">Barangay</label>
              <input
                class="form-control"
                id="barangay_select"
                name="barangay"
                list="barangay_options"
                required
                placeholder="TYPE OR SELECT BARANGAY"
                autocomplete="off"
                value="<?= h($prefBarangay) ?>"
                data-uppercase="1"
                <?= $isDuplicateAddBlock ? 'readonly' : '' ?>
              >
              <datalist id="barangay_options">
                <option value="">— Select Barangay —</option>
                <?php foreach ($allowedBarangays as $b): ?>
                  <option value="<?= h($b) ?>"></option>
                <?php endforeach; ?>
              </datalist>
              <?php if ($isDuplicateAddBlock): ?>
                <input type="hidden" name="barangay" value="<?= h($prefBarangay) ?>">
              <?php endif; ?>

              <div class="hint" id="brgyHint">
                <?php if (empty($allowedBarangays)): ?>
                  <div style="color:#991b1b; font-weight:800;">
                    No barangays loaded for <strong><?= h($prefMunicipality) ?></strong>.
                  </div>
                  <div style="margin-top:6px;">
                    Barangays table total rows: <strong><?= (int)$brgyTotal ?></strong> |
                    Matched rows for your municipality: <strong><?= (int)$brgyMatched ?></strong>
                  </div>
                  <div style="margin-top:6px;">
                    If total rows is <strong>0</strong>, ensure this exists:
                    <code>/storage/seed_barangays_regionx.sql</code>
                  </div>
                <?php else: ?>
                  Select barangay from the dropdown. (<?= count($allowedBarangays) ?> found)
                <?php endif; ?>
              </div>
            </div>

            <div></div>
          </div>

          <div style="margin-top:14px">
            <label class="form-label">Place of Birth</label>
            <input
              id="place_of_birth"
              class="form-control"
              name="place_of_birth"
              placeholder="Place of birth"
              value="<?= h(val('place_of_birth')) ?>"
              data-uppercase="1"
              <?= $isDuplicateAddBlock ? 'readonly' : '' ?>
            >
          </div>

          <div class="grid-2" style="margin-top:14px">
            <div>
              <label class="form-label">Date of Birth</label>
              <input
                id="dob"
                type="date"
                class="form-control"
                name="date_of_birth"
                max="<?= h($todayInputMax) ?>"
                title="Select the adolescent mother's date of birth."
                value="<?= h($dobInputValue) ?>"
                <?= $isDuplicateAddBlock ? 'readonly' : '' ?>
                required
              >
            </div>
            <div>
              <label class="form-label">Age</label>
              <input id="age" type="number" class="form-control" name="age" min="9" max="19" value="<?= h(val('age')) ?>" readonly>
              <div class="hint"><strong>Age must be 9–19</strong></div>
              <?php if ($isAgeOutside): ?>
                <div class="age-warn">
                  ⚠ Age is currently <strong><?= h($ageNow) ?></strong>. This record is outside the 9–19 adolescent filter and will be blocked on submit.
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="section">
          <div class="section-title">Part A — II. Educational Attainment</div>

          <?php
            $edu = (string)val('education');
            $gradeYear = (string)val('year_level');
            $gradeOptions = ahdisEducationLevelOptions($edu);
            $isNoEducation = ($edu === 'No Education');
            $isVocational = ($edu === 'Vocational');
            $isCollegeLevel = ($edu === 'College Level');
            $usesTypedEducationDetail = ($isVocational || $isCollegeLevel);
            if ($isVocational && ($gradeYear === '' || $gradeYear === 'Other Vocational Training') && trim(val('vocational_training_other')) !== '') {
              $gradeYear = trim(val('vocational_training_other'));
            }
            $typedEducationPlaceholder = $isVocational
              ? 'Specify vocational course/program'
              : ($isCollegeLevel ? 'Specify course/program and year level' : '');
            $lastSchoolValue = (string)val('last_school_attended_type');
            $reasonSchoolValue = (string)val('reason_not_attending_school');
            $reasonSchoolValues = ahdisNormalizeReasonsNotAttendingSchool($reasonSchoolValue);
            $reasonSchoolOtherValue = (string)val('reason_not_attending_school_other');
            $currentlyEnrolledValue = (string)val('currently_enrolled');
            $showReasonNotAttending = ($currentlyEnrolledValue === 'No');
            $reasonOptions = [];
            foreach (ahdisReasonNotAttendingSchoolOptions() as $reasonOpt) {
              if ($reasonOpt === 'Currently attending school' || $reasonOpt === 'Not applicable') continue;
              $reasonOptions[] = $reasonOpt;
            }
            $reasonSelectedValues = [];
            $legacyOtherReasons = [];
            foreach ($reasonSchoolValues as $savedReason) {
              if (in_array($savedReason, $reasonOptions, true)) {
                $reasonSelectedValues[] = $savedReason;
              } else {
                $legacyOtherReasons[] = $savedReason;
              }
            }
            $reasonOtherDisplay = trim($reasonSchoolOtherValue);
            if ($legacyOtherReasons) {
              if (!in_array('Other', $reasonSelectedValues, true)) {
                $reasonSelectedValues[] = 'Other';
              }
              if ($reasonOtherDisplay === '') {
                $reasonOtherDisplay = implode('; ', $legacyOtherReasons);
              }
            }
            $showReasonOther = ($showReasonNotAttending && in_array('Other', $reasonSelectedValues, true));
            $showVocationalOther = false;
          ?>

          <div>
            <div>
              <label class="form-label">Educational Attainment</label>
              <input type="hidden" id="education" value="<?= h($edu) ?>">
              <div class="radio-group education-radio-row" data-radio-group>
                <?php foreach (ahdisEducationAttainmentOptions() as $opt): ?>
                  <?php
                    $displayOpt = [
                      'Junior High School' => 'Junior HS',
                      'Senior High School' => 'Senior HS',
                    ][$opt] ?? $opt;
                  ?>
                  <label>
                    <input type="radio" name="education" value="<?= h($opt) ?>" <?= $edu === $opt ? 'checked' : '' ?> <?= $isDuplicateAddBlock ? 'disabled' : 'required' ?>>
                    <?= h($displayOpt) ?>
                  </label>
                <?php endforeach; ?>
              </div>
              <input type="hidden" id="educational_attainment_hidden" name="educational_attainment" value="<?= h($edu) ?>">
            </div>

            <div id="yearLevelWrap" class="am-anim is-shown" style="margin-top:14px">
              <label class="form-label" id="yearLevelLabel" for="<?= $usesTypedEducationDetail ? 'year_level_text' : 'year_level' ?>"><?= $isVocational ? 'Vocational Course / Program' : ($isCollegeLevel ? 'Course / Program and Year Level' : 'Grade / Year Level') ?></label>
              <select id="year_level" class="form-control" name="year_level" <?= ($isNoEducation || $usesTypedEducationDetail || $isDuplicateAddBlock) ? 'disabled' : '' ?> <?= (!$isNoEducation && !$usesTypedEducationDetail && !$isDuplicateAddBlock) ? 'required' : '' ?> style="<?= $usesTypedEducationDetail ? 'display:none' : '' ?>">
                <option value="" disabled hidden><?= $isNoEducation ? 'Not required' : '' ?></option>
                <?php foreach ($gradeOptions as $opt): ?>
                  <option value="<?= h($opt) ?>" <?= $gradeYear === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                <?php endforeach; ?>
              </select>
              <input id="year_level_text" class="form-control" name="year_level_text" value="<?= h($usesTypedEducationDetail ? $gradeYear : '') ?>" placeholder="<?= h($typedEducationPlaceholder) ?>" <?= ($usesTypedEducationDetail && !$isDuplicateAddBlock) ? 'required' : '' ?> <?= (!$usesTypedEducationDetail || $isDuplicateAddBlock) ? 'readonly' : '' ?> style="<?= $usesTypedEducationDetail ? '' : 'display:none' ?>">
              <input type="hidden" id="grade_year_level_hidden" name="grade_year_level" value="<?= h($isNoEducation ? '' : $gradeYear) ?>">
              <div class="hint" id="gradeYearHelper"><?= $isNoEducation ? 'Grade / Year Level is not required for No Education.' : '' ?></div>
            </div>
          </div>

          <div id="vocationalOtherWrap" class="am-anim <?= $showVocationalOther ? 'is-shown' : 'is-hidden' ?>" style="margin-top:14px; display:none">
            <label class="form-label" for="vocational_training_other">Specify vocational training</label>
            <input id="vocational_training_other" class="form-control" name="vocational_training_other" value="<?= h(val('vocational_training_other')) ?>" <?= $isDuplicateAddBlock ? 'readonly' : '' ?>>
          </div>

          <div class="grid-2" style="margin-top:14px">
            <div>
              <label class="form-label">Are you currently enrolled?</label>
              <div class="radio-group" data-radio-group>
                <label><input type="radio" name="currently_enrolled" value="Yes" <?= checkedRadio('currently_enrolled','Yes') ?> <?= $isDuplicateAddBlock ? 'disabled' : '' ?>> Yes</label>
                <label><input type="radio" name="currently_enrolled" value="No"  <?= checkedRadio('currently_enrolled','No')  ?> <?= $isDuplicateAddBlock ? 'disabled' : '' ?>> No</label>
              </div>
            </div>

            <div>
              <label class="form-label" for="last_school_attended_type">Last school attended</label>
              <select id="last_school_attended_type" name="last_school_attended_type" class="form-control" <?= $isDuplicateAddBlock ? 'disabled' : '' ?> required>
                <option value="">Select type</option>
                <?php foreach (ahdisLastSchoolAttendedTypeOptions() as $opt): ?>
                  <option value="<?= h($opt) ?>" <?= $lastSchoolValue === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div id="reasonNotAttendingWrapper" class="grid-2 am-anim <?= $showReasonNotAttending ? 'is-shown' : 'is-hidden' ?>" style="margin-top:14px; <?= $showReasonNotAttending ? '' : 'display:none' ?>">
            <div>
              <div class="form-label" id="reasonNotAttendingLabel">Reason for not attending school</div>
              <div id="reasonNotAttendingGroup" class="check-grid reason-not-attending-grid" role="group" aria-labelledby="reasonNotAttendingLabel" aria-required="<?= ($showReasonNotAttending && !$isDuplicateAddBlock) ? 'true' : 'false' ?>">
                <?php foreach ($reasonOptions as $opt): ?>
                  <?php $reasonId = 'reason_not_attending_' . substr(sha1($opt), 0, 12); ?>
                  <label class="check-item reason-not-attending-check <?= in_array($opt, $reasonSelectedValues, true) ? 'is-selected' : '' ?>" for="<?= h($reasonId) ?>">
                    <input
                      id="<?= h($reasonId) ?>"
                      type="checkbox"
                      class="reason-not-attending-chk"
                      name="reason_not_attending_school[]"
                      value="<?= h($opt) ?>"
                      <?= in_array($opt, $reasonSelectedValues, true) ? 'checked' : '' ?>
                      <?= (!$showReasonNotAttending || $isDuplicateAddBlock) ? 'disabled' : '' ?>
                    >
                    <span><?= h($opt) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
              <div class="field-error" id="reasonNotAttendingError"></div>
            </div>

            <div id="reasonOtherWrap" class="am-anim <?= $showReasonOther ? 'is-shown' : 'is-hidden' ?>">
              <label class="form-label" for="reason_not_attending_school_other">Please specify reason</label>
              <input id="reason_not_attending_school_other" class="form-control" name="reason_not_attending_school_other" value="<?= h($showReasonOther ? $reasonOtherDisplay : '') ?>" <?= ($showReasonOther && !$isDuplicateAddBlock) ? 'required' : '' ?> <?= (!$showReasonOther || $isDuplicateAddBlock) ? 'readonly' : '' ?>>
            </div>
          </div>
        </div>

        <div class="section">
          <div class="section-title">Part A — III. Civil Status</div>
          <div class="radio-group" data-radio-group>
            <label><input type="radio" name="civil_status" value="Single" <?= checkedRadio('civil_status','Single') ?> <?= $isDuplicateAddBlock ? 'disabled' : '' ?>> Single</label>
            <label><input type="radio" name="civil_status" value="Married" <?= checkedRadio('civil_status','Married') ?> <?= $isDuplicateAddBlock ? 'disabled' : '' ?>> Married</label>
            <label><input type="radio" name="civil_status" value="Living-in" <?= checkedRadio('civil_status','Living-in') ?> <?= $isDuplicateAddBlock ? 'disabled' : '' ?>> Living-in</label>
          </div>
          <div class="hint">If married/living-in, Part B becomes available.</div>
        </div>

        <div class="section">
          <div class="section-title">Part A — IV. Living Situation</div>
          <div class="radio-group" data-radio-group>
            <label><input type="radio" name="living_situation" value="Living with Parents" <?= checkedRadio('living_situation','Living with Parents') ?> <?= $isDuplicateAddBlock ? 'disabled' : '' ?>> Living with Parents</label>
            <label><input type="radio" name="living_situation" value="Living Alone" <?= checkedRadio('living_situation','Living Alone') ?> <?= $isDuplicateAddBlock ? 'disabled' : '' ?>> Living Alone</label>
            <label><input type="radio" name="living_situation" value="Living separately with husband/partner" <?= checkedRadio('living_situation','Living separately with husband/partner') ?> <?= $isDuplicateAddBlock ? 'disabled' : '' ?>> Living separately with husband/partner</label>
            <label><input type="radio" name="living_situation" value="Living with others" <?= checkedRadio('living_situation','Living with others') ?> <?= $isDuplicateAddBlock ? 'disabled' : '' ?>> Living with others (pls. specify)</label>
          </div>

          <div id="livingOthersWrap" class="am-anim <?= $showLivingOthers ? 'is-shown' : 'is-hidden' ?>" style="margin-top:14px">
            <label class="form-label">If Living with others (pls. specify)</label>
            <input class="form-control" id="living_others" name="living_others" value="<?= h(val('living_others')) ?>" data-uppercase="1" <?= $isDuplicateAddBlock ? 'readonly' : '' ?>>
          </div>
        </div>

        <div class="section" id="fertilitySection">
          <div class="section-title">Part A — V. Fertility</div>

          <div class="grid-2">
            <div>
              <label class="form-label">Are you currently pregnant?</label>
              <div class="radio-group" data-radio-group>
                <label><input type="radio" name="pregnant" value="Yes" <?= checkedRadio('pregnant','Yes') ?> <?= $isDuplicateAddBlock ? 'disabled' : '' ?>> Yes</label>
                <label><input type="radio" name="pregnant" value="No"  <?= checkedRadio('pregnant','No')  ?> <?= $isDuplicateAddBlock ? 'disabled' : '' ?>> No</label>
              </div>
              <div class="hint"><strong>If Yes, answer V.1 and V.2. If No, answer V.2 only.</strong></div>
            </div>
            <div></div>
          </div>

          <div id="fertilityV1" class="am-anim <?= $showV1 ? 'is-shown' : 'is-hidden' ?>" style="margin-top:14px">
            <div class="hint" style="font-weight:900; color:#163a73; margin-bottom:8px;">V.1 (Pregnant)</div>

            <div class="grid-2">
              <div>
                <label class="form-label">How many months are you pregnant?</label>
                <input
                  id="months_pregnant"
                  type="number"
                  class="form-control"
                  name="months_pregnant"
                  min="0"
                  max="9"
                  inputmode="numeric"
                  value="<?= h(val('months_pregnant')) ?>"
                  <?= $isDuplicateAddBlock ? 'readonly' : '' ?>
                >
                <div class="hint">Enter whole number of months (0-9).</div>
              </div>

              <div>
                <label class="form-label">Attended Prenatal Care</label>
                <div class="radio-group" data-radio-group id="prenatal_group">
                  <label><input type="radio" name="prenatal_care" value="Yes" <?= checkedRadio('prenatal_care','Yes') ?> <?= $isDuplicateAddBlock ? 'disabled' : '' ?>> Yes</label>
                  <label><input type="radio" name="prenatal_care" value="No"  <?= checkedRadio('prenatal_care','No')  ?> <?= $isDuplicateAddBlock ? 'disabled' : '' ?>> No</label>
                </div>

                <div
                  id="prenatalVisitsWrap"
                  class="am-anim <?= $showPrenatalVisits ? 'is-shown' : 'is-hidden' ?>"
                  style="margin-top:14px; <?= $showPrenatalVisits ? '' : 'display:none' ?>"
                >
                  <label class="form-label" for="prenatal_visits">How many times visited?</label>
                  <input
                    id="prenatal_visits"
                    type="number"
                    class="form-control"
                    name="prenatal_visits"
                    min="0"
                    step="1"
                    inputmode="numeric"
                    value="<?= h($showPrenatalVisits ? val('prenatal_visits') : '') ?>"
                    <?= $showPrenatalVisits && !$isDuplicateAddBlock ? 'required' : '' ?>
                    <?= !$showPrenatalVisits || $isDuplicateAddBlock ? 'disabled' : '' ?>
                  >
                </div>
              </div>
            </div>

            <div style="margin-top:14px">
              <label class="form-label">Health concerns during pregnancy (If any)</label>
              <textarea id="health_concerns" class="form-control" name="health_concerns" <?= $isDuplicateAddBlock ? 'readonly' : '' ?>><?= h(val('health_concerns')) ?></textarea>
            </div>
          </div>

          <div id="fertilityV2" class="am-anim is-shown" style="margin-top:14px">
            <div class="hint" style="font-weight:900; color:#163a73; margin-bottom:8px;">V.2</div>

            <div class="grid-2">
              <div>
                <label class="form-label">Age of first pregnancy</label>
                <input
                  id="age_first_pregnancy"
                  type="number"
                  class="form-control"
                  name="age_first_pregnancy"
                  min="1"
                  step="1"
                  inputmode="numeric"
                  required
                  value="<?= h(val('age_first_pregnancy')) ?>"
                  <?= $isDuplicateAddBlock ? 'readonly' : '' ?>
                >
                <div class="hint">Enter a whole number. The age cannot exceed the current age.</div>
              </div>
              <div>
                <label class="form-label">How many times have you been pregnant?</label>
                <input
                  id="times_pregnant"
                  type="number"
                  class="form-control"
                  name="times_pregnant"
                  min="0"
                  step="1"
                  inputmode="numeric"
                  value="<?= h(val('times_pregnant')) ?>"
                  <?= $isDuplicateAddBlock ? 'readonly' : '' ?>
                >
              </div>
            </div>

            <div class="grid-2" style="margin-top:14px">
              <div>
                <label class="form-label">Number of living children</label>
                <input
                  id="living_children"
                  type="number"
                  class="form-control"
                  name="living_children"
                  min="0"
                  step="1"
                  inputmode="numeric"
                  value="<?= h(val('living_children')) ?>"
                  <?= $isDuplicateAddBlock ? 'readonly' : '' ?>
                >
              </div>
              <div>
                <label class="form-label">Do you have a child under 1 year old?</label>
                <div class="radio-group" data-radio-group>
                  <label><input type="radio" name="child_under_one" value="Yes" <?= checkedRadio('child_under_one','Yes') ?> <?= $isDuplicateAddBlock ? 'disabled' : '' ?>> Yes</label>
                  <label><input type="radio" name="child_under_one" value="No"  <?= checkedRadio('child_under_one','No')  ?> <?= $isDuplicateAddBlock ? 'disabled' : '' ?>> No</label>
                </div>
              </div>
            </div>

            <div style="margin-top:14px">
              <label class="form-label">Family Planning method currently used</label>
              <select id="family_planning" class="form-control" name="family_planning" <?= $isDuplicateAddBlock ? 'disabled' : '' ?>>
                <option value="">Select method</option>
                <?php foreach (ahdisFamilyPlanningOptions() as $fpCode => $fpLabel): ?>
                  <option value="<?= h($fpCode) ?>" <?= ((string)val('family_planning') === (string)$fpCode) ? 'selected' : '' ?>><?= h($fpLabel) ?></option>
                <?php endforeach; ?>
                <?php if ($legacyFamilyPlanning !== ''): ?>
                  <option value="<?= h($legacyFamilyPlanning) ?>" selected><?= h($legacyFamilyPlanning) ?></option>
                <?php endif; ?>
              </select>
              <input type="hidden" name="family_planning_current" value="<?= h($familyPlanningRaw) ?>">
            </div>
          </div>
        </div>

        <div class="section">
          <div class="section-title">Part A — VI. Is your family a 4Ps beneficiary?</div>
          <div class="radio-group" data-radio-group>
            <label><input type="radio" name="is_4ps" value="Yes" <?= checkedRadio('is_4ps','Yes') ?> <?= $isDuplicateAddBlock ? 'disabled' : '' ?>> Yes</label>
            <label><input type="radio" name="is_4ps" value="No"  <?= checkedRadio('is_4ps','No')  ?> <?= $isDuplicateAddBlock ? 'disabled' : '' ?>> No</label>
          </div>
        </div>

        <div class="section">
          <div class="section-title">Part A — VII. Are you an IP member?</div>

          <div class="radio-group" data-radio-group>
            <label><input type="radio" name="is_ips" value="Yes" <?= checkedRadio('is_ips','Yes') ?> <?= $isDuplicateAddBlock ? 'disabled' : '' ?>> Yes</label>
            <label><input type="radio" name="is_ips" value="No"  <?= checkedRadio('is_ips','No')  ?> <?= $isDuplicateAddBlock ? 'disabled' : '' ?>> No</label>
          </div>

          <div id="ethnicityWrap" class="am-anim <?= $showEthnicity ? 'is-shown' : 'is-hidden' ?>" style="margin-top:14px">
            <label class="form-label">If yes, pls specify ethnicity</label>
            <input id="ethnicity" class="form-control" name="ethnicity" value="<?= h(val('ethnicity')) ?>" data-uppercase="1" <?= $isDuplicateAddBlock ? 'readonly' : '' ?>>
          </div>
        </div>

        <div class="section">
          <div class="section-title">Part A — VIII. Are you currently working?</div>

          <div class="grid-2">
            <div>
              <label class="form-label">Currently working?</label>
              <div class="radio-group" data-radio-group>
                <label><input type="radio" name="currently_working" value="Yes" <?= checkedRadio('currently_working','Yes') ?> <?= $isDuplicateAddBlock ? 'disabled' : '' ?>> Yes</label>
                <label><input type="radio" name="currently_working" value="No"  <?= checkedRadio('currently_working','No')  ?> <?= $isDuplicateAddBlock ? 'disabled' : '' ?>> No</label>
              </div>
            </div>

            <div id="monthlyIncomeWrap" class="am-anim <?= $showWorkFields ? 'is-shown' : 'is-hidden' ?>">
              <label class="form-label">Monthly income</label>
              <input
                id="monthly_income"
                class="form-control money"
                name="monthly_income"
                inputmode="numeric"
                placeholder="e.g., 5,000"
                value="<?= h(val('monthly_income')) ?>"
                <?= $isDuplicateAddBlock ? 'readonly' : '' ?>
              >
              <div class="hint">Digits only; commas will appear automatically.</div>
            </div>
          </div>

          <div id="occupationWrap" class="am-anim <?= $showWorkFields ? 'is-shown' : 'is-hidden' ?>" style="margin-top:14px">
            <label class="form-label">What is your usual occupation</label>
            <input
              id="occupation"
              class="form-control"
              name="occupation"
              placeholder="Occupation"
              value="<?= h(val('occupation')) ?>"
              data-uppercase="1"
              <?= $isDuplicateAddBlock ? 'readonly' : '' ?>
            >
          </div>
        </div>

        <div class="section">
          <div class="section-title">Part A — IX. What is the occupation of your?</div>

          <div class="grid-2">
            <div>
              <label class="form-label">Father</label>
              <input class="form-control" name="father_occupation" value="<?= h(val('father_occupation')) ?>" data-uppercase="1" <?= $isDuplicateAddBlock ? 'readonly' : '' ?>>
            </div>
            <div>
              <label class="form-label">Mother</label>
              <input class="form-control" name="mother_occupation" value="<?= h(val('mother_occupation')) ?>" data-uppercase="1" <?= $isDuplicateAddBlock ? 'readonly' : '' ?>>
            </div>
          </div>
        </div>

        <div class="section" id="govAssistSection">
          <div class="section-title gov-assist-title">Part A - X. Government Assistance, Programs, or Services you intend to avail of</div>
          <div class="section-title">Part A — X. Government Assistance, Programs, or Services you intend to avail of</div>

          <?php
            $assistOptions = [
              "Livelihood Training",
              "Skills Training",
              "Health Services",
              "Scholarship for schooling",
              "Others"
            ];
          ?>

          <div class="check-grid gov-assist-grid" role="group" aria-label="Government Assistance">
            <?php foreach ($assistOptions as $opt): ?>
              <?php
                $k = lowerSafe($opt);
                $isChecked = isset($govSelectedMap[$k]);
                $specVal = govSpecVal($opt, $govDetails);
                $specId = "gov_spec_" . preg_replace('/[^a-z0-9]+/i','_', $opt);
              ?>
              <div class="check-item gov-assist-item">
                <label class="gov-assist-check">
                  <input
                    type="checkbox"
                    class="gov-assist-chk"
                    name="gov_assistance[]"
                    value="<?= h($opt) ?>"
                    <?= $isChecked ? 'checked' : '' ?>
                    <?= $isDuplicateAddBlock ? 'disabled' : '' ?>
                    data-spec-id="<?= h($specId) ?>"
                  >
                  <?= h($opt) ?>
                </label>

                <div>
                  <label class="gov-assist-spec-label" for="<?= h($specId) ?>">
                    Please specify
                  </label>

                  <input
                    id="<?= h($specId) ?>"
                    class="form-control gov-spec <?= $isChecked ? '' : 'is-disabled' ?>"
                    name="gov_assistance_spec[<?= h($opt) ?>]"
                    placeholder="<?= $isChecked ? 'Write details here, if known...' : 'Select this service to enable details' ?>"
                    value="<?= h($specVal) ?>"
                    data-option-label="<?= h($opt) ?>"
                    data-uppercase="1"
                    <?= ($isChecked && !$isDuplicateAddBlock) ? '' : 'disabled aria-disabled="true"' ?>
                  >
                  <div class="field-error" id="<?= h($specId) ?>_error"></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <input type="hidden" name="gov_assistance_other" value="<?= h(val('gov_assistance_other')) ?>">
          <div class="hint gov-assist-helper">
            If you select a service, its specify field is optional. Unselected services are disabled and will not be submitted.
          </div>
          <div class="hint">
            You may leave “pls specify” blank even if you checked an option. You may also skip this entire section.
          </div>
        </div>

        <div class="section am-anim is-hidden" id="partnerSection">
          <div class="section-title">Part B. Husband’s / Partner’s Information</div>

          <input type="hidden" id="partner_name" name="partner_name" value="<?= h($partnerName) ?>">

          <div class="grid-3 partner-name-grid">
            <div>
              <label class="form-label">Partner Surname</label>
              <input
                id="partner_surname"
                class="form-control"
                name="partner_surname"
                value="<?= h($partnerSurname) ?>"
                data-uppercase="1"
                <?= $isDuplicateAddBlock ? 'readonly' : '' ?>
              >
            </div>
            <div>
              <label class="form-label">Partner First Name</label>
              <input
                id="partner_first_name"
                class="form-control"
                name="partner_first_name"
                value="<?= h($partnerFirstName) ?>"
                data-uppercase="1"
                <?= $isDuplicateAddBlock ? 'readonly' : '' ?>
              >
            </div>
            <div>
              <label class="form-label">Partner Middle Name</label>
              <input
                id="partner_middle_name"
                class="form-control"
                name="partner_middle_name"
                value="<?= h($partnerMiddleName) ?>"
                data-uppercase="1"
                <?= $isDuplicateAddBlock ? 'readonly' : '' ?>
              >
            </div>
          </div>

          <div class="grid-2" style="margin-top:14px">
            <div>
              <label class="form-label">Present Address</label>
              <input class="form-control" name="partner_address" value="<?= h($partnerAddress) ?>" data-uppercase="1" <?= $isDuplicateAddBlock ? 'readonly' : '' ?>>
              <div class="hint">You can type Barangay + Municipality.</div>
            </div>
            <div>
              <label class="form-label">Place of Birth</label>
              <input
                id="partner_place_of_birth"
                class="form-control"
                name="partner_place_of_birth"
                value="<?= h($partnerPob) ?>"
                data-uppercase="1"
                <?= $isDuplicateAddBlock ? 'readonly' : '' ?>
              >
            </div>
          </div>

          <div class="grid-2" style="margin-top:14px">
            <div>
              <label class="form-label">Date of Birth</label>
              <input
                id="partner_dob"
                type="date"
                class="form-control"
                name="partner_dob"
                max="<?= h($todayInputMax) ?>"
                title="Select the partner's date of birth."
                value="<?= h($partnerDobInputValue) ?>"
                <?= $isDuplicateAddBlock ? 'readonly' : '' ?>
              >
            </div>
            <div>
              <label class="form-label">Age</label>
              <input id="partner_age" type="number" class="form-control" name="partner_age" value="<?= h($partnerAge) ?>" readonly>
            </div>
          </div>

          <div style="margin-top:14px">
            <label class="form-label">Educational Attainment</label>
            <div class="radio-group" data-radio-group>
              <?php
                $pEdu = (string)$partnerEdu;
                $pEduOpts = ahdisEducationAttainmentOptions();
                foreach ($pEduOpts as $opt):
              ?>
                <label>
                  <input type="radio" name="partner_education" value="<?= h($opt) ?>" <?= ($pEdu === $opt ? 'checked' : '') ?> <?= $isDuplicateAddBlock ? 'disabled' : '' ?>>
                  <?= h($opt) ?>
                </label>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="grid-2" style="margin-top:14px">
            <div>
              <label class="form-label">Is Currently Enrolled?</label>
              <div class="radio-group" data-radio-group>
                <label><input type="radio" name="partner_currently_enrolled" value="Yes" <?= ($partnerEnrolled === 'Yes' ? 'checked' : '') ?> <?= $isDuplicateAddBlock ? 'disabled' : '' ?>> Yes</label>
                <label><input type="radio" name="partner_currently_enrolled" value="No"  <?= ($partnerEnrolled === 'No' ? 'checked' : '')  ?> <?= $isDuplicateAddBlock ? 'disabled' : '' ?>> No</label>
              </div>
            </div>
            <div>
              <label class="form-label" for="partner_year_level">Grade/Year Level</label>
              <select
                id="partner_year_level"
                class="form-control"
                name="partner_year_level"
                <?= ($isDuplicateAddBlock || $partnerEnrolled !== 'Yes' || $partnerEdu === 'No Education' || !$partnerYearOptions) ? 'disabled' : '' ?>
                <?= (!$isDuplicateAddBlock && $partnerEnrolled === 'Yes' && $partnerEdu !== 'No Education' && $partnerYearOptions) ? 'required' : '' ?>
              >
                <option value="" disabled hidden><?= $partnerEdu === 'No Education' ? 'Not required' : 'Select grade/year level' ?></option>
                <?php foreach ($partnerYearOptions as $opt): ?>
                  <option value="<?= h($opt) ?>" <?= $partnerYearLevel === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="grid-2" style="margin-top:14px">
            <div>
              <label class="form-label">Are you currently working?</label>
              <div class="radio-group" data-radio-group>
                <label><input type="radio" name="partner_currently_working" value="Yes" <?= ($partnerWorking === 'Yes' ? 'checked' : '') ?> <?= $isDuplicateAddBlock ? 'disabled' : '' ?>> Yes</label>
                <label><input type="radio" name="partner_currently_working" value="No"  <?= ($partnerWorking === 'No' ? 'checked' : '')  ?> <?= $isDuplicateAddBlock ? 'disabled' : '' ?>> No</label>
              </div>
            </div>

            <div id="partnerMonthlyIncomeWrap" class="am-anim <?= $showPartnerWorkFields ? 'is-shown' : 'is-hidden' ?>">
              <label class="form-label">Monthly Income</label>
              <input
                id="partner_monthly_income"
                class="form-control money"
                name="partner_monthly_income"
                inputmode="numeric"
                placeholder="e.g., 5,000"
                value="<?= h($partnerIncome) ?>"
                <?= $isDuplicateAddBlock ? 'readonly' : '' ?>
              >
              <div class="hint">Digits only; commas will appear automatically.</div>
            </div>
          </div>

          <div id="partnerOccupationWrap" class="am-anim <?= $showPartnerWorkFields ? 'is-shown' : 'is-hidden' ?>" style="margin-top:14px">
            <label class="form-label">What is your usual occupation?</label>
            <input
              id="partner_occupation"
              class="form-control"
              name="partner_occupation"
              placeholder="Occupation"
              value="<?= h($partnerOccupation) ?>"
              data-uppercase="1"
              <?= $isDuplicateAddBlock ? 'readonly' : '' ?>
            >
          </div>
        </div>

        <div class="section">
          <div class="section-title">Other observations / information noted</div>
          <textarea class="form-control" name="other_observations" placeholder="Write notes here..." <?= $isDuplicateAddBlock ? 'readonly' : '' ?>><?= h(val('other_observations')) ?></textarea>
        </div>

        <div class="section">
          <div class="section-title">Interviewed by / Noted by</div>

          <div class="grid-2 accountability-grid">
            <div class="accountability-field">
              <label class="form-label" for="interviewed_by">Interviewed by (Barangay Population Volunteer)</label>
              <input id="interviewed_by" class="form-control" name="interviewed_by" value="<?= h(val('interviewed_by')) ?>" data-uppercase="1" <?= $isDuplicateAddBlock ? 'readonly' : 'required' ?>>
            </div>

            <div class="accountability-field">
              <label class="form-label" for="interview_date">Interview Date</label>
              <input id="interview_date" class="form-control" type="date" name="interview_date" value="<?= h(val('interview_date')) ?>" <?= $isDuplicateAddBlock ? 'readonly' : '' ?>>
            </div>

            <div class="accountability-field">
              <label class="form-label" for="noted_by">Noted by (Municipal Population Officer)</label>
              <input id="noted_by" class="form-control" name="noted_by" value="<?= h(val('noted_by')) ?>" data-uppercase="1" <?= $isDuplicateAddBlock ? 'readonly' : '' ?>>
            </div>

            <div class="accountability-field">
              <label class="form-label" for="noted_date">Noted Date</label>
              <input id="noted_date" class="form-control" type="date" name="noted_date" value="<?= h(val('noted_date')) ?>" <?= $isDuplicateAddBlock ? 'readonly' : '' ?>>
            </div>

            <div class="accountability-field">
              <label class="form-label" for="encoded_by">Encoded By</label>
              <input
                id="encoded_by"
                class="form-control"
                name="encoded_by"
                value="<?= h(val('encoded_by')) ?>"
         
                data-uppercase="1"
                <?= $isDuplicateAddBlock ? 'readonly' : 'required' ?>
              >
            </div>

            <div class="accountability-field">
              <label class="form-label" for="date_encoded">Date Encoded</label>
              <input
                id="date_encoded"
                class="form-control"
                type="date"
                name="date_encoded"
                value="<?= h($dateEncodedInputValue) ?>"
                data-draft-exclude="1"
                readonly
              >
            
            </div>
          </div>
        </div>

        <div class="submit-area">
          <a class="btn btn-ghost" href="<?= h($DASHBOARD_URL) ?>">Cancel</a>

          <?php if ($isDuplicateAddBlock && $duplicateAid > 0): ?>
            <a class="btn btn-primary" href="<?= h($ADOL_VIEW_URL . '?adolescent_id=' . (int)$duplicateAid) ?>">
              Open Existing Profile
            </a>
          <?php else: ?>
            <button class="btn btn-primary" id="submitBtnMain" type="submit">Review Submission</button>
          <?php endif; ?>
        </div>
      </form>

      <div class="review-modal" id="reviewModal" aria-hidden="true">
        <div class="review-dialog" role="dialog" aria-modal="true" aria-labelledby="reviewTitle">
          <div class="review-head">
            <h4 class="review-title" id="reviewTitle">Review Before Submission</h4>
            <p class="review-subtitle">Confirm the details below before saving this record.</p>
          </div>
          <div class="review-body" id="reviewContent"></div>
          <div class="review-actions">
            <button class="btn btn-ghost" type="button" id="reviewEditBtn">Back/Edit Details</button>
            <button class="btn btn-primary" type="button" id="reviewConfirmBtn">
              <?php
                if ($isSnapshotOnly) echo "Confirm and Save Latest";
                elseif ($isLegacyEdit) echo "Confirm and Save";
                elseif ($isUpdateExisting) echo "Confirm and Submit Update";
                else echo "Confirm and Submit";
              ?>
            </button>
          </div>
        </div>
      </div>

      <div class="review-modal" id="draftModal" aria-hidden="true">
        <div class="review-dialog draft-dialog" role="dialog" aria-modal="true" aria-labelledby="draftTitle">
          <div class="review-head">
            <h4 class="review-title" id="draftTitle">Unsaved Draft Found</h4>
            <p class="review-subtitle">You have an unsaved draft. Do you want to continue or discard it?</p>
          </div>
          <div class="review-body">
            <div class="review-item">
              <span class="review-label">Draft status</span>
              <span class="review-value" id="draftSavedAt">Saved on this device</span>
            </div>
          </div>
          <div class="review-actions">
            <button class="btn btn-ghost" type="button" id="draftDiscardBtn">Discard Draft</button>
            <button class="btn btn-primary" type="button" id="draftContinueBtn">Continue Draft</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  const MUNICIPALITY  = <?= json_encode($prefMunicipality, JSON_UNESCAPED_UNICODE) ?>;
  const PREF_BRGY     = <?= json_encode($prefBarangay, JSON_UNESCAPED_UNICODE) ?>;
  const ALLOWED_BRGYS = <?= json_encode(array_values($allowedBarangays), JSON_UNESCAPED_UNICODE) ?>;
  const DUPLICATE_BLOCK = <?= json_encode($isDuplicateAddBlock) ?>;
  const DUPLICATE_AID   = <?= (int)$duplicateAid ?>;
  const ADOL_VIEW_URL   = <?= json_encode($ADOL_VIEW_URL, JSON_UNESCAPED_UNICODE) ?>;
  const DUPLICATE_REASON = <?= json_encode($duplicateReason, JSON_UNESCAPED_UNICODE) ?>;
  const EDUCATION_CONFIG = <?= json_encode(ahdisEducationClientConfig(), JSON_UNESCAPED_UNICODE) ?>;
  const $ = (id) => document.getElementById(id);
  const form = $("amForm");
  const DRAFT_KEY = form?.dataset?.draftKey || "ahdis.add_profile.draft.new";
  const DRAFT_FORM_ID = "add_profile";
  const DRAFT_VERSION = 2;
  const DRAFT_ENABLED = !!form && form.dataset.draftEnabled !== "0";
  let draftSaveTimer = null;
  let draftPromptPayload = null;
  let reviewConfirmed = false;
  let validationAttempted = false;
  let draftInitialFields = null;

  function parseDateFlexible(s) {
    s = (s || "").toString().trim();
    if (!s) return null;

    const iso = s.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (iso) {
      const y = +iso[1], m = +iso[2], d = +iso[3];
      const dt = new Date(y, m - 1, d);
      if (dt.getFullYear() === y && dt.getMonth() === (m - 1) && dt.getDate() === d) return dt;
      return null;
    }

    const us = s.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
    if (us) {
      const m = +us[1], d = +us[2], y = +us[3];
      const dt = new Date(y, m - 1, d);
      if (dt.getFullYear() === y && dt.getMonth() === (m - 1) && dt.getDate() === d) return dt;
      return null;
    }

    return null;
  }

  function toMMDDYYYYFromDate(dt) {
    const mm = String(dt.getMonth() + 1).padStart(2, "0");
    const dd = String(dt.getDate()).padStart(2, "0");
    const yy = dt.getFullYear();
    return `${mm}/${dd}/${yy}`;
  }

  function normalizeDOBField(el) {
    if (!el) return;
    if ((el.type || "").toLowerCase() === "date") return;
    const dt = parseDateFlexible(el.value);
    if (dt) el.value = toMMDDYYYYFromDate(dt);
  }

  function autoSlashMMDDYYYY(el) {
    if (!el) return;
    let v = (el.value || "").replace(/\D/g, "").slice(0, 8);
    if (v.length >= 5) el.value = v.slice(0,2) + "/" + v.slice(2,4) + "/" + v.slice(4);
    else if (v.length >= 3) el.value = v.slice(0,2) + "/" + v.slice(2);
    else el.value = v;
  }

  function calcAge(dobStr) {
    const dob = parseDateFlexible(dobStr);
    if (!dob) return "";
    const today = new Date();
    let age = today.getFullYear() - dob.getFullYear();
    const m = today.getMonth() - dob.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) age--;
    return age >= 0 ? age : "";
  }

  function refreshChipStates() {
    document.querySelectorAll("[data-radio-group]").forEach(group => {
      group.querySelectorAll("label").forEach(l => l.classList.remove("is-selected"));
      const checked = group.querySelector("input[type=radio]:checked");
      if (checked) checked.closest("label")?.classList.add("is-selected");
    });
  }

  function digitsOnlyStr(s) {
    return (s || "").toString().replace(/\D+/g, "");
  }

  function formatWithCommas(d) {
    return d ? d.replace(/\B(?=(\d{3})+(?!\d))/g, ",") : "";
  }

  function moneyFormat(el) {
    if (!el) return;
    el.value = formatWithCommas(digitsOnlyStr(el.value));
  }

  function normalize(s){
    return (s || "")
      .toString()
      .toLowerCase()
      .replace(/\./g,' ')
      .replace(/\s+/g,' ')
      .trim();
  }

  function getSelectedBarangay(){
    return ($("barangay_select")?.value || "").toString().trim();
  }

  function buildAddress(){
    const brgy = getSelectedBarangay();
    const mun  = (MUNICIPALITY || "").trim();
    let parts = [];
    if (brgy) parts.push(brgy);
    if (mun) parts.push(mun);
    return parts.join(", ");
  }

  function syncAddressFields(){
    syncNameFields();
    const normalized = buildAddress();
    if ($("present_address")) $("present_address").value = normalized;
    if ($("locked_municipality")) $("locked_municipality").value = MUNICIPALITY;
    if ($("locked_barangay")) $("locked_barangay").value = getSelectedBarangay();
  }

  function buildFullNameFromParts() {
    const last = ($("last_name")?.value || "").trim();
    const first = ($("first_name")?.value || "").trim();
    const middle = ($("middle_name")?.value || "").trim();
    const given = [first, middle].filter(Boolean).join(" ");
    if (last && given) return last + ", " + given;
    return [last, given].filter(Boolean).join(" ");
  }

  function syncNameFields() {
    const hidden = $("name_main");
    if (hidden) hidden.value = buildFullNameFromParts();
  }

  function restoreNamePartsFromHiddenIfNeeded() {
    const lastEl = $("last_name");
    const firstEl = $("first_name");
    const middleEl = $("middle_name");
    const hidden = $("name_main");
    if (!lastEl || !firstEl || !hidden) return;
    if ((lastEl.value || "").trim() !== "" || (firstEl.value || "").trim() !== "") return;

    const full = (hidden.value || "").trim();
    if (!full) return;

    if (full.includes(",")) {
      const parts = full.split(",");
      lastEl.value = (parts.shift() || "").trim();
      const given = parts.join(",").trim().split(/\s+/).filter(Boolean);
      firstEl.value = given.shift() || "";
      if (middleEl) middleEl.value = given.join(" ");
    } else {
      const pieces = full.split(/\s+/).filter(Boolean);
      firstEl.value = pieces.shift() || "";
      lastEl.value = pieces.pop() || "";
      if (middleEl) middleEl.value = pieces.join(" ");
    }
  }

  function toUppercaseValue(el) {
    if (!el || el.readOnly || el.disabled) return;
    if (!el.matches('[data-uppercase="1"]')) return;
    const type = (el.type || "text").toLowerCase();
    if (["password", "email", "date", "number", "hidden"].includes(type)) return;
    const start = el.selectionStart;
    const end = el.selectionEnd;
    const upper = (el.value || "").toUpperCase();
    if (el.value !== upper) {
      el.value = upper;
      try {
        if (typeof start === "number" && typeof end === "number") el.setSelectionRange(start, end);
      } catch (e) {}
    }
  }

  function normalizeAllowedBarangay() {
    const el = $("barangay_select");
    if (!el) return;
    const typed = normalize(el.value);
    if (!typed) return;
    const found = ALLOWED_BRGYS.find(b => normalize(b) === typed);
    if (found) el.value = found;
  }

  function showEl(el, show){
    if (!el) return;
    el.classList.toggle("is-hidden", !show);
    el.classList.toggle("is-shown", !!show);
  }

  function todayDateOnly() {
    const now = new Date();
    return new Date(now.getFullYear(), now.getMonth(), now.getDate());
  }

  function isFutureDate(dt) {
    if (!(dt instanceof Date)) return false;
    return dt > todayDateOnly();
  }

  function getFieldErrorElement(el) {
    if (!el) return null;
    const explicitId = el.getAttribute("aria-describedby");
    if (explicitId) {
      const existing = document.getElementById(explicitId);
      if (existing && existing.classList.contains("field-error")) return existing;
    }

    const id = el.id ? el.id + "_error" : "";
    if (id) {
      const found = document.getElementById(id);
      if (found && found.classList.contains("field-error")) {
        el.setAttribute("aria-describedby", id);
        return found;
      }
    }

    const err = document.createElement("div");
    err.className = "field-error";
    err.id = id || ("field_error_" + Math.random().toString(36).slice(2));
    el.setAttribute("aria-describedby", err.id);
    el.insertAdjacentElement("afterend", err);
    return err;
  }

  function setFieldError(el, message) {
    if (!el) return;
    const msg = message || "";
    const err = getFieldErrorElement(el);

    if (typeof el.setCustomValidity === "function") {
      el.setCustomValidity(msg);
    }

    el.classList.toggle("is-invalid", !!msg);
    el.setAttribute("aria-invalid", msg ? "true" : "false");

    if (err) {
      err.textContent = msg;
      err.classList.toggle("is-visible", !!msg);
    }
  }

  function requiredControlMessage(el, message = "Required field") {
    if (!el) return "";
    return (el.value || "").toString().trim() === "" ? message : "";
  }

  function setControlValidity(el, message, options = {}) {
    if (!el) return "";
    const msg = message || "";
    if (options.showErrors === false) {
      if (typeof el.setCustomValidity === "function") el.setCustomValidity(msg);
      if (msg === "") setFieldError(el, "");
    } else {
      setFieldError(el, msg);
    }
    return msg;
  }

  function radioInputs(name) {
    return Array.from(document.querySelectorAll('input[type="radio"]')).filter(el => el.name === name);
  }

  function getRadioErrorElement(group, name) {
    if (!group) return null;
    const expectedId = "radio_error_" + name.replace(/[^a-z0-9_]+/gi, "_");
    const next = group.nextElementSibling;
    if (next && next.classList.contains("field-error") && next.getAttribute("data-radio-error-for") === name) {
      return next;
    }
    const err = document.createElement("div");
    err.className = "field-error";
    err.id = expectedId;
    err.setAttribute("data-radio-error-for", name);
    group.insertAdjacentElement("afterend", err);
    return err;
  }

  function setRadioGroupError(name, message, options = {}) {
    const inputs = radioInputs(name);
    const group = inputs[0]?.closest("[data-radio-group]") || null;
    const msg = message || "";

    inputs.forEach(input => {
      if (typeof input.setCustomValidity === "function") input.setCustomValidity(msg);
      input.setAttribute("aria-invalid", msg ? "true" : "false");
    });

    if (options.showErrors === false) {
      group?.classList.remove("is-invalid");
      const existing = group?.nextElementSibling;
      if (existing && existing.classList.contains("field-error") && existing.getAttribute("data-radio-error-for") === name) {
        existing.textContent = "";
        existing.classList.remove("is-visible");
      }
      return msg;
    }

    group?.classList.toggle("is-invalid", !!msg);
    const err = getRadioErrorElement(group, name);
    if (err) {
      err.textContent = msg;
      err.classList.toggle("is-visible", !!msg);
    }
    return msg;
  }

  function validateRequiredRadio(name, options = {}) {
    const checked = radioValue(name);
    const msg = checked ? "" : "Required field";
    return setRadioGroupError(name, msg, options);
  }

  function validateRequiredControl(el, options = {}) {
    return setControlValidity(el, requiredControlMessage(el), options);
  }

  function clearRadioGroupError(name) {
    setRadioGroupError(name, "", { showErrors: true });
  }

  function refreshValidationAfterUserEdit() {
    if (!validationAttempted) return;
    validateSubmissionRequirements({ showErrors: true, focusFirst: false });
  }

  function setValidationSummary(messages) {
    const box = $("validationSummary");
    if (!box) return;
    const clean = Array.from(new Set((messages || []).filter(Boolean)));
    box.textContent = clean.length ? clean.join(" ") : "";
    box.classList.toggle("is-visible", clean.length > 0);
  }

  function focusFirstInvalid(invalidFields) {
    const first = (invalidFields || []).find(Boolean);
    if (!first) return;
    first.focus?.();
    first.scrollIntoView?.({ behavior: "auto", block: "center" });
  }

  function selectedEducationValue() {
    const checked = document.querySelector('input[name="education"]:checked');
    const edu = $("education");
    return (checked?.value || edu?.value || "").toString().trim();
  }

  function selectedPartnerEducationValue() {
    const checked = document.querySelector('input[name="partner_education"]:checked');
    return (checked?.value || "").toString().trim();
  }

  function isTypedEducationDetail(education) {
    return education === "Vocational" || education === "College Level";
  }

  function typedEducationPlaceholderFor(education) {
    if (education === "Vocational") return EDUCATION_CONFIG.vocationalPlaceholder || "Specify vocational course/program";
    if (education === "College Level") return EDUCATION_CONFIG.collegePlaceholder || "Specify course/program and year level";
    return "";
  }

  function typedEducationLabelFor(education) {
    if (education === "Vocational") return EDUCATION_CONFIG.vocationalLabel || "Vocational Course / Program";
    if (education === "College Level") return EDUCATION_CONFIG.collegeLabel || "Course / Program and Year Level";
    return EDUCATION_CONFIG.gradeLabel || "Grade / Year Level";
  }

  let educationChangeInitialized = false;
  let lastEducationSelection = selectedEducationValue();

  function syncEducationHiddenFields() {
    const edu = $("education");
    const year = $("year_level");
    const yearText = $("year_level_text");
    const eduHidden = $("educational_attainment_hidden");
    const gradeHidden = $("grade_year_level_hidden");
    const selectedEducation = selectedEducationValue();
    const typedMode = isTypedEducationDetail(selectedEducation);

    if (edu) edu.value = selectedEducation;
    if (eduHidden) eduHidden.value = selectedEducation;
    if (gradeHidden) {
      gradeHidden.value = typedMode
        ? (yearText?.value || "").toString().trim()
        : ((year && !year.disabled) ? (year.value || "").toString().trim() : "");
    }
  }

  function setSelectOptions(select, options, placeholder, selectedValue) {
    if (!select) return;
    const current = (selectedValue ?? select.value ?? "").toString();
    select.innerHTML = "";

    const blank = document.createElement("option");
    blank.value = "";
    blank.textContent = placeholder || "";
    blank.disabled = true;
    blank.hidden = true;
    select.appendChild(blank);

    (options || []).forEach(optionText => {
      const option = document.createElement("option");
      option.value = optionText;
      option.textContent = optionText;
      select.appendChild(option);
    });

    select.value = (options || []).includes(current) ? current : "";
  }

  function handleVocationalOtherChange() {
    const wrap = $("vocationalOtherWrap");
    const input = $("vocational_training_other");
    const shouldShow = false;

    showEl(wrap, shouldShow);
    if (wrap) wrap.style.display = "none";
    if (input) {
      input.required = false;
      input.readOnly = true;
      input.value = "";
      setFieldError(input, "");
    }
  }

  function handleEducationChange(changeOptions = {}) {
    const edu = $("education");
    const year = $("year_level");
    const yearText = $("year_level_text");
    const label = $("yearLevelLabel");
    const helper = $("gradeYearHelper");
    if (!edu || !year) return;

    const selectedEducation = selectedEducationValue();
    edu.value = selectedEducation;
    const gradeOptionsForEducation = EDUCATION_CONFIG.gradeYearOptions?.[selectedEducation] || [];
    const hasEducation = selectedEducation !== "";
    const isNoEducation = selectedEducation === "No Education";
    const typedMode = isTypedEducationDetail(selectedEducation);
    const educationChanged = changeOptions.clearIncompatible === true && selectedEducation !== lastEducationSelection;
    const previousYear = (year.value || "").toString().trim();

    if (label) {
      label.textContent = typedEducationLabelFor(selectedEducation);
      label.setAttribute("for", typedMode ? "year_level_text" : "year_level");
    }
    setSelectOptions(year, gradeOptionsForEducation, isNoEducation ? "Not required" : "", (educationChanged || typedMode) ? "" : previousYear);

    year.disabled = !hasEducation || isNoEducation || typedMode || DUPLICATE_BLOCK;
    year.required = hasEducation && !isNoEducation && !typedMode && !DUPLICATE_BLOCK;
    year.style.display = typedMode ? "none" : "";
    if (!hasEducation || isNoEducation || typedMode || !gradeOptionsForEducation.includes(year.value)) {
      year.value = "";
    }

    if (yearText) {
      yearText.style.display = typedMode ? "" : "none";
      yearText.placeholder = typedEducationPlaceholderFor(selectedEducation);
      yearText.required = typedMode && !DUPLICATE_BLOCK;
      yearText.readOnly = !typedMode || DUPLICATE_BLOCK;
      if (!typedMode || isNoEducation || educationChanged) {
        yearText.value = "";
        setFieldError(yearText, "");
      }
    }

    if (helper) helper.textContent = isNoEducation ? EDUCATION_CONFIG.noEducationHelper : "";
    if (isNoEducation) setFieldError(year, "");

    syncEducationHiddenFields();
    handleVocationalOtherChange();
    educationChangeInitialized = true;
    lastEducationSelection = selectedEducation;
  }

  function reasonNotAttendingInputs() {
    return Array.from(document.querySelectorAll(".reason-not-attending-chk"));
  }

  function selectedReasonNotAttendingValues() {
    return reasonNotAttendingInputs().filter(input => input.checked).map(input => input.value);
  }

  function setReasonNotAttendingGroupError(message, options = {}) {
    const inputs = reasonNotAttendingInputs();
    const group = $("reasonNotAttendingGroup");
    const error = $("reasonNotAttendingError");
    const msg = message || "";

    inputs.forEach((input, index) => {
      if (typeof input.setCustomValidity === "function") {
        input.setCustomValidity(index === 0 ? msg : "");
      }
      input.setAttribute("aria-invalid", msg ? "true" : "false");
    });

    if (options.showErrors === false) {
      group?.classList.remove("is-invalid");
      if (error) {
        error.textContent = "";
        error.classList.remove("is-visible");
      }
      return msg;
    }

    group?.classList.toggle("is-invalid", !!msg);
    if (error) {
      error.textContent = msg;
      error.classList.toggle("is-visible", !!msg);
    }
    return msg;
  }

  function handleReasonNotAttendingChange() {
    const enrolledValue = (radioValue("currently_enrolled") || "").toString().trim();
    const shouldShow = enrolledValue === "No";
    const reasonInputs = reasonNotAttendingInputs();
    const reasonGroup = $("reasonNotAttendingGroup");
    const wrap = $("reasonNotAttendingWrapper") || $("reasonNotAttendingWrap");
    const otherWrap = $("reasonOtherWrap");
    const other = $("reason_not_attending_school_other");

    showEl(wrap, shouldShow);
    if (wrap) wrap.style.display = shouldShow ? "" : "none";

    if (reasonGroup) {
      reasonGroup.setAttribute("aria-required", shouldShow && !DUPLICATE_BLOCK ? "true" : "false");
    }
    reasonInputs.forEach(input => {
      input.disabled = !shouldShow || DUPLICATE_BLOCK;
      if (!shouldShow) input.checked = false;
      input.closest(".reason-not-attending-check")?.classList.toggle("is-selected", input.checked);
    });
    if (!shouldShow) setReasonNotAttendingGroupError("");

    const isOther = shouldShow && selectedReasonNotAttendingValues().includes("Other");
    showEl(otherWrap, isOther);
    if (otherWrap) otherWrap.style.display = isOther ? "" : "none";

    if (other) {
      other.required = isOther && !DUPLICATE_BLOCK;
      other.readOnly = !isOther || DUPLICATE_BLOCK;
      if (!isOther) {
        other.value = "";
        setFieldError(other, "");
      }
    }
  }

  function handleCurrentlyEnrolledChange(){
    handleReasonNotAttendingChange();
  }

  function handlePartnerCurrentlyEnrolledChange(){
    handlePartnerEducationChange();
  }

  function handlePartnerEducationChange(changeOptions = {}){
    const year = $("partner_year_level");
    if (!year) return;

    const education = selectedPartnerEducationValue();
    const options = EDUCATION_CONFIG.partnerGradeYearOptions?.[education] || [];
    const enrolledYes = radioValue("partner_currently_enrolled") === "Yes";
    const required = isPartnerRequired()
      && enrolledYes
      && education !== ""
      && education !== "No Education"
      && options.length > 0
      && !DUPLICATE_BLOCK;
    const previous = (year.value || "").toString().trim();
    const placeholder = education === "No Education"
      ? "Not required"
      : (education ? "Select grade/year level" : "Select education first");

    setSelectOptions(year, options, placeholder, changeOptions.clearIncompatible ? "" : previous);
    year.disabled = !required;
    year.required = required;

    if (!required && !DUPLICATE_BLOCK) {
      year.value = "";
      setFieldError(year, "");
    }
  }

  function handleLivingSituationGate(){
    const isOthers = document.querySelector('input[name="living_situation"][value="Living with others"]')?.checked;
    const wrap = $("livingOthersWrap");
    const inp = $("living_others");
    showEl(wrap, !!isOthers);
    if (inp) {
      inp.readOnly = !isOthers || DUPLICATE_BLOCK;
      if (!isOthers && !DUPLICATE_BLOCK) inp.value = "";
    }
  }

  function handleIPsGate(){
    const yes = document.querySelector('input[name="is_ips"][value="Yes"]')?.checked;
    const wrap = $("ethnicityWrap");
    const inp = $("ethnicity");
    showEl(wrap, !!yes);
    if (inp) {
      inp.readOnly = !yes || DUPLICATE_BLOCK;
      if (!yes && !DUPLICATE_BLOCK) inp.value = "";
    }
  }

  function handleWorkingGate(){
    const yes = document.querySelector('input[name="currently_working"][value="Yes"]')?.checked;
    showEl($("monthlyIncomeWrap"), !!yes);
    showEl($("occupationWrap"), !!yes);

    const monthly = $("monthly_income");
    const occ = $("occupation");
    if (monthly) {
      monthly.readOnly = !yes || DUPLICATE_BLOCK;
      if (!yes && !DUPLICATE_BLOCK) monthly.value = "";
    }
    if (occ) {
      occ.required = !!yes && !DUPLICATE_BLOCK;
      occ.readOnly = !yes || DUPLICATE_BLOCK;
      if (!yes && !DUPLICATE_BLOCK) {
        occ.value = "";
        setFieldError(occ, "");
      }
    }
  }

  function handlePartnerWorkingGate(){
    const yes = document.querySelector('input[name="partner_currently_working"][value="Yes"]')?.checked;
    showEl($("partnerMonthlyIncomeWrap"), !!yes);
    showEl($("partnerOccupationWrap"), !!yes);

    const monthly = $("partner_monthly_income");
    const occ = $("partner_occupation");
    if (monthly) {
      monthly.required = !!yes && !DUPLICATE_BLOCK && isPartnerRequired();
      monthly.readOnly = !yes || DUPLICATE_BLOCK;
      if (!yes && !DUPLICATE_BLOCK) {
        monthly.value = "";
        setFieldError(monthly, "");
      }
    }
    if (occ) {
      occ.required = !!yes && !DUPLICATE_BLOCK && isPartnerRequired();
      occ.readOnly = !yes || DUPLICATE_BLOCK;
      if (!yes && !DUPLICATE_BLOCK) {
        occ.value = "";
        setFieldError(occ, "");
      }
    }
  }

  function isPartnerRequired(){
    const civil = (radioValue("civil_status") || "").toLowerCase().replace(/\s+/g, "-");
    return civil === "married" || civil === "living-in" || civil === "live-in";
  }

  function handleCivilStatusPartnerGate(){
    const show = isPartnerRequired();
    showEl($("partnerSection"), show);

    const selectors = [
      '#partner_surname',
      '#partner_first_name',
      '#partner_middle_name',
      'input[name="partner_address"]',
      '#partner_place_of_birth',
      '#partner_dob',
      '#partner_age',
      'input[name="partner_education"]',
      'input[name="partner_currently_enrolled"]',
      '#partner_year_level',
      'input[name="partner_currently_working"]',
      '#partner_monthly_income',
      '#partner_occupation'
    ];

    selectors.forEach(sel => {
      document.querySelectorAll(sel).forEach(el => {
        const tag = (el.tagName || "").toLowerCase();
        const type = (el.type || "").toLowerCase();
        if (tag === "select" || type === "radio" || type === "checkbox") {
          el.disabled = !show || DUPLICATE_BLOCK;
        } else if (el.id !== "partner_age") {
          el.readOnly = !show || DUPLICATE_BLOCK;
        }
      });
    });

    if (show) {
      handlePartnerCurrentlyEnrolledChange();
      handlePartnerWorkingGate();
    } else {
      [
        $("partner_surname"),
        $("partner_first_name"),
        $("partner_middle_name"),
        document.querySelector('input[name="partner_address"]'),
        $("partner_place_of_birth"),
        $("partner_dob"),
        $("partner_year_level"),
        $("partner_monthly_income"),
        $("partner_occupation")
      ].forEach(el => setFieldError(el, ""));
      clearRadioGroupError("partner_education");
      clearRadioGroupError("partner_currently_enrolled");
      clearRadioGroupError("partner_currently_working");
    }
  }

  function handleFertilityGate(){
    const yes = document.querySelector('input[name="pregnant"][value="Yes"]')?.checked;
    showEl($("fertilityV1"), !!yes);

    const months = $("months_pregnant");
    const health = $("health_concerns");
    document.querySelectorAll('input[name="prenatal_care"]').forEach(el => {
      el.disabled = !yes || DUPLICATE_BLOCK;
      if (!yes && !DUPLICATE_BLOCK) el.checked = false;
    });

    if (months) {
      months.readOnly = !yes || DUPLICATE_BLOCK;
      if (!yes && !DUPLICATE_BLOCK) months.value = "";
    }
    if (health) {
      health.readOnly = !yes || DUPLICATE_BLOCK;
      if (!yes && !DUPLICATE_BLOCK) health.value = "";
    }

    if (!yes) {
      handlePrenatalCareGate();
    }
  }

  function handlePrenatalCareGate(){
    const pregnantYes = radioValue("pregnant") === "Yes";
    const prenatalYes = radioValue("prenatal_care") === "Yes";
    const shouldShow = pregnantYes && prenatalYes;

    const wrap = $("prenatalVisitsWrap");
    const visits = $("prenatal_visits");

    showEl(wrap, shouldShow);
    if (wrap) wrap.style.display = shouldShow ? "" : "none";

    if (visits) {
      visits.disabled = !shouldShow || DUPLICATE_BLOCK;
      visits.required = shouldShow && !DUPLICATE_BLOCK;

      if (!shouldShow && !DUPLICATE_BLOCK) {
        visits.value = "";
        setFieldError(visits, "");
      }
    }
  }

  function handleGovAssistSpecs(){
    document.querySelectorAll(".gov-assist-chk").forEach(chk => {
      const specId = chk.getAttribute("data-spec-id");
      const spec = specId ? document.getElementById(specId) : null;
      if (!spec) return;
      const err = document.getElementById(specId + "_error");
      const label = spec.getAttribute("data-option-label") || chk.value || "this service";

      if (DUPLICATE_BLOCK) {
        spec.disabled = true;
        spec.required = false;
        spec.classList.add("is-disabled");
        setFieldError(spec, "");
        return;
      }

      if (chk.checked) {
        spec.disabled = false;
        spec.required = false;
        spec.setAttribute("aria-disabled", "false");
        spec.classList.remove("is-disabled");
        spec.placeholder = "Write details here, if known...";
        setFieldError(spec, "");
        if (err) err.classList.remove("is-visible");
      } else {
        spec.value = "";
        spec.disabled = true;
        spec.required = false;
        setFieldError(spec, "");
        spec.setAttribute("aria-disabled", "true");
        spec.classList.add("is-disabled");
        spec.placeholder = "Select this service to enable details";
        if (err) err.classList.remove("is-visible");
      }
    });
  }

  function validateGovAssistDetails(){
    handleGovAssistSpecs();
    return true;
  }

  function getAgeValue() {
    const v = ($("age")?.value || "").toString().trim();
    if (v === "" || !/^\d+$/.test(v)) return null;
    return parseInt(v, 10);
  }

  function validateAgeAdolescent() {
    const n = getAgeValue();
    if (n === null) return false;
    return n >= 9 && n <= 19;
  }

  function getFirstPregnancyAgeValue() {
    const v = ($("age_first_pregnancy")?.value || "").toString().trim();
    if (v === "" || !/^\d+$/.test(v)) return null;
    return parseInt(v, 10);
  }

  function firstPregnancyAgeMessage() {
    const currentAge = getAgeValue();
    const firstAge = getFirstPregnancyAgeValue();

    if (firstAge === null) {
      return "Required field";
    }
    if (firstAge < 1 || !Number.isSafeInteger(firstAge)) {
      return "Age at first pregnancy must be a positive whole number.";
    }
    if (currentAge !== null && firstAge > currentAge) {
      return "Age at first pregnancy cannot be greater than current age.";
    }
    return "";
  }

  function validateFirstPregnancyAge(options = {}) {
    const el = $("age_first_pregnancy");
    if (!el) return true;
    const msg = firstPregnancyAgeMessage();
    if (options.showErrors === false) {
      el.setCustomValidity(msg);
      if (msg === "") setFieldError(el, "");
    } else {
      setFieldError(el, msg);
    }
    return msg === "";
  }

  /* ======================
     NUMERIC FIELD VALIDATORS
     ====================== */

  function monthsPregnantMessage() {
    const v = ($("months_pregnant")?.value || "").toString().trim();
    if (v === "") {
      // Only required if pregnant = Yes
      if (($("pregnant")?.checked ? ($("pregnant").value === "Yes" ? true : false) : false)) {
        return "Required when currently pregnant.";
      }
      return "";
    }
    if (!/^\d+$/.test(v)) {
      return "Must be a whole number only.";
    }
    const n = parseInt(v, 10);
    if (n > 9) {
      return "Pregnancy typically does not exceed 9 months.";
    }
    return "";
  }

  function validateMonthsPregnant(options = {}) {
    const el = $("months_pregnant");
    if (!el) return true;
    const msg = monthsPregnantMessage();
    if (options.showErrors === false) {
      el.setCustomValidity(msg);
      if (msg === "") setFieldError(el, "");
    } else {
      setFieldError(el, msg);
    }
    return msg === "";
  }

  function prenatalVisitsMessage() {
    const pregnantYes = radioValue("pregnant") === "Yes";
    const prenatalYes = radioValue("prenatal_care") === "Yes";

    if (!pregnantYes || !prenatalYes) return "";

    const v = ($("prenatal_visits")?.value || "").toString().trim();
    if (v === "") return "Required when Prenatal Care is Yes.";
    if (!/^\d+$/.test(v)) return "Must be a whole number starting from 0.";

    const n = Number(v);
    if (!Number.isSafeInteger(n) || n < 0) return "Must be a whole number starting from 0.";
    return "";
  }

  function validatePrenatalVisits(options = {}) {
    const el = $("prenatal_visits");
    if (!el) return true;
    const msg = prenatalVisitsMessage();
    if (options.showErrors === false) {
      el.setCustomValidity(msg);
      if (msg === "") setFieldError(el, "");
    } else {
      setFieldError(el, msg);
    }
    return msg === "";
  }

  function timesPregnantMessage() {
    const v = ($("times_pregnant")?.value || "").toString().trim();
    if (v === "") return "";
    if (!/^\d+$/.test(v)) {
      return "Must be a whole number only.";
    }
    return "";
  }

  function validateTimesPregnant(options = {}) {
    const el = $("times_pregnant");
    if (!el) return true;
    const msg = timesPregnantMessage();
    if (options.showErrors === false) {
      el.setCustomValidity(msg);
      if (msg === "") setFieldError(el, "");
    } else {
      setFieldError(el, msg);
    }
    return msg === "";
  }

  function livingChildrenMessage() {
    const v = ($("living_children")?.value || "").toString().trim();
    if (v === "") return "";
    if (!/^\d+$/.test(v)) {
      return "Must be a whole number only.";
    }
    return "";
  }

  function validateLivingChildren(options = {}) {
    const el = $("living_children");
    if (!el) return true;
    const msg = livingChildrenMessage();
    if (options.showErrors === false) {
      el.setCustomValidity(msg);
      if (msg === "") setFieldError(el, "");
    } else {
      setFieldError(el, msg);
    }
    return msg === "";
  }

  function filterBarangayOptions(){
    return;
  }

  function importantFieldMessage(el) {
    if (!el) return "";
    if (el.id === "last_name" || el.id === "first_name") {
      if ((el.value || "").trim() === "") {
        return "Required field";
      }
    } else if (el.id === "barangay_select") {
      if (!getSelectedBarangay()) {
        return "Required field";
      }
      if (ALLOWED_BRGYS.length && !ALLOWED_BRGYS.some(b => normalize(b) === normalize(getSelectedBarangay()))) {
        return "Please select a Barangay from the list.";
      }
    } else if (el.id === "dob") {
      const raw = (el.value || "").toString().trim();
      if (raw === "") {
        return "Required field";
      } else if (!parseDateFlexible(raw)) {
        return "Please select a valid Date of Birth.";
      } else {
        const dob = parseDateFlexible(raw);
        if (dob && isFutureDate(dob)) {
          return "Date of Birth cannot be in the future.";
        }
        const age = parseInt(calcAge(raw), 10);
        if (!Number.isFinite(age) || age < 9 || age > 19) {
          return "Date of Birth must be for an adolescent age 9-19.";
        }
      }
    }
    return "";
  }

  function setImportantFieldValidity(el, options = {}) {
    if (!el) return "";
    const msg = importantFieldMessage(el);

    if (options.showErrors === false) {
      el.setCustomValidity(msg);
      if (msg === "") setFieldError(el, "");
    } else {
      setFieldError(el, msg);
    }

    return msg;
  }

  function validateImportantDetails(options = {}) {
    const importantFields = [
      $("last_name"),
      $("first_name"),
      $("barangay_select"),
      $("dob")
    ].filter(Boolean);

    const invalidFields = [];
    const messages = [];

    for (const field of importantFields) {
      const msg = setImportantFieldValidity(field, options);
      if (msg !== "") {
        invalidFields.push(field);
        messages.push(msg);
      }
    }

    if (invalidFields.length && options.focusFirst !== false) {
      focusFirstInvalid(invalidFields);
    }

    return {
      ok: invalidFields.length === 0,
      invalidFields,
      messages
    };
  }

  function validateSubmissionRequirements(options = {}) {
    const showErrors = options.showErrors !== false;
    const invalidFields = [];
    const messages = [];

    function addInvalid(field, message, summary) {
      if (!message) return;
      invalidFields.push(field);
      messages.push(summary || message);
    }

    normalizeAllowedBarangay();
    syncAddressFields();
    syncPartnerNameField();

    if ($("age")) $("age").value = calcAge($("dob")?.value || "");
    if ($("partner_age")) $("partner_age").value = calcAge($("partner_dob")?.value || "");

    const important = validateImportantDetails({ showErrors, focusFirst: false });
    invalidFields.push(...important.invalidFields);
    messages.push(...important.messages);

    [
      ["currently_enrolled", "Are you currently enrolled is required."],
      ["civil_status", "Civil Status is required."],
      ["living_situation", "Living Situation is required."],
      ["pregnant", "Currently pregnant is required."],
      ["is_4ps", "4Ps beneficiary is required."],
      ["is_ips", "IP member is required."],
      ["currently_working", "Currently working is required."]
    ].forEach(([name, summary]) => {
      const msg = validateRequiredRadio(name, { showErrors });
      if (msg) addInvalid(radioInputs(name)[0] || null, msg, summary);
    });

    const education = $("education");
    const selectedEducation = selectedEducationValue();
    addInvalid(
      radioInputs("education")[0] || education,
      setRadioGroupError("education", selectedEducation === "" ? "Required field" : "", { showErrors }),
      "Educational Attainment is required."
    );

    handleEducationChange();

    const firstAgeEl = $("age_first_pregnancy");
    if (!validateFirstPregnancyAge({ showErrors })) {
      invalidFields.push(firstAgeEl);
      messages.push(firstPregnancyAgeMessage());
    }

    const monthsEl = $("months_pregnant");
    if (!validateMonthsPregnant({ showErrors })) {
      invalidFields.push(monthsEl);
      messages.push(monthsPregnantMessage());
    }

    const prenatalVisitsEl = $("prenatal_visits");
    if (!validatePrenatalVisits({ showErrors })) {
      invalidFields.push(prenatalVisitsEl);
      messages.push(prenatalVisitsMessage());
    }

    const timesEl = $("times_pregnant");
    if (!validateTimesPregnant({ showErrors })) {
      invalidFields.push(timesEl);
      messages.push(timesPregnantMessage());
    }

    const childrenEl = $("living_children");
    if (!validateLivingChildren({ showErrors })) {
      invalidFields.push(childrenEl);
      messages.push(livingChildrenMessage());
    }

    const year = $("year_level");
    const yearText = $("year_level_text");
    const typedMode = isTypedEducationDetail(selectedEducation);
    const gradeOptions = EDUCATION_CONFIG.gradeYearOptions?.[selectedEducation] || [];
    const selectedYear = typedMode
      ? (yearText?.value || "").toString().trim()
      : (year?.value || "").toString().trim();
    const yearControl = typedMode ? yearText : year;
    if (selectedEducation && selectedEducation !== "No Education" && yearControl && selectedYear === "") {
      setControlValidity(yearControl, "Required field", { showErrors });
      invalidFields.push(yearControl);
      messages.push(typedMode ? typedEducationLabelFor(selectedEducation) + " is required." : "Grade / Year Level is required for the selected Educational Attainment.");
    } else if (!typedMode && selectedYear !== "" && !gradeOptions.includes(selectedYear)) {
      setControlValidity(year, "Please select a valid option for the selected Educational Attainment.", { showErrors });
      invalidFields.push(year);
      messages.push("Grade / Year Level must match the selected Educational Attainment.");
    } else {
      setControlValidity(year, "", { showErrors });
      setControlValidity(yearText, "", { showErrors });
    }

    const vocationalOther = $("vocational_training_other");
    setControlValidity(vocationalOther, "", { showErrors });

    const lastSchool = $("last_school_attended_type");
    addInvalid(
      lastSchool,
      setControlValidity(lastSchool, requiredControlMessage(lastSchool), { showErrors }),
      "Last school attended is required."
    );

    handleReasonNotAttendingChange();
    const reasonInputs = reasonNotAttendingInputs();
    const selectedReasons = selectedReasonNotAttendingValues();
    const currentlyEnrolledNo = radioValue("currently_enrolled") === "No";
    if (currentlyEnrolledNo) {
      const reasonMessage = selectedReasons.length ? "" : "Required field";
      addInvalid(reasonInputs[0] || null, setReasonNotAttendingGroupError(reasonMessage, { showErrors }), "Reason for not attending school is required.");
    } else {
      setReasonNotAttendingGroupError("", { showErrors });
    }

    const reasonOther = $("reason_not_attending_school_other");
    if (currentlyEnrolledNo && selectedReasons.includes("Other")) {
      addInvalid(
        reasonOther,
        setControlValidity(reasonOther, requiredControlMessage(reasonOther), { showErrors }),
        "Please specify reason for not attending school."
      );
    } else {
      setControlValidity(reasonOther, "", { showErrors });
    }

    const workingYes = radioValue("currently_working") === "Yes";
    const occupation = $("occupation");
    if (workingYes && occupation) {
      addInvalid(
        occupation,
        setControlValidity(occupation, requiredControlMessage(occupation), { showErrors }),
        "Occupation is required when Currently Working is Yes."
      );
    } else if (occupation) {
      setControlValidity(occupation, "", { showErrors });
    }

    const partnerRequired = isPartnerRequired();
    const partnerTextFields = [
      [$("partner_surname"), "Partner Surname is required."],
      [$("partner_first_name"), "Partner First Name is required."],
      [document.querySelector('input[name="partner_address"]'), "Partner Address is required."],
      [$("partner_place_of_birth"), "Partner Place of Birth is required."]
    ];

    if (partnerRequired) {
      partnerTextFields.forEach(([field, summary]) => {
        addInvalid(field, setControlValidity(field, requiredControlMessage(field), { showErrors }), summary);
      });

      const partnerDob = $("partner_dob");
      let partnerDobMsg = "";
      if (partnerDob) {
        const raw = (partnerDob.value || "").toString().trim();
        if (raw === "") {
          partnerDobMsg = "Required field";
        } else {
          const dt = parseDateFlexible(raw);
          if (!dt) partnerDobMsg = "Please select a valid Date of Birth.";
          else if (isFutureDate(dt)) partnerDobMsg = "Date of Birth cannot be in the future.";
        }
        addInvalid(partnerDob, setControlValidity(partnerDob, partnerDobMsg, { showErrors }), "Partner Date of Birth is required.");
      }

      [
        ["partner_education", "Partner Educational Attainment is required."],
        ["partner_currently_enrolled", "Partner Currently Enrolled is required."],
        ["partner_currently_working", "Partner Currently Working is required."]
      ].forEach(([name, summary]) => {
        const msg = validateRequiredRadio(name, { showErrors });
        if (msg) addInvalid(radioInputs(name)[0] || null, msg, summary);
      });

      const partnerEnrolledYes = radioValue("partner_currently_enrolled") === "Yes";
      const partnerYear = $("partner_year_level");
      const partnerEducation = selectedPartnerEducationValue();
      const partnerYearOptions = EDUCATION_CONFIG.partnerGradeYearOptions?.[partnerEducation] || [];
      const partnerYearRequired = partnerEnrolledYes && partnerEducation !== "" && partnerEducation !== "No Education" && partnerYearOptions.length > 0;
      if (partnerYearRequired && partnerYear) {
        handlePartnerEducationChange();
        addInvalid(
          partnerYear,
          setControlValidity(partnerYear, requiredControlMessage(partnerYear), { showErrors }),
          "Partner Grade/Year Level is required when Partner Currently Enrolled is Yes."
        );
      } else if (partnerYear) {
        handlePartnerEducationChange();
        setControlValidity(partnerYear, "", { showErrors });
      }

      const partnerWorkingYes = radioValue("partner_currently_working") === "Yes";
      const partnerMonthly = $("partner_monthly_income");
      const partnerOcc = $("partner_occupation");
      if (partnerWorkingYes) {
        addInvalid(
          partnerMonthly,
          setControlValidity(partnerMonthly, requiredControlMessage(partnerMonthly), { showErrors }),
          "Partner Monthly Income is required when Partner Currently Working is Yes."
        );
        addInvalid(
          partnerOcc,
          setControlValidity(partnerOcc, requiredControlMessage(partnerOcc), { showErrors }),
          "Partner Occupation is required when Partner Currently Working is Yes."
        );
      } else {
        setControlValidity(partnerMonthly, "", { showErrors });
        setControlValidity(partnerOcc, "", { showErrors });
      }
    } else {
      partnerTextFields.forEach(([field]) => setControlValidity(field, "", { showErrors }));
      setControlValidity($("partner_dob"), "", { showErrors });
      setControlValidity($("partner_year_level"), "", { showErrors });
      setControlValidity($("partner_monthly_income"), "", { showErrors });
      setControlValidity($("partner_occupation"), "", { showErrors });
      clearRadioGroupError("partner_education");
      clearRadioGroupError("partner_currently_enrolled");
      clearRadioGroupError("partner_currently_working");
    }

    const interviewedBy = $("interviewed_by");
    addInvalid(
      interviewedBy,
      setControlValidity(interviewedBy, requiredControlMessage(interviewedBy), { showErrors }),
      "Interviewed by is required."
    );

    const encodedBy = $("encoded_by");
    addInvalid(
      encodedBy,
      setControlValidity(encodedBy, requiredControlMessage(encodedBy), { showErrors }),
      "Encoded By is required."
    );

    handleGovAssistSpecs();

    if (!MUNICIPALITY) {
      messages.push("Municipality is required. Please update your Account Location first.");
    }

    if (showErrors) {
      setValidationSummary(invalidFields.length ? ["Please complete the highlighted required fields."] : []);
    }

    if (invalidFields.length && options.focusFirst !== false) {
      focusFirstInvalid(invalidFields);
    }

    return {
      ok: invalidFields.length === 0 && MUNICIPALITY !== "",
      invalidFields,
      messages
    };
  }

  function escapeHtml(value) {
    return (value ?? "").toString()
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function fieldValue(selector) {
    const el = document.querySelector(selector);
    return (el?.value || "").toString().trim();
  }

  function selectedOptionLabel(selector) {
    const el = document.querySelector(selector);
    return (el && el.selectedOptions.length) ? el.selectedOptions[0].text.trim() : "";
  }

  function partnerDisplayNameFromFields() {
    const surname = fieldValue('#partner_surname');
    const first = fieldValue('#partner_first_name');
    const middle = fieldValue('#partner_middle_name');
    const given = [first, middle].filter(Boolean).join(" ");
    if (surname && given) return surname + ", " + given;
    return [surname, given].filter(Boolean).join(" ");
  }

  function syncPartnerNameField() {
    const hidden = $("partner_name");
    if (!hidden) return;
    const composed = partnerDisplayNameFromFields();
    if (composed !== "") {
      hidden.value = composed;
    }
  }

  function radioValue(name) {
    return document.querySelector('input[name="' + name + '"]:checked')?.value || "";
  }

  function checkedValues(name) {
    return Array.from(document.querySelectorAll('input[name="' + name + '"]:checked')).map(el => el.value);
  }

  function valueOrBlank(value) {
    const v = (value || "").toString().trim();
    return v === "" ? "Blank" : v;
  }

  function selectedGovAssistanceReview() {
    const selected = checkedValues("gov_assistance[]");
    if (!selected.length) return "Blank";

    return selected.map(label => {
      const checkbox = Array.from(document.querySelectorAll(".gov-assist-chk"))
        .find(chk => chk.value === label);
      const specId = checkbox?.getAttribute("data-spec-id") || "";
      const detail = specId ? (document.getElementById(specId)?.value || "").trim() : "";
      return detail ? label + ": " + detail : label;
    }).join("; ");
  }

  function reviewItem(label, value) {
    return (
      '<div class="review-item">' +
        '<span class="review-label">' + escapeHtml(label) + '</span>' +
        '<span class="review-value">' + escapeHtml(valueOrBlank(value)) + '</span>' +
      '</div>'
    );
  }

  function reviewSection(title, items) {
    return (
      '<div class="review-section">' + escapeHtml(title) + '</div>' +
      '<div class="review-grid">' + items.join("") + '</div>'
    );
  }

  function buildReviewHtml() {
    syncPartnerNameField();
    const partnerVisible = !$("partnerSection")?.classList.contains("is-hidden");
    const educationValue = selectedEducationValue();
    const gradeYearLabel = typedEducationLabelFor(educationValue);
    const educationItems = [
      reviewItem("Education", fieldValue('#education')),
      reviewItem(gradeYearLabel, fieldValue('#grade_year_level_hidden') || "Not applicable"),
      reviewItem("Last School Attended", fieldValue('#last_school_attended_type')),
      reviewItem("Currently Enrolled", radioValue("currently_enrolled"))
    ];

    if (radioValue("currently_enrolled") === "No") {
      const selectedReasons = selectedReasonNotAttendingValues();
      educationItems.push(reviewItem("Reason Not Attending School", selectedReasons.join("; ")));
      if (selectedReasons.includes("Other")) {
        educationItems.push(reviewItem("Other Reason", fieldValue('#reason_not_attending_school_other')));
      }
    }

    const sections = [
      reviewSection("Identifying Information", [
        reviewItem("Surname", fieldValue('#last_name')),
        reviewItem("First Name", fieldValue('#first_name')),
        reviewItem("Middle Name", fieldValue('#middle_name')),
        reviewItem("Municipality", MUNICIPALITY),
        reviewItem("Barangay", getSelectedBarangay()),
        reviewItem("Date of Birth", fieldValue('#dob')),
        reviewItem("Current Age", fieldValue('#age')),
        reviewItem("Place of Birth", fieldValue('#place_of_birth'))
      ]),
      reviewSection("Education, Civil Status, and Living Situation", educationItems.concat([
        reviewItem("Civil Status", radioValue("civil_status")),
        reviewItem("Living Situation", radioValue("living_situation")),
        reviewItem("Living with Others Specify", fieldValue('#living_others'))
      ])),
      reviewSection("Fertility", [
        reviewItem("Currently Pregnant", radioValue("pregnant")),
        reviewItem("Months Pregnant", fieldValue('#months_pregnant')),
        reviewItem("Prenatal Care", radioValue("prenatal_care")),
        reviewItem("Prenatal Visits", radioValue("prenatal_care") === "Yes" ? fieldValue('#prenatal_visits') : "Not applicable"),
        reviewItem("Age at First Pregnancy", fieldValue('#age_first_pregnancy')),
        reviewItem("Times Pregnant", fieldValue('input[name="times_pregnant"]')),
        reviewItem("Living Children", fieldValue('#living_children')),
        reviewItem("Child Under 1 Year Old", radioValue("child_under_one")),
        reviewItem("Family Planning", selectedOptionLabel('#family_planning'))
      ]),
      reviewSection("Programs and Work", [
        reviewItem("4Ps Beneficiary", radioValue("is_4ps")),
        reviewItem("IP Member", radioValue("is_ips")),
        reviewItem("Ethnicity", fieldValue('#ethnicity')),
        reviewItem("Currently Working", radioValue("currently_working")),
        reviewItem("Monthly Income", fieldValue('#monthly_income')),
        reviewItem("Occupation", fieldValue('#occupation')),
        reviewItem("Government Assistance", selectedGovAssistanceReview())
      ])
    ];

    if (partnerVisible) {
      sections.push(reviewSection("Partner Information", [
        reviewItem("Partner Surname", fieldValue('#partner_surname')),
        reviewItem("Partner First Name", fieldValue('#partner_first_name')),
        reviewItem("Partner Middle Name", fieldValue('#partner_middle_name')),
        reviewItem("Partner Address", fieldValue('input[name="partner_address"]')),
        reviewItem("Partner Place of Birth", fieldValue('#partner_place_of_birth')),
        reviewItem("Partner Date of Birth", fieldValue('#partner_dob')),
        reviewItem("Partner Age", fieldValue('#partner_age')),
        reviewItem("Partner Education", radioValue("partner_education")),
        reviewItem("Partner Currently Enrolled", radioValue("partner_currently_enrolled")),
        reviewItem("Partner Grade/Year Level", fieldValue('#partner_year_level')),
        reviewItem("Partner Working", radioValue("partner_currently_working")),
        reviewItem("Partner Monthly Income", fieldValue('#partner_monthly_income')),
        reviewItem("Partner Occupation", fieldValue('#partner_occupation'))
      ]));
    }

    sections.push(reviewSection("Interview", [
      reviewItem("Other Observations", fieldValue('textarea[name="other_observations"]')),
      reviewItem("Interviewed By", fieldValue('input[name="interviewed_by"]')),
      reviewItem("Interview Date", fieldValue('input[name="interview_date"]')),
      reviewItem("Noted By", fieldValue('input[name="noted_by"]')),
      reviewItem("Noted Date", fieldValue('input[name="noted_date"]')),
      reviewItem("Encoded By", fieldValue('input[name="encoded_by"]')),
      reviewItem("Date Encoded", fieldValue('input[name="date_encoded"]'))
    ]));

    return sections.join("");
  }

  function openReviewModal() {
    const content = $("reviewContent");
    const modal = $("reviewModal");
    if (!content || !modal) return;
    content.innerHTML = buildReviewHtml();
    modal.classList.add("is-open");
    modal.setAttribute("aria-hidden", "false");
    $("reviewEditBtn")?.focus();
  }

  function closeReviewModal() {
    const modal = $("reviewModal");
    if (!modal) return;
    modal.classList.remove("is-open");
    modal.setAttribute("aria-hidden", "true");
    $("submitBtnMain")?.focus();
  }

  function bindImmediateRadioHandling(){
    document.querySelectorAll('input[type="radio"]').forEach(radio => {
      if (radio.dataset.immediateBound === "1") return;
      radio.dataset.immediateBound = "1";
      radio.addEventListener("change", function(){
        refreshChipStates();

        if (this.name === "currently_enrolled") handleCurrentlyEnrolledChange();
        if (this.name === "partner_currently_enrolled") handlePartnerCurrentlyEnrolledChange();
        if (this.name === "living_situation") handleLivingSituationGate();
        if (this.name === "is_ips") handleIPsGate();
        if (this.name === "currently_working") handleWorkingGate();
        if (this.name === "partner_currently_working") handlePartnerWorkingGate();
        if (this.name === "civil_status") handleCivilStatusPartnerGate();
        if (this.name === "pregnant") {
          handleFertilityGate();
          handlePrenatalCareGate();
        }
        if (this.name === "prenatal_care") handlePrenatalCareGate();
        refreshValidationAfterUserEdit();
      });
      radio.addEventListener("click", function(){
        if (this.name === "currently_enrolled") handleCurrentlyEnrolledChange();
      });
    });
  }

  function applyDuplicateAddBlock() {
    const form = $("amForm");
    if (!form || !DUPLICATE_BLOCK) return;

    const keepEnabledNames = new Set([
      "id",
      "adolescent_id",
      "csrf_token",
      "edit_latest",
      "locked_municipality",
      "locked_barangay",
      "present_address",
      "barangay"
    ]);

    const allFields = form.querySelectorAll("input, select, textarea, button");

    allFields.forEach(function(el) {
      if (!el) return;
      if (el.name && keepEnabledNames.has(el.name)) return;
      if (el.id === "submitBtnMain") return;

      if (el.tagName === "SELECT") {
        el.disabled = true;
        return;
      }

      if (el.type === "radio" || el.type === "checkbox") {
        el.disabled = true;
        return;
      }

      if (el.tagName === "BUTTON") {
        el.disabled = true;
        return;
      }

      if (el.id !== "age" && el.id !== "partner_age") {
        el.readOnly = true;
      }
    });
  }

  document.addEventListener("input", function(e){
    if (!e.target) return;
    toUppercaseValue(e.target);

    if ((e.target.id === "dob" || e.target.id === "partner_dob") && (e.target.type || "").toLowerCase() !== "date") {
      autoSlashMMDDYYYY(e.target);
    }

    if (e.target.id === "dob") {
      if ($("age")) $("age").value = calcAge(e.target.value);
      validateFirstPregnancyAge({ showErrors: validationAttempted });
    }
    if (e.target.id === "partner_dob") {
      if ($("partner_age")) $("partner_age").value = calcAge(e.target.value);
    }

    if (e.target.id === "last_name" || e.target.id === "first_name" || e.target.id === "middle_name") {
      syncNameFields();
    }
    if (e.target.id === "partner_surname" || e.target.id === "partner_first_name" || e.target.id === "partner_middle_name") {
      syncPartnerNameField();
    }

    if (e.target.id === "last_name" || e.target.id === "first_name" || e.target.id === "dob") {
      setImportantFieldValidity(e.target, { showErrors: validationAttempted });
    }

    if (e.target.id === "age_first_pregnancy") validateFirstPregnancyAge({ showErrors: validationAttempted });
    if (e.target.id === "months_pregnant") validateMonthsPregnant({ showErrors: validationAttempted });
    if (e.target.id === "prenatal_visits") {
      e.target.value = (e.target.value || "").toString().replace(/\D+/g, "");
      validatePrenatalVisits({ showErrors: validationAttempted });
    }
    if (e.target.id === "times_pregnant") validateTimesPregnant({ showErrors: validationAttempted });
    if (e.target.id === "living_children") validateLivingChildren({ showErrors: validationAttempted });
    if (e.target.id === "year_level_text") syncEducationHiddenFields();
    if (e.target.id === "barangay_select") {
      setImportantFieldValidity(e.target, { showErrors: validationAttempted });
      syncAddressFields();
    }
    if (e.target.classList && e.target.classList.contains("gov-spec")) handleGovAssistSpecs();
    if (e.target.classList && e.target.classList.contains("money")) moneyFormat(e.target);
    refreshValidationAfterUserEdit();
  });

  document.addEventListener("change", function(e){
    if (!e.target) return;

    if (e.target.matches("input[type=radio]")) refreshChipStates();
    if (e.target.id === "barangay_select") {
      normalizeAllowedBarangay();
      syncAddressFields();
      setImportantFieldValidity(e.target, { showErrors: validationAttempted });
    }
    if (e.target.id === "dob") setImportantFieldValidity(e.target, { showErrors: validationAttempted });
    if (e.target.id === "education" || e.target.name === "education") handleEducationChange({ clearIncompatible: true });
    if (e.target.name === "partner_education") handlePartnerEducationChange({ clearIncompatible: true });
    if (e.target.name === "partner_currently_enrolled") handlePartnerCurrentlyEnrolledChange();
    if (e.target.id === "year_level" || e.target.id === "year_level_text") {
      syncEducationHiddenFields();
      handleVocationalOtherChange();
    }
    if (e.target.classList && e.target.classList.contains("reason-not-attending-chk")) handleReasonNotAttendingChange();
    if (e.target.classList && e.target.classList.contains("gov-assist-chk")) handleGovAssistSpecs();
    if (e.target.id === "age_first_pregnancy") validateFirstPregnancyAge({ showErrors: validationAttempted });
    if (e.target.id === "months_pregnant") validateMonthsPregnant({ showErrors: validationAttempted });
    if (e.target.id === "prenatal_visits") validatePrenatalVisits({ showErrors: validationAttempted });
    if (e.target.id === "times_pregnant") validateTimesPregnant({ showErrors: validationAttempted });
    if (e.target.id === "living_children") validateLivingChildren({ showErrors: validationAttempted });
    if (e.target.name === "pregnant") {
      handleFertilityGate();
      handlePrenatalCareGate();
      validateMonthsPregnant({ showErrors: validationAttempted });
      validatePrenatalVisits({ showErrors: validationAttempted });
      validateTimesPregnant({ showErrors: validationAttempted });
      validateLivingChildren({ showErrors: validationAttempted });
    }
    if (e.target.name === "prenatal_care") {
      handlePrenatalCareGate();
      validatePrenatalVisits({ showErrors: validationAttempted });
    }
    if (e.target.classList && e.target.classList.contains("money")) moneyFormat(e.target);
    refreshValidationAfterUserEdit();
  });

  document.addEventListener("blur", function(e){
    if (!e.target) return;

    if (e.target.id === "dob" || e.target.id === "partner_dob") {
      normalizeDOBField(e.target);
      if (e.target.id === "dob" && $("age")) $("age").value = calcAge(e.target.value);
      if (e.target.id === "partner_dob" && $("partner_age")) $("partner_age").value = calcAge(e.target.value);
    }

    if (e.target.id === "last_name" || e.target.id === "first_name" || e.target.id === "barangay_select" || e.target.id === "dob") {
      setImportantFieldValidity(e.target, { showErrors: validationAttempted });
    }
    refreshValidationAfterUserEdit();

  }, true);

  document.addEventListener("invalid", function(e){
    if (!e.target) return;
    if (e.target.id === "last_name" || e.target.id === "first_name" || e.target.id === "barangay_select" || e.target.id === "dob") {
      setImportantFieldValidity(e.target, { showErrors: validationAttempted });
    }
  }, true);

  function canUseDraftStorage() {
    if (!DRAFT_ENABLED || !window.localStorage) return false;
    try {
      const probe = "__ahdis_draft_probe__";
      localStorage.setItem(probe, "1");
      localStorage.removeItem(probe);
      return true;
    } catch (e) {
      return false;
    }
  }

  function isSensitiveDraftField(el) {
    const type = (el.type || "").toLowerCase();
    const name = (el.name || "").toLowerCase();
    const id = (el.id || "").toLowerCase();
    if (!el.name) return true;
    if (["password", "file", "submit", "button", "reset", "hidden"].includes(type)) return true;
    if (["csrf_token", "review_confirmed"].includes(name)) return true;
    return /(password|passwd|passcode|username|user_name|email|credential|token)/.test(name + " " + id);
  }

  function getDraftFields() {
    if (!form) return [];
    return Array.from(form.elements).filter(el => {
      if (!el || isSensitiveDraftField(el)) return false;
      if (el.dataset && el.dataset.draftExclude === "1") return false;
      if (el.disabled && !(el.classList && el.classList.contains("gov-spec"))) return false;
      return true;
    });
  }

  function collectDraftFields() {
    const fields = {};
    getDraftFields().forEach(el => {
      const name = el.name;
      const type = (el.type || "").toLowerCase();
      if (type === "radio") {
        if (el.checked) fields[name] = el.value;
        else if (!(name in fields)) fields[name] = "";
        return;
      }
      if (type === "checkbox") {
        if (!Array.isArray(fields[name])) fields[name] = [];
        if (el.checked) fields[name].push(el.value);
        return;
      }
      fields[name] = el.value || "";
    });
    return fields;
  }

  function initializeDraftBaseline() {
    if (!form) return;
    draftInitialFields = collectDraftFields();
  }

  function normalizeDraftValue(value) {
    if (Array.isArray(value)) {
      return value.map(v => (v == null ? "" : String(v).trim())).filter(Boolean).sort();
    }
    return (value == null ? "" : String(value)).trim();
  }

  function draftValuesEqual(a, b) {
    const aa = normalizeDraftValue(a);
    const bb = normalizeDraftValue(b);
    if (Array.isArray(aa) || Array.isArray(bb)) {
      if (!Array.isArray(aa) || !Array.isArray(bb) || aa.length !== bb.length) return false;
      return aa.every((v, i) => v === bb[i]);
    }
    return aa === bb;
  }

  function hasMeaningfulDraftData(draft) {
    const fields = draft?.fields;
    if (!fields || typeof fields !== "object" || Array.isArray(fields)) return false;

    const baseline = draftInitialFields || {};
    return Object.keys(fields).some(name => {
      const value = normalizeDraftValue(fields[name]);
      const hasValue = Array.isArray(value) ? value.length > 0 : value !== "";
      if (!hasValue) return false;
      return !draftValuesEqual(fields[name], baseline[name]);
    });
  }

  function collectDraftPayload() {
    if (!form) return null;
    syncNameFields();
    syncAddressFields();
    syncPartnerNameField();

    return {
      version: DRAFT_VERSION,
      formId: DRAFT_FORM_ID,
      updatedAt: Date.now(),
      path: location.pathname,
      fields: collectDraftFields()
    };
  }

  function saveAddProfileDraft() {
    if (!canUseDraftStorage() || reviewConfirmed) return;
    const payload = collectDraftPayload();
    if (!payload) return;
    try {
      if (hasMeaningfulDraftData(payload)) {
        localStorage.setItem(DRAFT_KEY, JSON.stringify(payload));
      } else {
        localStorage.removeItem(DRAFT_KEY);
      }
    } catch (e) {}
  }

  function saveDraftNow() {
    saveAddProfileDraft();
  }

  function scheduleDraftSave() {
    if (!canUseDraftStorage() || reviewConfirmed) return;
    clearTimeout(draftSaveTimer);
    draftSaveTimer = setTimeout(saveDraftNow, 250);
  }

  function clearAddProfileDraft() {
    if (!window.localStorage) return;
    try {
      localStorage.removeItem(DRAFT_KEY);
    } catch (e) {}
  }

  function clearDraft() {
    clearAddProfileDraft();
  }

  function readDraftPayload() {
    if (!canUseDraftStorage()) return null;
    try {
      const raw = localStorage.getItem(DRAFT_KEY);
      if (!raw) return null;
      const payload = JSON.parse(raw);
      if (
        !payload ||
        payload.version !== DRAFT_VERSION ||
        payload.formId !== DRAFT_FORM_ID ||
        (payload.path && payload.path !== location.pathname) ||
        !payload.fields ||
        typeof payload.fields !== "object" ||
        !hasMeaningfulDraftData(payload)
      ) {
        clearAddProfileDraft();
        return null;
      }
      return payload;
    } catch (e) {
      clearAddProfileDraft();
      return null;
    }
  }

  function setFieldValueFromDraft(el, fields) {
    if (!el || isSensitiveDraftField(el)) return;
    const name = el.name;
    const type = (el.type || "").toLowerCase();
    if (!(name in fields)) return;

    if (type === "radio") {
      el.checked = String(fields[name]) === String(el.value);
      return;
    }
    if (type === "checkbox") {
      const values = Array.isArray(fields[name]) ? fields[name].map(String) : [String(fields[name])];
      el.checked = values.includes(String(el.value));
      return;
    }
    el.value = fields[name] == null ? "" : String(fields[name]);
  }

  function refreshAfterDraftRestore() {
    normalizeAllowedBarangay();
    normalizeDOBField($("dob"));
    normalizeDOBField($("partner_dob"));
    if ($("age")) $("age").value = calcAge($("dob")?.value || "");
    if ($("partner_age")) $("partner_age").value = calcAge($("partner_dob")?.value || "");
    document.querySelectorAll(".money").forEach(moneyFormat);
    restoreNamePartsFromHiddenIfNeeded();
    document.querySelectorAll('[data-uppercase="1"]').forEach(toUppercaseValue);
    syncNameFields();
    syncPartnerNameField();
    bindImmediateRadioHandling();
    refreshChipStates();
    handleEducationChange();
    handleCurrentlyEnrolledChange();
    handleReasonNotAttendingChange();
    handleVocationalOtherChange();
    handlePartnerCurrentlyEnrolledChange();
    handleCivilStatusPartnerGate();
    handleFertilityGate();
    handlePrenatalCareGate();
    handleLivingSituationGate();
    handleIPsGate();
    handleWorkingGate();
    handlePartnerWorkingGate();
    handleGovAssistSpecs();
    syncAddressFields();
    validateFirstPregnancyAge({ showErrors: false });
    validatePrenatalVisits({ showErrors: false });
    validateSubmissionRequirements({ showErrors: false, focusFirst: false });
  }

  function restoreAddProfileDraft(payload) {
    if (!payload || !payload.fields || !form) return;
    getDraftFields().forEach(el => setFieldValueFromDraft(el, payload.fields));
    refreshAfterDraftRestore();
    scheduleDraftSave();
  }

  function restoreDraft(payload) {
    restoreAddProfileDraft(payload);
  }

  function clearVisibleDraftFields() {
    if (!form) return;
    getDraftFields().forEach(el => {
      const type = (el.type || "").toLowerCase();
      if (type === "hidden") return;
      if (type === "radio" || type === "checkbox") {
        el.checked = false;
      } else {
        el.value = "";
      }
    });
    refreshAfterDraftRestore();
  }

  function closeDraftPrompt() {
    const modal = $("draftModal");
    if (!modal) return;
    modal.classList.remove("is-open");
    modal.setAttribute("aria-hidden", "true");
  }

  function openDraftPromptIfAvailable() {
    const payload = readDraftPayload();
    const modal = $("draftModal");
    if (!payload || !modal || DUPLICATE_BLOCK) return false;
    draftPromptPayload = payload;
    const savedAt = $("draftSavedAt");
    if (savedAt) {
      const savedAtRow = savedAt.closest(".review-item");
      const rawTime = payload.updatedAt || payload.savedAt || "";
      const when = rawTime ? new Date(rawTime) : null;
      if (when && !isNaN(when.getTime())) {
        savedAt.textContent = "Last saved: " + when.toLocaleString();
        savedAt.style.display = "";
        if (savedAtRow) savedAtRow.style.display = "";
      } else {
        savedAt.textContent = "";
        savedAt.style.display = "none";
        if (savedAtRow) savedAtRow.style.display = "none";
      }
    }
    modal.classList.add("is-open");
    modal.setAttribute("aria-hidden", "false");
    $("draftContinueBtn")?.focus();
    return true;
  }

  if (form) {
    form.addEventListener("input", scheduleDraftSave);
    form.addEventListener("change", scheduleDraftSave);

    form.addEventListener("submit", function(e){
      if (DUPLICATE_BLOCK) {
        e.preventDefault();
        let msg = "This file or name already existed in the database. Please open the existing profile instead of creating a new one.";
        if (DUPLICATE_REASON === "possible_duplicate_name" || DUPLICATE_REASON === "possible_duplicate") {
          msg = "Possible duplicate detected. This file or name already existed. Please verify and open the existing profile first.";
        }
        alert(msg);
        if (DUPLICATE_AID > 0) {
          window.location.href = ADOL_VIEW_URL + "?adolescent_id=" + encodeURIComponent(String(DUPLICATE_AID));
        }
        return;
      }

      validationAttempted = true;
      syncPartnerNameField();
      const validation = validateSubmissionRequirements({ showErrors: true, focusFirst: !reviewConfirmed });
      if (!validation.ok) {
        e.preventDefault();
        return;
      }

      if (!reviewConfirmed) {
        e.preventDefault();
        if ($("review_confirmed")) $("review_confirmed").value = "0";
        openReviewModal();
        return;
      }

      if ($("review_confirmed")) $("review_confirmed").value = "1";
      clearDraft();
      return;

      const ageOk = validateAgeAdolescent();
      if (!ageOk) {
        e.preventDefault();
        const n = getAgeValue();
        alert(
          "Age validation failed.\n\n" +
          "This profiling form is for adolescent mothers age 9–19 only.\n" +
          "Current computed age: " + (n === null ? "N/A" : n) + "\n\n" +
          "Please correct Date of Birth."
        );
        $("dob")?.focus();
        return;
      }

      if (!validateFirstPregnancyAge()) {
        e.preventDefault();
        $("age_first_pregnancy")?.focus();
        return;
      }

      if (!validateGovAssistDetails()) {
        e.preventDefault();
        return;
      }
    });

    $("reviewEditBtn")?.addEventListener("click", function(){
      reviewConfirmed = false;
      if ($("review_confirmed")) $("review_confirmed").value = "0";
      closeReviewModal();
    });

    $("reviewConfirmBtn")?.addEventListener("click", function(){
      validationAttempted = true;
      syncPartnerNameField();
      const validation = validateSubmissionRequirements({ showErrors: true, focusFirst: false });
      if (!validation.ok) {
        reviewConfirmed = false;
        if ($("review_confirmed")) $("review_confirmed").value = "0";
        closeReviewModal();
        focusFirstInvalid(validation.invalidFields);
        return;
      }

      reviewConfirmed = true;
      if ($("review_confirmed")) $("review_confirmed").value = "1";
      clearDraft();

      if (typeof form.requestSubmit === "function") {
        form.requestSubmit();
      } else {
        form.submit();
      }
    });

    $("draftContinueBtn")?.addEventListener("click", function(){
      closeDraftPrompt();
      restoreDraft(draftPromptPayload);
    });

    $("draftDiscardBtn")?.addEventListener("click", function(){
      closeDraftPrompt();
      clearDraft();
      clearVisibleDraftFields();
    });

    window.addEventListener("beforeunload", function(){
      saveDraftNow();
    });
  }

  $("reviewModal")?.addEventListener("click", function(e){
    if (e.target === this) closeReviewModal();
  });

  $("draftModal")?.addEventListener("click", function(e){
    if (e.target === this) closeDraftPrompt();
  });

  document.addEventListener("keydown", function(e){
    if (e.key === "Escape" && $("reviewModal")?.classList.contains("is-open")) {
      closeReviewModal();
    }
    if (e.key === "Escape" && $("draftModal")?.classList.contains("is-open")) {
      closeDraftPrompt();
    }
  });

  window.addEventListener("load", function(){
    normalizeDOBField($("dob"));
    normalizeDOBField($("partner_dob"));

    if ($("age")) $("age").value = calcAge($("dob")?.value || "");
    if ($("partner_age")) $("partner_age").value = calcAge($("partner_dob")?.value || "");

    document.querySelectorAll(".money").forEach(moneyFormat);

    if (PREF_BRGY) {
      const p = normalize(PREF_BRGY);
      const sel = $("barangay_select");
      if (sel) {
        const found = ALLOWED_BRGYS.find(b => normalize(b) === p);
        if (found) sel.value = found;
      }
    }

    restoreNamePartsFromHiddenIfNeeded();
    document.querySelectorAll('[data-uppercase="1"]').forEach(toUppercaseValue);
    syncNameFields();
    syncPartnerNameField();
    bindImmediateRadioHandling();
    refreshChipStates();
    handleEducationChange();
    handleCurrentlyEnrolledChange();
    handleReasonNotAttendingChange();
    handleVocationalOtherChange();
    handlePartnerCurrentlyEnrolledChange();
    handleCivilStatusPartnerGate();
    handleFertilityGate();
    handlePrenatalCareGate();
    handleLivingSituationGate();
    handleIPsGate();
    handleWorkingGate();
    handlePartnerWorkingGate();
    handleGovAssistSpecs();
    initializeDraftBaseline();
    openDraftPromptIfAvailable();
    validateFirstPregnancyAge({ showErrors: false });
    validatePrenatalVisits({ showErrors: false });
    validateSubmissionRequirements({ showErrors: false, focusFirst: false });
    syncAddressFields();
    applyDuplicateAddBlock();
  });
})();
</script>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
