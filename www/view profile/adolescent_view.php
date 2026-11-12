<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/education_options.php";
require_once __DIR__ . "/../includes/header.php";
require_once __DIR__ . "/../includes/sidebar.php";

if (!($conn instanceof PDO)) {
  http_response_code(500);
  die("DB not available.");
}
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* =========================
   Helpers (NO redeclare)
   ========================= */
if (!function_exists('h')) {
  function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('baseUrl')) {
  function baseUrl(): string {
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $dir = preg_replace('#/(auth|config|dashboard|profiling|reports|includes|view profile)$#', '', $dir);
    return $dir ?: '';
  }
}
if (!function_exists('am_tableExists')) {
  function am_tableExists(PDO $conn, string $table): bool {
    $st = $conn->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:t LIMIT 1");
    $st->execute([':t' => $table]);
    return (bool)$st->fetchColumn();
  }
}
if (!function_exists('am_hasColumn')) {
  function am_hasColumn(PDO $conn, string $table, string $col): bool {
    try {
      if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;
      $col = trim($col);
      if ($col === '') return false;
      $st = $conn->query("PRAGMA table_info($table)");
      $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
      foreach ($rows as $r) {
        $name = (string)($r['name'] ?? '');
        if ($name !== '' && strcasecmp($name, $col) === 0) return true;
      }
      return false;
    } catch (Throwable $e) {
      return false;
    }
  }
}
if (!function_exists('am_safeJsonDecodeArray')) {
  function am_safeJsonDecodeArray(string $json): array {
    $json = trim($json);
    if ($json === '') return [];
    $d = json_decode($json, true);
    return is_array($d) ? $d : [];
  }
}
if (!function_exists('am_pick')) {
  function am_pick(array $data, array $keys, string $default = ''): string {
    foreach ($keys as $k) {
      if (array_key_exists($k, $data) && $data[$k] !== null && (string)$data[$k] !== '') {
        return (string)$data[$k];
      }
    }
    return $default;
  }
}
if (!function_exists('am_parseDate')) {
  function am_parseDate(?string $s): ?DateTimeImmutable {
    $s = trim((string)$s);
    if ($s === '') return null;

    $manila = new DateTimeZone('Asia/Manila');

    $hasTimezoneInfo = (bool) preg_match('/(?:Z|[+\-]\d{2}:\d{2}|[+\-]\d{4})$/', $s);

    if ($hasTimezoneInfo) {
      try {
        $dt = new DateTimeImmutable($s);
        return $dt->setTimezone($manila);
      } catch (Throwable $e) {
      }
    }

    $dateOnlyFormats = [
      'Y-m-d',
      'm/d/Y',
      'd/m/Y',
    ];

    foreach ($dateOnlyFormats as $f) {
      $dt = DateTimeImmutable::createFromFormat('!' . $f, $s, $manila);
      if ($dt instanceof DateTimeImmutable) {
        return $dt->setTimezone($manila);
      }
    }

    $dateTimeFormats = [
      'Y-m-d H:i:s',
      'Y-m-d H:i',
      'm/d/Y H:i:s',
      'm/d/Y H:i',
      'd/m/Y H:i:s',
      'd/m/Y H:i',
      DateTimeInterface::ATOM,
    ];

    foreach ($dateTimeFormats as $f) {
      $dtLocal = DateTimeImmutable::createFromFormat($f, $s, $manila);
      if ($dtLocal instanceof DateTimeImmutable) {
        // Saved timestamps in this app are timezone-less Manila local time.
        // Only strings with an explicit timezone suffix should be converted.
        return $dtLocal->setTimezone($manila);
      }
    }

    try {
      $dt = new DateTimeImmutable($s, $manila);
      return $dt->setTimezone($manila);
    } catch (Throwable $e) {
      return null;
    }
  }
}
if (!function_exists('am_ageAt')) {
  function am_ageAt(?string $dob, ?string $atDate): string {
    $d1 = am_parseDate($dob);
    $d2 = am_parseDate($atDate);
    if (!$d1 || !$d2) return '';
    if ($d2 < $d1) return '';
    return (string)$d1->diff($d2)->y;
  }
}

/* =========================
   UI helpers (guarded)
   ========================= */
