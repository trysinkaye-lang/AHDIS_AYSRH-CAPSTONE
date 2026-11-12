<?php
declare(strict_types=1);

require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/education_options.php";
require_once __DIR__ . "/../includes/header.php";
require_once __DIR__ . "/../includes/sidebar.php";

date_default_timezone_set('Asia/Manila');

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
if (!function_exists('am_decodeStringJsonArray')) {
  function am_decodeStringJsonArray($v): array {
    if (is_array($v)) return $v;
    $s = trim((string)$v);
    if ($s === '') return [];
    $d = json_decode($s, true);
    return is_array($d) ? $d : [];
  }
}
if (!function_exists('am_parseDate')) {
  function am_parseDate(?string $s): ?DateTimeImmutable {
    $s = trim((string)$s);
    if ($s === '') {
      return null;
    }

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
if (!function_exists('am_joinList')) {
  function am_joinList(array $items): string {
    $clean = [];
    foreach ($items as $x) {
      $x = trim((string)$x);
      if ($x !== '') $clean[] = $x;
    }
    return $clean ? implode(', ', $clean) : '';
  }
}

if (!function_exists('am_partnerDisplayName')) {
  function am_partnerDisplayName(string $surname, string $first, string $middle, string $fallback = ''): string {
    $surname = trim($surname);
    $first = trim($first);
    $middle = trim($middle);
    $given = trim($first . ($middle !== '' ? ' ' . $middle : ''));
    if ($surname !== '' && $given !== '') return $surname . ', ' . $given;
    $name = trim($surname . ' ' . $given);
    return $name !== '' ? $name : trim($fallback);
  }
}

/**
 * Merge scalar DB columns from the encounter row into $data when JSON is empty or incomplete.
 */
if (!function_exists('am_mergeRowIntoData')) {
  function am_mergeRowIntoData(array $row, array $data): array {
    $deny = [
      'id','adolescent_id','data_json',
      'created_at','updated_at',
      'created_by_user_id','encoded_by_user_id',
    ];

    foreach ($row as $k => $v) {
      $k = (string)$k;
      if ($k === '') continue;
      if (in_array($k, $deny, true)) continue;

      if (is_scalar($v) || $v === null) {
        $sv = trim((string)$v);
        if ($sv === '') continue;

        if (!array_key_exists($k, $data) || trim((string)$data[$k]) === '') {
          $data[$k] = $sv;
        }
      }
    }
    return $data;
  }
}

/* =========================
   UI helpers (guarded)
   ========================= */
if (!function_exists('ew_fmtDate')) {
  function ew_fmtDate(?string $s): string {
    $dt = am_parseDate($s);
    return $dt ? $dt->setTimezone(new DateTimeZone('Asia/Manila'))->format('M d, Y')
               : (trim((string)$s) !== '' ? trim((string)$s) : '—');
  }
}
if (!function_exists('ew_fmtDateTime')) {
  function ew_fmtDateTime(?string $s): string {
    $dt = am_parseDate($s);
    return $dt ? $dt->setTimezone(new DateTimeZone('Asia/Manila'))->format('M d, Y • h:i A')
               : (trim((string)$s) !== '' ? trim((string)$s) : '—');
  }
}
if (!function_exists('ew_fmtEncodedDisplay')) {
  function ew_fmtEncodedDisplay(?string $createdAt, ?string $encDate): string {
    $createdAt = trim((string)$createdAt);
    $encDate   = trim((string)$encDate);

    if ($createdAt !== '') {
      $dt = am_parseDate($createdAt);
      if ($dt instanceof DateTimeImmutable) {
        return $dt->setTimezone(new DateTimeZone('Asia/Manila'))->format('M d, Y');
      }
      return $createdAt;
    }

    if ($encDate !== '') {
      $dt = am_parseDate($encDate);
      if ($dt instanceof DateTimeImmutable) {
        return $dt->setTimezone(new DateTimeZone('Asia/Manila'))->format('M d, Y');
      }
      return $encDate;
    }

    return '—';
  }
}
if (!function_exists('ew_statusBadge')) {
  function ew_statusBadge(string $status): array {
    $s = strtolower(trim($status));
    if ($s === 'approved' || $s === 'validated') return ['ok', 'Approved'];
    if ($s === 'rejected') return ['bad', 'Rejected'];
    if ($s === 'draft') return ['muted', 'Draft'];
    if ($s === 'pending') return ['warn', 'Pending'];
    return ['info', 'Submitted'];
  }
}
if (!function_exists('ew_v')) {
  function ew_v(string $v): string {
    $v = trim($v);
    return $v === '' ? '—' : $v;
  }
}
if (!function_exists('ew_isLong')) {
  function ew_isLong(string $v, int $len = 120): bool {
    $s = trim($v);
    $n = function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
    return $n > $len;
  }
}

/* =========================
   Show-all fallback renderer (NO RAW JSON)
   ========================= */
if (!function_exists('ew_prettyKey')) {
  function ew_prettyKey(string $k): string {
    $k = str_replace(['_', '-'], ' ', $k);
    $k = preg_replace('/\s+/', ' ', $k);
    return ucwords(trim($k));
  }
}
if (!function_exists('ew_valueToText')) {
  function ew_valueToText($v): string {
    if ($v === null) return '';
    if (is_bool($v)) return $v ? 'Yes' : 'No';
    if (is_scalar($v)) return trim((string)$v);

    if (is_array($v)) {
      $allScalar = true;
      foreach ($v as $x) {
        if (is_array($x) || is_object($x)) { $allScalar = false; break; }
      }
      if ($allScalar) {
        $items = [];
        foreach ($v as $x) {
          $s = trim((string)$x);
          if ($s !== '') $items[] = $s;
        }
        return $items ? implode(', ', $items) : '';
      }

      $pairs = [];
      foreach ($v as $k => $x) {
        $kk = trim((string)$k);
        $vv = ew_valueToText($x);
        if ($kk !== '' && $vv !== '') $pairs[] = $kk . ': ' . $vv;
      }
      return $pairs ? implode(' • ', $pairs) : '';
    }

    return trim((string)$v);
  }
}

/* =========================
   URLs
   ========================= */
$moduleBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$moduleBase = ($moduleBase === '/' ? '' : $moduleBase);
$rootBase = preg_replace('#/view profile$#', '', $moduleBase) ?: '';
$BACK_PROFILE_URL = $moduleBase . "/adolescent_view.php";
$LIST_URL         = $moduleBase . "/view.php";

/* =========================
   Inputs
   ========================= */
$adolescentId = (int)($_GET['adolescent_id'] ?? 0);
$encounterId  = (int)($_GET['encounter_id'] ?? 0);
$legacyId     = (int)($_GET['legacy_id'] ?? 0);
$isLegacy     = ((int)($_GET['legacy'] ?? 0) === 1);

if ($isLegacy) {
  if ($legacyId <= 0 && $encounterId > 0) $legacyId = $encounterId;
  if ($legacyId <= 0) {
    http_response_code(400);
    echo "<div style='padding:16px;'><h3>Missing legacy_id</h3></div>";
    require_once __DIR__ . "/../includes/footer.php";
    exit;
  }
} else {
  if ($encounterId <= 0) {
    http_response_code(400);
    echo "<div style='padding:16px;'><h3>Missing encounter_id</h3></div>";
    require_once __DIR__ . "/../includes/footer.php";
    exit;
  }
}

$hasAdolescents = am_tableExists($conn, 'adolescents');
$hasEncounters  = am_tableExists($conn, 'encounters');
$hasLegacy      = am_tableExists($conn, 'adolescent_mothers');
$hasUsers       = am_tableExists($conn, 'users');

/* =========================
   Resolve username column (optional)
   ========================= */
$userNameCol = null;
if ($hasUsers && am_hasColumn($conn, 'users', 'id')) {
  foreach (['username','name','full_name'] as $cand) {
    if (am_hasColumn($conn, 'users', $cand)) { $userNameCol = $cand; break; }
  }
}

/* =========================
   Load master profile (for name + DOB)
   ========================= */
$displayName = 'Adolescent';
$dob = '';
$barangay = '';
$municipality = '';

if ($hasAdolescents && $adolescentId > 0) {
  $stA = $conn->prepare("
    SELECT full_name, first_name, middle_name, last_name, date_of_birth, barangay, municipality
    FROM adolescents
    WHERE id=:id
    LIMIT 1
  ");
  $stA->execute([':id' => $adolescentId]);
  $a = $stA->fetch(PDO::FETCH_ASSOC);

  if ($a) {
    $name = trim((string)($a['full_name'] ?? ''));
    if ($name === '') {
      $name = trim((string)($a['first_name'] ?? '') . ' ' . (string)($a['middle_name'] ?? '') . ' ' . (string)($a['last_name'] ?? ''));
    }
    $displayName = $name !== '' ? $name : ('Adolescent #' . (string)$adolescentId);
    $dob = (string)($a['date_of_birth'] ?? '');
    $barangay = (string)($a['barangay'] ?? '');
    $municipality = (string)($a['municipality'] ?? '');
  }
}

/* =========================
   Load encounter row + data
   ========================= */
$row = null;
$data = [];
$encDate = '';
$encodedBy = '';
$status = '';
$createdAt = '';
$sourceLabel = $isLegacy ? 'LEGACY' : 'NEW';

if ($isLegacy) {
  if (!$hasLegacy) {
    http_response_code(500);
    echo "<div style='padding:16px;'><h3>Legacy table not found</h3></div>";
    require_once __DIR__ . "/../includes/footer.php";
    exit;
  }

  if ($adolescentId > 0 && am_hasColumn($conn, 'adolescent_mothers', 'adolescent_id')) {
    $st = $conn->prepare("SELECT * FROM adolescent_mothers WHERE id=:id AND adolescent_id=:aid LIMIT 1");
    $st->execute([':id' => $legacyId, ':aid' => $adolescentId]);
  } else {
    $st = $conn->prepare("SELECT * FROM adolescent_mothers WHERE id=:id LIMIT 1");
    $st->execute([':id' => $legacyId]);
  }

  $row = $st->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    http_response_code(404);
    echo "<div style='padding:16px;'><h3>Encounter not found (legacy)</h3></div>";
    require_once __DIR__ . "/../includes/footer.php";
    exit;
  }

  if ($adolescentId <= 0) $adolescentId = (int)($row['adolescent_id'] ?? 0);

  $data      = am_safeJsonDecodeArray((string)($row['data_json'] ?? ''));
  $data      = am_mergeRowIntoData($row, $data);
  $encDate   = (string)($row['interview_date'] ?? ($row['encounter_date'] ?? ($row['created_at'] ?? '')));
  $status    = (string)($row['status'] ?? 'submitted');
  $createdAt = (string)($row['created_at'] ?? '');

  if ($displayName === 'Adolescent' || $dob === '' || $barangay === '' || $municipality === '') {
    $displayName  = am_pick($data, ['name','full_name'], $displayName);
    $dob          = $dob ?: am_pick($data, ['date_of_birth','dob'], '');
    $barangay     = $barangay ?: am_pick($data, ['locked_barangay','barangay'], '');
    $municipality = $municipality ?: am_pick($data, ['locked_municipality','municipality'], '');
  }

} else {
  if (!$hasEncounters) {
    http_response_code(500);
    echo "<div style='padding:16px;'><h3>Encounters table not found</h3></div>";
    require_once __DIR__ . "/../includes/footer.php";
    exit;
  }

  if ($adolescentId > 0) {
    $st = $conn->prepare("SELECT * FROM encounters WHERE id=:id AND adolescent_id=:aid LIMIT 1");
    $st->execute([':id' => $encounterId, ':aid' => $adolescentId]);
  } else {
    $st = $conn->prepare("SELECT * FROM encounters WHERE id=:id LIMIT 1");
    $st->execute([':id' => $encounterId]);
  }

  $row = $st->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    http_response_code(404);
    echo "<div style='padding:16px;'><h3>Encounter not found</h3></div>";
    require_once __DIR__ . "/../includes/footer.php";
    exit;
  }

  if ($adolescentId <= 0) $adolescentId = (int)($row['adolescent_id'] ?? 0);

  $data      = am_safeJsonDecodeArray((string)($row['data_json'] ?? ''));
  $data      = am_mergeRowIntoData($row, $data);
  $encDate   = (string)($row['encounter_date'] ?? ($row['interview_date'] ?? ($row['created_at'] ?? '')));
  $status    = (string)($row['status'] ?? 'submitted');
  $createdAt = (string)($row['created_at'] ?? '');

  if (($displayName === 'Adolescent' || $dob === '' || $barangay === '' || $municipality === '') && $hasAdolescents && $adolescentId > 0) {
    $stA = $conn->prepare("
      SELECT full_name, first_name, middle_name, last_name, date_of_birth, barangay, municipality
      FROM adolescents WHERE id=:id LIMIT 1
    ");
    $stA->execute([':id' => $adolescentId]);
    $a = $stA->fetch(PDO::FETCH_ASSOC);
    if ($a) {
      $name = trim((string)($a['full_name'] ?? ''));
      if ($name === '') {
        $name = trim((string)($a['first_name'] ?? '') . ' ' . (string)($a['middle_name'] ?? '') . ' ' . (string)($a['last_name'] ?? ''));
      }
      $displayName = $name !== '' ? $name : ('Adolescent #' . (string)$adolescentId);
      $dob = (string)($a['date_of_birth'] ?? '');
      $barangay = (string)($a['barangay'] ?? '');
      $municipality = (string)($a['municipality'] ?? '');
    }
  }
}

$encodedBy = am_pick($data, ['encoded_by'], '');
$dateEncoded = am_pick($data, ['date_encoded'], '');

/* =========================
   Map detailed fields
   ========================= */
$ageAtInterview = am_ageAt($dob, $encDate);
if ($ageAtInterview === '') {
  $ageAtInterview = am_pick($data, ['age', 'imported_age'], '');
}

/* Personal */
$presentAddress = am_pick($data, ['present_address','presentAddress','current_address','address'], '');
$placeOfBirth   = am_pick($data, ['place_of_birth','placeOfBirth'], '');

/* Education */
$education      = ahdisNormalizeEducationAttainment(am_pick($data, ['educational_attainment','education'], ''));
$education      = $education !== '' ? $education : am_pick($data, ['educational_attainment','education'], '');
$enrolled       = am_pick($data, ['currently_enrolled','currentlyEnrolled'], '');
$yearLevelRaw   = am_pick($data, ['grade_year_level','gradeYearLevel','year_level','yearLevel'], '');
$yearLevel      = $education === 'No Education'
  ? 'Not applicable'
  : (ahdisNormalizeGradeYearLevel($education, $yearLevelRaw) ?: $yearLevelRaw);
$vocationalOther = ($education === 'Vocational' && $yearLevel === 'Other Vocational Training')
  ? am_pick($data, ['vocational_training_other','vocationalTrainingOther'], '')
  : '';
if ($education === 'Vocational' && $vocationalOther !== '' && ($yearLevel === '' || $yearLevel === 'Other Vocational Training')) {
  $yearLevel = $vocationalOther;
}
$gradeLabel     = $education === 'Vocational'
  ? 'Vocational Course / Program'
  : ($education === 'College Level' ? 'Course / Program and Year Level' : 'Grade / Year Level');
$lastSchoolType = am_pick($data, ['last_school_attended_type','lastSchoolAttendedType'], '');
$schoolReasonRaw = am_pick($data, ['reason_not_attending_school','reason_for_not_attending_school','reasonNotAttendingSchool'], '');
$schoolReason = ahdisFormatReasonsNotAttendingSchool($schoolReasonRaw);
$hasOtherSchoolReason = ahdisReasonsNotAttendingSchoolContains($schoolReasonRaw, 'Other');
$schoolReasonOther = $hasOtherSchoolReason
  ? am_pick($data, ['reason_not_attending_school_other','reasonNotAttendingSchoolOther'], '')
  : '';
$showSchoolReason = $enrolled === 'No' && $schoolReason !== '';
$vocationalOther = '';

/* Household */
$civilStatus    = am_pick($data, ['civil_status','civilStatus'], '');
$livingSit      = am_pick($data, ['living_situation','livingSituation'], '');
$livingOthers   = am_pick($data, ['living_others','livingOthers'], '');

/* Pregnancy */
$pregnant       = am_pick($data, ['pregnant'], '');
$monthsPreg     = am_pick($data, ['months_pregnant','monthsPregnant'], '');
$prenatalCare   = am_pick($data, ['prenatal_care','prenatalCare'], '');
$healthConcerns = am_pick($data, ['health_concerns','healthConcerns'], '');

$timesPreg      = am_pick($data, ['times_pregnant','pregnancy_count','pregnancyCount'], '');
$ageFirstPreg   = am_pick($data, ['age_first_pregnancy','ageFirstPregnancy'], '');
$livingChildren = am_pick($data, ['living_children','livingChildren'], '');
$childUnderOne  = am_pick($data, ['child_under_one','childUnderOne'], '');
$familyPlanning = am_pick($data, ['family_planning','familyPlanning'], '');
$familyPlanningDisplay = ahdisFamilyPlanningDisplay($familyPlanning);

/* Socio */
$is4ps          = am_pick($data, ['is_4ps','is4ps'], '');
$isIps          = am_pick($data, ['is_ips','isIps'], '');
$ethnicity      = am_pick($data, ['ethnicity'], '');

$working        = am_pick($data, ['currently_working','currentlyWorking'], '');
$monthlyIncome  = am_pick($data, ['monthly_income','monthlyIncome'], '');
$occupation     = am_pick($data, ['occupation'], '');

$fatherOcc      = am_pick($data, ['father_occupation','fatherOccupation'], '');
$motherOcc      = am_pick($data, ['mother_occupation','motherOccupation'], '');

/* Assistance */
$govAssistanceArr = am_decodeStringJsonArray($data['gov_assistance'] ?? ($data['programs_applied'] ?? ($row['programs_applied'] ?? '')));
$govAssistanceTxt = am_joinList($govAssistanceArr);
$govOther         = am_pick($data, ['gov_assistance_other','govAssistanceOther'], '');

$govDetails = am_safeJsonDecodeArray((string)($data['gov_assistance_details'] ?? ($data['govAssistanceDetails'] ?? '')));
$govDetailsTxt = '';
if ($govDetails) {
  $pairs = [];
  foreach ($govDetails as $k => $v) {
    $k = trim((string)$k);
    $v = trim((string)$v);
    if ($k !== '' && $v !== '') $pairs[] = $k . ': ' . $v;
  }
  $govDetailsTxt = $pairs ? implode(' • ', $pairs) : '';
}

/* Remarks */
$observations = am_pick($data, ['other_observations','otherObservations','remarks'], '');
$interviewedBy= am_pick($data, ['interviewed_by','interviewedBy'], '');
$notedBy      = am_pick($data, ['noted_by','notedBy'], '');
$notedDate    = am_pick($data, ['noted_date','notedDate'], '');

/* Partner */
$partnerSurname = am_pick($data, ['partner_surname','partnerSurname','partner_last_name','partnerLastName'], '');
$partnerFirstName = am_pick($data, ['partner_first_name','partnerFirstName'], '');
$partnerMiddleName = am_pick($data, ['partner_middle_name','partnerMiddleName'], '');
$partnerLegacyName = am_pick($data, ['partner_name','partnerName'], '');
$partnerName   = am_partnerDisplayName($partnerSurname, $partnerFirstName, $partnerMiddleName, $partnerLegacyName);
$partnerAddr   = am_pick($data, ['partner_address','partnerAddress'], '');
$partnerPob    = am_pick($data, ['partner_place_of_birth','partnerPlaceOfBirth'], '');
$partnerDob    = am_pick($data, ['partner_dob','partnerDob'], '');
$partnerAge    = am_pick($data, ['partner_age','partnerAge'], '');
$partnerEdu    = am_pick($data, ['partner_education','partnerEducation'], '');
$partnerEnroll = am_pick($data, ['partner_currently_enrolled','partnerCurrentlyEnrolled'], '');
$partnerYL     = am_pick($data, ['partner_year_level','partnerYearLevel'], '');
$partnerWork   = am_pick($data, ['partner_currently_working','partnerCurrentlyWorking'], '');
$partnerInc    = am_pick($data, ['partner_monthly_income','partnerMonthlyIncome'], '');
$partnerOcc    = am_pick($data, ['partner_occupation','partnerOccupation'], '');

/* Back links */
if ($isLegacy && $adolescentId <= 0) {
  $backHref = $BACK_PROFILE_URL . "?id=" . (int)$legacyId . "#history";
} else {
  $backHref = $BACK_PROFILE_URL . ($adolescentId > 0 ? '?adolescent_id=' . (int)$adolescentId : '') . "#history";
}

$fallbackBackHref = $backHref;
$referrer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
if ($referrer !== '') {
  $refPath = (string)(parse_url($referrer, PHP_URL_PATH) ?? '');
  $refQuery = (string)(parse_url($referrer, PHP_URL_QUERY) ?? '');
  $currentPath = (string)($_SERVER['SCRIPT_NAME'] ?? '');
  $allowedPrefix = $rootBase !== '' ? $rootBase . '/' : '/';
  if ($refPath !== '' && $refPath !== $currentPath && str_starts_with($refPath, $allowedPrefix)) {
    $backHref = $refPath . ($refQuery !== '' ? '?' . $refQuery : '');
  }
}
if ($backHref === '') {
  $backHref = $fallbackBackHref;
}

/* Status pill */
[$sClass, $sText] = ew_statusBadge($status);
$encodedDisplay = ew_fmtEncodedDisplay($createdAt, $encDate);

/* =========================
   Track shown keys + leftovers
   ========================= */
$shownKeys = [
  'name','full_name','first_name','middle_name','last_name',
  'date_of_birth','dob','barangay','municipality','locked_barangay','locked_municipality',
  'present_address','presentAddress','current_address','address',
  'place_of_birth','placeOfBirth',
  'education','educational_attainment','currently_enrolled','currentlyEnrolled',
  'year_level','yearLevel','grade_year_level','gradeYearLevel',
  'last_school_attended_type','lastSchoolAttendedType',
  'reason_not_attending_school','reason_for_not_attending_school','reasonNotAttendingSchool',
  'reason_not_attending_school_other','reasonNotAttendingSchoolOther',
  'vocational_training_other','vocationalTrainingOther',
  'civil_status','civilStatus','living_situation','livingSituation','living_others','livingOthers',
  'pregnant','months_pregnant','monthsPregnant','prenatal_care','prenatalCare','health_concerns','healthConcerns',
  'times_pregnant','pregnancy_count','pregnancyCount','age_first_pregnancy','ageFirstPregnancy',
  'living_children','livingChildren','child_under_one','childUnderOne','family_planning','familyPlanning',
  'is_4ps','is4ps','is_ips','isIps','ethnicity',
  'currently_working','currentlyWorking','monthly_income','monthlyIncome','occupation',
  'father_occupation','fatherOccupation','mother_occupation','motherOccupation',
  'gov_assistance','programs_applied','gov_assistance_other','govAssistanceOther',
  'gov_assistance_details','govAssistanceDetails',
  'other_observations','otherObservations','remarks','interviewed_by','interviewedBy','noted_by','notedBy','noted_date','notedDate',
  'encoded_by','date_encoded',
  'partner_name','partnerName','partner_surname','partnerSurname','partner_last_name','partnerLastName',
  'partner_first_name','partnerFirstName','partner_middle_name','partnerMiddleName','partner_address','partnerAddress',
  'partner_place_of_birth','partnerPlaceOfBirth','partner_dob','partnerDob','partner_age','partnerAge',
  'partner_education','partnerEducation','partner_currently_enrolled','partnerCurrentlyEnrolled',
  'partner_year_level','partnerYearLevel','partner_currently_working','partnerCurrentlyWorking',
  'partner_monthly_income','partnerMonthlyIncome','partner_occupation','partnerOccupation',
];

$leftovers = [];
foreach ($data as $k => $v) {
  $k = trim((string)$k);
  if ($k === '') continue;
  if (in_array($k, $shownKeys, true)) continue;

  $txt = ew_valueToText($v);
  if (trim($txt) === '') continue;

  $leftovers[$k] = $txt;
}
$hasLeftovers = !empty($leftovers);
?>
<style>
.ewrap{ max-width:1160px; margin:0 auto; padding:16px; }
.ehero{
  border:1px solid rgba(15,23,42,.10);
  background: linear-gradient(135deg, rgba(37,99,235,.10), rgba(185, 16, 145, 0.08));
  border-radius:18px;
  padding:14px 14px;
  box-shadow:0 12px 28px rgba(15,23,42,.07);
}
.ehead{ display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:flex-start; }
.ehead h2{ margin:0; font-weight:950; letter-spacing:.1px; }
.esub{ margin-top:6px; color:rgba(15,23,42,.72); font-weight:800; }
.ebadges{ display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-top:8px; }
.epill{
  display:inline-flex; align-items:center; gap:8px;
  padding:6px 10px; border-radius:999px;
  border:1px solid rgba(15,23,42,.12);
  background:rgba(255,255,255,.70);
  backdrop-filter: blur(6px);
  font-weight:950; font-size:12px; color:#0f172a;
}
.epill.ok{ background:rgba(22,163,74,.10); border-color:rgba(22,163,74,.20); color:#166534; }
.epill.warn{ background:rgba(245,158,11,.12); border-color:rgba(245,158,11,.22); color:#92400e; }
.epill.info{ background:rgba(37,99,235,.10); border-color:rgba(37,99,235,.20); color:#1d4ed8; }
.epill.bad{ background:rgba(239,68,68,.10); border-color:rgba(239,68,68,.20); color:#991b1b; }
.epill.muted{ background:rgba(100,116,139,.10); border-color:rgba(100,116,139,.20); color:#334155; }

.eactions{ display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; }
.ebtn{
  display:inline-flex; align-items:center; justify-content:center; gap:8px;
  height:40px; padding:0 14px;
  border-radius:12px;
  border:1px solid rgba(15,23,42,.14);
  background:#fff; color:#0f172a;
  text-decoration:none; font-weight:950;
}
.ebtn:hover{ background:rgba(15,23,42,.03); }
.ebtn.primary{ background:#163a73; border-color:#163a73; color:#fff; }
.ebtn.primary:hover{ filter:brightness(.96); }

.ecard{
  margin-top:14px;
  border:1px solid rgba(15,23,42,.10);
  border-radius:16px;
  background:#fff;
  box-shadow: 0 10px 26px rgba(15,23,42,.06);
  overflow:hidden;
}
.ecard-h{
  padding:12px 14px;
  font-weight:950;
  color:#0f172a;
  border-bottom:1px solid rgba(15,23,42,.08);
  background:linear-gradient(180deg, rgba(248,250,252,1), rgba(255,255,255,1));
  display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;
}
.ecard-b{ padding:14px; }

.elabel{ color:rgba(15,23,42,.60); font-size:12px; font-weight:950; }
.eval{ margin-top:4px; font-weight:900; color:#0f172a; }
.eval.long{
  white-space:pre-wrap;
  border:1px dashed rgba(15,23,42,.18);
  background:rgba(15,23,42,.02);
  padding:10px;
  border-radius:12px;
  font-weight:850;
}

@media print{
  .eactions{ display:none !important; }
  .ewrap{ max-width:none; padding:0; }
  .ehero{ box-shadow:none; }
  .ecard{ box-shadow:none; }
}

@media (max-width: 576px){
  .ewrap{ padding:12px; }
  .ebtn{ width:100%; }
}
</style>

<div class="ewrap">

  <div class="ehero">
    <div class="ehead">
      <div>
        <h2>Details</h2>
        <div class="esub">
          <div>
            <b><?= h($displayName) ?></b>
            <?= $barangay !== '' ? " - Barangay " . h($barangay) : "" ?>
          </div>

          <div class="ebadges">
            <span class="epill">Adolescent ID: <b><?= (int)$adolescentId ?></b></span>

            <?php if ($isLegacy): ?>
              <span class="epill warn">Legacy Row ID: <b><?= (int)$legacyId ?></b></span>
              <span class="epill warn">LEGACY</span>
            <?php else: ?>
              <span class="epill ok">NEW</span>
            <?php endif; ?>

            <span class="epill <?= h($sClass) ?>"><?= h($sText) ?></span>
            <span class="epill info">Interview: <b><?= h(ew_fmtDate($encDate)) ?></b></span>
          </div>
        </div>
      </div>

      <div class="eactions">
        <a class="ebtn" href="<?= h($backHref) ?>">Back</a>
        <a class="ebtn" href="<?= h($LIST_URL) ?>">Profiles List</a>
      </div>
    </div>
  </div>

  <div class="ecard" id="sec-summary">
    <div class="ecard-h">
      <span>Summary</span>
      <span class="epill info">Encoded: <b><?= h($encodedDisplay) ?></b></span>
    </div>
    <div class="ecard-b">
      <div class="row g-3">
        <div class="col-md-4">
          <div class="elabel">Date Interviewed</div>
          <div class="eval"><?= h(ew_v($encDate)) ?></div>
        </div>
        <div class="col-md-4">
          <div class="elabel">Age at time of interview</div>
          <div class="eval"><?= h($ageAtInterview !== '' ? $ageAtInterview : '—') ?></div>
        </div>
        <div class="col-md-4">
          <div class="elabel">Status</div>
          <div class="eval">
            <span class="epill <?= h($sClass) ?>"><?= h($sText) ?></span>
          </div>
        </div>
        <div class="col-md-6">
          <div class="elabel">Encoder</div>
          <div class="eval"><?= h(ew_v($encodedBy)) ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="ecard" id="sec-personal">
    <div class="ecard-h">Personal & Education</div>
    <div class="ecard-b">
      <div class="row g-3">
        <div class="col-md-6">
          <div class="elabel">Present address</div>
          <?php $v = (string)$presentAddress; ?>
          <div class="eval <?= ew_isLong($v) ? 'long' : '' ?>"><?= h(ew_v($v)) ?></div>
        </div>

        <div class="col-md-3">
          <div class="elabel">Place of birth</div>
          <div class="eval"><?= h(ew_v($placeOfBirth)) ?></div>
        </div>

        <div class="col-md-4">
          <div class="elabel">Educational Attainment</div>
          <div class="eval"><?= h($education !== '' ? $education : 'Not provided') ?></div>
        </div>
        <div class="col-md-4">
          <div class="elabel"><?= h($gradeLabel) ?></div>
          <div class="eval"><?= h($yearLevel !== '' ? $yearLevel : 'Not provided') ?></div>
        </div>
        <div class="col-md-4">
          <div class="elabel">Last school attended</div>
          <div class="eval"><?= h($lastSchoolType !== '' ? $lastSchoolType : 'Not provided') ?></div>
        </div>
        <div class="col-md-4">
          <div class="elabel">Currently Enrolled</div>
          <div class="eval"><?= h(ew_v($enrolled)) ?></div>
        </div>
        <?php if ($showSchoolReason): ?>
          <div class="col-md-4">
            <div class="elabel">Reason for not attending school</div>
            <div class="eval"><?= h($schoolReason) ?></div>
          </div>
          <?php if ($hasOtherSchoolReason && $schoolReasonOther !== ''): ?>
            <div class="col-md-4">
              <div class="elabel">Other reason</div>
              <div class="eval"><?= h($schoolReasonOther) ?></div>
            </div>
          <?php endif; ?>
        <?php endif; ?>
        <?php if ($vocationalOther !== ''): ?>
          <div class="col-md-4">
            <div class="elabel">Other vocational training</div>
            <div class="eval"><?= h($vocationalOther !== '' ? $vocationalOther : 'Not provided') ?></div>
          </div>
        <?php endif; ?>
        <div class="col-md-4">
          <div class="elabel">Civil Status</div>
          <div class="eval"><?= h(ew_v($civilStatus)) ?></div>
        </div>
        <div class="col-md-4">
          <div class="elabel">Living Situation</div>
          <div class="eval"><?= h(ew_v($livingSit)) ?></div>
        </div>
        <div class="col-md-4">
          <div class="elabel">Living Others</div>
          <div class="eval"><?= h(ew_v($livingOthers)) ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="ecard" id="sec-preg">
    <div class="ecard-h">Pregnancy</div>
    <div class="ecard-b">
      <div class="row g-3">
        <div class="col-md-3">
          <div class="elabel">Currently pregnant</div>
          <div class="eval"><?= h(ew_v($pregnant)) ?></div>
        </div>
        <div class="col-md-3">
          <div class="elabel">Months Pregnant</div>
          <div class="eval"><?= h(ew_v($monthsPreg)) ?></div>
        </div>
        <div class="col-md-3">
          <div class="elabel">Prenatal Care</div>
          <div class="eval"><?= h(ew_v($prenatalCare)) ?></div>
        </div>
        <div class="col-md-3">
          <div class="elabel">Health Concerns</div>
          <?php $v = (string)$healthConcerns; ?>
          <div class="eval <?= ew_isLong($v) ? 'long' : '' ?>"><?= h(ew_v($v)) ?></div>
        </div>

        <div class="col-md-3">
          <div class="elabel">Times Pregnant</div>
          <div class="eval"><?= h(ew_v($timesPreg)) ?></div>
        </div>
        <div class="col-md-3">
          <div class="elabel">Age at First Pregnancy</div>
          <div class="eval"><?= h(ew_v($ageFirstPreg)) ?></div>
        </div>
        <div class="col-md-3">
          <div class="elabel">Living Children</div>
          <div class="eval"><?= h(ew_v($livingChildren)) ?></div>
        </div>
        <div class="col-md-3">
          <div class="elabel">Child Under One</div>
          <div class="eval"><?= h(ew_v($childUnderOne)) ?></div>
        </div>

        <div class="col-md-6">
          <div class="elabel">Family Planning</div>
          <?php $v = (string)$familyPlanningDisplay; ?>
          <div class="eval <?= ew_isLong($v) ? 'long' : '' ?>"><?= h(ew_v($v)) ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="ecard" id="sec-partner">
    <div class="ecard-h">Partner Information</div>
    <div class="ecard-b">
      <div class="row g-3">
        <div class="col-md-12">
          <div class="elabel">Partner Name</div>
          <div class="eval"><?= h(ew_v($partnerName)) ?></div>
        </div>
        <div class="col-md-4">
          <div class="elabel">Partner Surname</div>
          <div class="eval"><?= h(ew_v($partnerSurname)) ?></div>
        </div>
        <div class="col-md-4">
          <div class="elabel">Partner First Name</div>
          <div class="eval"><?= h(ew_v($partnerFirstName)) ?></div>
        </div>
        <div class="col-md-4">
          <div class="elabel">Partner Middle Name</div>
          <div class="eval"><?= h(ew_v($partnerMiddleName)) ?></div>
        </div>
        <div class="col-md-6">
          <div class="elabel">Partner Address</div>
          <?php $v = (string)$partnerAddr; ?>
          <div class="eval <?= ew_isLong($v) ? 'long' : '' ?>"><?= h(ew_v($v)) ?></div>
        </div>

        <div class="col-md-3">
          <div class="elabel">Place of Birth</div>
          <div class="eval"><?= h(ew_v($partnerPob)) ?></div>
        </div>
        <div class="col-md-3">
          <div class="elabel">Date of Birth</div>
          <div class="eval"><?= h(ew_v($partnerDob)) ?></div>
        </div>
        <div class="col-md-3">
          <div class="elabel">Age</div>
          <div class="eval"><?= h(ew_v($partnerAge)) ?></div>
        </div>

        <div class="col-md-4">
          <div class="elabel">Education</div>
          <div class="eval"><?= h(ew_v($partnerEdu)) ?></div>
        </div>
        <div class="col-md-4">
          <div class="elabel">Currently Enrolled</div>
          <div class="eval"><?= h(ew_v($partnerEnroll)) ?></div>
        </div>
        <div class="col-md-4">
          <div class="elabel">Year Level</div>
          <div class="eval"><?= h(ew_v($partnerYL)) ?></div>
        </div>

        <div class="col-md-4">
          <div class="elabel">Currently Working</div>
          <div class="eval"><?= h(ew_v($partnerWork)) ?></div>
        </div>
        <div class="col-md-4">
          <div class="elabel">Monthly Income</div>
          <div class="eval"><?= h(ew_v($partnerInc)) ?></div>
        </div>
        <div class="col-md-4">
          <div class="elabel">Occupation</div>
          <div class="eval"><?= h(ew_v($partnerOcc)) ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="ecard" id="sec-socio">
    <div class="ecard-h">Socio-economic</div>
    <div class="ecard-b">
      <div class="row g-3">
        <div class="col-md-3">
          <div class="elabel">4Ps</div>
          <div class="eval"><?= h(ew_v($is4ps)) ?></div>
        </div>
        <div class="col-md-3">
          <div class="elabel">IPs</div>
          <div class="eval"><?= h(ew_v($isIps)) ?></div>
        </div>
        <div class="col-md-3">
          <div class="elabel">Ethnicity</div>
          <div class="eval"><?= h(ew_v($ethnicity)) ?></div>
        </div>
        <div class="col-md-3">
          <div class="elabel">Currently Working</div>
          <div class="eval"><?= h(ew_v($working)) ?></div>
        </div>

        <div class="col-md-4">
          <div class="elabel">Monthly Income</div>
          <div class="eval"><?= h(ew_v($monthlyIncome)) ?></div>
        </div>
        <div class="col-md-4">
          <div class="elabel">Occupation</div>
          <div class="eval"><?= h(ew_v($occupation)) ?></div>
        </div>
        <div class="col-md-4">
          <div class="elabel">Father Occupation</div>
          <div class="eval"><?= h(ew_v($fatherOcc)) ?></div>
        </div>

        <div class="col-md-6">
          <div class="elabel">Mother Occupation</div>
          <div class="eval"><?= h(ew_v($motherOcc)) ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="ecard" id="sec-assist">
    <div class="ecard-h">Assistance & Programs</div>
    <div class="ecard-b">
      <div class="row g-3">
        <div class="col-md-6">
          <div class="elabel">Government Assistance</div>
          <?php $v = (string)$govAssistanceTxt; ?>
          <div class="eval <?= ew_isLong($v) ? 'long' : '' ?>"><?= h(ew_v($v)) ?></div>
        </div>
        <div class="col-md-6">
          <div class="elabel">Other Assistance</div>
          <?php $v = (string)$govOther; ?>
          <div class="eval <?= ew_isLong($v) ? 'long' : '' ?>"><?= h(ew_v($v)) ?></div>
        </div>
        <div class="col-12">
          <div class="elabel">Assistance Details</div>
          <?php $v = (string)$govDetailsTxt; ?>
          <div class="eval <?= ew_isLong($v) ? 'long' : '' ?>"><?= h(ew_v($v)) ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="ecard" id="sec-remarks">
    <div class="ecard-h">Remarks / Observations</div>
    <div class="ecard-b">
      <div class="row g-3">
        <div class="col-12">
          <div class="elabel">Other Observations</div>
          <?php $v = (string)$observations; ?>
          <div class="eval <?= ew_isLong($v) ? 'long' : '' ?>"><?= h(ew_v($v)) ?></div>
        </div>
        <div class="col-md-4">
          <div class="elabel">Interviewed by</div>
          <div class="eval"><?= h(ew_v($interviewedBy)) ?></div>
        </div>
        <div class="col-md-4">
          <div class="elabel">Noted by</div>
          <div class="eval"><?= h(ew_v($notedBy)) ?></div>
        </div>
        <div class="col-md-4">
          <div class="elabel">Noted Date</div>
          <div class="eval"><?= h(ew_v($notedDate)) ?></div>
        </div>
        <div class="col-md-4">
          <div class="elabel">Encoded By</div>
          <div class="eval"><?= h(ew_v($encodedBy)) ?></div>
        </div>
        <div class="col-md-4">
          <div class="elabel">Date Encoded</div>
          <div class="eval"><?= h(ew_fmtDate($dateEncoded)) ?></div>
        </div>
      </div>
    </div>
  </div>

  <?php if ($hasLeftovers): ?>
    <div class="ecard" id="sec-all">
      <div class="ecard-h">Other Captured Fields</div>
      <div class="ecard-b">
        <div class="row g-3">
          <?php foreach ($leftovers as $k => $txt): ?>
            <div class="col-md-6">
              <div class="elabel"><?= h(ew_prettyKey((string)$k)) ?></div>
              <?php $v = (string)$txt; ?>
              <div class="eval <?= ew_isLong($v) ? 'long' : '' ?>"><?= h(ew_v($v)) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  <?php endif; ?>

</div>
