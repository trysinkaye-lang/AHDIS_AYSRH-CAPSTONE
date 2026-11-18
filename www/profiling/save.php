<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/education_options.php";
require_once __DIR__ . "/../includes/fertility_validation.php";

/* =====================================================
   Helpers
===================================================== */
if (!function_exists('hdb_save')) {
    function hdb_save($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('failValidation_save')) {
    function failValidation_save(string $message): void
    {
        http_response_code(422);
        exit($message);
    }
}

if (!function_exists('baseUrl_save')) {
    function baseUrl_save(): string
    {
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        $dir = preg_replace('#/(auth|config|dashboard|profiling|includes|reports|view profile)$#', '', $dir);
        return $dir ?: '';
    }
}

if (!function_exists('buildUrlFromRelative_save')) {
    function buildUrlFromRelative_save(string $baseUrl, string $relative): string
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');
        if ($relative === '') {
            return $baseUrl ?: '/';
        }

        $segments = array_map(
            static fn($seg) => rawurlencode($seg),
            array_values(array_filter(explode('/', $relative), static fn($x) => $x !== ''))
        );

        $path = implode('/', $segments);
        return rtrim($baseUrl, '/') . '/' . $path;
    }
}

if (!function_exists('resolveExistingRoute_save')) {
    function resolveExistingRoute_save(string $baseUrl, string $projectRoot, array $candidates): string
    {
        foreach ($candidates as $relative) {
            $full = rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
            if (is_file($full)) {
                return buildUrlFromRelative_save($baseUrl, $relative);
            }
        }
        return buildUrlFromRelative_save($baseUrl, $candidates[0] ?? '');
    }
}

if (!function_exists('postv_save')) {
    function postv_save(string $k, $default = null)
    {
        if (!isset($_POST[$k])) {
            return $default;
        }
        if (is_array($_POST[$k])) {
            return $_POST[$k];
        }
        $v = trim((string)$_POST[$k]);
        return $v === '' ? $default : $v;
    }
}

if (!function_exists('postFirst_save')) {
    function postFirst_save(array $keys, $default = null)
    {
        foreach ($keys as $key) {
            $value = postv_save((string)$key, null);
            if ($value !== null && (!is_string($value) || trim($value) !== '')) {
                return $value;
            }
        }
        return $default;
    }
}

if (!function_exists('safeTableName_save')) {
    function safeTableName_save(string $table): string
    {
        $table = trim($table);
        if ($table === '' || !preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new RuntimeException('Invalid table name.');
        }
        return $table;
    }
}

if (!function_exists('safeColumnName_save')) {
    function safeColumnName_save(string $col): string
    {
        $col = trim($col);
        if ($col === '' || !preg_match('/^[A-Za-z0-9_]+$/', $col)) {
            throw new RuntimeException('Invalid column name.');
        }
        return $col;
    }
}

if (!function_exists('tableExists_save')) {
    function tableExists_save(PDO $conn, string $table): bool
    {
        $stmt = $conn->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:t LIMIT 1");
        $stmt->execute([':t' => $table]);
        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('tableColumnsAssoc_save')) {
    function tableColumnsAssoc_save(PDO $conn, string $table): array
    {
        $table = safeTableName_save($table);
        $stmt  = $conn->query("PRAGMA table_info($table)");
        $cols  = [];
        foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $r) {
            $name = (string)($r['name'] ?? '');
            if ($name !== '') {
                $cols[$name] = true;
            }
        }
        return $cols;
    }
}

if (!function_exists('dbHasColumn_save')) {
    function dbHasColumn_save(PDO $conn, string $table, string $col): bool
    {
        try {
            $table = safeTableName_save($table);
            $col   = trim($col);
            if ($col === '') {
                return false;
            }

            $stmt = $conn->query("PRAGMA table_info($table)");
            $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

            foreach ($rows as $r) {
                $name = (string)($r['name'] ?? '');
                if ($name !== '' && strcasecmp($name, $col) === 0) {
                    return true;
                }
            }
            return false;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('addColumnIfMissing_save')) {
    function addColumnIfMissing_save(PDO $conn, string $table, string $col, string $type): void
    {
        if (!tableExists_save($conn, $table)) {
            return;
        }
        if (dbHasColumn_save($conn, $table, $col)) {
            return;
        }

        $table = safeTableName_save($table);
        $col   = safeColumnName_save($col);
        $type  = trim($type);
        if ($type === '') {
            $type = 'TEXT';
        }

        $conn->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$type}");
    }
}

if (!function_exists('appendEducationEncounterColumns_save')) {
    function appendEducationEncounterColumns_save(array $encCols, array &$encSets, array &$encParams, array $educationColumns): void
    {
        foreach ($educationColumns as $column => $value) {
            if (!isset($encCols[$column])) {
                continue;
            }
            $param = ':' . $column;
            $encSets[] = $column . ' = ' . $param;
            $encParams[$param] = $value;
        }
    }
}

if (!function_exists('lowerSafe_save')) {
    function lowerSafe_save(string $s): string
    {
        $s = trim($s);
        if ($s === '') {
            return '';
        }
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }
}

if (!function_exists('normSpaces_save')) {
    function normSpaces_save(string $s): string
    {
        $s = trim($s);
        $s = (string)preg_replace('/\s+/', ' ', $s);
        return $s;
    }
}

if (!function_exists('encounterValueExists_save')) {
    function encounterValueExists_save(PDO $conn, int $adolescentId, string $encounterDate): bool
    {
        $st = $conn->prepare("
            SELECT 1
            FROM encounters
            WHERE adolescent_id = :aid
              AND TRIM(COALESCE(encounter_date,'')) = TRIM(:dt)
            LIMIT 1
        ");
        $st->execute([
            ':aid' => $adolescentId,
            ':dt'  => $encounterDate,
        ]);
        return (bool)$st->fetchColumn();
    }
}

if (!function_exists('buildUniqueEncounterDate_save')) {
    function buildUniqueEncounterDate_save(PDO $conn, int $adolescentId, ?string $baseDate): string
    {
        $baseDate = trim((string)$baseDate);
        if ($baseDate === '') {
            $baseDate = date('Y-m-d');
        }

        $baseDate = substr($baseDate, 0, 10);

        if (!encounterValueExists_save($conn, $adolescentId, $baseDate)) {
            return $baseDate;
        }

        for ($i = 1; $i <= 3650; $i++) {
            $candidateTs = strtotime($baseDate . " +{$i} day");
            if ($candidateTs === false) {
                break;
            }

            $candidate = date('Y-m-d', $candidateTs);
            if (!encounterValueExists_save($conn, $adolescentId, $candidate)) {
                return $candidate;
            }
        }

        return date('Y-m-d');
    }
}

if (!function_exists('saveNowLocal')) {
    function saveNowLocal(): string
    {
        if (function_exists('appNow')) {
            return (string) appNow();
        }
        return date('Y-m-d H:i:s');
    }
}

/* =====================================================
   ROUTING
===================================================== */
$projectRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$baseUrl = baseUrl_save();

$ADD_URL = resolveExistingRoute_save($baseUrl, $projectRoot, [
    'profiling/add.php',
    'add.php',
]);

$ADOL_VIEW_URL = resolveExistingRoute_save($baseUrl, $projectRoot, [
    'profiling/adolescent_view.php',
    'view profile/adolescent_view.php',
]);

$VIEW_URL = resolveExistingRoute_save($baseUrl, $projectRoot, [
    'profiling/view.php',
    'view profile/view.php',
]);

/* =====================================================
   AUTO DATA CLEANING
===================================================== */
if (!function_exists('cleanText_save')) {
    function cleanText_save(?string $v): string
    {
        $s = trim((string)$v);
        $s = (string)preg_replace('/\s+/', ' ', $s);
        return $s;
    }
}

if (!function_exists('upperText_save')) {
    function upperText_save(?string $v): string
    {
        $s = cleanText_save($v);
        return function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
    }
}

if (!function_exists('buildFullNameFromParts_save')) {
    function buildFullNameFromParts_save(string $last, string $first, string $middle): string
    {
        $last = upperText_save($last);
        $first = upperText_save($first);
        $middle = upperText_save($middle);
        $given = trim($first . ($middle !== '' ? ' ' . $middle : ''));
        if ($last !== '' && $given !== '') {
            return $last . ', ' . $given;
        }
        return trim($last . ' ' . $given);
    }
}

if (!function_exists('buildPartnerNameFromParts_save')) {
    function buildPartnerNameFromParts_save(string $surname, string $first, string $middle, string $fallback = ''): string
    {
        $name = buildFullNameFromParts_save($surname, $first, $middle);
        return $name !== '' ? $name : upperText_save($fallback);
    }
}

if (!function_exists('cleanUnknown_save')) {
    function cleanUnknown_save(?string $v, string $fallback = 'Unknown'): string
    {
        $s = cleanText_save($v);
        if ($s === '') {
            return $fallback;
        }
        $low = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
        if (in_array($low, ['n/a', 'na', 'none', 'null', '-', 'unspecified', 'unknown'], true)) {
            return $fallback;
        }
        return $s;
    }
}

if (!function_exists('canonYesNo_save')) {
    function canonYesNo_save(?string $v): string
    {
        $s = cleanText_save($v);
        if ($s === '') {
            return 'Unknown';
        }
        $low = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);

        if (in_array($low, ['yes', 'y', '1', 'true', 'oo', 'opo', 'oui'], true)) {
            return 'Yes';
        }
        if (in_array($low, ['no', 'n', '0', 'false', 'wala', 'hindi'], true)) {
            return 'No';
        }
        return 'Unknown';
    }
}

if (!function_exists('canonCivilStatus_save')) {
    function canonCivilStatus_save(?string $v): string
    {
        $s = cleanText_save($v);
        if ($s === '') {
            return 'Unknown';
        }
        $low = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);

        $map = [
            'single'    => 'Single',
            'married'   => 'Married',
            'widowed'   => 'Widowed',
            'separated' => 'Separated',
            'living in' => 'Living-in',
            'living-in' => 'Living-in',
            'live in'   => 'Living-in',
            'live-in'   => 'Living-in',
        ];

        return $map[$low] ?? cleanUnknown_save($s, 'Unknown');
    }
}