if (!function_exists('am_fmtDateHuman')) {
  function am_fmtDateHuman(?string $s): string {
    $dt = am_parseDate($s);
    return $dt ? $dt->setTimezone(new DateTimeZone('Asia/Manila'))->format('M d, Y') : (trim((string)$s) !== '' ? trim((string)$s) : '—');
  }
}
if (!function_exists('am_fmtDateTimeHuman')) {
  function am_fmtDateTimeHuman(?string $s): string {
    $dt = am_parseDate($s);
    return $dt ? $dt->setTimezone(new DateTimeZone('Asia/Manila'))->format('M d, Y • h:i A') : (trim((string)$s) !== '' ? trim((string)$s) : '—');
  }
}
if (!function_exists('am_fmtEncodedDisplayHuman')) {
  function am_fmtEncodedDisplayHuman(?string $createdAt, ?string $updatedAt, ?string $latestEncounterDate): string {
    $createdAt = trim((string)$createdAt);
    $updatedAt = trim((string)$updatedAt);
    $latestEncounterDate = trim((string)$latestEncounterDate);

    $preferred = $updatedAt !== '' ? $updatedAt : $createdAt;

    if ($preferred !== '') {
      $dt = am_parseDate($preferred);
      if ($dt instanceof DateTimeImmutable) {
        return $dt->setTimezone(new DateTimeZone('Asia/Manila'))->format('M d, Y');
      }
      return $preferred;
    }

    if ($latestEncounterDate !== '') {
      $dt = am_parseDate($latestEncounterDate);
      if ($dt instanceof DateTimeImmutable) {
        return $dt->setTimezone(new DateTimeZone('Asia/Manila'))->format('M d, Y');
      }
      return $latestEncounterDate;
    }

    return '—';
  }
}
if (!function_exists('am_statusBadge')) {
  function am_statusBadge(string $status): array {
    $s = strtolower(trim($status));
    if ($s === 'approved' || $s === 'validated') return ['success', 'Approved'];
    if ($s === 'rejected') return ['danger', 'Rejected'];
    if ($s === 'draft') return ['secondary', 'Draft'];
    if ($s === 'pending') return ['warning text-dark', 'Pending'];
    return ['primary', 'Submitted'];
  }
}

/* =========================
   URLs
   ========================= */
$moduleBase  = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$moduleBase  = ($moduleBase === '/' ? '' : $moduleBase);
$root        = preg_replace('#/view profile$#', '', $moduleBase) ?: '';
$DASH_URL    = $root . "/dashboard/index.php";
$LIST_URL    = $moduleBase . "/view.php";
$ADD_URL     = $root . "/profiling/add.php";
$DETAILS_URL = $moduleBase . "/encounter_view.php";

/* =========================
   Resolve request
   ========================= */
$adolescentId = (int)($_GET['adolescent_id'] ?? 0);
$legacyId     = (int)($_GET['id'] ?? 0);

$hasAdolescents = am_tableExists($conn, 'adolescents');
$hasEncounters  = am_tableExists($conn, 'encounters');
$hasLegacy      = am_tableExists($conn, 'adolescent_mothers');
$hasUsers       = am_tableExists($conn, 'users');

if ($adolescentId <= 0 && $legacyId <= 0) {
  http_response_code(400);
  echo "<div class='content' style='padding:16px;'><h3>Missing/invalid ID</h3>
        <p>Use <code>?adolescent_id=</code> or legacy <code>?id=</code>.</p></div>";
  require_once __DIR__ . "/../includes/footer.php";
  exit;
}

/* =========================
   If legacy id provided, load that row first (and resolve adolescent_id if present)
   ========================= */
$legacyRow = null;

if ($adolescentId <= 0 && $legacyId > 0) {
  if (!$hasLegacy) {
    http_response_code(500);
    echo "<div class='content' style='padding:16px;'><h3>Setup needed</h3>
          <p>Table <code>adolescent_mothers</code> not found.</p></div>";
    require_once __DIR__ . "/../includes/footer.php";
    exit;
  }

  $st = $conn->prepare("SELECT * FROM adolescent_mothers WHERE id = :id LIMIT 1");
  $st->execute([':id' => $legacyId]);
  $legacyRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;

  if (!$legacyRow) {
    http_response_code(404);
    echo "<div class='content' style='padding:16px;'><h3>Not found</h3>
          <p>Legacy record not found.</p></div>";
    require_once __DIR__ . "/../includes/footer.php";
    exit;
  }

  $adolescentId = (int)($legacyRow['adolescent_id'] ?? 0);
}

/* =========================
   Profile
   ========================= */
$profile = [
  'display_name'    => '',
  'date_of_birth'   => '',
  'barangay'        => '',
  'municipality'    => '',
  'present_address' => '',
  'educational_attainment' => '',
  'currently_enrolled' => '',
  'grade_year_level' => '',
  'last_school_attended_type' => '',
  'reason_not_attending_school' => '',
  'reason_for_not_attending_school' => '',
  'reason_not_attending_school_other' => '',
  'vocational_training_other' => '',
  'created_at'      => '',
  'updated_at'      => '',
];