if (!function_exists('isKnownValue_save')) {
    function isKnownValue_save(?string $v): bool
    {
        $s = cleanText_save($v);
        if ($s === '') {
            return false;
        }
        $low = lowerSafe_save($s);
        return !in_array($low, ['unknown', 'n/a', 'na', 'none', 'null', '-', 'unspecified'], true);
    }
}

if (!function_exists('isYesNoValue_save')) {
    function isYesNoValue_save(?string $v): bool
    {
        return in_array(canonYesNo_save($v), ['Yes', 'No'], true);
    }
}

if (!function_exists('canonBarangay_save')) {
    function canonBarangay_save(?string $v): string
    {
        return cleanUnknown_save($v, 'Unknown');
    }
}

if (!function_exists('canonMunicipality_save')) {
    function canonMunicipality_save(?string $v): string
    {
        return cleanUnknown_save($v, 'Unknown');
    }
}

if (!function_exists('normalizeDobToIso_save')) {
    function normalizeDobToIso_save(?string $raw): ?string
    {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            [$yy, $mm, $dd] = array_map('intval', explode('-', $raw));
            if (!checkdate($mm, $dd, $yy)) {
                return null;
            }
            return sprintf('%04d-%02d-%02d', $yy, $mm, $dd);
        }

        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $raw, $m)) {
            $mm = (int)$m[1];
            $dd = (int)$m[2];
            $yy = (int)$m[3];
            if (!checkdate($mm, $dd, $yy)) {
                return null;
            }
            return sprintf('%04d-%02d-%02d', $yy, $mm, $dd);
        }

        if (preg_match('/^(\d{2})[-.](\d{2})[-.](\d{4})$/', $raw, $m)) {
            $mm = (int)$m[1];
            $dd = (int)$m[2];
            $yy = (int)$m[3];
            if (!checkdate($mm, $dd, $yy)) {
                return null;
            }
            return sprintf('%04d-%02d-%02d', $yy, $mm, $dd);
        }

        $ts = strtotime($raw);
        if ($ts === false) {
            return null;
        }
        return date('Y-m-d', $ts);
    }
}

if (!function_exists('calcAgeFromIso_save')) {
    function calcAgeFromIso_save(?string $dobIso): ?int
    {
        $dobIso = trim((string)$dobIso);
        if ($dobIso === '') {
            return null;
        }
        $d = DateTime::createFromFormat('Y-m-d', $dobIso);
        if (!$d || $d->format('Y-m-d') !== $dobIso) {
            return null;
        }
        $today = new DateTime('today');
        $d->setTime(0, 0, 0);
        if ($d > $today) {
            return null;
        }
        return (int)$d->diff($today)->y;
    }
}

if (!function_exists('govAssistanceOptions_save')) {
    function govAssistanceOptions_save(): array
    {
        return [
            'Livelihood Training',
            'Skills Training',
            'Health Services',
            'Scholarship for schooling',
            'Others',
        ];
    }
}

if (!function_exists('firstPregnancyAgeError_save')) {
    function firstPregnancyAgeError_save($raw, int $currentAge): string
    {
        $s = trim((string)$raw);
        if ($s === '') {
            return 'Please enter age at first pregnancy.';
        }
        if (!ctype_digit($s)) {
            return 'Age at first pregnancy must be a whole number.';
        }

        // Open numeric answer; do not impose the 9-19 adolescent bracket.
        // Reject 0 and unrealistic values, and ensure safe integer conversion.
        $firstAge = filter_var($s, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 150]]);
        if ($firstAge === false) {
            return 'Age at first pregnancy must be a positive whole number.';
        }
        if ($firstAge > $currentAge) {
            return 'Age at first pregnancy cannot be greater than current age.';
        }

        return '';
    }
}

if (!function_exists('monthsPregnantError_save')) {
    function monthsPregnantError_save($raw, string $pregnant): string
    {
        $s = trim((string)$raw);
        
        // Only validate if currently pregnant = Yes
        if ($pregnant !== 'Yes') {
            return '';
        }
        
        if ($s === '') {
            return 'Months pregnant is required when currently pregnant.';
        }
        
        if (!ctype_digit($s)) {
            return 'Months pregnant must be a whole number.';
        }
        
        $months = (int)$s;
        if ($months > 9) {
            return 'Pregnancy typically does not exceed 9 months.';
        }
        
        return '';
    }
}

if (!function_exists('timesPregnantError_save')) {
    function timesPregnantError_save($raw): string
    {
        $s = trim((string)$raw);
        if ($s === '') {
            return '';
        }
        
        if (!ctype_digit($s)) {
            return 'Times pregnant must be a whole number.';
        }
        
        return '';
    }
}

if (!function_exists('livingChildrenError_save')) {
    function livingChildrenError_save($raw): string
    {
        return ahdisNonNegativeWholeNumberError($raw, 'Living children');
    }
}

if (!function_exists('monthlyIncomeError_save')) {
    function monthlyIncomeError_save($raw): string
    {
        $s = trim((string)$raw);
        if ($s === '') {
            return '';
        }
        
        // Remove commas and check if remaining string is digits only
        $digitsOnly = str_replace(',', '', $s);
        if (!ctype_digit($digitsOnly)) {
            return 'Monthly income must contain digits only.';
        }
        
        return '';
    }
}

if (!function_exists('normalizeDateToIso_save')) {
    function normalizeDateToIso_save(?string $raw): ?string
    {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw;
        }
        return normalizeDobToIso_save($raw);
    }
}

if (!function_exists('splitNameGuess_save')) {
    function splitNameGuess_save(string $full): array
    {
        $full = normSpaces_save($full);
        if ($full === '') {
            return ['', '', ''];
        }

        if (strpos($full, ',') !== false) {
            [$last, $rest] = array_map('trim', explode(',', $full, 2));
            $parts = preg_split('/\s+/', trim($rest)) ?: [];
            $first = $parts[0] ?? '';
            $middle = '';
            if (count($parts) > 1) {
                $middle = implode(' ', array_slice($parts, 1));
            }
            return [$last, $first, $middle];
        }

        $parts = preg_split('/\s+/', $full) ?: [];
        if (count($parts) === 1) {
            return ['', $parts[0], ''];
        }
        if (count($parts) === 2) {
            return [$parts[1], $parts[0], ''];
        }

        $first = $parts[0];
        $last = $parts[count($parts) - 1];
        $middle = implode(' ', array_slice($parts, 1, -1));
        return [$last, $first, $middle];
    }
}

/* =====================================================
   Ensure PDO connection exists
===================================================== */
if (!($conn instanceof PDO)) {
    http_response_code(500);
    exit('DB not available.');
}
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* =====================================================
   METHOD + CSRF
===================================================== */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

$sessionToken = (string)($_SESSION['csrf_profiling_form'] ?? '');
$postedToken  = (string)($_POST['csrf_token'] ?? '');

if ($sessionToken === '' || $postedToken === '' || !hash_equals($sessionToken, $postedToken)) {
    http_response_code(400);
    exit('Invalid CSRF token.');
}

if ((string)($_POST['review_confirmed'] ?? '') !== '1') {
    failValidation_save('Please review the details and click Confirm and Submit before saving.');
}

/* =====================================================
   INPUTS
===================================================== */
$legacyPostedId = (int)($_POST['id'] ?? 0);
$legacyAliasId  = (int)($_POST['legacy_id'] ?? 0);
if ($legacyPostedId <= 0 && $legacyAliasId > 0) {
    $legacyPostedId = $legacyAliasId;
}

$postedEncounterId = (int)($_POST['encounter_id'] ?? 0);
$postedAdolescentId = (int)($_POST['adolescent_id'] ?? 0);

$editLatestFlag = ((int)($_POST['edit_latest'] ?? 0) === 1);
$editLatest     = ($editLatestFlag && ($legacyPostedId > 0 || $postedEncounterId > 0));

$lastNamePosted   = upperText_save((string)postv_save('last_name', ''));
$firstNamePosted  = upperText_save((string)postv_save('first_name', ''));
$middleNamePosted = upperText_save((string)postv_save('middle_name', ''));
$nameRaw          = buildFullNameFromParts_save($lastNamePosted, $firstNamePosted, $middleNamePosted);
if ($nameRaw === '') {
    $nameRaw = upperText_save((string)postv_save('name'));
}
$name = cleanText_save($nameRaw);

$municipalityRaw = (string)postv_save('locked_municipality');
$municipality    = canonMunicipality_save($municipalityRaw);

$lockedBarangayRaw = (string)postv_save('locked_barangay');
$barangayInputRaw  = (string)postv_save('barangay');

$lockedBarangay = canonBarangay_save($lockedBarangayRaw);
$barangayInput  = canonBarangay_save($barangayInputRaw);

$barangayFinal  = ($lockedBarangayRaw !== '' ? $lockedBarangay : $barangayInput);

if ($lastNamePosted === '') {
    failValidation_save('Surname is required.');
}
if ($firstNamePosted === '') {
    failValidation_save('First Name is required.');
}
if ($name === '') {
    failValidation_save('Name is required.');
}
if ($municipality === 'Unknown') {
    failValidation_save('Municipality is required. Please update your Account Location first.');
}
if ($barangayFinal === 'Unknown') {
    failValidation_save('Barangay is required.');
}

$presentAddressPosted = cleanText_save((string)postv_save('present_address'));
$presentAddressFinal  = trim($presentAddressPosted);
if ($presentAddressFinal === '') {
    $presentAddressFinal = trim($barangayFinal . ', ' . $municipality);
}

$dobRaw = (string)postv_save('date_of_birth');
$dobIso = normalizeDobToIso_save($dobRaw);
$age    = calcAgeFromIso_save($dobIso);

if (trim($dobRaw) === '') {
    failValidation_save('Date of Birth is required.');
}
if ($dobIso === null) {
    failValidation_save('Please select a valid Date of Birth.');
}

$dobDateCheck = DateTime::createFromFormat('Y-m-d', $dobIso);
if (!$dobDateCheck || $dobDateCheck->format('Y-m-d') !== $dobIso) {
    failValidation_save('Please select a valid Date of Birth.');
}
if ($dobDateCheck > new DateTime('today')) {
    failValidation_save('Date of Birth cannot be in the future.');
}
if ($age === null || $age < 9 || $age > 19) {
    failValidation_save('Date of Birth must be for an adolescent age 9-19.');
}

$education = ahdisNormalizeEducationAttainment((string)postFirst_save(['educational_attainment', 'education'], ''));
if ($education === '') {
    failValidation_save('Educational Attainment is required.');
}

$currentlyEnrolled = canonYesNo_save((string)postv_save('currently_enrolled'));
if (!isYesNoValue_save($currentlyEnrolled)) {
    failValidation_save('Are you currently enrolled is required.');
}

$yearLevelRaw = ($education === 'Vocational' || $education === 'College Level')
    ? (string)postv_save('year_level_text', '')
    : (string)postFirst_save(['grade_year_level', 'year_level'], '');
$yearLevel = ahdisNormalizeGradeYearLevel($education, $yearLevelRaw);
if ($education === 'No Education') {
    $yearLevel = '';
} elseif ($yearLevel === '') {
    if ($education === 'Vocational') {
        failValidation_save('Vocational course/program is required.');
    } elseif ($education === 'College Level') {
        failValidation_save('Course/program and year level is required.');
    } else {
        failValidation_save('Grade / Year Level is required and must match the selected Educational Attainment.');
    }
}

$lastSchoolAttendedType = ahdisNormalizeLastSchoolAttendedType((string)postv_save('last_school_attended_type'));
if ($lastSchoolAttendedType === '') {
    failValidation_save('Last school attended is required.');
}

$reasonNotAttendingSchool = '';
$reasonNotAttendingSchoolOther = '';
if ($currentlyEnrolled === 'No') {
    $reasonValues = ahdisNormalizeReasonsNotAttendingSchool(postv_save('reason_not_attending_school', []));
    $allowedReasonValues = array_values(array_filter(
        ahdisReasonNotAttendingSchoolOptions(),
        static fn(string $reason): bool => !in_array($reason, ['Currently attending school', 'Not applicable'], true)
    ));

    if (!$reasonValues) {
        failValidation_save('Reason for not attending school is required.');
    }
    foreach ($reasonValues as $reasonValue) {
        if (!in_array($reasonValue, $allowedReasonValues, true)) {
            failValidation_save('Please select only valid reasons for not attending school.');
        }
    }

    $reasonNotAttendingSchool = ahdisSerializeReasonsNotAttendingSchool($reasonValues);
    $hasOtherReason = in_array('Other', $reasonValues, true);

    $reasonNotAttendingSchoolOther = cleanText_save((string)postv_save('reason_not_attending_school_other'));
    if ($hasOtherReason && $reasonNotAttendingSchoolOther === '') {
        failValidation_save('Please specify reason for not attending school.');
    }

    if (!$hasOtherReason) {
        $reasonNotAttendingSchoolOther = '';
    }
}

$vocationalTrainingOther = '';

$civilRaw = canonCivilStatus_save((string)postv_save('civil_status'));
if (!isKnownValue_save($civilRaw)) {
    failValidation_save('Civil Status is required.');
}

$livingSituation = cleanUnknown_save((string)postv_save('living_situation'), 'Unknown');
if (!isKnownValue_save($livingSituation)) {
    failValidation_save('Living Situation is required.');
}

$pregnant = canonYesNo_save((string)postv_save('pregnant'));
if (!isYesNoValue_save($pregnant)) {
    failValidation_save('Are you currently pregnant is required.');
}

$is4ps = canonYesNo_save((string)postv_save('is_4ps'));
if (!isYesNoValue_save($is4ps)) {
    failValidation_save('4Ps beneficiary is required.');
}

$isIps = canonYesNo_save((string)postv_save('is_ips'));
if (!isYesNoValue_save($isIps)) {
    failValidation_save('IP member is required.');
}

$currentlyWorking = canonYesNo_save((string)postv_save('currently_working'));
$occupation = upperText_save((string)postv_save('occupation'));
$monthlyIncome = (string)postv_save('monthly_income');

if (!isYesNoValue_save($currentlyWorking)) {
    failValidation_save('Currently working is required.');
}
if ($currentlyWorking === 'Yes' && $occupation === '') {
    failValidation_save('Occupation is required when Currently Working is Yes.');
}

// Validate monthly income format
$monthlyIncomeError = monthlyIncomeError_save($monthlyIncome);
if ($monthlyIncomeError !== '') {
    failValidation_save($monthlyIncomeError);
}

if ($currentlyWorking !== 'Yes') {
    $occupation = '';
    $monthlyIncome = '';
}

/* =====================================================
   GOV ASSISTANCE
===================================================== */
$govSelected = $_POST['gov_assistance'] ?? [];
$govSpec     = $_POST['gov_assistance_spec'] ?? [];

if (!is_array($govSelected)) {
    $govSelected = [];
}
if (!is_array($govSpec)) {
    $govSpec = [];
}

$govClean   = [];
$govDetails = [];
$allowedGov = [];
foreach (govAssistanceOptions_save() as $allowedOption) {
    $allowedGov[lowerSafe_save($allowedOption)] = $allowedOption;
}

foreach ($govSelected as $program) {
    $program = cleanText_save((string)$program);
    if ($program === '') {
        continue;
    }

    $programKey = lowerSafe_save($program);
    if (!isset($allowedGov[$programKey])) {
        failValidation_save('Invalid government assistance option.');
    }

    $program = $allowedGov[$programKey];
    $detail = isset($govSpec[$program]) ? upperText_save((string)$govSpec[$program]) : '';

    $govClean[] = $program;
    if ($detail !== '') {
        $govDetails[$program] = $detail;
    }
}

$govJson        = $govClean ? json_encode($govClean, JSON_UNESCAPED_UNICODE) : null;
$govDetailsJson = $govDetails ? json_encode($govDetails, JSON_UNESCAPED_UNICODE) : null;

if ($govClean && $govJson === false) {
    $govJson = null;
}
if ($govDetails && $govDetailsJson === false) {
    $govDetailsJson = null;
}

/* =====================================================
   PARTNER LOGIC
===================================================== */
$civilKey = lowerSafe_save($civilRaw);
$allowPartner = in_array($civilKey, ['married', 'living-in'], true);

$partnerDobIso = null;
$partnerDobRaw = (string)postv_save('partner_dob');
if ($allowPartner) {
    $partnerDobIso = normalizeDobToIso_save($partnerDobRaw);
}

$partnerSurname = $allowPartner
    ? upperText_save((string)postFirst_save(['partner_surname', 'partner_last_name', 'p_surname', 'p_last_name'], ''))
    : null;
$partnerFirstName = $allowPartner
    ? upperText_save((string)postFirst_save(['partner_first_name', 'p_first_name'], ''))
    : null;
$partnerMiddleName = $allowPartner
    ? upperText_save((string)postFirst_save(['partner_middle_name', 'p_middle_name'], ''))
    : null;
$partnerLegacyName = $allowPartner
    ? upperText_save((string)postFirst_save(['partner_name', 'p_name'], ''))
    : null;
$partnerFullName = $allowPartner
    ? buildPartnerNameFromParts_save((string)$partnerSurname, (string)$partnerFirstName, (string)$partnerMiddleName, (string)$partnerLegacyName)
    : null;
$partnerEducation = $allowPartner
    ? ahdisNormalizeEducationAttainment((string)postv_save('partner_education'))
    : null;
$partnerCurrentlyEnrolled = $allowPartner
    ? canonYesNo_save((string)postv_save('partner_currently_enrolled'))
    : null;
$partnerYearLevelRaw = $allowPartner
    ? (string)postv_save('partner_year_level')
    : '';
$partnerYearLevel = $allowPartner
    ? ahdisNormalizePartnerGradeYearLevel($partnerEducation, $partnerYearLevelRaw)
    : null;

$partnerData = [
    'partner_name'               => $partnerFullName,
    'partner_surname'            => $partnerSurname,
    'partner_first_name'         => $partnerFirstName,
    'partner_middle_name'        => $partnerMiddleName,
    'partner_address'            => $allowPartner ? upperText_save((string)postv_save('partner_address')) : null,
    'partner_place_of_birth'     => $allowPartner ? upperText_save((string)postv_save('partner_place_of_birth')) : null,
    'partner_dob'                => $allowPartner ? $partnerDobIso : null,
    'partner_age'                => $allowPartner ? calcAgeFromIso_save($partnerDobIso) : null,
    'partner_education'          => $partnerEducation,
    'partner_currently_enrolled' => $partnerCurrentlyEnrolled,
    'partner_year_level'         => $partnerYearLevel !== null ? upperText_save($partnerYearLevel) : null,
    'partner_currently_working'  => $allowPartner ? canonYesNo_save((string)postv_save('partner_currently_working')) : null,
    'partner_monthly_income'     => $allowPartner ? cleanText_save((string)postv_save('partner_monthly_income')) : null,
    'partner_occupation'         => $allowPartner ? upperText_save((string)postv_save('partner_occupation')) : null,
];