/* NEW profile */
if ($hasAdolescents && $adolescentId > 0) {
  $stA = $conn->prepare("
    SELECT
      id, last_name, first_name, middle_name, full_name,
      date_of_birth,
      barangay, municipality,
      present_address,
      educational_attainment,
      currently_enrolled,
      grade_year_level,
      last_school_attended_type,
      reason_not_attending_school,
      reason_for_not_attending_school,
      reason_not_attending_school_other,
      vocational_training_other,
      created_at, updated_at
    FROM adolescents
    WHERE id = :id
    LIMIT 1
  ");
  $stA->execute([':id' => $adolescentId]);
  $a = $stA->fetch(PDO::FETCH_ASSOC) ?: null;

  if ($a) {
    $fullName = trim((string)($a['full_name'] ?? ''));
    if ($fullName === '') {
      $fullName = trim(
        (string)($a['first_name'] ?? '') . ' ' .
        (string)($a['middle_name'] ?? '') . ' ' .
        (string)($a['last_name'] ?? '')
      );
    }

    $profile['display_name']    = $fullName !== '' ? $fullName : ('Adolescent #' . (string)$adolescentId);
    $profile['date_of_birth']   = (string)($a['date_of_birth'] ?? '');
    $profile['barangay']        = (string)($a['barangay'] ?? '');
    $profile['municipality']    = (string)($a['municipality'] ?? '');
    $profile['present_address'] = (string)($a['present_address'] ?? '');
    $profile['educational_attainment'] = ahdisNormalizeEducationAttainment((string)($a['educational_attainment'] ?? ''));
    $profile['currently_enrolled'] = (string)($a['currently_enrolled'] ?? '');
    $profile['grade_year_level'] = (string)($a['grade_year_level'] ?? '');
    $profile['last_school_attended_type'] = (string)($a['last_school_attended_type'] ?? '');
    $profile['reason_not_attending_school'] = (string)($a['reason_not_attending_school'] ?? '');
    $profile['reason_for_not_attending_school'] = (string)($a['reason_for_not_attending_school'] ?? '');
    $profile['reason_not_attending_school_other'] = (string)($a['reason_not_attending_school_other'] ?? '');
    $profile['vocational_training_other'] = (string)($a['vocational_training_other'] ?? '');
    $profile['created_at']      = (string)($a['created_at'] ?? '');
    $profile['updated_at']      = (string)($a['updated_at'] ?? '');
  }
}

/* LEGACY fallback profile */
if ($profile['display_name'] === '' && $hasLegacy) {

  if (!$legacyRow && $adolescentId > 0 && am_hasColumn($conn, 'adolescent_mothers', 'adolescent_id')) {

    $hasInterview = am_hasColumn($conn, 'adolescent_mothers', 'interview_date');
    $hasEncounter = am_hasColumn($conn, 'adolescent_mothers', 'encounter_date');
    $hasCreatedAt = am_hasColumn($conn, 'adolescent_mothers', 'created_at');

    $orderExpr = "id";
    if ($hasInterview || $hasEncounter || $hasCreatedAt) {
      $orderExpr = "COALESCE(" .
        ($hasInterview ? "interview_date," : "") .
        ($hasEncounter ? "encounter_date," : "") .
        ($hasCreatedAt ? "created_at," : "") .
        "id)";
    }

    $st = $conn->prepare("
      SELECT *
      FROM adolescent_mothers
      WHERE adolescent_id = :aid
      ORDER BY $orderExpr DESC, id DESC
      LIMIT 1
    ");
    $st->execute([':aid' => $adolescentId]);
    $legacyRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }

  if ($legacyRow) {
    $data = am_safeJsonDecodeArray((string)($legacyRow['data_json'] ?? ''));

    $profile['display_name']    = am_pick($data, ['name','full_name'], (string)($legacyRow['name'] ?? ($adolescentId > 0 ? ('Adolescent #' . $adolescentId) : ('Legacy #' . $legacyId))));
    $profile['date_of_birth']   = am_pick($data, ['date_of_birth','dob'], (string)($legacyRow['date_of_birth'] ?? ''));
    $profile['barangay']        = am_pick($data, ['locked_barangay','barangay'], (string)($legacyRow['locked_barangay'] ?? ($legacyRow['barangay'] ?? '')));
    $profile['municipality']    = am_pick($data, ['locked_municipality','municipality'], (string)($legacyRow['locked_municipality'] ?? ($legacyRow['municipality'] ?? '')));
    $profile['present_address'] = am_pick($data, ['present_address','address'], (string)($legacyRow['present_address'] ?? ''));
    $profile['educational_attainment'] = ahdisNormalizeEducationAttainment(am_pick($data, ['educational_attainment','education'], (string)($legacyRow['educational_attainment'] ?? ($legacyRow['education'] ?? ''))));
    $profile['currently_enrolled'] = am_pick($data, ['currently_enrolled'], (string)($legacyRow['currently_enrolled'] ?? ''));
    $profile['grade_year_level'] = am_pick($data, ['grade_year_level','year_level'], (string)($legacyRow['grade_year_level'] ?? ($legacyRow['year_level'] ?? '')));
    $profile['last_school_attended_type'] = am_pick($data, ['last_school_attended_type'], (string)($legacyRow['last_school_attended_type'] ?? ''));
    $profile['reason_not_attending_school'] = am_pick($data, ['reason_not_attending_school','reason_for_not_attending_school'], (string)($legacyRow['reason_not_attending_school'] ?? ($legacyRow['reason_for_not_attending_school'] ?? '')));
    $profile['reason_for_not_attending_school'] = $profile['reason_not_attending_school'];
    $profile['reason_not_attending_school_other'] = am_pick($data, ['reason_not_attending_school_other'], (string)($legacyRow['reason_not_attending_school_other'] ?? ''));
    $profile['vocational_training_other'] = am_pick($data, ['vocational_training_other'], (string)($legacyRow['vocational_training_other'] ?? ''));
    $profile['created_at']      = (string)($legacyRow['created_at'] ?? '');
    $profile['updated_at']      = (string)($legacyRow['updated_at'] ?? '');
  }
}

/* If still empty, fail safely */
if ($profile['display_name'] === '') {
  http_response_code(404);
  echo "<div class='content' style='padding:16px;'><h3>Profile not found</h3></div>";
  require_once __DIR__ . "/../includes/footer.php";
  exit;
}

/* =========================
   History (TIMELINE SUMMARY ONLY)
   ========================= */
$history = [];

/* ----- NEW encounters history ----- */
if ($hasEncounters && $adolescentId > 0 && am_hasColumn($conn, 'encounters', 'adolescent_id')) {

  $eHasId        = am_hasColumn($conn, 'encounters', 'id');
  $eHasStatus    = am_hasColumn($conn, 'encounters', 'status');
  $eHasDataJson  = am_hasColumn($conn, 'encounters', 'data_json');
  $eHasEncDate   = am_hasColumn($conn, 'encounters', 'encounter_date');
  $eHasIntDate   = am_hasColumn($conn, 'encounters', 'interview_date');
  $eHasCreatedAt = am_hasColumn($conn, 'encounters', 'created_at');
  $eHasEncodedBy = am_hasColumn($conn, 'encounters', 'encoded_by');
  $eHasDateEncoded = am_hasColumn($conn, 'encounters', 'date_encoded');

  if ($eHasId) {

    $selectParts = [
      "e.id AS id",
      ($eHasStatus   ? "e.status AS status" : "'' AS status"),
      ($eHasDataJson ? "e.data_json AS data_json" : "'' AS data_json"),
      ($eHasEncDate ? "e.encounter_date AS encounter_date" : "NULL AS encounter_date"),
      ($eHasCreatedAt ? "e.created_at AS created_at" : "NULL AS created_at"),
      ($eHasEncodedBy ? "e.encoded_by AS encoded_by" : "'' AS encoded_by"),
      ($eHasDateEncoded ? "e.date_encoded AS date_encoded" : "'' AS date_encoded")
    ];

    $sortExpr = ($eHasEncDate ? "e.encounter_date" : ($eHasCreatedAt ? "e.created_at" : "e.id"));

    $sql = "
      SELECT " . implode(", ", $selectParts) . "
      FROM encounters e
      WHERE e.adolescent_id = :aid
      ORDER BY $sortExpr DESC, e.id DESC
    ";

    $stE = $conn->prepare($sql);
    $stE->execute([':aid' => $adolescentId]);
    $encs = $stE->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($encs as $e) {
      $data = am_safeJsonDecodeArray((string)($e['data_json'] ?? ''));

      $encDate   = (string)($e['encounter_date'] ?? '');
      $createdAt = (string)($e['created_at'] ?? '');
      $timesPreg = am_pick($data, ['times_pregnant','pregnancy_count','pregnancyCount'], '');
      $encodedBy = trim((string)($e['encoded_by'] ?? ''));
      if ($encodedBy === '') $encodedBy = am_pick($data, ['encoded_by'], '');
      $dateEncoded = trim((string)($e['date_encoded'] ?? ''));
      if ($dateEncoded === '') $dateEncoded = am_pick($data, ['date_encoded'], '');

      $history[] = [
        'encounter_id' => (int)($e['id'] ?? 0),
        'legacy_id'    => 0,
        'date'         => $encDate !== '' ? $encDate : $createdAt,
        'datetime'     => $createdAt !== '' ? $createdAt : ($encDate !== '' ? $encDate : ''),
        'status'       => (string)($e['status'] ?? 'submitted'),
        'encoded_by'   => $encodedBy,
        'date_encoded' => $dateEncoded,
        'times_preg'   => $timesPreg,
        'age'          => am_pick($data, ['age', 'imported_age'], ''),
        'is_legacy'    => false,
      ];
    }
  }
}

/* ----- LEGACY history fallback ----- */
if (!$history && $hasLegacy) {

  $lHasId        = am_hasColumn($conn, 'adolescent_mothers', 'id');
  $lHasAid       = am_hasColumn($conn, 'adolescent_mothers', 'adolescent_id');
  $lHasStatus    = am_hasColumn($conn, 'adolescent_mothers', 'status');
  $lHasDataJson  = am_hasColumn($conn, 'adolescent_mothers', 'data_json');
  $lHasIntDate   = am_hasColumn($conn, 'adolescent_mothers', 'interview_date');
  $lHasEncDate   = am_hasColumn($conn, 'adolescent_mothers', 'encounter_date');
  $lHasCreatedAt = am_hasColumn($conn, 'adolescent_mothers', 'created_at');
  $lHasEncodedBy = am_hasColumn($conn, 'adolescent_mothers', 'encoded_by');
  $lHasDateEncoded = am_hasColumn($conn, 'adolescent_mothers', 'date_encoded');

  $dateExpr = "NULL";
  if ($lHasIntDate || $lHasEncDate || $lHasCreatedAt) {
    $dateExpr = "COALESCE(" .
      ($lHasIntDate ? "l.interview_date," : "") .
      ($lHasEncDate ? "l.encounter_date," : "") .
      ($lHasCreatedAt ? "l.created_at," : "") .
      "NULL)";
  }

  $legs = [];
  if ($lHasId) {
    if ($adolescentId > 0 && $lHasAid) {
      $sql = "
        SELECT
          l.*,
          $dateExpr AS any_date
        FROM adolescent_mothers l
        WHERE l.adolescent_id = :aid
        ORDER BY $dateExpr DESC, l.id DESC
      ";
      $stL = $conn->prepare($sql);
      $stL->execute([':aid' => $adolescentId]);
      $legs = $stL->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
      $legs = $legacyRow ? [$legacyRow] : [];
      if ($legs) {
        foreach ($legs as &$rr) {
          $rr['any_date'] = (string)(
            ($lHasIntDate ? ($rr['interview_date'] ?? '') : '') ?:
            ($lHasEncDate ? ($rr['encounter_date'] ?? '') : '') ?:
            ($lHasCreatedAt ? ($rr['created_at'] ?? '') : '')
          );
        }
        unset($rr);
      }
    }
  }

  foreach ($legs as $r) {
    $data = $lHasDataJson ? am_safeJsonDecodeArray((string)($r['data_json'] ?? '')) : [];

    $encDate = (string)(
      ($lHasEncDate ? ($r['encounter_date'] ?? '') : '') ?:
      ($lHasIntDate ? ($r['interview_date'] ?? '') : '')
    );
    $createdAt = (string)($lHasCreatedAt ? ($r['created_at'] ?? '') : '');
    $timesPreg = am_pick($data, ['times_pregnant','pregnancy_count','pregnancyCount'], '');

    $encodedBy = $lHasEncodedBy ? trim((string)($r['encoded_by'] ?? '')) : '';
    if ($encodedBy === '') $encodedBy = am_pick($data, ['encoded_by'], '');
    $dateEncoded = $lHasDateEncoded ? trim((string)($r['date_encoded'] ?? '')) : '';
    if ($dateEncoded === '') $dateEncoded = am_pick($data, ['date_encoded'], '');

    $lid = (int)($r['id'] ?? 0);

    $history[] = [
      'encounter_id' => $lid,
      'legacy_id'    => $lid,
      'date'         => $encDate !== '' ? $encDate : ($createdAt !== '' ? $createdAt : (string)($r['any_date'] ?? '')),
      'datetime'     => $createdAt !== '' ? $createdAt : ($encDate !== '' ? $encDate : (string)($r['any_date'] ?? '')),
      'status'       => (string)($lHasStatus ? ($r['status'] ?? 'submitted') : 'submitted'),
      'encoded_by'   => $encodedBy,
      'date_encoded' => $dateEncoded,
      'times_preg'   => $timesPreg,
      'age'          => am_pick($data, ['age', 'imported_age'], (string)($r['age'] ?? '')),
      'is_legacy'    => true,
    ];
  }
}

/* =========================
   Derived UI values
   ========================= */
$ageToday = am_ageAt($profile['date_of_birth'] ?? '', date('Y-m-d'));
if ($ageToday === '' && $history) {
  $ageToday = trim((string)($history[0]['age'] ?? ''));
}
$submissionCount = count($history);
$latestDate = $history ? (string)($history[0]['date'] ?? '') : '';
$latestDateHuman = $history ? am_fmtDateHuman($latestDate) : '—';

$encodedAtRaw = trim((string)($profile['updated_at'] ?: $profile['created_at']));
$encodedAtHuman = am_fmtEncodedDisplayHuman(
  (string)($profile['created_at'] ?? ''),
  (string)($profile['updated_at'] ?? ''),
  $latestDate
);

$educationDisplay = (string)($profile['educational_attainment'] ?? '');
$gradeYearRaw = (string)($profile['grade_year_level'] ?? '');
$gradeYearDisplay = $educationDisplay === 'No Education'
  ? 'Not applicable'
  : (ahdisNormalizeGradeYearLevel($educationDisplay, $gradeYearRaw) ?: $gradeYearRaw);
if ($educationDisplay === 'Vocational' && (string)($profile['vocational_training_other'] ?? '') !== '' && ($gradeYearDisplay === '' || $gradeYearDisplay === 'Other Vocational Training')) {
  $gradeYearDisplay = (string)$profile['vocational_training_other'];
}
$gradeYearLabel = $educationDisplay === 'Vocational'
  ? 'Vocational Course / Program'
  : ($educationDisplay === 'College Level' ? 'Course / Program and Year Level' : 'Grade / Year Level');
$lastSchoolDisplay = (string)($profile['last_school_attended_type'] ?? '');
$currentlyEnrolledDisplay = (string)($profile['currently_enrolled'] ?? '');
$reasonSchoolRaw = $profile['reason_not_attending_school'] ?? '';
if (trim((string)$reasonSchoolRaw) === '') {
  $reasonSchoolRaw = $profile['reason_for_not_attending_school'] ?? '';
}
$reasonSchoolDisplay = ahdisFormatReasonsNotAttendingSchool($reasonSchoolRaw);
$hasOtherSchoolReason = ahdisReasonsNotAttendingSchoolContains($reasonSchoolRaw, 'Other');
$reasonOtherDisplay = $hasOtherSchoolReason ? (string)($profile['reason_not_attending_school_other'] ?? '') : '';
$showReasonSchoolDisplay = $currentlyEnrolledDisplay === 'No' && $reasonSchoolDisplay !== '';
$vocationalOtherDisplay = '';
?>
<style>
  .am-page{ max-width:1150px; margin:0 auto; }
  .am-hero{
    border:1px solid rgba(0,0,0,.08);
    border-radius:16px;
    background: linear-gradient(135deg, rgba(37,99,235,.10), rgba(183, 15, 116, 0.08));
    padding:16px 16px;
    box-shadow:0 10px 26px rgba(0,0,0,.06);
  }
  .am-back-button{
    display:inline-flex;
    align-items:center;
    gap:8px;
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
  .am-back-button:hover{
    background: rgba(255,255,255,.18);
    border-color: rgba(255,255,255,.45);
  }
  .am-back-button:focus-visible{
    outline: 2px solid rgba(255,255,255,.90);
    outline-offset: 2px;
  }
  .am-title{ display:flex; flex-direction:column; align-items:flex-start; gap:12px; }
  .am-title h2{ margin:0; font-weight:800; letter-spacing:.2px; }
  .am-sub{ opacity:.85; margin-top:4px; }
  .am-chips{ display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; }
  .am-chip{
    display:inline-flex; align-items:center; gap:8px;
    padding:6px 10px; border-radius:999px;
    background:#fff; border:1px solid rgba(0,0,0,.08);
    font-size:13px; box-shadow:0 6px 16px rgba(0,0,0,.06);
  }
  .am-chip b{ font-weight:800; }
  .am-actions{ display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; }

  .am-card{
    border:1px solid rgba(0,0,0,.08);
    border-radius:16px;
    overflow:hidden;
    box-shadow:0 10px 22px rgba(0,0,0,.05);
  }
  .am-card .card-header{
    background:#fff;
    border-bottom:1px solid rgba(0,0,0,.06);
    font-weight:800;
    padding:12px 14px;
  }
  .am-kv{
    display:grid;
    grid-template-columns: repeat(12, 1fr);
    gap:12px;
  }
  .am-kv .am-item{
    grid-column: span 6;
    background:#fff;
    border:1px solid rgba(0,0,0,.06);
    border-radius:14px;
    padding:12px 12px;
  }
  .am-kv .am-item.full{ grid-column: span 12; }
  .am-label{ font-size:12px; color:#6b7280; }
  .am-value{ font-weight:700; margin-top:2px; color:#111827; word-break:break-word; }

  .am-timeline{
    position:relative;
    padding-left:22px;
  }
  .am-timeline:before{
    content:'';
    position:absolute;
    left:10px; top:10px; bottom:10px;
    width:2px; background:rgba(0,0,0,.08);
  }
  .am-row{
    position:relative;
    background:#fff;
    border:1px solid rgba(0,0,0,.06);
    border-radius:16px;
    padding:12px 12px;
    margin-bottom:10px;
    box-shadow:0 8px 18px rgba(0,0,0,.04);
  }
  .am-row:before{
    content:'';
    position:absolute;
    left:-16px; top:18px;
    width:10px; height:10px;
    border-radius:50%;
    background:#2563eb;
    box-shadow:0 0 0 4px rgba(37,99,235,.12);
  }
  .am-row.legacy:before{
    background:#f59e0b;
    box-shadow:0 0 0 4px rgba(245,158,11,.14);
  }
  .am-row-top{
    display:flex; align-items:flex-start; justify-content:space-between; gap:10px; flex-wrap:wrap;
  }
  .am-meta{
    display:flex; gap:10px; flex-wrap:wrap; align-items:center;
    color:#374151;
    font-size:13px;
  }
  .am-meta .badge{ border-radius:999px; padding:.35em .6em; }
  .am-actions-row{ display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; }
  .am-muted{ color:#6b7280; font-size:13px; }

  .am-anchor{ scroll-margin-top: 90px; }

  @media (max-width: 768px){
    .am-kv .am-item{ grid-column: span 12; }
  }
</style>

<div class="content" style="padding:16px;">
  <div class="am-page">

    <div class="am-hero">
      <div class="am-title">
        <a class="am-back-button" href="<?= h($LIST_URL) ?>" aria-label="Back to View Profiles">
          <span aria-hidden="true">←</span>
          Back to View Profiles
        </a>
        <div>
          <h2><?= h($profile['display_name'] ?: 'Adolescent Profile') ?></h2>
          <div class="am-sub">
            <?php if ($adolescentId > 0): ?>
              Adolescent ID: <b><?= (int)$adolescentId ?></b>
            <?php else: ?>
              Legacy Record ID: <b><?= (int)$legacyId ?></b>
            <?php endif; ?>
          </div>

          <div class="am-chips">
            <span class="am-chip">Age Today: <b><?= h($ageToday !== '' ? $ageToday : '—') ?></b></span>
            <span class="am-chip">Submissions: <b><?= (int)$submissionCount ?></b></span>
            <span class="am-chip">Latest: <b><?= h($latestDateHuman) ?></b></span>
            <?php if ($encodedAtRaw !== '' || $latestDate !== ''): ?>
              <span class="am-chip">Encoded: <b><?= h($encodedAtHuman) ?></b></span>
            <?php endif; ?>
            <?php if (!empty($profile['barangay'])): ?>
              <span class="am-chip">Barangay: <b><?= h($profile['barangay']) ?></b></span>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <div id="profile" class="am-anchor"></div>
    <div class="card am-card" style="margin-top:14px;">
      <div class="card-header">Master Profile</div>
      <div class="card-body">
        <div class="am-kv">
          <div class="am-item">
            <div class="am-label">Date of birth</div>
            <div class="am-value"><?= h($profile['date_of_birth'] ?: '—') ?></div>
          </div>
          <div class="am-item">
            <div class="am-label">Age (today)</div>
            <div class="am-value"><?= h($ageToday !== '' ? $ageToday : '—') ?></div>
          </div>
          <div class="am-item">
            <div class="am-label">Barangay</div>
            <div class="am-value"><?= h($profile['barangay'] ?: '—') ?></div>
          </div>
          <div class="am-item">
            <div class="am-label">Encoded / Updated</div>
            <div class="am-value"><?= h($encodedAtHuman) ?></div>
          </div>
          <div class="am-item">
            <div class="am-label">Created</div>
            <div class="am-value"><?= h(am_fmtEncodedDisplayHuman((string)($profile['created_at'] ?? ''), '', $latestDate)) ?></div>
          </div>
          <div class="am-item full">
            <div class="am-label">Present address</div>
            <div class="am-value"><?= h($profile['present_address'] ?: '—') ?></div>
          </div>
          <div class="am-item">
            <div class="am-label">Educational Attainment</div>
            <div class="am-value"><?= h($educationDisplay !== '' ? $educationDisplay : 'Not provided') ?></div>
          </div>
          <div class="am-item">
            <div class="am-label"><?= h($gradeYearLabel) ?></div>
            <div class="am-value"><?= h($gradeYearDisplay !== '' ? $gradeYearDisplay : 'Not provided') ?></div>
          </div>
          <div class="am-item">
            <div class="am-label">Last school attended</div>
            <div class="am-value"><?= h($lastSchoolDisplay !== '' ? $lastSchoolDisplay : 'Not provided') ?></div>
          </div>
          <div class="am-item">
            <div class="am-label">Currently Enrolled</div>
            <div class="am-value"><?= h($currentlyEnrolledDisplay !== '' ? $currentlyEnrolledDisplay : 'Not provided') ?></div>
          </div>
          <?php if ($showReasonSchoolDisplay): ?>
            <div class="am-item">
              <div class="am-label">Reason for not attending school</div>
              <div class="am-value"><?= h($reasonSchoolDisplay) ?></div>
            </div>
            <?php if ($hasOtherSchoolReason && $reasonOtherDisplay !== ''): ?>
              <div class="am-item">
                <div class="am-label">Other reason</div>
                <div class="am-value"><?= h($reasonOtherDisplay) ?></div>
              </div>
            <?php endif; ?>
          <?php endif; ?>
          <?php if ($vocationalOtherDisplay !== ''): ?>
            <div class="am-item">
              <div class="am-label">Other vocational training</div>
              <div class="am-value"><?= h($vocationalOtherDisplay !== '' ? $vocationalOtherDisplay : 'Not provided') ?></div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div id="history" class="am-anchor"></div>
    <div class="card am-card" style="margin-top:14px;">
      <div class="card-header">Encounter History Timeline (Summary)</div>
      <div class="card-body">

        <?php if (!$history): ?>
          <div class="alert alert-info mb-0">
            <div class="fw-bold">No encounter history found.</div>
            <div class="small">Click <b>+ New Update</b> to create the first submission.</div>
          </div>
        <?php else: ?>

          <div class="am-timeline">

            <?php foreach ($history as $enc): ?>
              <?php
                $ageAt = am_ageAt($profile['date_of_birth'] ?? '', $enc['date'] ?? '');
                if ($ageAt === '') $ageAt = trim((string)($enc['age'] ?? ''));
                $dateHuman = am_fmtDateHuman((string)($enc['date'] ?? ''));
                $dateTimeHuman = am_fmtDateTimeHuman((string)($enc['datetime'] ?? ($enc['date'] ?? '')));
                $dateEncodedHuman = am_fmtDateHuman((string)($enc['date_encoded'] ?? ''));

                $isLegacyEntry = !empty($enc['is_legacy']);

                if ($isLegacyEntry) {
                  $detailsLink = $DETAILS_URL . '?legacy_id=' . (int)$enc['legacy_id'] . '&legacy=1';
                  if ($adolescentId > 0) $detailsLink .= '&adolescent_id=' . (int)$adolescentId;
                } else {
                  $detailsLink = $DETAILS_URL . '?adolescent_id=' . (int)$adolescentId . '&encounter_id=' . (int)$enc['encounter_id'];
                }

                [$badgeClass, $badgeText] = am_statusBadge((string)($enc['status'] ?? 'submitted'));
              ?>
              <div class="am-row <?= $isLegacyEntry ? 'legacy' : '' ?>">
                <div class="am-row-top">
                  <div>
                    <div class="fw-bold" style="font-size:15px;">
                      <?= h($dateHuman) ?>
                      <span class="badge bg-<?= h($badgeClass) ?>" style="margin-left:6px;"><?= h($badgeText) ?></span>
                      <?php if ($isLegacyEntry): ?>
                        <span class="badge bg-warning text-dark" style="margin-left:6px;">LEGACY</span>
                      <?php else: ?>
                        <span class="badge bg-success" style="margin-left:6px;">NEW</span>
                      <?php endif; ?>
                    </div>

                    <div class="am-meta" style="margin-top:6px;">
                      <span>Date & time: <b><?= h($dateTimeHuman) ?></b></span>
                      <span>Age at interview: <b><?= h($ageAt !== '' ? $ageAt : '—') ?></b></span>
                      <span>Times pregnant: <b><?= h($enc['times_preg'] ?: '—') ?></b></span>
                      <span>Encoded by: <b><?= h($enc['encoded_by'] ?: '—') ?></b></span>
                      <span>Date encoded: <b><?= h($dateEncodedHuman) ?></b></span>
                    </div>
                  </div>

                  <div class="am-actions-row">
                    <a class="btn btn-sm btn-primary" href="<?= h($detailsLink) ?>">View Details</a>
                  </div>
                </div>

                <div class="am-muted" style="margin-top:10px;">
                 Click <b>View Details</b> to open full documentation for this submission.
                </div>
              </div>
            <?php endforeach; ?>

          </div>

        <?php endif; ?>

      </div>
    </div>

  </div>
</div>