if ($allowPartner) {
    if ((string)$partnerData['partner_surname'] === '') {
        failValidation_save('Partner Surname is required when Civil Status is Married or Living-in.');
    }
    if ((string)$partnerData['partner_first_name'] === '') {
        failValidation_save('Partner First Name is required when Civil Status is Married or Living-in.');
    }
    if ((string)$partnerData['partner_address'] === '') {
        failValidation_save('Partner Address is required when Civil Status is Married or Living-in.');
    }
    if ((string)$partnerData['partner_place_of_birth'] === '') {
        failValidation_save('Partner Place of Birth is required when Civil Status is Married or Living-in.');
    }
    if (trim($partnerDobRaw) === '') {
        failValidation_save('Partner Date of Birth is required when Civil Status is Married or Living-in.');
    }
    if ($partnerDobIso === null) {
        failValidation_save('Please select a valid Partner Date of Birth.');
    }

    $partnerDobDateCheck = DateTime::createFromFormat('Y-m-d', $partnerDobIso);
    if (!$partnerDobDateCheck || $partnerDobDateCheck->format('Y-m-d') !== $partnerDobIso) {
        failValidation_save('Please select a valid Partner Date of Birth.');
    }
    if ($partnerDobDateCheck > new DateTime('today')) {
        failValidation_save('Partner Date of Birth cannot be in the future.');
    }

    if (!isKnownValue_save((string)$partnerData['partner_education'])) {
        failValidation_save('Partner Educational Attainment is required when Civil Status is Married or Living-in.');
    }
    if (!isYesNoValue_save((string)$partnerData['partner_currently_enrolled'])) {
        failValidation_save('Partner Currently Enrolled is required when Civil Status is Married or Living-in.');
    }
    if ($partnerData['partner_currently_enrolled'] === 'Yes'
        && (string)$partnerData['partner_education'] !== 'No Education'
        && (string)$partnerData['partner_year_level'] === ''
    ) {
        failValidation_save('Partner Grade/Year Level is required when Partner Currently Enrolled is Yes.');
    }
    if ($partnerData['partner_currently_enrolled'] !== 'Yes') {
        $partnerData['partner_year_level'] = '';
    }

    if (!isYesNoValue_save((string)$partnerData['partner_currently_working'])) {
        failValidation_save('Partner Currently Working is required when Civil Status is Married or Living-in.');
    }
    if ($partnerData['partner_currently_working'] === 'Yes') {
        if ((string)$partnerData['partner_monthly_income'] === '') {
            failValidation_save('Partner Monthly Income is required when Partner Currently Working is Yes.');
        }
        
        // Validate partner monthly income format
        $partnerIncomeError = monthlyIncomeError_save((string)$partnerData['partner_monthly_income']);
        if ($partnerIncomeError !== '') {
            failValidation_save($partnerIncomeError);
        }
        
        if ((string)$partnerData['partner_occupation'] === '') {
            failValidation_save('Partner Occupation is required when Partner Currently Working is Yes.');
        }
    } else {
        $partnerData['partner_monthly_income'] = '';
        $partnerData['partner_occupation'] = '';
    }
}

/* =====================================================
   PREGNANCY RULES
===================================================== */
$monthsPregnant = postv_save('months_pregnant');
$prenatalCare   = cleanText_save((string)postv_save('prenatal_care'));
$healthConcerns = cleanText_save((string)postv_save('health_concerns'));

if ($pregnant === 'No') {
    $monthsPregnant = null;
    $prenatalCare   = null;
    $healthConcerns = null;
}

$ageFirst       = postv_save('age_first_pregnancy');
$timesPreg      = postv_save('times_pregnant');
$livingChildren = postv_save('living_children');

$familyPlanningPosted = cleanText_save((string)postv_save('family_planning'));
$familyPlanning = ahdisNormalizeFamilyPlanning($familyPlanningPosted);
if ($familyPlanning === '') {
    $familyPlanning = 'Unknown';
} elseif (!isset(ahdisFamilyPlanningOptions()[$familyPlanning])) {
    $familyPlanningCurrent = cleanText_save((string)postv_save('family_planning_current'));
    $legacyPreserved = (
        $editLatest ||
        ($familyPlanningCurrent !== '' && ahdisNormalizeFamilyPlanning($familyPlanningCurrent) === $familyPlanning)
    );
    if (!$legacyPreserved) {
        failValidation_save('Family Planning method currently used must be a valid option.');
    }
}

$ageFirstError = firstPregnancyAgeError_save($ageFirst, (int)$age);
if ($ageFirstError !== '') {
    failValidation_save($ageFirstError);
}
$ageFirst = (int)trim((string)$ageFirst);

// Validate months pregnant
$monthsPregnantError = monthsPregnantError_save($monthsPregnant, $pregnant);
if ($monthsPregnantError !== '') {
    failValidation_save($monthsPregnantError);
}

// Validate times pregnant
$timesPregnantError = timesPregnantError_save($timesPreg);
if ($timesPregnantError !== '') {
    failValidation_save($timesPregnantError);
}

// Validate living children
$livingChildrenError = livingChildrenError_save($livingChildren);
if ($livingChildrenError !== '') {
    failValidation_save($livingChildrenError);
}

$interviewDateRaw  = cleanText_save((string)postv_save('interview_date'));
$encounterDateBase = normalizeDateToIso_save($interviewDateRaw) ?: date('Y-m-d');
$encounterDateBase = substr($encounterDateBase, 0, 10);
$encounterDateIso  = $encounterDateBase;
$interviewedBy = upperText_save((string)postv_save('interviewed_by'));
if ($interviewedBy === '') {
    failValidation_save('Interviewed by is required.');
}
$encodedBy = upperText_save((string)postv_save('encoded_by'));
if ($encodedBy === '') {
    failValidation_save('Encoded By is required.');
}

// The server is authoritative for new submissions. Edit Latest preserves the
// date already associated with that submission (resolved again after loading it).
$dateEncoded = $editLatest
    ? (normalizeDateToIso_save(cleanText_save((string)postv_save('date_encoded'))) ?? '')
    : date('Y-m-d');

/* =====================================================
   FINAL DATA MAP
===================================================== */
$data = [
    'locked_municipality' => $municipality,
    'locked_barangay'     => $barangayFinal,
    'barangay'            => ($barangayInputRaw !== '' ? $barangayInput : $barangayFinal),

    'name'                => $name,
    'last_name'           => $lastNamePosted,
    'first_name'          => $firstNamePosted,
    'middle_name'         => $middleNamePosted,
    'date_of_birth'       => $dobIso,
    'age'                 => $age,

    'present_address'     => $presentAddressFinal,
    'place_of_birth'      => upperText_save((string)postv_save('place_of_birth')),
    'sex'                 => cleanText_save((string)postv_save('sex')),

    'education'           => $education,
    'educational_attainment' => $education,
    'currently_enrolled'  => $currentlyEnrolled,
    'year_level'          => $yearLevel,
    'grade_year_level'    => $yearLevel,
    'last_school_attended_type' => $lastSchoolAttendedType,
    'reason_not_attending_school' => $reasonNotAttendingSchool,
    'reason_for_not_attending_school' => $reasonNotAttendingSchool,
    'reason_not_attending_school_other' => $reasonNotAttendingSchoolOther,
    'vocational_training_other' => $vocationalTrainingOther,

    'civil_status'        => $civilRaw,
    'living_situation'    => $livingSituation,
    'living_others'       => upperText_save((string)postv_save('living_others')),

    'pregnant'            => $pregnant,
    'months_pregnant'     => $monthsPregnant,
    'prenatal_care'       => ($prenatalCare !== '' ? $prenatalCare : null),
    'health_concerns'     => ($healthConcerns !== '' ? $healthConcerns : null),

    'age_first_pregnancy' => $ageFirst,
    'times_pregnant'      => $timesPreg,
    'living_children'     => $livingChildren,
    'child_under_one'     => canonYesNo_save((string)postv_save('child_under_one')),
    'family_planning'     => $familyPlanning,

    'is_4ps'              => $is4ps,
    'is_ips'              => $isIps,
    'ethnicity'           => cleanUnknown_save(upperText_save((string)postv_save('ethnicity')), 'Unknown'),

    'currently_working'   => $currentlyWorking,
    'monthly_income'      => cleanText_save($monthlyIncome),
    'occupation'          => $occupation,

    'father_occupation'   => upperText_save((string)postv_save('father_occupation')),
    'mother_occupation'   => upperText_save((string)postv_save('mother_occupation')),

    'gov_assistance'         => $govJson,
    'gov_assistance_details' => $govDetailsJson,
    'gov_assistance_other'   => upperText_save((string)postv_save('gov_assistance_other')),

    'other_observations'  => cleanText_save((string)postv_save('other_observations')),
    'interviewed_by'      => $interviewedBy,
    'noted_by'            => upperText_save((string)postv_save('noted_by')),
    'interview_date'      => $interviewDateRaw,
    'noted_date'          => cleanText_save((string)postv_save('noted_date')),
    'encoded_by'          => $encodedBy,
    'date_encoded'        => $dateEncoded,
];

$data = array_merge($data, $partnerData);

$educationColumnValues = [
    'currently_enrolled' => $currentlyEnrolled !== '' ? $currentlyEnrolled : null,
    'educational_attainment' => $education !== '' ? $education : null,
    'grade_year_level' => $yearLevel !== '' ? $yearLevel : null,
    'last_school_attended_type' => $lastSchoolAttendedType !== '' ? $lastSchoolAttendedType : null,
    'reason_not_attending_school' => $reasonNotAttendingSchool !== '' ? $reasonNotAttendingSchool : null,
    'reason_for_not_attending_school' => $reasonNotAttendingSchool !== '' ? $reasonNotAttendingSchool : null,
    'reason_not_attending_school_other' => $reasonNotAttendingSchoolOther !== '' ? $reasonNotAttendingSchoolOther : null,
    'vocational_training_other' => $vocationalTrainingOther !== '' ? $vocationalTrainingOther : null,
];

/* =====================================================
   Ensure tables exist
===================================================== */
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
            created_at TEXT,
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
            created_at TEXT,
            verified_by_user_id INTEGER,
            verified_at TEXT
        );
    ");

    addColumnIfMissing_save($conn, 'adolescents', 'municipality', 'TEXT');
    addColumnIfMissing_save($conn, 'adolescents', 'barangay', 'TEXT');
    addColumnIfMissing_save($conn, 'adolescents', 'present_address', 'TEXT');
    addColumnIfMissing_save($conn, 'adolescents', 'sex', 'TEXT');
    addColumnIfMissing_save($conn, 'adolescents', 'educational_attainment', 'TEXT');
    addColumnIfMissing_save($conn, 'adolescents', 'currently_enrolled', 'TEXT');
    addColumnIfMissing_save($conn, 'adolescents', 'grade_year_level', 'TEXT');
    addColumnIfMissing_save($conn, 'adolescents', 'last_school_attended_type', 'TEXT');
    addColumnIfMissing_save($conn, 'adolescents', 'reason_not_attending_school', 'TEXT');
    addColumnIfMissing_save($conn, 'adolescents', 'reason_for_not_attending_school', 'TEXT');
    addColumnIfMissing_save($conn, 'adolescents', 'reason_not_attending_school_other', 'TEXT');
    addColumnIfMissing_save($conn, 'adolescents', 'vocational_training_other', 'TEXT');
    addColumnIfMissing_save($conn, 'adolescents', 'updated_at', 'TEXT');
    addColumnIfMissing_save($conn, 'adolescents', 'created_at', 'TEXT');

    addColumnIfMissing_save($conn, 'encounters', 'educational_attainment', 'TEXT');
    addColumnIfMissing_save($conn, 'encounters', 'currently_enrolled', 'TEXT');
    addColumnIfMissing_save($conn, 'encounters', 'grade_year_level', 'TEXT');
    addColumnIfMissing_save($conn, 'encounters', 'last_school_attended_type', 'TEXT');
    addColumnIfMissing_save($conn, 'encounters', 'reason_not_attending_school', 'TEXT');
    addColumnIfMissing_save($conn, 'encounters', 'reason_for_not_attending_school', 'TEXT');
    addColumnIfMissing_save($conn, 'encounters', 'reason_not_attending_school_other', 'TEXT');
    addColumnIfMissing_save($conn, 'encounters', 'vocational_training_other', 'TEXT');
    addColumnIfMissing_save($conn, 'encounters', 'encoded_by', 'TEXT');
    addColumnIfMissing_save($conn, 'encounters', 'date_encoded', 'TEXT');
    addColumnIfMissing_save($conn, 'encounters', 'created_by_user_id', 'INTEGER');
    addColumnIfMissing_save($conn, 'encounters', 'created_at', 'TEXT');
    addColumnIfMissing_save($conn, 'encounters', 'verified_by_user_id', 'INTEGER');
    addColumnIfMissing_save($conn, 'encounters', 'verified_at', 'TEXT');

    if (tableExists_save($conn, 'adolescent_mothers')) {
        addColumnIfMissing_save($conn, 'adolescent_mothers', 'adolescent_id', 'INTEGER');
        addColumnIfMissing_save($conn, 'adolescent_mothers', 'encounter_date', 'TEXT');
        addColumnIfMissing_save($conn, 'adolescent_mothers', 'programs_applied', 'TEXT');
        addColumnIfMissing_save($conn, 'adolescent_mothers', 'status', 'TEXT');
        addColumnIfMissing_save($conn, 'adolescent_mothers', 'encoded_by_user_id', 'INTEGER');

        addColumnIfMissing_save($conn, 'adolescent_mothers', 'locked_municipality', 'TEXT');
        addColumnIfMissing_save($conn, 'adolescent_mothers', 'locked_barangay', 'TEXT');
        addColumnIfMissing_save($conn, 'adolescent_mothers', 'last_name', 'TEXT');
        addColumnIfMissing_save($conn, 'adolescent_mothers', 'first_name', 'TEXT');
        addColumnIfMissing_save($conn, 'adolescent_mothers', 'middle_name', 'TEXT');
        addColumnIfMissing_save($conn, 'adolescent_mothers', 'date_of_birth', 'TEXT');
        addColumnIfMissing_save($conn, 'adolescent_mothers', 'interview_date', 'TEXT');
        addColumnIfMissing_save($conn, 'adolescent_mothers', 'encoded_by', 'TEXT');
        addColumnIfMissing_save($conn, 'adolescent_mothers', 'date_encoded', 'TEXT');
        addColumnIfMissing_save($conn, 'adolescent_mothers', 'educational_attainment', 'TEXT');
        addColumnIfMissing_save($conn, 'adolescent_mothers', 'currently_enrolled', 'TEXT');
        addColumnIfMissing_save($conn, 'adolescent_mothers', 'grade_year_level', 'TEXT');
        addColumnIfMissing_save($conn, 'adolescent_mothers', 'last_school_attended_type', 'TEXT');
        addColumnIfMissing_save($conn, 'adolescent_mothers', 'reason_not_attending_school', 'TEXT');
        addColumnIfMissing_save($conn, 'adolescent_mothers', 'reason_for_not_attending_school', 'TEXT');
        addColumnIfMissing_save($conn, 'adolescent_mothers', 'reason_not_attending_school_other', 'TEXT');
        addColumnIfMissing_save($conn, 'adolescent_mothers', 'vocational_training_other', 'TEXT');

        addColumnIfMissing_save($conn, 'adolescent_mothers', 'gov_assistance', 'TEXT');
        addColumnIfMissing_save($conn, 'adolescent_mothers', 'gov_assistance_details', 'TEXT');
        addColumnIfMissing_save($conn, 'adolescent_mothers', 'gov_assistance_other', 'TEXT');
        addColumnIfMissing_save($conn, 'adolescent_mothers', 'partner_name', 'TEXT');
        addColumnIfMissing_save($conn, 'adolescent_mothers', 'partner_surname', 'TEXT');
        addColumnIfMissing_save($conn, 'adolescent_mothers', 'partner_first_name', 'TEXT');
        addColumnIfMissing_save($conn, 'adolescent_mothers', 'partner_middle_name', 'TEXT');
        addColumnIfMissing_save($conn, 'adolescent_mothers', 'data_json', 'TEXT');
        addColumnIfMissing_save($conn, 'adolescent_mothers', 'created_at', 'TEXT');
    }

    $conn->exec("
        CREATE UNIQUE INDEX IF NOT EXISTS ux_adolescents_person
        ON adolescents (
            LOWER(TRIM(COALESCE(last_name,''))),
            LOWER(TRIM(COALESCE(first_name,''))),
            LOWER(TRIM(COALESCE(middle_name,''))),
            TRIM(COALESCE(date_of_birth,''))
        );
    ");

    $conn->exec("
        CREATE UNIQUE INDEX IF NOT EXISTS ux_encounters_adolescent_date
        ON encounters (
            adolescent_id,
            encounter_date
        );
    ");
} catch (Throwable $e) {
    // continue safely
}

/* =====================================================
   Resolve current user
===================================================== */
$actorUserId = (int)($_SESSION['user_id'] ?? ($_SESSION['id'] ?? 0));
$nowLocal = saveNowLocal();

/* =====================================================
   MAIN SAVE
===================================================== */
try {
    $conn->beginTransaction();

    $legacyRow = null;
    $encounterRow = null;
    if ($legacyPostedId > 0 && tableExists_save($conn, 'adolescent_mothers')) {
        $stL = $conn->prepare("SELECT * FROM adolescent_mothers WHERE id=:id LIMIT 1");
        $stL->execute([':id' => $legacyPostedId]);
        $legacyRow = $stL->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($editLatest && !$legacyRow) {
            $conn->rollBack();
            http_response_code(404);
            exit('Legacy snapshot not found. Cannot Edit Latest.');
        }

        if ($legacyRow) {
            $legacyLinkedAid = (int)($legacyRow['adolescent_id'] ?? 0);
            if ($legacyLinkedAid > 0 && $postedAdolescentId <= 0) {
                $postedAdolescentId = $legacyLinkedAid;
            }
        }
    }

    if ($postedEncounterId > 0 && tableExists_save($conn, 'encounters')) {
        $stEncounter = $conn->prepare("SELECT * FROM encounters WHERE id = :id LIMIT 1");
        $stEncounter->execute([':id' => $postedEncounterId]);
        $encounterRow = $stEncounter->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($editLatest && !$encounterRow && $legacyPostedId <= 0) {
            $conn->rollBack();
            http_response_code(404);
            exit('Encounter not found. Cannot Edit Latest.');
        }

        if ($encounterRow) {
            $encounterLinkedAid = (int)($encounterRow['adolescent_id'] ?? 0);
            if ($encounterLinkedAid > 0 && $postedAdolescentId <= 0) {
                $postedAdolescentId = $encounterLinkedAid;
            }
        }
    }

    if ($editLatest) {
        $dateSourceRow = is_array($encounterRow) ? $encounterRow : (is_array($legacyRow) ? $legacyRow : []);
        $storedDateEncoded = trim((string)($dateSourceRow['date_encoded'] ?? ''));
        if ($storedDateEncoded === '') {
            $storedJson = json_decode((string)($dateSourceRow['data_json'] ?? ''), true);
            if (is_array($storedJson)) {
                $storedDateEncoded = trim((string)($storedJson['date_encoded'] ?? ''));
            }
        }
        $dateEncoded = $storedDateEncoded === ''
            ? ''
            : (normalizeDateToIso_save($storedDateEncoded) ?? $storedDateEncoded);
        $data['date_encoded'] = $dateEncoded;
    }

    $lastName = $lastNamePosted;
    $firstName = $firstNamePosted;
    $middleName = $middleNamePosted;

    $adolescentId = 0;
    $rowA = null;

    if ($postedAdolescentId > 0) {
        $chkA = $conn->prepare("SELECT id, barangay, municipality, date_of_birth FROM adolescents WHERE id = :id LIMIT 1");
        $chkA->execute([':id' => $postedAdolescentId]);
        $rowA = $chkA->fetch(PDO::FETCH_ASSOC) ?: null;
        $adolescentId = $rowA ? (int)($rowA['id'] ?? 0) : 0;

        if ($adolescentId > 0) {
            $dbDob = trim((string)($rowA['date_of_birth'] ?? ''));
            if ($dbDob !== '' && $dbDob !== $dobIso) {
                $q = http_build_query([
                    'duplicate'     => '1',
                    'adolescent_id' => $adolescentId,
                    'reason'        => 'adolescent_id_dob_mismatch',
                    'old_dob'       => $dbDob,
                    'new_dob'       => $dobIso,
                ]);
                $conn->rollBack();
                header('Location: ' . $ADD_URL . '?' . $q);
                exit;
            }
        }
    }

    if ($adolescentId <= 0) {
        $find = $conn->prepare("
            SELECT id, barangay, municipality, date_of_birth
            FROM adolescents
            WHERE LOWER(TRIM(COALESCE(last_name,'')))   = LOWER(TRIM(:ln))
              AND LOWER(TRIM(COALESCE(first_name,'')))  = LOWER(TRIM(:fn))
              AND LOWER(TRIM(COALESCE(middle_name,''))) = LOWER(TRIM(:mn))
              AND TRIM(COALESCE(date_of_birth,''))      = TRIM(:dob)
            ORDER BY id DESC
            LIMIT 1
        ");
        $find->execute([
            ':ln'  => $lastName,
            ':fn'  => $firstName,
            ':mn'  => $middleName,
            ':dob' => $dobIso,
        ]);
        $rowA = $find->fetch(PDO::FETCH_ASSOC) ?: null;
        $adolescentId = $rowA ? (int)($rowA['id'] ?? 0) : 0;
    }

    if ($adolescentId <= 0) {
        $find2 = $conn->prepare("
            SELECT id, barangay, municipality, date_of_birth
            FROM adolescents
            WHERE LOWER(TRIM(COALESCE(full_name,''))) = LOWER(TRIM(:full))
              AND TRIM(COALESCE(date_of_birth,''))    = TRIM(:dob)
            ORDER BY id DESC
            LIMIT 1
        ");
        $find2->execute([
            ':full' => $name,
            ':dob'  => $dobIso,
        ]);
        $rowA = $find2->fetch(PDO::FETCH_ASSOC) ?: null;
        $adolescentId = $rowA ? (int)($rowA['id'] ?? 0) : 0;
    }

    if (!$editLatest && $postedAdolescentId <= 0) {
        if ($adolescentId > 0) {
            $q = http_build_query([
                'duplicate'     => '1',
                'adolescent_id' => $adolescentId,
                'reason'        => 'already_exists',
            ]);
            $conn->rollBack();
            header('Location: ' . $ADD_URL . '?' . $q);
            exit;
        }

        $possible = $conn->prepare("
            SELECT id, date_of_birth
            FROM adolescents
            WHERE LOWER(TRIM(COALESCE(full_name,''))) = LOWER(TRIM(:full))
            ORDER BY id DESC
            LIMIT 1
        ");
        $possible->execute([
            ':full' => $name,
        ]);
        $possibleRow = $possible->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($possibleRow) {
            $possibleAid = (int)($possibleRow['id'] ?? 0);
            $possibleDob = trim((string)($possibleRow['date_of_birth'] ?? ''));

            $q = http_build_query([
                'duplicate'     => '1',
                'adolescent_id' => $possibleAid,
                'reason'        => 'possible_duplicate',
                'old_dob'       => $possibleDob,
                'new_dob'       => $dobIso,
            ]);
            $conn->rollBack();
            header('Location: ' . $ADD_URL . '?' . $q);
            exit;
        }
    }

    if ($adolescentId > 0) {
        if (!$rowA) {
            $r = $conn->prepare("SELECT id, barangay, municipality, date_of_birth FROM adolescents WHERE id=:id LIMIT 1");
            $r->execute([':id' => $adolescentId]);
            $rowA = $r->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $existingBrgy = cleanText_save((string)($rowA['barangay'] ?? ''));
        $existingMun  = cleanText_save((string)($rowA['municipality'] ?? ''));

        if ($existingBrgy === '' && $barangayFinal !== 'Unknown') {
            $conn->prepare("
                UPDATE adolescents
                SET barangay = :b,
                    updated_at = :updated_at
                WHERE id = :id
            ")->execute([
                ':b'          => $barangayFinal,
                ':updated_at' => $nowLocal,
                ':id'         => $adolescentId,
            ]);
            $existingBrgy = $barangayFinal;
        }

        if ($existingMun === '' && $municipality !== 'Unknown') {
            $conn->prepare("
                UPDATE adolescents
                SET municipality = :m,
                    updated_at = :updated_at
                WHERE id = :id
            ")->execute([
                ':m'          => $municipality,
                ':updated_at' => $nowLocal,
                ':id'         => $adolescentId,
            ]);
            $existingMun = $municipality;
        }

        if ($existingBrgy !== '' && $barangayFinal !== 'Unknown' && strcasecmp($existingBrgy, $barangayFinal) !== 0) {
            $q = http_build_query([
                'duplicate'        => '1',
                'adolescent_id'    => $adolescentId,
                'reason'           => 'barangay_mismatch',
                'old_barangay'     => $existingBrgy,
                'new_barangay'     => $barangayFinal,
                'old_municipality' => $existingMun,
                'new_municipality' => $municipality,
            ]);
            $conn->rollBack();
            header('Location: ' . $ADD_URL . '?' . $q);
            exit;
        }
    }

    if ($adolescentId <= 0) {
        $insA = $conn->prepare("
            INSERT INTO adolescents (
                last_name, first_name, middle_name, full_name,
                date_of_birth, sex, barangay, municipality, present_address,
                educational_attainment, currently_enrolled, grade_year_level, last_school_attended_type,
                reason_not_attending_school, reason_for_not_attending_school, reason_not_attending_school_other, vocational_training_other,
                created_at
            ) VALUES (
                :ln, :fn, :mn, :full,
                :dob, :sex, :brgy, :mun, :addr,
                :educational_attainment, :currently_enrolled, :grade_year_level, :last_school_attended_type,
                :reason_not_attending_school, :reason_for_not_attending_school, :reason_not_attending_school_other, :vocational_training_other,
                :created_at
            )
        ");
        $insA->execute([
            ':ln'         => ($lastName !== '' ? $lastName : null),
            ':fn'         => ($firstName !== '' ? $firstName : null),
            ':mn'         => ($middleName !== '' ? $middleName : null),
            ':full'       => $name,
            ':dob'        => $dobIso,
            ':sex'        => cleanText_save((string)postv_save('sex')) ?: null,
            ':brgy'       => $barangayFinal,
            ':mun'        => $municipality,
            ':addr'       => $presentAddressFinal ?: null,
            ':educational_attainment' => $educationColumnValues['educational_attainment'],
            ':currently_enrolled' => $educationColumnValues['currently_enrolled'],
            ':grade_year_level' => $educationColumnValues['grade_year_level'],
            ':last_school_attended_type' => $educationColumnValues['last_school_attended_type'],
            ':reason_not_attending_school' => $educationColumnValues['reason_not_attending_school'],
            ':reason_for_not_attending_school' => $educationColumnValues['reason_for_not_attending_school'],
            ':reason_not_attending_school_other' => $educationColumnValues['reason_not_attending_school_other'],
            ':vocational_training_other' => $educationColumnValues['vocational_training_other'],
            ':created_at' => $nowLocal,
        ]);
        $adolescentId = (int)$conn->lastInsertId();
    } else {
        $updA = $conn->prepare("
            UPDATE adolescents
            SET last_name = COALESCE(NULLIF(:ln,''), last_name),
                first_name = COALESCE(NULLIF(:fn,''), first_name),
                middle_name = COALESCE(NULLIF(:mn,''), middle_name),
                full_name = COALESCE(NULLIF(:full,''), full_name),
                sex = COALESCE(NULLIF(:sex,''), sex),
                barangay = COALESCE(NULLIF(:brgy,''), barangay),
                municipality = COALESCE(NULLIF(:mun,''), municipality),
                present_address = COALESCE(NULLIF(:addr,''), present_address),
                educational_attainment = :educational_attainment,
                currently_enrolled = :currently_enrolled,
                grade_year_level = :grade_year_level,
                last_school_attended_type = :last_school_attended_type,
                reason_not_attending_school = :reason_not_attending_school,
                reason_for_not_attending_school = :reason_for_not_attending_school,
                reason_not_attending_school_other = :reason_not_attending_school_other,
                vocational_training_other = :vocational_training_other,
                updated_at = :updated_at
            WHERE id = :id
        ");
        $updA->execute([
            ':ln'         => ($lastName !== '' ? $lastName : null),
            ':fn'         => ($firstName !== '' ? $firstName : null),
            ':mn'         => ($middleName !== '' ? $middleName : null),
            ':full'       => $name,
            ':sex'        => cleanText_save((string)postv_save('sex')) ?: null,
            ':brgy'       => ($barangayFinal !== 'Unknown' ? $barangayFinal : null),
            ':mun'        => ($municipality !== 'Unknown' ? $municipality : null),
            ':addr'       => $presentAddressFinal,
            ':educational_attainment' => $educationColumnValues['educational_attainment'],
            ':currently_enrolled' => $educationColumnValues['currently_enrolled'],
            ':grade_year_level' => $educationColumnValues['grade_year_level'],
            ':last_school_attended_type' => $educationColumnValues['last_school_attended_type'],
            ':reason_not_attending_school' => $educationColumnValues['reason_not_attending_school'],
            ':reason_for_not_attending_school' => $educationColumnValues['reason_for_not_attending_school'],
            ':reason_not_attending_school_other' => $educationColumnValues['reason_not_attending_school_other'],
            ':vocational_training_other' => $educationColumnValues['vocational_training_other'],
            ':updated_at' => $nowLocal,
            ':id'         => $adolescentId,
        ]);
    }

    if ($editLatest) {
        $encounterDateIso = $encounterDateBase;
    } else {
        $encounterDateIso = $encounterDateBase;
        if (encounterValueExists_save($conn, $adolescentId, $encounterDateIso)) {
            $conn->rollBack();
            http_response_code(409);
            exit('Duplicate encounter blocked: this adolescent already has a submission for the same interview date.');
        }
    }

    if ($editLatest && $postedEncounterId > 0) {
        if (!tableExists_save($conn, 'encounters')) {
            $conn->rollBack();
            http_response_code(500);
            exit('Encounters table missing.');
        }
        if (!$encounterRow) {
            $conn->rollBack();
            http_response_code(404);
            exit('Encounter not found. Cannot Edit Latest.');
        }

        $programsApplied = $govJson;
        $encDataJson = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($encDataJson === false) {
            $encDataJson = '{}';
        }

        $encCols = tableColumnsAssoc_save($conn, 'encounters');
        if (isset($encCols['encounter_date'])) {
            $stConflict = $conn->prepare(
                "SELECT id
                 FROM encounters
                 WHERE adolescent_id = :aid
                   AND TRIM(COALESCE(encounter_date,'')) = TRIM(:dt)
                   AND id <> :id
                 LIMIT 1"
            );
            $stConflict->execute([
                ':aid' => $adolescentId,
                ':dt'  => $encounterDateIso,
                ':id'  => $postedEncounterId,
            ]);
            $conflictId = (int)($stConflict->fetchColumn() ?: 0);

            // Older encounter JSON can carry a stale interview_date. If it collides,
            // keep the row's current canonical encounter_date instead of failing the edit.
            if ($conflictId > 0) {
                $currentEncounterDateIso = normalizeDateToIso_save((string)($encounterRow['encounter_date'] ?? ''));
                if ($currentEncounterDateIso !== null && $currentEncounterDateIso !== '' && $currentEncounterDateIso !== $encounterDateIso) {
                    $stCurrentDateConflict = $conn->prepare(
                        "SELECT id
                         FROM encounters
                         WHERE adolescent_id = :aid
                           AND TRIM(COALESCE(encounter_date,'')) = TRIM(:dt)
                           AND id <> :id
                         LIMIT 1"
                    );
                    $stCurrentDateConflict->execute([
                        ':aid' => $adolescentId,
                        ':dt'  => $currentEncounterDateIso,
                        ':id'  => $postedEncounterId,
                    ]);

                    if ((int)($stCurrentDateConflict->fetchColumn() ?: 0) === 0) {
                        $encounterDateIso = $currentEncounterDateIso;
                        $interviewDateRaw = $currentEncounterDateIso;
                        $data['interview_date'] = $currentEncounterDateIso;
                        $conflictId = 0;
                    }
                }
            }

            if ($conflictId > 0) {
                $conn->rollBack();
                http_response_code(409);
                exit('Failed to save latest record: another submission already exists for this interview date.');
            }
        }

        $encSets = [];
        $encParams = [':id' => $postedEncounterId];

        if (isset($encCols['adolescent_id']) && $adolescentId > 0) {
            $encSets[] = 'adolescent_id = :adolescent_id';
            $encParams[':adolescent_id'] = $adolescentId;
        }
        if (isset($encCols['encounter_date'])) {
            $encSets[] = 'encounter_date = :encounter_date';
            $encParams[':encounter_date'] = $encounterDateIso;
        }
        if (isset($encCols['interview_date'])) {
            $encSets[] = 'interview_date = :interview_date';
            $encParams[':interview_date'] = ($interviewDateRaw !== '' ? $interviewDateRaw : $encounterDateIso);
        }
        if (isset($encCols['status'])) {
            $encSets[] = 'status = :status';
            $encParams[':status'] = 'submitted';
        }
        if (isset($encCols['pregnant'])) {
            $encSets[] = 'pregnant = :pregnant';
            $encParams[':pregnant'] = ($pregnant !== 'Unknown' ? $pregnant : null);
        }
        if (isset($encCols['times_pregnant'])) {
            $encSets[] = 'times_pregnant = :times_pregnant';
            $encParams[':times_pregnant'] = ($timesPreg === '' || $timesPreg === null) ? null : (int)$timesPreg;
        }
        if (isset($encCols['age_first_pregnancy'])) {
            $encSets[] = 'age_first_pregnancy = :age_first_pregnancy';
            $encParams[':age_first_pregnancy'] = ($ageFirst === '' || $ageFirst === null) ? null : (int)$ageFirst;
        }
        if (isset($encCols['living_children'])) {
            $encSets[] = 'living_children = :living_children';
            $encParams[':living_children'] = ($livingChildren === '' || $livingChildren === null) ? null : (int)$livingChildren;
        }
        if (isset($encCols['family_planning'])) {
            $encSets[] = 'family_planning = :family_planning';
            $encParams[':family_planning'] = ($familyPlanning !== '' ? $familyPlanning : null);
        }
        if (isset($encCols['programs_applied'])) {
            $encSets[] = 'programs_applied = :programs_applied';
            $encParams[':programs_applied'] = $programsApplied;
        }
        if (isset($encCols['encoded_by'])) {
            $encSets[] = 'encoded_by = :encoded_by';
            $encParams[':encoded_by'] = $encodedBy;
        }
        if (isset($encCols['date_encoded'])) {
            $encSets[] = 'date_encoded = :date_encoded';
            $encParams[':date_encoded'] = ($dateEncoded !== '' ? $dateEncoded : null);
        }
        appendEducationEncounterColumns_save($encCols, $encSets, $encParams, $educationColumnValues);
        if (isset($encCols['data_json'])) {
            $encSets[] = 'data_json = :data_json';
            $encParams[':data_json'] = $encDataJson;
        }

        if ($encSets) {
            $sqlEnc = "UPDATE encounters SET " . implode(', ', $encSets) . " WHERE id = :id";
            $stEnc = $conn->prepare($sqlEnc);
            foreach ($encParams as $key => $value) {
                $stEnc->bindValue($key, $value);
            }
            $stEnc->execute();
        }

        $conn->commit();
        $_SESSION['csrf_profiling_form'] = bin2hex(random_bytes(32));

        header('Location: ' . $ADOL_VIEW_URL . '?adolescent_id=' . urlencode((string)$adolescentId) . '#history');
        exit;
    }

    if ($editLatest) {
        if (!tableExists_save($conn, 'adolescent_mothers')) {
            $conn->rollBack();
            http_response_code(500);
            exit('Legacy table missing.');
        }

        $colsLegacy = tableColumnsAssoc_save($conn, 'adolescent_mothers');
        $programsApplied = $govJson;
        $legacy = $data;

        if (isset($colsLegacy['adolescent_id'])) {
            $legacy['adolescent_id'] = ($adolescentId > 0 ? $adolescentId : null);
        }
        if (isset($colsLegacy['encounter_date'])) {
            $legacy['encounter_date'] = $encounterDateIso;
        }
        if (isset($colsLegacy['programs_applied'])) {
            $legacy['programs_applied'] = $programsApplied;
        }
        if (isset($colsLegacy['status'])) {
            $legacy['status'] = 'submitted';
        }
        if (isset($colsLegacy['encoded_by_user_id'])) {
            $legacy['encoded_by_user_id'] = ($actorUserId > 0 ? $actorUserId : null);
        }

        if (isset($colsLegacy['data_json'])) {
            $snapJson = json_encode($data, JSON_UNESCAPED_UNICODE);
            if ($snapJson === false) {
                $snapJson = '{}';
            }
            $legacy['data_json'] = $snapJson;
        }

        if (isset($colsLegacy['created_at']) && !isset($legacy['created_at'])) {
            $legacy['created_at'] = $nowLocal;
        }

        $final = [];
        foreach ($legacy as $k => $v) {
            if (isset($colsLegacy[$k])) {
                $final[$k] = $v;
            }
        }

        if (empty($final)) {
            $conn->rollBack();
            http_response_code(500);
            exit('Legacy schema not compatible for update.');
        }

        if (isset($colsLegacy['adolescent_id']) && $adolescentId > 0) {
            $conn->prepare("UPDATE adolescent_mothers SET adolescent_id=:aid WHERE id=:id")
                ->execute([
                    ':aid' => $adolescentId,
                    ':id'  => $legacyPostedId,
                ]);
        }

        $sets = [];
        foreach ($final as $k => $v) {
            if ($k === 'id') {
                continue;
            }
            $sets[] = "$k = :$k";
        }

        $sql = "UPDATE adolescent_mothers SET " . implode(', ', $sets) . " WHERE id = :id";
        $stmt = $conn->prepare($sql);

        foreach ($final as $k => $v) {
            if ($k === 'id') {
                continue;
            }
            $stmt->bindValue(":$k", $v);
        }
        $stmt->bindValue(':id', $legacyPostedId, PDO::PARAM_INT);
        $stmt->execute();

        if ($adolescentId > 0 && tableExists_save($conn, 'encounters')) {
            $encCols = tableColumnsAssoc_save($conn, 'encounters');
            if (isset($encCols['id']) && isset($encCols['adolescent_id'])) {
                $stEncCount = $conn->prepare("SELECT COUNT(*) FROM encounters WHERE adolescent_id = :aid");
                $stEncCount->execute([':aid' => $adolescentId]);
                $encounterCount = (int)($stEncCount->fetchColumn() ?: 0);

                $targetEncounterId = 0;
                $candidateDates = [];
                foreach ([
                    normalizeDateToIso_save((string)($legacyRow['encounter_date'] ?? '')),
                    normalizeDateToIso_save((string)($legacyRow['interview_date'] ?? '')),
                    $encounterDateIso,
                ] as $candidateDate) {
                    $candidateDate = trim((string)$candidateDate);
                    if ($candidateDate !== '' && !in_array($candidateDate, $candidateDates, true)) {
                        $candidateDates[] = $candidateDate;
                    }
                }

                if (isset($encCols['encounter_date'])) {
                    $stMatchByDate = $conn->prepare(
                        "SELECT id
                         FROM encounters
                         WHERE adolescent_id = :aid
                           AND TRIM(COALESCE(encounter_date,'')) = TRIM(:dt)
                         ORDER BY id DESC
                         LIMIT 2"
                    );

                    foreach ($candidateDates as $candidateDate) {
                        $stMatchByDate->execute([
                            ':aid' => $adolescentId,
                            ':dt'  => $candidateDate,
                        ]);
                        $matchIds = array_map('intval', $stMatchByDate->fetchAll(PDO::FETCH_COLUMN) ?: []);
                        if (count($matchIds) === 1) {
                            $targetEncounterId = $matchIds[0];
                            break;
                        }
                    }
                }

                if ($targetEncounterId <= 0 && isset($encCols['created_at'])) {
                    $legacyCreatedAt = trim((string)($legacyRow['created_at'] ?? ''));
                    if ($legacyCreatedAt !== '') {
                        $stMatchByCreated = $conn->prepare(
                            "SELECT id
                             FROM encounters
                             WHERE adolescent_id = :aid
                               AND TRIM(COALESCE(created_at,'')) = TRIM(:created_at)
                             ORDER BY id DESC
                             LIMIT 2"
                        );
                        $stMatchByCreated->execute([
                            ':aid'        => $adolescentId,
                            ':created_at' => $legacyCreatedAt,
                        ]);
                        $matchIds = array_map('intval', $stMatchByCreated->fetchAll(PDO::FETCH_COLUMN) ?: []);
                        if (count($matchIds) === 1) {
                            $targetEncounterId = $matchIds[0];
                        }
                    }
                }

                if ($targetEncounterId <= 0 && $encounterCount === 1) {
                    $stOnlyEnc = $conn->prepare("SELECT id FROM encounters WHERE adolescent_id = :aid LIMIT 1");
                    $stOnlyEnc->execute([':aid' => $adolescentId]);
                    $targetEncounterId = (int)($stOnlyEnc->fetchColumn() ?: 0);
                }

                if ($encounterCount > 0 && $targetEncounterId <= 0) {
                    $conn->rollBack();
                    http_response_code(409);
                    exit('Failed to save latest record: unable to match this snapshot to its encounter history row.');
                }

                if ($targetEncounterId > 0) {
                    if (isset($encCols['encounter_date'])) {
                        $stConflict = $conn->prepare(
                            "SELECT id
                             FROM encounters
                             WHERE adolescent_id = :aid
                               AND TRIM(COALESCE(encounter_date,'')) = TRIM(:dt)
                               AND id <> :id
                             LIMIT 1"
                        );
                        $stConflict->execute([
                            ':aid' => $adolescentId,
                            ':dt'  => $encounterDateIso,
                            ':id'  => $targetEncounterId,
                        ]);
                        if ((int)($stConflict->fetchColumn() ?: 0) > 0) {
                            $conn->rollBack();
                            http_response_code(409);
                            exit('Failed to save latest record: another submission already exists for this interview date.');
                        }
                    }

                    $encSets = [];
                    $encParams = [':id' => $targetEncounterId];

                    if (isset($encCols['encounter_date'])) {
                        $encSets[] = 'encounter_date = :encounter_date';
                        $encParams[':encounter_date'] = $encounterDateIso;
                    }
                    if (isset($encCols['interview_date'])) {
                        $encSets[] = 'interview_date = :interview_date';
                        $encParams[':interview_date'] = ($interviewDateRaw !== '' ? $interviewDateRaw : $encounterDateIso);
                    }
                    if (isset($encCols['status'])) {
                        $encSets[] = 'status = :status';
                        $encParams[':status'] = 'submitted';
                    }
                    if (isset($encCols['pregnant'])) {
                        $encSets[] = 'pregnant = :pregnant';
                        $encParams[':pregnant'] = ($pregnant !== 'Unknown' ? $pregnant : null);
                    }
                    if (isset($encCols['times_pregnant'])) {
                        $encSets[] = 'times_pregnant = :times_pregnant';
                        $encParams[':times_pregnant'] = ($timesPreg === '' || $timesPreg === null) ? null : (int)$timesPreg;
                    }
                    if (isset($encCols['age_first_pregnancy'])) {
                        $encSets[] = 'age_first_pregnancy = :age_first_pregnancy';
                        $encParams[':age_first_pregnancy'] = ($ageFirst === '' || $ageFirst === null) ? null : (int)$ageFirst;
                    }
                    if (isset($encCols['living_children'])) {
                        $encSets[] = 'living_children = :living_children';
                        $encParams[':living_children'] = ($livingChildren === '' || $livingChildren === null) ? null : (int)$livingChildren;
                    }
                    if (isset($encCols['family_planning'])) {
                        $encSets[] = 'family_planning = :family_planning';
                        $encParams[':family_planning'] = ($familyPlanning !== '' ? $familyPlanning : null);
                    }
                    if (isset($encCols['programs_applied'])) {
                        $encSets[] = 'programs_applied = :programs_applied';
                        $encParams[':programs_applied'] = $programsApplied;
                    }
                    if (isset($encCols['encoded_by'])) {
                        $encSets[] = 'encoded_by = :encoded_by';
                        $encParams[':encoded_by'] = $encodedBy;
                    }
                    if (isset($encCols['date_encoded'])) {
                        $encSets[] = 'date_encoded = :date_encoded';
                        $encParams[':date_encoded'] = ($dateEncoded !== '' ? $dateEncoded : null);
                    }
                    appendEducationEncounterColumns_save($encCols, $encSets, $encParams, $educationColumnValues);
                    if (isset($encCols['data_json'])) {
                        $encSets[] = 'data_json = :data_json';
                        $encParams[':data_json'] = $snapJson;
                    }

                    if ($encSets) {
                        $sqlEnc = "UPDATE encounters SET " . implode(', ', $encSets) . " WHERE id = :id";
                        $stEnc = $conn->prepare($sqlEnc);
                        foreach ($encParams as $key => $value) {
                            $stEnc->bindValue($key, $value);
                        }
                        $stEnc->execute();
                    }
                }
            }
        }

        $conn->commit();
        $_SESSION['csrf_profiling_form'] = bin2hex(random_bytes(32));

        header('Location: ' . $ADOL_VIEW_URL . '?adolescent_id=' . urlencode((string)$adolescentId) . '#history');
        exit;
    }

    $programsApplied = $govJson;

    $encDataJson = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($encDataJson === false) {
        $encDataJson = '{}';
    }

    $insE = $conn->prepare("
        INSERT INTO encounters (
            adolescent_id, encounter_date, status,
            pregnant, times_pregnant, age_first_pregnancy, living_children, family_planning,
            educational_attainment, currently_enrolled, grade_year_level, last_school_attended_type,
            reason_not_attending_school, reason_for_not_attending_school, reason_not_attending_school_other, vocational_training_other,
            programs_applied, data_json, encoded_by, date_encoded,
            created_by_user_id, created_at
        ) VALUES (
            :aid, :dt, 'submitted',
            :preg, :times, :agefirst, :children, :fp,
            :educational_attainment, :currently_enrolled, :grade_year_level, :last_school_attended_type,
            :reason_not_attending_school, :reason_for_not_attending_school, :reason_not_attending_school_other, :vocational_training_other,
            :prog, :json, :encoded_by, :date_encoded,
            :uid, :created_at
        )
    ");
    $insE->execute([
        ':aid'        => $adolescentId,
        ':dt'         => $encounterDateIso,
        ':preg'       => ($pregnant !== 'Unknown' ? $pregnant : null),
        ':times'      => ($timesPreg === '' || $timesPreg === null) ? null : (int)$timesPreg,
        ':agefirst'   => ($ageFirst === '' || $ageFirst === null) ? null : (int)$ageFirst,
        ':children'   => ($livingChildren === '' || $livingChildren === null) ? null : (int)$livingChildren,
        ':fp'         => ($familyPlanning !== '' ? $familyPlanning : null),
        ':educational_attainment' => $educationColumnValues['educational_attainment'],
        ':currently_enrolled' => $educationColumnValues['currently_enrolled'],
        ':grade_year_level' => $educationColumnValues['grade_year_level'],
        ':last_school_attended_type' => $educationColumnValues['last_school_attended_type'],
        ':reason_not_attending_school' => $educationColumnValues['reason_not_attending_school'],
        ':reason_for_not_attending_school' => $educationColumnValues['reason_for_not_attending_school'],
        ':reason_not_attending_school_other' => $educationColumnValues['reason_not_attending_school_other'],
        ':vocational_training_other' => $educationColumnValues['vocational_training_other'],
        ':prog'       => $programsApplied,
        ':json'       => $encDataJson,
        ':encoded_by' => $encodedBy,
        ':date_encoded' => $dateEncoded,
        ':uid'        => $actorUserId > 0 ? $actorUserId : null,
        ':created_at' => $nowLocal,
    ]);

    if (tableExists_save($conn, 'adolescent_mothers')) {
        $colsLegacy = tableColumnsAssoc_save($conn, 'adolescent_mothers');
        $legacy = $data;

        if (isset($colsLegacy['adolescent_id'])) {
            $legacy['adolescent_id'] = $adolescentId;
        }
        if (isset($colsLegacy['encounter_date'])) {
            $legacy['encounter_date'] = $encounterDateIso;
        }
        if (isset($colsLegacy['programs_applied'])) {
            $legacy['programs_applied'] = $programsApplied;
        }
        if (isset($colsLegacy['status'])) {
            $legacy['status'] = 'submitted';
        }
        if (isset($colsLegacy['encoded_by_user_id'])) {
            $legacy['encoded_by_user_id'] = ($actorUserId > 0 ? $actorUserId : null);
        }
        if (isset($colsLegacy['data_json'])) {
            $legacy['data_json'] = $encDataJson;
        }
        if (isset($colsLegacy['created_at']) && !isset($legacy['created_at'])) {
            $legacy['created_at'] = $nowLocal;
        }

        $final = [];
        foreach ($legacy as $k => $v) {
            if (isset($colsLegacy[$k])) {
                $final[$k] = $v;
            }
        }

        if (!empty($final)) {
            $columns = array_keys($final);
            $params  = array_map(static fn($c) => ":$c", $columns);

            $sql = "INSERT INTO adolescent_mothers (" . implode(',', $columns) . ")
                    VALUES (" . implode(',', $params) . ")";
            $stmt = $conn->prepare($sql);

            foreach ($final as $k => $v) {
                $stmt->bindValue(":$k", $v);
            }
            $stmt->execute();
        }
    }

    $conn->commit();
    $_SESSION['csrf_profiling_form'] = bin2hex(random_bytes(32));

    header('Location: ' . $ADOL_VIEW_URL . '?adolescent_id=' . urlencode((string)$adolescentId) . '#history');
    exit;
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('[AHDIS Save] Failed to save record: ' . $e->getMessage());
    http_response_code(500);
    exit('The record could not be saved. Please review the form and try again.');
}
