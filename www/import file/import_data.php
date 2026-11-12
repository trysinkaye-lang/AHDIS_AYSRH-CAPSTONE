<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/education_options.php";

if (!($conn instanceof PDO)) {
    die("<h3>ERROR</h3><p>Database connection is NOT PDO.</p>");
}
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* =========================================================
   HELPERS
========================================================= */
function imp_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

final class ImpValidationException extends RuntimeException {}

const IMP_MAX_BACKUP_FILE_SIZE = 20 * 1024 * 1024;

/**
 * Authoritative restore-format definition used by PHP validation, the HTML
 * picker filter, and JavaScript validation. Legacy XLS is intentionally not
 * included because the existing restore workflow only supports CSV and XLSX.
 */
function imp_supportedBackupFormats(): array {
    return [
        'xlsx' => [
            'label' => 'XLSX',
            'accept' => [
                '.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
            'mime_types' => [
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/zip',
                'application/x-zip',
                'application/x-zip-compressed',
                'application/octet-stream',
            ],
        ],
        'csv' => [
            'label' => 'CSV',
            'accept' => [
                '.csv',
                'text/csv',
            ],
            'mime_types' => [
                'text/csv',
                'text/plain',
                'application/csv',
                'application/vnd.ms-excel',
                'application/octet-stream',
            ],
        ],
    ];
}

function imp_backupAcceptValue(): string {
    $tokens = [];
    foreach (imp_supportedBackupFormats() as $format) {
        foreach ($format['accept'] as $token) {
            $tokens[] = (string)$token;
        }
    }
    return implode(',', array_values(array_unique($tokens)));
}

function imp_backupFormatLabels(): string {
    $labels = array_column(imp_supportedBackupFormats(), 'label');
    if (count($labels) < 2) {
        return (string)($labels[0] ?? 'XLSX');
    }
    $last = array_pop($labels);
    return implode(', ', $labels) . ' or ' . $last;
}

function imp_uploadErrorMessage(int $error): string {
    return match ($error) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
            'File is too large. Maximum allowed size is 20MB.',
        UPLOAD_ERR_PARTIAL =>
            'The file upload was interrupted. Please choose the file again.',
        UPLOAD_ERR_NO_FILE =>
            'Please choose an XLSX or CSV backup file.',
        UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION =>
            'The uploaded file could not be stored. Please try again or contact the administrator.',
        default => 'Upload failed. Please choose the backup file again.',
    };
}

function imp_detectFileMime(string $filePath): string {
    if (!class_exists('finfo')) {
        return '';
    }

    try {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        return strtolower(trim((string)$finfo->file($filePath)));
    } catch (Throwable $ignored) {
        return '';
    }
}

function imp_validateUploadedBackup(array $file, int $maxFileSize): array {
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new ImpValidationException(imp_uploadErrorMessage($error));
    }

    $originalName = trim((string)($file['name'] ?? ''));
    $tmpName = (string)($file['tmp_name'] ?? '');
    if ($originalName === '' || $tmpName === '') {
        throw new ImpValidationException('Please choose an XLSX or CSV backup file.');
    }

    if (!is_uploaded_file($tmpName) || !is_file($tmpName) || !is_readable($tmpName)) {
        throw new ImpValidationException('The selected file could not be read. Please choose it again.');
    }

    $actualSize = filesize($tmpName);
    if ($actualSize === false || $actualSize <= 0) {
        throw new ImpValidationException('The selected backup file is empty or unreadable.');
    }
    if ($actualSize > $maxFileSize) {
        throw new ImpValidationException('File is too large. Maximum allowed size is 20MB.');
    }

    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $formats = imp_supportedBackupFormats();
    if (!isset($formats[$extension])) {
        throw new ImpValidationException('Unsupported file type. Please choose an ' . imp_backupFormatLabels() . ' backup file.');
    }

    $detectedMime = imp_detectFileMime($tmpName);
    $allowedMimes = array_map('strtolower', (array)$formats[$extension]['mime_types']);
    if ($detectedMime !== '' && !in_array($detectedMime, $allowedMimes, true)) {
        throw new ImpValidationException(
            'The file contents do not match the .' . $extension . ' extension. Please choose a genuine ' .
            $formats[$extension]['label'] . ' backup file.'
        );
    }

    return [
        'name' => $originalName,
        'tmp_name' => $tmpName,
        'size' => (int)$actualSize,
        'extension' => $extension,
        'mime_type' => $detectedMime,
    ];
}

function imp_resultNeedsAttention(array $result): bool {
    $status = strtolower(trim((string)($result['status'] ?? '')));
    $message = strtolower(trim((string)($result['message'] ?? '')));

    if (in_array($status, ['failed', 'skipped', 'warning', 'duplicate'], true)) {
        return true;
    }

    return str_contains($message, 'warning')
        || str_contains($message, 'duplicate')
        || str_contains($message, 'already exists')
        || str_contains($message, 'skipped')
        || str_contains($message, 'could not');
}

function imp_nowLocal(): string {
    if (function_exists('appNow')) {
        return (string)appNow();
    }
    return date('Y-m-d H:i:s');
}

function imp_baseUrl(): string {
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $dir = preg_replace('#/(auth|config|dashboard|profiling|reports|includes|import file|imports)$#', '', $dir);
    return $dir ?: '';
}

function imp_tableExists(PDO $conn, string $table): bool {
    $stmt = $conn->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:t LIMIT 1");
    $stmt->execute([':t' => $table]);
    return (bool)$stmt->fetchColumn();
}

function imp_dbHasColumn(PDO $conn, string $table, string $col): bool {
    $stmt = $conn->query("PRAGMA table_info($table)");
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ($rows as $r) {
        if (strcasecmp((string)($r['name'] ?? ''), $col) === 0) {
            return true;
        }
    }
    return false;
}

function imp_addColumnIfMissing(PDO $conn, string $table, string $col, string $type): void {
    if (!imp_tableExists($conn, $table)) return;
    if (imp_dbHasColumn($conn, $table, $col)) return;
    $conn->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$type}");
}

function imp_clean(?string $v): string {
    $s = trim((string)$v);
    $s = str_replace("\xC2\xA0", ' ', $s);
    $s = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $s) ?? $s;
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return trim($s);
}

function imp_lower(string $v): string {
    return function_exists('mb_strtolower')
        ? mb_strtolower($v, 'UTF-8')
        : strtolower($v);
}

function imp_normalizeText(?string $v): string {
    return imp_lower(imp_clean((string)$v));
}

function imp_normalizePlace(?string $v): string {
    $s = imp_normalizeText($v);
    if ($s === '') return '';

    $s = preg_replace('/\./', '', $s) ?? $s;
    $s = preg_replace('/\bbrgy\b/u', 'barangay', $s) ?? $s;
    $s = preg_replace('/\bbrg\b/u', 'barangay', $s) ?? $s;
    $s = preg_replace('/\bmun\b/u', 'municipality', $s) ?? $s;
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;

    return trim($s);
}

function imp_valuesMatch(?string $a, ?string $b): bool {
    return imp_normalizeText($a) === imp_normalizeText($b);
}

function imp_placesMatch(?string $a, ?string $b): bool {
    return imp_normalizePlace($a) === imp_normalizePlace($b);
}

function imp_nullify($v): ?string {
    $s = imp_clean((string)$v);
    if ($s === '') return null;

    $low = imp_lower($s);
    if (in_array($low, ['n/a', 'na', 'null', 'none', 'unknown', '-', '--'], true)) {
        return null;
    }
    return $s;
}

function imp_partnerNameFromParts(?string $surname, ?string $first, ?string $middle, $fallback = null): ?string {
    $surname = imp_nullify($surname);
    $first = imp_nullify($first);
    $middle = imp_nullify($middle);
    $given = trim((string)$first . ($middle !== null ? ' ' . $middle : ''));
    if ($surname !== null && $given !== '') return $surname . ', ' . $given;
    $name = trim((string)$surname . ' ' . $given);
    if ($name !== '') return $name;
    return imp_nullify($fallback);
}

function imp_yesNo(?string $v): ?string {
    $s = imp_clean((string)$v);
    if ($s === '') return null;

    $low = imp_lower($s);

    if (in_array($low, [
        'yes', 'y', '1', 'true', 'opo', 'oo',
        'enrolled', 'currently enrolled', 'in school', 'in-school',
        'pregnant', 'currently pregnant', 'adolescent mother', 'mother'
    ], true)) return 'Yes';
    if (in_array($low, [
        'no', 'n', '0', 'false', 'hindi', 'wala',
        'not enrolled', 'not currently enrolled', 'out of school', 'out-of-school',
        'not pregnant', 'not an adolescent mother'
    ], true)) return 'No';

    return $s;
}

function imp_normalizeEducationForImport(array $row, ?string $currentlyEnrolled = null): array {
    $warnings = [];

    $education = ahdisNormalizeEducationAttainment((string)($row['education'] ?? ''));
    $gradeRaw = imp_nullify($row['year_level'] ?? null);
    $gradeYear = null;

    if ($education !== '') {
        if ($education === 'No Education') {
            if ($gradeRaw !== null) {
                $warnings[] = 'Grade / Year Level cleared because Educational Attainment is No Education.';
            }
            $gradeYear = null;
        } elseif ($gradeRaw !== null) {
            $normalizedGrade = ahdisNormalizeGradeYearLevel($education, $gradeRaw);
            if ($normalizedGrade === '') {
                $warnings[] = 'Invalid Grade / Year Level cleared for ' . $education . '.';
            } else {
                $gradeYear = $normalizedGrade;
            }
        }
    } elseif ($gradeRaw !== null) {
        $warnings[] = 'Grade / Year Level cleared because Educational Attainment is missing or invalid.';
    }

    $lastSchool = ahdisNormalizeLastSchoolAttendedType((string)($row['last_school_attended_type'] ?? '')) ?: null;
    $reasonValues = ahdisNormalizeReasonsNotAttendingSchool($row['reason_not_attending_school'] ?? ($row['reason_for_not_attending_school'] ?? ''));
    $reason = $reasonValues ? ahdisSerializeReasonsNotAttendingSchool($reasonValues) : null;
    if ($currentlyEnrolled === 'Yes') {
        $reason = null;
    } elseif ($currentlyEnrolled === 'No' && $reason === null) {
        $warnings[] = 'Reason for not attending school is required when Currently Enrolled is No.';
    }

    $reasonOther = imp_nullify($row['reason_not_attending_school_other'] ?? null);
    if (!ahdisReasonsNotAttendingSchoolContains($reason, 'Other')) {
        $reasonOther = null;
    } elseif ($reasonOther === null) {
        $warnings[] = 'Other reason is blank.';
    }

    $vocationalOther = imp_nullify($row['vocational_training_other'] ?? null);
    if ($education === 'Vocational' && $vocationalOther !== null && ($gradeYear === null || $gradeYear === 'Other Vocational Training')) {
        $gradeYear = $vocationalOther;
    }
    $vocationalOther = null;

    return [
        'educational_attainment' => $education !== '' ? $education : null,
        'grade_year_level' => $gradeYear,
        'last_school_attended_type' => $lastSchool,
        'reason_not_attending_school' => $reason,
        'reason_for_not_attending_school' => $reason,
        'reason_not_attending_school_other' => $reasonOther,
        'vocational_training_other' => $vocationalOther,
        'warnings' => $warnings,
    ];
}

function imp_civilStatus(?string $v): ?string {
    $s = imp_clean((string)$v);
    if ($s === '') return null;

    $low = imp_lower($s);
    $low = str_replace(['_', '/'], ' ', $low);
    $low = preg_replace('/\s+/', ' ', $low) ?? $low;
    $low = trim($low);

    if (in_array($low, [
        'submitted', 'draft', 'pending', 'approved', 'verified', 'validated',
        'rejected', 'restored', 'encoded'
    ], true)) {
        return null;
    }

    $map = [
        'single' => 'Single',
        'married' => 'Married',
        'widowed' => 'Widowed',
        'separated' => 'Separated',
        'living in' => 'Living-in',
        'living-in' => 'Living-in',
        'live in' => 'Living-in',
        'live-in' => 'Living-in',
        'cohabiting' => 'Living-in',
        'co habitation' => 'Living-in',
        'cohabitation' => 'Living-in',
    ];

    return $map[$low] ?? null;
}

function imp_parseDate(?string $raw): ?DateTime {
    $raw = imp_clean((string)$raw);
    if ($raw === '') return null;

    if (is_numeric($raw)) {
        $excelSerial = (float)$raw;
        if ($excelSerial >= 10000 && $excelSerial <= 2958465) {
            $seconds = (int)round(($excelSerial - 25569) * 86400);
            $excelDate = DateTime::createFromFormat('!Y-m-d H:i:s', gmdate('Y-m-d H:i:s', $seconds));
            if ($excelDate instanceof DateTime) return $excelDate;
        }
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw)) {
        $raw = substr($raw, 0, 10);
    }

    $formats = [
        'Y-m-d',
        'm/d/Y',
        'n/j/Y',
        'd/m/Y',
        'j/n/Y',
        'Y/m/d',
        'm-d-Y',
        'n-j-Y',
        'd-m-Y',
        'j-n-Y',
        'm.d.Y',
        'd.m.Y',
        'F j, Y',
        'F d, Y',
        'M j, Y',
        'M d, Y',
        'd-M-Y',
        'j-M-Y',
        'd M Y',
        'j M Y',
    ];

    foreach ($formats as $fmt) {
        $d = DateTime::createFromFormat($fmt, $raw);
        if ($d instanceof DateTime && $d->format($fmt) === $raw) {
            return $d;
        }
    }

    $ts = strtotime($raw);
    if ($ts === false) return null;

    return (new DateTime())->setTimestamp($ts);
}

function imp_dateIso(?string $raw): ?string {
    $d = imp_parseDate($raw);
    return $d ? $d->format('Y-m-d') : null;
}

function imp_ageFromDob(?string $dob): ?int {
    $d = imp_parseDate($dob);
    if (!$d) return null;

    $today = new DateTime('today');
    $d->setTime(0, 0, 0);
    if ($d > $today) return null;
    return (int)$d->diff($today)->y;
}

function imp_splitName(string $full): array {
    $full = imp_clean($full);
    if ($full === '') return ['', '', ''];

    if (strpos($full, ',') !== false) {
        [$last, $rest] = array_map('trim', explode(',', $full, 2));
        $parts = preg_split('/\s+/', $rest) ?: [];
        $first = $parts[0] ?? '';
        $middle = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';
        return [$last, $first, $middle];
    }

    $parts = preg_split('/\s+/', $full) ?: [];
    if (count($parts) === 1) return ['', $parts[0], ''];
    if (count($parts) === 2) return [$parts[1], $parts[0], ''];

    $first = $parts[0];
    $last  = $parts[count($parts) - 1];
    $middle = implode(' ', array_slice($parts, 1, -1));

    return [$last, $first, $middle];
}

function imp_jsonDecodeAssoc(?string $raw): array {
    $raw = trim((string)$raw);
    if ($raw === '') return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function imp_programsToJson(?string $raw): ?string {
    $raw = imp_clean((string)$raw);
    if ($raw === '') return null;

    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $clean = array_values(array_filter(array_map(
            fn($x) => imp_clean((string)$x),
            $decoded
        ), fn($x) => $x !== ''));
        return $clean ? json_encode($clean, JSON_UNESCAPED_UNICODE) : null;
    }

    $parts = preg_split('/\s*,\s*/', $raw) ?: [];
    $parts = array_values(array_filter(array_map(
        fn($x) => imp_clean((string)$x),
        $parts
    ), fn($x) => $x !== ''));

    return $parts ? json_encode($parts, JSON_UNESCAPED_UNICODE) : null;
}

function imp_governmentAssistanceDetailsToJson(?string $raw): ?string {
    $raw = trim((string)$raw);
    if ($raw === '') return null;

    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return json_encode($decoded, JSON_UNESCAPED_UNICODE) ?: null;
    }

    $details = [];
    foreach (preg_split('/\s*;\s*/', $raw) ?: [] as $entry) {
        $entry = trim((string)$entry);
        if ($entry === '' || !str_contains($entry, ':')) continue;
        [$program, $detail] = array_map('trim', explode(':', $entry, 2));
        if ($program !== '' && $detail !== '') {
            $details[$program] = $detail;
        }
    }

    return $details
        ? (json_encode($details, JSON_UNESCAPED_UNICODE) ?: null)
        : $raw;
}

/**
 * NORMALIZE HEADER NAMES
 */
function imp_normalizeHeaderName(string $name): string {
    $name = preg_replace('/^\xEF\xBB\xBF/', '', $name) ?? $name;
    $name = trim($name);

    $name = str_replace(["\r", "\n", "\t"], ' ', $name);
    $name = preg_replace('/\s+/', ' ', $name) ?? $name;
    $name = strtolower($name);

    $name = str_replace(['-', '/', '\\', '.', '(', ')', '[', ']', ':'], ' ', $name);
    $name = preg_replace('/\s+/', '_', $name) ?? $name;
    $name = preg_replace('/_+/', '_', $name) ?? $name;
    $name = trim($name, '_');

    $aliases = [
        'adolescent_id' => 'adolescent_id',
        'adolescentid' => 'adolescent_id',
        'profile_id' => 'adolescent_id',
        'profileid' => 'adolescent_id',
        'old_profile_id' => 'adolescent_id',
        'backup_id' => 'adolescent_id',
        'source_backup_id' => 'adolescent_id',
        'id' => 'adolescent_id',

        'full_name' => 'full_name',
        'fullname' => 'full_name',
        'name' => 'full_name',
        'complete_name' => 'full_name',
        'client_name' => 'full_name',

        'date_of_birth' => 'date_of_birth',
        'birth_date' => 'date_of_birth',
        'date_birth' => 'date_of_birth',
        'dob' => 'date_of_birth',
        'birthday' => 'date_of_birth',
        'birthdate' => 'date_of_birth',
        'age' => 'age',
        'age_today' => 'age',

        'barangay' => 'barangay',
        'brgy' => 'barangay',
        'barangay_name' => 'barangay',
        'address_barangay' => 'barangay',
        'residence_barangay' => 'barangay',
        'locked_barangay' => 'barangay',

        'municipality' => 'municipality',
        'municipal' => 'municipality',
        'city_municipality' => 'municipality',
        'city' => 'municipality',
        'province' => 'province',
        'purok' => 'purok',
        'sitio' => 'sitio',
        'locked_municipality' => 'municipality',

        'last_name' => 'last_name',
        'surname' => 'last_name',
        'family_name' => 'last_name',
        'lastname' => 'last_name',
        'firstname' => 'first_name',
        'first_name' => 'first_name',
        'given_name' => 'first_name',
        'given_names' => 'first_name',
        'middlename' => 'middle_name',
        'middle_name' => 'middle_name',
        'middle_initial' => 'middle_name',
        'mi' => 'middle_name',
        'extension_name' => 'extension_name',
        'suffix' => 'extension_name',
        'ext_name' => 'extension_name',
        'gender' => 'sex',
        'sex' => 'sex',
        'present_address' => 'present_address',
        'address' => 'present_address',
        'home_address' => 'present_address',
        'residential_address' => 'present_address',

        'encounter_date' => 'encounter_date',
        'profiling_date' => 'profiling_date',
        'submission_date' => 'submission_date',
        'date_submitted' => 'submission_date',
        'date_encoded' => 'date_encoded',
        'encoded_date' => 'date_encoded',
        'created_at' => 'created_at',
        'created' => 'created_at',
        'month' => 'month',
        'year' => 'year',
        'date_interviewed' => 'encounter_date',
        'interview_date' => 'interview_date',

        'status' => 'status',
        'civil_status' => 'civil_status',
        'marital_status' => 'civil_status',
        'status_civil' => 'civil_status',
        'record_status' => 'status',
        'encounter_status' => 'status',
        'pregnant' => 'pregnant',
        'currently_pregnant' => 'pregnant',
        'pregnancy_status' => 'pregnant',
        'adolescent_mother' => 'pregnant',
        'times_pregnant' => 'times_pregnant',
        'gravida' => 'times_pregnant',
        'age_at_first_pregnancy' => 'age_first_pregnancy',
        'age_of_first_pregnancy' => 'age_first_pregnancy',
        'age_first_pregnancy' => 'age_first_pregnancy',
        'number_of_children' => 'living_children',
        'number_of_living_children' => 'living_children',
        'living_children' => 'living_children',
        'para' => 'living_children',
        'family_planning_method' => 'family_planning',
        'family_planning_method_currently_used' => 'family_planning',
        'family_planning' => 'family_planning',
        'programs_applied' => 'programs_applied',
        'gov_assistance_selected' => 'gov_assistance_selected',
        'government_assistance_selected' => 'gov_assistance_selected',

        'currently_enrolled' => 'currently_enrolled',
        'currently_in_school' => 'currently_enrolled',
        'school_status' => 'currently_enrolled',
        'enrollment_status' => 'currently_enrolled',
        'grade_year_level' => 'year_level',
        'year_level' => 'year_level',
        'grade_level' => 'year_level',
        'highest_grade_completed' => 'year_level',
        'educational_attainment' => 'education',
        'education_attainment' => 'education',
        'highest_educational_attainment' => 'education',
        'education' => 'education',
        'last_school_attended' => 'last_school_attended_type',
        'last_school_attended_type' => 'last_school_attended_type',
        'reason_for_not_attending_school' => 'reason_not_attending_school',
        'reason_not_attending_school' => 'reason_not_attending_school',
        'other_reason_for_not_attending_school' => 'reason_not_attending_school_other',
        'reason_not_attending_school_other' => 'reason_not_attending_school_other',
        'please_specify_reason' => 'reason_not_attending_school_other',
        'other_vocational_training' => 'vocational_training_other',
        'vocational_training_other' => 'vocational_training_other',
        'specify_vocational_training' => 'vocational_training_other',
        'living_situation' => 'living_situation',
        'living_with_others_specify' => 'living_others',
        'living_others' => 'living_others',
        'has_child_under_1_year_old' => 'child_under_one',
        'child_under_one' => 'child_under_one',
        'currently_working' => 'currently_working',
        'monthly_income' => 'monthly_income',
        'household_income' => 'monthly_income',
        'family_income' => 'monthly_income',
        'income' => 'monthly_income',
        'occupation' => 'occupation',
        'ethnicity_ip_group' => 'ethnicity',
        'ethnicity' => 'ethnicity',
        '4ps_beneficiary' => 'is_4ps',
        'is_4ps' => 'is_4ps',
        'ip_member' => 'is_ips',
        'is_ips' => 'is_ips',
        'father_occupation' => 'father_occupation',
        'mother_occupation' => 'mother_occupation',
        'health_concerns_during_pregnancy' => 'health_concerns',
        'health_concerns' => 'health_concerns',
        'attended_pre_post_natal_care' => 'prenatal_care',
        'attended_prenatal_care' => 'prenatal_care',
        'prenatal_care' => 'prenatal_care',
        'months_pregnant' => 'months_pregnant',
        'interviewed_by' => 'interviewed_by',
        'interviewed_by_barangay_population_volunteer' => 'interviewed_by',
        'noted_by' => 'noted_by',
        'noted_by_municipal_population_officer' => 'noted_by',
        'noted_date' => 'noted_date',
        'other_observations' => 'other_observations',
        'other_observations_information_noted' => 'other_observations',
        'place_of_birth' => 'place_of_birth',

        'partner_name' => 'partner_name',
        'husband_name' => 'partner_name',
        'live_in_partner_name' => 'partner_name',
        'partner_surname' => 'partner_surname',
        'partner_last_name' => 'partner_surname',
        'partner_first_name' => 'partner_first_name',
        'partner_given_name' => 'partner_first_name',
        'partner_middle_name' => 'partner_middle_name',
        'partner_age' => 'partner_age',
        'husband_age' => 'partner_age',
        'age_of_partner' => 'partner_age',
        'partner_date_of_birth' => 'partner_dob',
        'partner_dob' => 'partner_dob',
        'partner_address' => 'partner_address',
        'partner_present_address' => 'partner_address',
        'partner_place_of_birth' => 'partner_place_of_birth',
        'partner_educational_attainment' => 'partner_education',
        'partner_education' => 'partner_education',
        'partner_currently_enrolled' => 'partner_currently_enrolled',
        'partner_grade_year_level' => 'partner_year_level',
        'partner_year_level' => 'partner_year_level',
        'partner_currently_working' => 'partner_currently_working',
        'partner_occupation' => 'partner_occupation',
        'partner_monthly_income' => 'partner_monthly_income',
        'partner_income' => 'partner_monthly_income',
        'husband_monthly_income' => 'partner_monthly_income',
        'husband_occupation' => 'partner_occupation',

        'government_assistance_details' => 'gov_assistance_details',
        'gov_assistance_details' => 'gov_assistance_details',
        'government_assistance_others' => 'gov_assistance_other',
        'gov_assistance_other' => 'gov_assistance_other',

        'latest_update_id' => 'encounter_id',
        'profile_created_at' => 'adolescent_created_at',
        'profile_updated_at' => 'adolescent_updated_at',
        'latest_update_saved_at' => 'encounter_created_at',
        'verified_date_time' => 'verified_at',
        'encoded_by' => 'encoded_by',
        'verified_by' => 'verified_by',
        'verified_at' => 'verified_at',
        'data_json' => 'data_json',
        'source_data_json' => 'data_json',
        'additional_details' => 'additional_details',
        'additional_legacy_details' => 'additional_details',
    ];

    return $aliases[$name] ?? $name;
}

function imp_normalizeAssocKeys(array $row): array {
    $out = [];
    foreach ($row as $k => $v) {
        $out[imp_normalizeHeaderName((string)$k)] = is_string($v) ? trim($v) : $v;
    }
    return $out;
}

function imp_requireHeaders(array $header, array $required): array {
    $norm = array_map('imp_normalizeHeaderName', $header);
    $set = array_flip($norm);

    $missing = [];
    foreach ($required as $r) {
        if (!isset($set[imp_normalizeHeaderName($r)])) {
            $missing[] = $r;
        }
    }
    return $missing;
}

function imp_readCsvAssoc(string $filePath): array {
    $rows = [];
    $fh = fopen($filePath, 'r');
    if (!$fh) {
        throw new ImpValidationException('The selected CSV backup file could not be read.');
    }

    $header = fgetcsv($fh);
    if (!$header) {
        fclose($fh);
        throw new ImpValidationException('The selected CSV backup file is empty or invalid.');
    }

    $header = array_map(fn($h) => trim((string)$h), $header);

    while (($line = fgetcsv($fh)) !== false) {
        if ($line === [null] || $line === false) continue;

        $assoc = [];
        foreach ($header as $i => $col) {
            $assoc[$col] = isset($line[$i]) ? trim((string)$line[$i]) : '';
        }

        $assoc = imp_normalizeAssocKeys($assoc);

        $nonEmpty = array_filter($assoc, fn($v) => trim((string)$v) !== '');
        if (!$nonEmpty) continue;

        $rows[] = $assoc;
    }

    fclose($fh);
    return [$header, $rows];
}

function imp_readXlsxAssoc(string $filePath): array {
    $autoloads = [
        __DIR__ . '/../vendor/autoload.php',
        dirname(__DIR__) . '/vendor/autoload.php',
        __DIR__ . '/vendor/autoload.php',
    ];

    foreach ($autoloads as $a) {
        if (file_exists($a)) {
            require_once $a;
            break;
        }
    }

    if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
        throw new RuntimeException('XLSX restore support is unavailable.');
    }

    try {
        $readerType = \PhpOffice\PhpSpreadsheet\IOFactory::identify($filePath);
        if ($readerType !== \PhpOffice\PhpSpreadsheet\IOFactory::READER_XLSX) {
            throw new ImpValidationException('The selected file is not a genuine XLSX workbook.');
        }

        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($readerType);
        // Keep styles so true Excel date cells can be read using their display format.
        $reader->setReadDataOnly(false);
        $spreadsheet = $reader->load($filePath);
    } catch (ImpValidationException $e) {
        throw $e;
    } catch (Throwable $e) {
        throw new ImpValidationException('The selected XLSX backup is invalid, corrupted, or unreadable.');
    }

    $sheet = $spreadsheet->getActiveSheet();
    $rawRows = $sheet->toArray(null, true, true, false);

    if (!$rawRows || !isset($rawRows[0])) {
        throw new ImpValidationException('The selected XLSX backup is empty or invalid.');
    }

    $header = array_map(fn($h) => trim((string)$h), (array)$rawRows[0]);
    $rows = [];

    for ($r = 1, $len = count($rawRows); $r < $len; $r++) {
        $line = (array)$rawRows[$r];
        $assoc = [];
        foreach ($header as $i => $col) {
            $assoc[$col] = isset($line[$i]) ? trim((string)$line[$i]) : '';
        }

        $assoc = imp_normalizeAssocKeys($assoc);

        $nonEmpty = array_filter($assoc, fn($v) => trim((string)$v) !== '');
        if (!$nonEmpty) continue;

        $rows[] = $assoc;
    }

    return [$header, $rows];
}

function imp_ensureAuditLog(PDO $conn): void {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            username TEXT,
            action TEXT NOT NULL,
            entity TEXT NOT NULL,
            entity_id INTEGER,
            ip TEXT,
            user_agent TEXT,
            details TEXT,
            created_at TEXT
        );
    ");
}

function imp_audit(PDO $conn, string $action, string $entity, ?int $entityId = null, array $details = []): void {
    try {
        $stmt = $conn->prepare("
            INSERT INTO audit_log (user_id, username, action, entity, entity_id, ip, user_agent, details, created_at)
            VALUES (:user_id, :username, :action, :entity, :entity_id, :ip, :ua, :details, :created_at)
        ");
        $stmt->execute([
            ':user_id' => (int)($_SESSION['user_id'] ?? 0) ?: null,
            ':username' => (string)($_SESSION['username'] ?? '') ?: null,
            ':action' => $action,
            ':entity' => $entity,
            ':entity_id' => $entityId,
            ':ip' => (string)($_SERVER['REMOTE_ADDR'] ?? '') ?: null,
            ':ua' => (string)($_SERVER['HTTP_USER_AGENT'] ?? '') ?: null,
            ':details' => $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            ':created_at' => imp_nowLocal(),
        ]);
    } catch (Throwable $e) {
        // silent
    }
}

/* =========================================================
   MATCHING LOGIC
========================================================= */

function imp_findExistingAdolescentByBackupIdentity(
    PDO $conn,
    int $backupId,
    string $fullName,
    string $dobIso
): ?array {
    if ($backupId <= 0 || $fullName === '' || $dobIso === '') {
        return null;
    }

    $st = $conn->prepare("
        SELECT *
        FROM adolescents
        WHERE source_backup_id = :sid
        ORDER BY id DESC
        LIMIT 1
    ");
    $st->execute([':sid' => $backupId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$row) {
        return null;
    }

    $dbName = imp_normalizeText((string)($row['full_name'] ?? ''));
    $dbDob  = trim((string)($row['date_of_birth'] ?? ''));

    if ($dbName === imp_normalizeText($fullName) && $dbDob === $dobIso) {
        return $row;
    }

    return ['__conflict__' => true] + $row;
}

function imp_findExistingAdolescentFallback(
    PDO $conn,
    string $fullName,
    string $dobIso
): ?array {
    if ($fullName === '' || $dobIso === '') {
        return null;
    }

    [$lastName, $firstName, $middleName] = imp_splitName($fullName);

    $st = $conn->prepare("
        SELECT *
        FROM adolescents
        WHERE LOWER(TRIM(COALESCE(last_name,'')))   = LOWER(TRIM(:ln))
          AND LOWER(TRIM(COALESCE(first_name,'')))  = LOWER(TRIM(:fn))
          AND LOWER(TRIM(COALESCE(middle_name,''))) = LOWER(TRIM(:mn))
          AND TRIM(COALESCE(date_of_birth,''))      = TRIM(:dob)
        ORDER BY id DESC
        LIMIT 1
    ");
    $st->execute([
        ':ln' => $lastName,
        ':fn' => $firstName,
        ':mn' => $middleName,
        ':dob' => $dobIso,
    ]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($row) {
        return $row;
    }

    $st2 = $conn->prepare("
        SELECT *
        FROM adolescents
        WHERE LOWER(TRIM(COALESCE(full_name,''))) = LOWER(TRIM(:full))
          AND TRIM(COALESCE(date_of_birth,''))    = TRIM(:dob)
        ORDER BY id DESC
        LIMIT 1
    ");
    $st2->execute([
        ':full' => $fullName,
        ':dob' => $dobIso,
    ]);
    return $st2->fetch(PDO::FETCH_ASSOC) ?: null;
}

function imp_encounterExists(PDO $conn, int $adolescentId, string $encounterDate): bool {
    $st = $conn->prepare("
        SELECT 1
        FROM encounters
        WHERE adolescent_id = :aid
          AND TRIM(COALESCE(encounter_date,'')) = TRIM(:dt)
        LIMIT 1
    ");
    $st->execute([
        ':aid' => $adolescentId,
        ':dt' => $encounterDate,
    ]);
    return (bool)$st->fetchColumn();
}

function imp_countTable(PDO $conn, string $table): int {
    try {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !imp_tableExists($conn, $table)) {
            return 0;
        }
        $st = $conn->query("SELECT COUNT(*) FROM {$table}");
        return $st ? (int)$st->fetchColumn() : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

function detectImportFormat(array $header): string {
    $norm = array_values(array_unique(array_map('imp_normalizeHeaderName', $header)));
    $set = array_flip($norm);

    $newSignals = 0;
    foreach (['adolescent_id', 'encounter_id', 'data_json', 'verified_at', 'adolescent_created_at', 'encounter_created_at'] as $key) {
        if (isset($set[$key])) $newSignals++;
    }

    $oldSignals = 0;
    foreach ([
        'surname', 'given_name', 'date_encoded', 'date_submitted', 'submission_date',
        'profiling_date', 'birth_date', 'birthday', 'dob', 'age', 'city_municipality',
        'pregnancy_status', 'adolescent_mother', 'husband_name'
    ] as $key) {
        $mapped = imp_normalizeHeaderName($key);
        if (isset($set[$mapped])) $oldSignals++;
    }

    $identitySignals = 0;
    foreach (['adolescent_id', 'full_name', 'last_name', 'first_name', 'date_of_birth', 'age'] as $key) {
        if (isset($set[$key])) $identitySignals++;
    }

    if ($identitySignals === 0) {
        return 'unknown';
    }

    if ($newSignals > 0 && $oldSignals > 0) {
        return 'mixed';
    }

    if ($newSignals > 0) {
        return 'new';
    }

    return 'old';
}

function mapOldColumnToCurrentField(string $header): string {
    return imp_normalizeHeaderName($header);
}

function getValueFromPossibleColumns(array $row, array $columns, $default = null) {
    foreach ($columns as $column) {
        $key = mapOldColumnToCurrentField((string)$column);
        if (array_key_exists($key, $row) && trim((string)$row[$key]) !== '') {
            return $row[$key];
        }
    }
    return $default;
}

if (!function_exists('normalizeText')) {
    function normalizeText($value): ?string {
        return imp_nullify($value);
    }
}

function normalizeDate(?string $raw): ?string {
    return imp_dateIso($raw);
}

function normalizeCivilStatus(?string $value): ?string {
    return imp_civilStatus($value);
}

function normalizeIncomeRange(?string $value): ?string {
    $raw = imp_clean((string)$value);
    if ($raw === '') return null;

    $low = imp_lower($raw);
    $low = str_replace(['php', 'peso', 'pesos', ','], ['', '', '', ''], $low);
    $low = trim(preg_replace('/\s+/', ' ', $low) ?? $low);

    if (in_array($low, ['no income', 'none', 'n/a', 'na', 'unknown', '-', '--', '0'], true)) {
        return 'No Income';
    }

    if (preg_match('/20\s*,?\s*001|above|over|more than/i', $raw)) {
        return '20,001 and Above';
    }

    preg_match_all('/\d[\d,]*/', $raw, $matches);
    $numbers = [];
    foreach (($matches[0] ?? []) as $m) {
        $n = (int)str_replace(',', '', $m);
        if ($n > 0) $numbers[] = $n;
    }

    if (!$numbers) {
        if (str_contains($low, 'below') || str_contains($low, 'less')) return '1-10,000';
        return $raw;
    }

    $max = max($numbers);
    if ($max <= 0) return 'No Income';
    if ($max <= 10000) return '1-10,000';
    if ($max <= 20000) return '10,001 - 20,000';
    return '20,001 and Above';
}

function computeAgeFromDob(?string $dob): ?int {
    return imp_ageFromDob($dob);
}

function getAgeForReporting(?string $dob, $importedAge = null): ?int {
    $computed = computeAgeFromDob($dob);
    if ($computed !== null) return $computed;
    return imp_parseAgeValue($importedAge);
}

function imp_parseAgeValue($value): ?int {
    $raw = imp_clean((string)$value);
    if ($raw === '') return null;
    if (is_numeric($raw)) {
        $age = (int)$raw;
        return ($age >= 0 && $age <= 120) ? $age : null;
    }
    if (preg_match('/\b(\d{1,3})\b/', $raw, $m)) {
        $age = (int)$m[1];
        return ($age >= 0 && $age <= 120) ? $age : null;
    }
    return null;
}

function imp_normalizeSex(?string $value): ?string {
    $raw = imp_clean((string)$value);
    if ($raw === '') return null;
    $low = imp_lower($raw);
    if (in_array($low, ['m', 'male', 'boy', 'lalaki'], true)) return 'Male';
    if (in_array($low, ['f', 'female', 'girl', 'babae'], true)) return 'Female';
    return $raw;
}

function imp_titleCase(string $value): string {
    $value = imp_clean($value);
    if ($value === '') return '';
    if (function_exists('mb_convert_case')) {
        return mb_convert_case(imp_lower($value), MB_CASE_TITLE, 'UTF-8');
    }
    return ucwords(strtolower($value));
}

function imp_normalizeLocationValue($value): ?string {
    $raw = imp_nullify($value);
    if ($raw === null) return null;
    $raw = preg_replace('/^(brgy\.?|barangay)\s+/i', '', $raw) ?? $raw;
    return imp_titleCase($raw);
}

function imp_buildFullNameFromRow(array $row): string {
    $full = imp_clean((string)getValueFromPossibleColumns($row, ['full_name', 'name', 'complete_name'], ''));
    if ($full !== '') return $full;

    $last = imp_clean((string)getValueFromPossibleColumns($row, ['last_name', 'surname', 'family_name'], ''));
    $first = imp_clean((string)getValueFromPossibleColumns($row, ['first_name', 'given_name'], ''));
    $middle = imp_clean((string)getValueFromPossibleColumns($row, ['middle_name', 'middle_initial'], ''));
    $ext = imp_clean((string)getValueFromPossibleColumns($row, ['extension_name', 'suffix'], ''));

    if ($ext !== '' && $last !== '') {
        $last = trim($last . ' ' . $ext);
    }

    $given = trim($first . ($middle !== '' ? ' ' . $middle : ''));
    if ($last !== '' && $given !== '') return $last . ', ' . $given;
    return trim($given . ' ' . $last);
}

function imp_namePartsFromRow(array $row, string $fullName): array {
    $last = imp_clean((string)getValueFromPossibleColumns($row, ['last_name', 'surname', 'family_name'], ''));
    $first = imp_clean((string)getValueFromPossibleColumns($row, ['first_name', 'given_name'], ''));
    $middle = imp_clean((string)getValueFromPossibleColumns($row, ['middle_name', 'middle_initial'], ''));
    $ext = imp_clean((string)getValueFromPossibleColumns($row, ['extension_name', 'suffix'], ''));

    if ($last === '' && $first === '') {
        [$last, $first, $middle] = imp_splitName($fullName);
    }

    if ($ext !== '' && $last !== '' && stripos($last, $ext) === false) {
        $last = trim($last . ' ' . $ext);
    }

    return [$last, $first, $middle];
}

function imp_buildEncounterDate(array $row, array &$warnings): string {
    $raw = getValueFromPossibleColumns($row, [
        'encounter_date',
        'submission_date',
        'date_submitted',
        'date_encoded',
        'profiling_date',
        'interview_date',
        'created_at',
    ], null);

    $date = normalizeDate($raw !== null ? (string)$raw : null);
    if ($date !== null) return $date;

    $month = imp_clean((string)getValueFromPossibleColumns($row, ['month'], ''));
    $year = imp_clean((string)getValueFromPossibleColumns($row, ['year'], ''));
    if ($month !== '' && $year !== '') {
        $monthNum = is_numeric($month) ? (int)$month : (int)date('n', strtotime($month . ' 1'));
        $yearNum = (int)$year;
        if ($monthNum >= 1 && $monthNum <= 12 && $yearNum >= 1900) {
            $warnings[] = 'Encounter date was built from Month and Year.';
            return sprintf('%04d-%02d-01', $yearNum, $monthNum);
        }
    }

    $warnings[] = 'Missing encounter date, import date used.';
    return date('Y-m-d');
}

function imp_encounterStatus(?string $value): string {
    $raw = imp_clean((string)$value);
    $low = imp_lower($raw);
    $allowed = [
        'submitted' => 'submitted',
        'draft' => 'draft',
        'pending' => 'pending',
        'approved' => 'approved',
        'validated' => 'validated',
        'verified' => 'verified',
        'rejected' => 'rejected',
    ];
    return $allowed[$low] ?? 'submitted';
}

function imp_latestStoredAge(PDO $conn, int $adolescentId): ?int {
    if ($adolescentId <= 0 || !imp_tableExists($conn, 'encounters')) return null;
    try {
        $st = $conn->prepare("
            SELECT data_json
            FROM encounters
            WHERE adolescent_id = :aid
            ORDER BY COALESCE(NULLIF(TRIM(encounter_date),''),'0000-00-00') DESC, id DESC
            LIMIT 1
        ");
        $st->execute([':aid' => $adolescentId]);
        $json = imp_jsonDecodeAssoc((string)($st->fetchColumn() ?: ''));
        return imp_parseAgeValue($json['age'] ?? ($json['imported_age'] ?? null));
    } catch (Throwable $e) {
        return null;
    }
}

function findExistingAdolescent(PDO $conn, array $identity): ?array {
    $backupId = (int)($identity['backup_id'] ?? 0);
    $fullName = imp_clean((string)($identity['full_name'] ?? ''));
    $dobIso = imp_clean((string)($identity['date_of_birth'] ?? ''));
    $age = imp_parseAgeValue($identity['age'] ?? null);
    $barangay = imp_normalizePlace((string)($identity['barangay'] ?? ''));
    $municipality = imp_normalizePlace((string)($identity['municipality'] ?? ''));

    if ($backupId > 0 && imp_dbHasColumn($conn, 'adolescents', 'source_backup_id')) {
        $st = $conn->prepare("SELECT * FROM adolescents WHERE source_backup_id = :sid ORDER BY id DESC LIMIT 1");
        $st->execute([':sid' => $backupId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($row) {
            $dbName = imp_normalizeText((string)($row['full_name'] ?? ''));
            $dbDob = imp_clean((string)($row['date_of_birth'] ?? ''));
            if (($fullName === '' || $dbName === imp_normalizeText($fullName)) && ($dobIso === '' || $dbDob === '' || $dbDob === $dobIso)) {
                return $row;
            }
        }
    }

    if ($fullName === '') return null;

    $candidates = [];
    if ($dobIso !== '') {
        $st = $conn->prepare("
            SELECT *
            FROM adolescents
            WHERE LOWER(TRIM(COALESCE(full_name,''))) = LOWER(TRIM(:full))
              AND TRIM(COALESCE(date_of_birth,'')) = TRIM(:dob)
            ORDER BY id DESC
        ");
        $st->execute([':full' => $fullName, ':dob' => $dobIso]);
        $candidates = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if (!$candidates && $dobIso !== '') {
        [$lastName, $firstName, $middleName] = imp_splitName($fullName);
        $st = $conn->prepare("
            SELECT *
            FROM adolescents
            WHERE LOWER(TRIM(COALESCE(last_name,'')))   = LOWER(TRIM(:ln))
              AND LOWER(TRIM(COALESCE(first_name,'')))  = LOWER(TRIM(:fn))
              AND LOWER(TRIM(COALESCE(middle_name,''))) = LOWER(TRIM(:mn))
              AND TRIM(COALESCE(date_of_birth,''))      = TRIM(:dob)
            ORDER BY id DESC
        ");
        $st->execute([':ln' => $lastName, ':fn' => $firstName, ':mn' => $middleName, ':dob' => $dobIso]);
        $candidates = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if (!$candidates && $age !== null && ($barangay !== '' || $municipality !== '')) {
        $st = $conn->prepare("
            SELECT *
            FROM adolescents
            WHERE LOWER(TRIM(COALESCE(full_name,''))) = LOWER(TRIM(:full))
            ORDER BY id DESC
        ");
        $st->execute([':full' => $fullName]);
        $candidates = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    foreach ($candidates as $candidate) {
        $dbBarangay = imp_normalizePlace((string)($candidate['barangay'] ?? ''));
        $dbMunicipality = imp_normalizePlace((string)($candidate['municipality'] ?? ''));

        if ($barangay !== '' && $dbBarangay !== '' && $barangay !== $dbBarangay) {
            continue;
        }
        if ($municipality !== '' && $dbMunicipality !== '' && $municipality !== $dbMunicipality) {
            continue;
        }

        if ($dobIso !== '') {
            return $candidate;
        }

        $storedAge = imp_latestStoredAge($conn, (int)($candidate['id'] ?? 0));
        if ($age !== null && ($storedAge === null || $storedAge === $age)) {
            return $candidate;
        }
    }

    return null;
}

function buildDefaultValuesForMissingNewFields(array $row): array {
    return [
        'educational_attainment' => null,
        'currently_enrolled' => null,
        'grade_year_level' => null,
        'last_school_attended_type' => null,
        'reason_not_attending_school' => null,
        'reason_for_not_attending_school' => null,
        'reason_not_attending_school_other' => null,
        'vocational_training_other' => null,
    ];
}

function upsertAdolescent(PDO $conn, array $data, ?array $existing = null): array {
    $now = imp_nowLocal();
    $defaults = buildDefaultValuesForMissingNewFields($data);
    $data = array_merge($defaults, $data);

    if ($existing) {
        $adolescentId = (int)$existing['id'];
        $upd = $conn->prepare("
            UPDATE adolescents
            SET source_backup_id = COALESCE(:sid, source_backup_id),
                full_name = COALESCE(:full, full_name),
                last_name = COALESCE(:ln, last_name),
                first_name = COALESCE(:fn, first_name),
                middle_name = COALESCE(:mn, middle_name),
                date_of_birth = COALESCE(:dob, date_of_birth),
                sex = COALESCE(:sex, sex),
                barangay = COALESCE(:brgy, barangay),
                municipality = COALESCE(:mun, municipality),
                present_address = COALESCE(:addr, present_address),
                educational_attainment = COALESCE(:educational_attainment, educational_attainment),
                currently_enrolled = COALESCE(:currently_enrolled, currently_enrolled),
                grade_year_level = COALESCE(:grade_year_level, grade_year_level),
                last_school_attended_type = COALESCE(:last_school_attended_type, last_school_attended_type),
                reason_not_attending_school = COALESCE(:reason_not_attending_school, reason_not_attending_school),
                reason_for_not_attending_school = COALESCE(:reason_for_not_attending_school, reason_for_not_attending_school),
                reason_not_attending_school_other = COALESCE(:reason_not_attending_school_other, reason_not_attending_school_other),
                vocational_training_other = COALESCE(:vocational_training_other, vocational_training_other),
                updated_at = :updated_at
            WHERE id = :id
        ");
        $upd->execute([
            ':sid' => ($data['source_backup_id'] ?? null) ?: null,
            ':full' => ($data['full_name'] ?? null) ?: null,
            ':ln' => ($data['last_name'] ?? null) ?: null,
            ':fn' => ($data['first_name'] ?? null) ?: null,
            ':mn' => ($data['middle_name'] ?? null) ?: null,
            ':dob' => ($data['date_of_birth'] ?? null) ?: null,
            ':sex' => ($data['sex'] ?? null) ?: null,
            ':brgy' => ($data['barangay'] ?? null) ?: null,
            ':mun' => ($data['municipality'] ?? null) ?: null,
            ':addr' => ($data['present_address'] ?? null) ?: null,
            ':educational_attainment' => $data['educational_attainment'],
            ':currently_enrolled' => $data['currently_enrolled'],
            ':grade_year_level' => $data['grade_year_level'],
            ':last_school_attended_type' => $data['last_school_attended_type'],
            ':reason_not_attending_school' => $data['reason_not_attending_school'],
            ':reason_for_not_attending_school' => $data['reason_for_not_attending_school'],
            ':reason_not_attending_school_other' => $data['reason_not_attending_school_other'],
            ':vocational_training_other' => $data['vocational_training_other'],
            ':updated_at' => ($data['updated_at'] ?? null) ?: $now,
            ':id' => $adolescentId,
        ]);

        return ['id' => $adolescentId, 'created' => false];
    }

    $ins = $conn->prepare("
        INSERT INTO adolescents (
            source_backup_id, last_name, first_name, middle_name, full_name,
            date_of_birth, sex, barangay, municipality, present_address,
            educational_attainment, currently_enrolled, grade_year_level, last_school_attended_type,
            reason_not_attending_school, reason_for_not_attending_school, reason_not_attending_school_other, vocational_training_other,
            created_at, updated_at
        ) VALUES (
            :sid, :ln, :fn, :mn, :full,
            :dob, :sex, :brgy, :mun, :addr,
            :educational_attainment, :currently_enrolled, :grade_year_level, :last_school_attended_type,
            :reason_not_attending_school, :reason_for_not_attending_school, :reason_not_attending_school_other, :vocational_training_other,
            :created_at, :updated_at
        )
    ");
    $ins->execute([
        ':sid' => ($data['source_backup_id'] ?? null) ?: null,
        ':ln' => ($data['last_name'] ?? null) ?: null,
        ':fn' => ($data['first_name'] ?? null) ?: null,
        ':mn' => ($data['middle_name'] ?? null) ?: null,
        ':full' => ($data['full_name'] ?? null) ?: null,
        ':dob' => ($data['date_of_birth'] ?? null) ?: null,
        ':sex' => ($data['sex'] ?? null) ?: null,
        ':brgy' => ($data['barangay'] ?? null) ?: null,
        ':mun' => ($data['municipality'] ?? null) ?: null,
        ':addr' => ($data['present_address'] ?? null) ?: null,
        ':educational_attainment' => $data['educational_attainment'],
        ':currently_enrolled' => $data['currently_enrolled'],
        ':grade_year_level' => $data['grade_year_level'],
        ':last_school_attended_type' => $data['last_school_attended_type'],
        ':reason_not_attending_school' => $data['reason_not_attending_school'],
        ':reason_for_not_attending_school' => $data['reason_for_not_attending_school'],
        ':reason_not_attending_school_other' => $data['reason_not_attending_school_other'],
        ':vocational_training_other' => $data['vocational_training_other'],
        ':created_at' => ($data['created_at'] ?? null) ?: $now,
        ':updated_at' => ($data['updated_at'] ?? null) ?: null,
    ]);

    return ['id' => (int)$conn->lastInsertId(), 'created' => true];
}

function insertEncounterIfNotDuplicate(PDO $conn, int $adolescentId, string $encounterDate, array $data): array {
    if (imp_encounterExists($conn, $adolescentId, $encounterDate)) {
        return ['inserted' => false, 'duplicate' => true, 'id' => 0];
    }

    $enc = $conn->prepare("
        INSERT INTO encounters (
            adolescent_id, encounter_date, status,
            pregnant, times_pregnant, age_first_pregnancy, living_children, family_planning,
            educational_attainment, currently_enrolled, grade_year_level, last_school_attended_type,
            reason_not_attending_school, reason_for_not_attending_school, reason_not_attending_school_other, vocational_training_other,
            programs_applied, data_json, created_by_user_id, created_at,
            verified_at
        ) VALUES (
            :aid, :dt, :status,
            :preg, :times, :agefirst, :children, :fp,
            :educational_attainment, :currently_enrolled, :grade_year_level, :last_school_attended_type,
            :reason_not_attending_school, :reason_for_not_attending_school, :reason_not_attending_school_other, :vocational_training_other,
            :prog, :json, :uid, :created_at,
            :verified_at
        )
    ");
    $enc->execute([
        ':aid' => $adolescentId,
        ':dt' => $encounterDate,
        ':status' => $data['status'] ?? 'submitted',
        ':preg' => $data['pregnant'] ?? null,
        ':times' => $data['times_pregnant'] ?? null,
        ':agefirst' => $data['age_first_pregnancy'] ?? null,
        ':children' => $data['living_children'] ?? null,
        ':fp' => $data['family_planning'] ?? null,
        ':educational_attainment' => $data['educational_attainment'] ?? null,
        ':currently_enrolled' => $data['currently_enrolled'] ?? null,
        ':grade_year_level' => $data['grade_year_level'] ?? null,
        ':last_school_attended_type' => $data['last_school_attended_type'] ?? null,
        ':reason_not_attending_school' => $data['reason_not_attending_school'] ?? null,
        ':reason_for_not_attending_school' => $data['reason_for_not_attending_school'] ?? null,
        ':reason_not_attending_school_other' => $data['reason_not_attending_school_other'] ?? null,
        ':vocational_training_other' => $data['vocational_training_other'] ?? null,
        ':prog' => $data['programs_applied'] ?? null,
        ':json' => $data['data_json'] ?? '{}',
        ':uid' => $data['created_by_user_id'] ?? null,
        ':created_at' => $data['created_at'] ?? imp_nowLocal(),
        ':verified_at' => $data['verified_at'] ?? null,
    ]);

    return ['inserted' => true, 'duplicate' => false, 'id' => (int)$conn->lastInsertId()];
}

/* =========================================================
   ENSURE SCHEMA
========================================================= */
$conn->exec("
CREATE TABLE IF NOT EXISTS adolescents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_backup_id INTEGER,
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
)
");

$conn->exec("
CREATE TABLE IF NOT EXISTS encounters (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    adolescent_id INTEGER NOT NULL,
    encounter_date TEXT NOT NULL,
    status TEXT DEFAULT 'draft',
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
    created_by_user_id INTEGER,
    created_at TEXT,
    verified_by_user_id INTEGER,
    verified_at TEXT,
    FOREIGN KEY(adolescent_id) REFERENCES adolescents(id)
)
");

imp_addColumnIfMissing($conn, 'adolescents', 'source_backup_id', 'INTEGER');
imp_addColumnIfMissing($conn, 'adolescents', 'educational_attainment', 'TEXT');
imp_addColumnIfMissing($conn, 'adolescents', 'currently_enrolled', 'TEXT');
imp_addColumnIfMissing($conn, 'adolescents', 'grade_year_level', 'TEXT');
imp_addColumnIfMissing($conn, 'adolescents', 'last_school_attended_type', 'TEXT');
imp_addColumnIfMissing($conn, 'adolescents', 'reason_not_attending_school', 'TEXT');
imp_addColumnIfMissing($conn, 'adolescents', 'reason_for_not_attending_school', 'TEXT');
imp_addColumnIfMissing($conn, 'adolescents', 'reason_not_attending_school_other', 'TEXT');
imp_addColumnIfMissing($conn, 'adolescents', 'vocational_training_other', 'TEXT');
imp_addColumnIfMissing($conn, 'encounters', 'educational_attainment', 'TEXT');
imp_addColumnIfMissing($conn, 'encounters', 'currently_enrolled', 'TEXT');
imp_addColumnIfMissing($conn, 'encounters', 'grade_year_level', 'TEXT');
imp_addColumnIfMissing($conn, 'encounters', 'last_school_attended_type', 'TEXT');
imp_addColumnIfMissing($conn, 'encounters', 'reason_not_attending_school', 'TEXT');
imp_addColumnIfMissing($conn, 'encounters', 'reason_for_not_attending_school', 'TEXT');
imp_addColumnIfMissing($conn, 'encounters', 'reason_not_attending_school_other', 'TEXT');
imp_addColumnIfMissing($conn, 'encounters', 'vocational_training_other', 'TEXT');
$conn->exec("CREATE INDEX IF NOT EXISTS idx_adolescents_source_backup_id ON adolescents(source_backup_id)");
$conn->exec("CREATE INDEX IF NOT EXISTS idx_encounters_adolescent_date ON encounters(adolescent_id, encounter_date)");

if (imp_tableExists($conn, 'adolescent_mothers')) {
    imp_addColumnIfMissing($conn, 'adolescent_mothers', 'adolescent_id', 'INTEGER');
    imp_addColumnIfMissing($conn, 'adolescent_mothers', 'encounter_date', 'TEXT');
    imp_addColumnIfMissing($conn, 'adolescent_mothers', 'programs_applied', 'TEXT');
    imp_addColumnIfMissing($conn, 'adolescent_mothers', 'status', 'TEXT');
    imp_addColumnIfMissing($conn, 'adolescent_mothers', 'encoded_by_user_id', 'INTEGER');
    imp_addColumnIfMissing($conn, 'adolescent_mothers', 'locked_municipality', 'TEXT');
    imp_addColumnIfMissing($conn, 'adolescent_mothers', 'locked_barangay', 'TEXT');
    imp_addColumnIfMissing($conn, 'adolescent_mothers', 'interview_date', 'TEXT');
    imp_addColumnIfMissing($conn, 'adolescent_mothers', 'educational_attainment', 'TEXT');
    imp_addColumnIfMissing($conn, 'adolescent_mothers', 'currently_enrolled', 'TEXT');
    imp_addColumnIfMissing($conn, 'adolescent_mothers', 'grade_year_level', 'TEXT');
    imp_addColumnIfMissing($conn, 'adolescent_mothers', 'last_school_attended_type', 'TEXT');
    imp_addColumnIfMissing($conn, 'adolescent_mothers', 'reason_not_attending_school', 'TEXT');
    imp_addColumnIfMissing($conn, 'adolescent_mothers', 'reason_for_not_attending_school', 'TEXT');
    imp_addColumnIfMissing($conn, 'adolescent_mothers', 'reason_not_attending_school_other', 'TEXT');
    imp_addColumnIfMissing($conn, 'adolescent_mothers', 'vocational_training_other', 'TEXT');
    imp_addColumnIfMissing($conn, 'adolescent_mothers', 'gov_assistance', 'TEXT');
    imp_addColumnIfMissing($conn, 'adolescent_mothers', 'gov_assistance_details', 'TEXT');
    imp_addColumnIfMissing($conn, 'adolescent_mothers', 'gov_assistance_other', 'TEXT');
    imp_addColumnIfMissing($conn, 'adolescent_mothers', 'partner_name', 'TEXT');
    imp_addColumnIfMissing($conn, 'adolescent_mothers', 'partner_surname', 'TEXT');
    imp_addColumnIfMissing($conn, 'adolescent_mothers', 'partner_first_name', 'TEXT');
    imp_addColumnIfMissing($conn, 'adolescent_mothers', 'partner_middle_name', 'TEXT');
    imp_addColumnIfMissing($conn, 'adolescent_mothers', 'data_json', 'TEXT');
    imp_addColumnIfMissing($conn, 'adolescent_mothers', 'created_at', 'TEXT');
}

imp_ensureAuditLog($conn);

if (empty($_SESSION['csrf_import_data'])) {
    $_SESSION['csrf_import_data'] = bin2hex(random_bytes(32));
}

$results = [];
$summary = [
    'total' => 0,
    'imported' => 0,
    'new_adolescents' => 0,
    'updated_master' => 0,
    'encounters_added' => 0,
    'legacy_synced' => 0,
    'duplicates_skipped' => 0,
    'missing_new_fields' => 0,
    'warnings' => 0,
    'skipped' => 0,
    'failed' => 0,
    'format' => '',
    'database_path' => defined('AHDIS_DB_PATH') ? AHDIS_DB_PATH : '',
    'adolescents_before' => 0,
    'encounters_before' => 0,
    'adolescents_after' => 0,
    'encounters_after' => 0,
    'linked_after' => 0,
];

$errorMsg = '';
$successMsg = '';
$maxFileSize = IMP_MAX_BACKUP_FILE_SIZE;
$supportedBackupFormats = imp_supportedBackupFormats();
$backupAcceptValue = imp_backupAcceptValue();
$backupFormatLabels = imp_backupFormatLabels();
$showSuccessfulRows = (string)($_POST['show_successful_rows'] ?? $_GET['show_successful_rows'] ?? '') === '1';
$resultPage = max(1, (int)($_GET['result_page'] ?? 1));
$resultPerPage = 100;
$transactionBatchSize = 500;

/* =========================================================
   IMPORT
========================================================= */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        $postedToken = (string)($_POST['csrf_token'] ?? '');
        $sessionToken = (string)($_SESSION['csrf_import_data'] ?? '');

        if ($sessionToken === '' || $postedToken === '' || !hash_equals($sessionToken, $postedToken)) {
            throw new RuntimeException('Invalid CSRF token.');
        }

        if (!isset($_FILES['import_file']) || !is_array($_FILES['import_file'])) {
            throw new ImpValidationException('Please choose an XLSX or CSV backup file.');
        }

        $upload = imp_validateUploadedBackup($_FILES['import_file'], $maxFileSize);
        $originalName = $upload['name'];
        $tmpName = $upload['tmp_name'];
        $fileSize = $upload['size'];
        $ext = $upload['extension'];

        if ($ext === 'csv') {
            [$header, $rows] = imp_readCsvAssoc($tmpName);
        } else {
            [$header, $rows] = imp_readXlsxAssoc($tmpName);
        }

        $format = detectImportFormat($header);
        if ($format === 'unknown') {
            throw new ImpValidationException('This is not a recognizable AHDIS backup. Please choose a file containing the expected identity columns.');
        }

        $normalizedHeader = array_values(array_unique(array_map('imp_normalizeHeaderName', $header)));
        $headerSet = array_flip($normalizedHeader);
        $missingColumns = [];
        foreach (['education', 'currently_enrolled', 'year_level', 'pregnant', 'civil_status'] as $newField) {
            if (!isset($headerSet[$newField])) {
                $missingColumns[] = $newField;
            }
        }
        if (!isset($headerSet['encounter_date']) && !isset($headerSet['interview_date'])) {
            $missingColumns[] = 'interview_date';
        }

        $summary['total'] = count($rows);
        $summary['format'] = $format;
        $summary['database_path'] = defined('AHDIS_DB_PATH') ? AHDIS_DB_PATH : '';
        $summary['adolescents_before'] = imp_countTable($conn, 'adolescents');
        $summary['encounters_before'] = imp_countTable($conn, 'encounters');
        $actorUserId = (int)($_SESSION['user_id'] ?? ($_SESSION['id'] ?? 0));
        $nowLocal = imp_nowLocal();
        $importDate = date('Y-m-d');

        error_log('[AHDIS Import] Starting import. db_path=' . $summary['database_path'] . '; format=' . $format . '; missing_columns=' . implode(',', $missingColumns) . '; rows=' . $summary['total'] . '; adolescents_before=' . $summary['adolescents_before'] . '; encounters_before=' . $summary['encounters_before']);

        $conn->beginTransaction();
        $rowsSinceCommit = 0;

        $seenRows = [];

        foreach ($rows as $idx => $row) {
            $rowNo = $idx + 2;

            try {
                $rowWarnings = [];
                $rowMissingNewFields = false;

                $backupId = (int)getValueFromPossibleColumns($row, ['adolescent_id', 'source_backup_id', 'backup_id', 'profile_id', 'id'], 0);
                $fullName = imp_buildFullNameFromRow($row);

                if ($fullName === '' && $backupId > 0) {
                    $fullName = 'Imported Profile #' . $backupId;
                    $rowWarnings[] = 'Missing full name, old profile ID used as display name.';
                }

                $dobRaw = getValueFromPossibleColumns($row, ['date_of_birth', 'birth_date', 'birthday', 'dob', 'birthdate'], null);
                $dobIso = normalizeDate($dobRaw !== null ? (string)$dobRaw : null);
                $ageFallback = imp_parseAgeValue(getValueFromPossibleColumns($row, ['age', 'age_today', 'current_age'], null));

                if ($dobRaw !== null && $dobIso === null) {
                    $rowWarnings[] = 'Invalid date of birth cleared.';
                }
                if ($dobIso === null && $ageFallback !== null) {
                    $rowWarnings[] = 'Missing date of birth, age used as fallback.';
                }

                $barangay = imp_normalizeLocationValue(getValueFromPossibleColumns($row, ['barangay', 'locked_barangay', 'address_barangay', 'residence_barangay'], null));
                $municipality = imp_normalizeLocationValue(getValueFromPossibleColumns($row, ['municipality', 'city_municipality', 'city', 'locked_municipality'], null));
                $province = imp_normalizeLocationValue(getValueFromPossibleColumns($row, ['province'], null));
                $purok = imp_nullify(getValueFromPossibleColumns($row, ['purok'], null));
                $sitio = imp_nullify(getValueFromPossibleColumns($row, ['sitio'], null));
                $presentAddress = imp_nullify(getValueFromPossibleColumns($row, ['present_address', 'address', 'home_address', 'residential_address'], null));
                $sex = imp_normalizeSex(getValueFromPossibleColumns($row, ['sex', 'gender'], null));

                if ($presentAddress === null && $barangay && $municipality) {
                    $presentAddress = $barangay . ', ' . $municipality;
                }

                if (!$barangay || !$municipality) {
                    $rowWarnings[] = 'Missing municipality/barangay.';
                }

                $hasIdentity = ($backupId > 0)
                    || ($fullName !== '' && $dobIso !== null)
                    || ($fullName !== '' && $ageFallback !== null && ($barangay !== null || $municipality !== null));

                if (!$hasIdentity) {
                    $summary['failed']++;
                    error_log('[AHDIS Import] Row ' . $rowNo . ' skipped: insufficient identity fields.');
                    $results[] = [
                        'row' => $rowNo,
                        'name' => $fullName ?: 'N/A',
                        'status' => 'Failed',
                        'message' => 'This row does not have enough identity information to import.'
                    ];
                    continue;
                }

                $encounterDate = imp_buildEncounterDate($row, $rowWarnings);

                $adolescentCreatedAt = imp_nullify(getValueFromPossibleColumns($row, ['adolescent_created_at', 'profile_created_at', 'created_at', 'date_encoded'], null));
                $adolescentUpdatedAt = imp_nullify(getValueFromPossibleColumns($row, ['adolescent_updated_at', 'profile_updated_at'], null));
                $encounterCreatedAt = imp_nullify(getValueFromPossibleColumns($row, ['encounter_created_at', 'latest_update_saved_at', 'created_at', 'date_encoded'], null));
                $verifiedAt = imp_nullify(getValueFromPossibleColumns($row, ['verified_at', 'verified_date_time'], null));
                if ($verifiedAt !== null) {
                    $verifiedAt = imp_clean($verifiedAt);
                }

                $programsSource = getValueFromPossibleColumns($row, ['programs_applied', 'gov_assistance_selected', 'government_assistance_selected', 'gov_assistance'], null);
                $programsJson = imp_programsToJson($programsSource);
                $govDetailsImport = imp_governmentAssistanceDetailsToJson($row['gov_assistance_details'] ?? null);
                $currentlyEnrolledImport = imp_yesNo(getValueFromPossibleColumns($row, ['currently_enrolled', 'school_status', 'enrollment_status'], null));
                $educationImport = imp_normalizeEducationForImport($row, $currentlyEnrolledImport);

                foreach (['education', 'currently_enrolled', 'year_level', 'pregnant', 'civil_status'] as $maybeMissing) {
                    if (!array_key_exists($maybeMissing, $row)) {
                        $rowMissingNewFields = true;
                        break;
                    }
                }
                if ($rowMissingNewFields) {
                    $summary['missing_new_fields']++;
                }

                $seenKey = ($backupId > 0 ? ('id:' . $backupId) : ('name:' . imp_normalizeText($fullName)))
                    . '|dob:' . (string)$dobIso
                    . '|age:' . (string)($ageFallback ?? '')
                    . '|mun:' . imp_normalizePlace((string)$municipality)
                    . '|brgy:' . imp_normalizePlace((string)$barangay)
                    . '|date:' . $encounterDate;
                if (isset($seenRows[$seenKey])) {
                    $summary['duplicates_skipped']++;
                    $results[] = [
                        'row' => $rowNo,
                        'name' => $fullName,
                        'status' => 'Skipped',
                        'message' => 'Duplicate encounter row found in the same import file.'
                    ];
                    continue;
                }
                $seenRows[$seenKey] = true;

                [$lastName, $firstName, $middleName] = imp_namePartsFromRow($row, $fullName);

                $existing = findExistingAdolescent($conn, [
                    'backup_id' => $backupId,
                    'full_name' => $fullName,
                    'date_of_birth' => $dobIso,
                    'age' => $ageFallback,
                    'barangay' => $barangay,
                    'municipality' => $municipality,
                ]);

                if ($existing && imp_encounterExists($conn, (int)$existing['id'], $encounterDate)) {
                    $summary['duplicates_skipped']++;
                    $results[] = [
                        'row' => $rowNo,
                        'name' => $fullName,
                        'status' => 'Skipped',
                        'message' => 'The adolescent already existed and the encounter was skipped because the same encounter date already exists.'
                    ];
                    continue;
                }

                $upsert = upsertAdolescent($conn, [
                    'source_backup_id' => $backupId > 0 ? $backupId : null,
                    'last_name' => $lastName ?: null,
                    'first_name' => $firstName ?: null,
                    'middle_name' => $middleName ?: null,
                    'full_name' => $fullName,
                    'date_of_birth' => $dobIso,
                    'sex' => $sex,
                    'barangay' => $barangay,
                    'municipality' => $municipality,
                    'present_address' => $presentAddress,
                    'educational_attainment' => $educationImport['educational_attainment'],
                    'currently_enrolled' => $currentlyEnrolledImport,
                    'grade_year_level' => $educationImport['grade_year_level'],
                    'last_school_attended_type' => $educationImport['last_school_attended_type'],
                    'reason_not_attending_school' => $educationImport['reason_not_attending_school'],
                    'reason_for_not_attending_school' => $educationImport['reason_for_not_attending_school'],
                    'reason_not_attending_school_other' => $educationImport['reason_not_attending_school_other'],
                    'vocational_training_other' => $educationImport['vocational_training_other'],
                    'created_at' => $adolescentCreatedAt ?: $nowLocal,
                    'updated_at' => $adolescentUpdatedAt ?: $encounterCreatedAt ?: $nowLocal,
                ], $existing);

                $adolescentId = (int)$upsert['id'];
                if (!empty($upsert['created'])) {
                    $summary['new_adolescents']++;
                } else {
                    $summary['updated_master']++;
                }

                $extraDetails = imp_jsonDecodeAssoc($row['additional_details'] ?? null);
                $jsonFromExport = imp_jsonDecodeAssoc($row['data_json'] ?? null);
                $partnerSurnameImport = imp_nullify($row['partner_surname'] ?? ($jsonFromExport['partner_surname'] ?? ($jsonFromExport['partner_last_name'] ?? null)));
                $partnerFirstNameImport = imp_nullify($row['partner_first_name'] ?? ($jsonFromExport['partner_first_name'] ?? null));
                $partnerMiddleNameImport = imp_nullify($row['partner_middle_name'] ?? ($jsonFromExport['partner_middle_name'] ?? null));
                $partnerNameImport = imp_partnerNameFromParts(
                    $partnerSurnameImport,
                    $partnerFirstNameImport,
                    $partnerMiddleNameImport,
                    $row['partner_name'] ?? ($jsonFromExport['partner_name'] ?? null)
                );

                $civilRaw = getValueFromPossibleColumns($row, ['civil_status', 'marital_status', 'status'], null);
                $civilStatus = normalizeCivilStatus($civilRaw !== null ? (string)$civilRaw : null);
                if ($civilRaw !== null && $civilStatus === null && trim((string)$civilRaw) !== '') {
                    $rowWarnings[] = 'Unknown civil status normalized to blank.';
                }

                $monthlyIncome = normalizeIncomeRange((string)getValueFromPossibleColumns($row, ['monthly_income', 'household_income', 'family_income', 'income'], ''));
                $partnerMonthlyIncome = normalizeIncomeRange((string)getValueFromPossibleColumns($row, ['partner_monthly_income', 'partner_income', 'husband_monthly_income'], ''));
                $ageForReporting = getAgeForReporting($dobIso, $ageFallback);

                $baseDataJson = [
                    'source_backup_id' => $backupId > 0 ? $backupId : null,
                    'source_import_format' => $format,
                    'age' => $ageForReporting,
                    'imported_age' => $ageFallback,
                    'currently_enrolled' => $currentlyEnrolledImport,
                    'year_level' => $educationImport['grade_year_level'],
                    'grade_year_level' => $educationImport['grade_year_level'],
                    'education' => $educationImport['educational_attainment'],
                    'educational_attainment' => $educationImport['educational_attainment'],
                    'last_school_attended_type' => $educationImport['last_school_attended_type'],
                    'reason_not_attending_school' => $educationImport['reason_not_attending_school'],
                    'reason_for_not_attending_school' => $educationImport['reason_for_not_attending_school'],
                    'reason_not_attending_school_other' => $educationImport['reason_not_attending_school_other'],
                    'vocational_training_other' => $educationImport['vocational_training_other'],
                    'civil_status' => $civilStatus,
                    'province' => $province,
                    'purok' => $purok,
                    'sitio' => $sitio,
                    'living_situation' => imp_nullify($row['living_situation'] ?? null),
                    'living_others' => imp_nullify($row['living_others'] ?? null),
                    'child_under_one' => imp_yesNo($row['child_under_one'] ?? null),
                    'currently_working' => imp_yesNo($row['currently_working'] ?? null),
                    'monthly_income' => $monthlyIncome,
                    'occupation' => imp_nullify($row['occupation'] ?? null),
                    'ethnicity' => imp_nullify($row['ethnicity'] ?? null),
                    'is_4ps' => imp_yesNo($row['is_4ps'] ?? null),
                    'is_ips' => imp_yesNo($row['is_ips'] ?? null),
                    'father_occupation' => imp_nullify($row['father_occupation'] ?? null),
                    'mother_occupation' => imp_nullify($row['mother_occupation'] ?? null),
                    'health_concerns' => imp_nullify($row['health_concerns'] ?? null),
                    'prenatal_care' => imp_nullify($row['prenatal_care'] ?? null),
                    'months_pregnant' => imp_nullify($row['months_pregnant'] ?? null),
                    'place_of_birth' => imp_nullify($row['place_of_birth'] ?? null),
                    'interview_date' => imp_nullify($row['interview_date'] ?? null),
                    'interviewed_by' => imp_nullify($row['interviewed_by'] ?? null),
                    'noted_by' => imp_nullify($row['noted_by'] ?? null),
                    'noted_date' => imp_nullify($row['noted_date'] ?? null),
                    'other_observations' => imp_nullify($row['other_observations'] ?? null),

                    'partner_name' => $partnerNameImport,
                    'partner_surname' => $partnerSurnameImport,
                    'partner_first_name' => $partnerFirstNameImport,
                    'partner_middle_name' => $partnerMiddleNameImport,
                    'partner_age' => imp_nullify($row['partner_age'] ?? null),
                    'partner_dob' => imp_dateIso($row['partner_dob'] ?? null),
                    'partner_address' => imp_nullify($row['partner_address'] ?? null),
                    'partner_place_of_birth' => imp_nullify($row['partner_place_of_birth'] ?? null),
                    'partner_education' => imp_nullify($row['partner_education'] ?? null),
                    'partner_currently_enrolled' => imp_yesNo($row['partner_currently_enrolled'] ?? null),
                    'partner_year_level' => imp_nullify($row['partner_year_level'] ?? null),
                    'partner_currently_working' => imp_yesNo($row['partner_currently_working'] ?? null),
                    'partner_occupation' => imp_nullify($row['partner_occupation'] ?? null),
                    'partner_monthly_income' => $partnerMonthlyIncome,

                    'gov_assistance' => $programsJson,
                    'gov_assistance_selected' => imp_nullify($row['gov_assistance_selected'] ?? null),
                    'gov_assistance_details' => $govDetailsImport,
                    'gov_assistance_other' => imp_nullify($row['gov_assistance_other'] ?? null),

                    'name' => $fullName,
                    'date_of_birth' => $dobIso,
                    'present_address' => $presentAddress,
                    'locked_barangay' => $barangay,
                    'locked_municipality' => $municipality,
                    'barangay' => $barangay,
                    'municipality' => $municipality,
                    'sex' => $sex,
                    'encoded_by' => imp_nullify($row['encoded_by'] ?? null),
                    'verified_by' => imp_nullify($row['verified_by'] ?? null),
                    'verified_at' => $verifiedAt,
                    'adolescent_created_at' => $adolescentCreatedAt,
                    'adolescent_updated_at' => $adolescentUpdatedAt,
                    'encounter_created_at' => $encounterCreatedAt,
                ];

                $mergedDataJson = array_merge($jsonFromExport, $extraDetails, $baseDataJson);
                $dataJson = json_encode($mergedDataJson, JSON_UNESCAPED_UNICODE);
                if ($dataJson === false) $dataJson = '{}';

                $pregnant = imp_yesNo(getValueFromPossibleColumns($row, ['pregnant', 'pregnancy_status', 'adolescent_mother'], null));
                $timesPregnant = imp_parseAgeValue(getValueFromPossibleColumns($row, ['times_pregnant', 'gravida'], null));
                $ageFirstPregnancy = imp_parseAgeValue(getValueFromPossibleColumns($row, ['age_first_pregnancy', 'age_at_first_pregnancy'], null));
                $livingChildren = imp_parseAgeValue(getValueFromPossibleColumns($row, ['living_children', 'number_of_children', 'number_of_living_children', 'para'], null));
                $familyPlanning = imp_nullify(getValueFromPossibleColumns($row, ['family_planning', 'family_planning_method'], null));
                $status = imp_encounterStatus((string)getValueFromPossibleColumns($row, ['encounter_status', 'record_status', 'status'], 'submitted'));

                $encounterInsert = insertEncounterIfNotDuplicate($conn, $adolescentId, $encounterDate, [
                    'status' => $status,
                    'pregnant' => $pregnant,
                    'times_pregnant' => $timesPregnant,
                    'age_first_pregnancy' => $ageFirstPregnancy,
                    'living_children' => $livingChildren,
                    'family_planning' => $familyPlanning,
                    'educational_attainment' => $educationImport['educational_attainment'],
                    'currently_enrolled' => $currentlyEnrolledImport,
                    'grade_year_level' => $educationImport['grade_year_level'],
                    'last_school_attended_type' => $educationImport['last_school_attended_type'],
                    'reason_not_attending_school' => $educationImport['reason_not_attending_school'],
                    'reason_for_not_attending_school' => $educationImport['reason_for_not_attending_school'],
                    'reason_not_attending_school_other' => $educationImport['reason_not_attending_school_other'],
                    'vocational_training_other' => $educationImport['vocational_training_other'],
                    'programs_applied' => $programsJson,
                    'data_json' => $dataJson,
                    'created_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
                    'created_at' => $encounterCreatedAt ?: $nowLocal,
                    'verified_at' => $verifiedAt,
                ]);

                if (!empty($encounterInsert['duplicate'])) {
                    $summary['duplicates_skipped']++;
                    $results[] = [
                        'row' => $rowNo,
                        'name' => $fullName,
                        'status' => 'Skipped',
                        'message' => 'The adolescent already existed and the encounter was skipped because the same encounter date already exists.'
                    ];
                    continue;
                }

                $summary['encounters_added']++;

                if (imp_tableExists($conn, 'adolescent_mothers')) {
                    $legacyData = [
                        'adolescent_id' => $adolescentId,
                        'name' => $fullName,
                        'present_address' => $presentAddress,
                        'date_of_birth' => $dobIso,
                        'age' => $ageForReporting,
                        'sex' => $sex,
                        'place_of_birth' => imp_nullify($row['place_of_birth'] ?? null),
                        'education' => $educationImport['educational_attainment'],
                        'educational_attainment' => $educationImport['educational_attainment'],
                        'currently_enrolled' => $currentlyEnrolledImport,
                        'year_level' => $educationImport['grade_year_level'],
                        'grade_year_level' => $educationImport['grade_year_level'],
                        'last_school_attended_type' => $educationImport['last_school_attended_type'],
                        'reason_not_attending_school' => $educationImport['reason_not_attending_school'],
                        'reason_for_not_attending_school' => $educationImport['reason_for_not_attending_school'],
                        'reason_not_attending_school_other' => $educationImport['reason_not_attending_school_other'],
                        'vocational_training_other' => $educationImport['vocational_training_other'],
                        'civil_status' => $civilStatus,
                        'living_situation' => imp_nullify($row['living_situation'] ?? null),
                        'living_others' => imp_nullify($row['living_others'] ?? null),
                        'pregnant' => $pregnant,
                        'prenatal_care' => imp_nullify($row['prenatal_care'] ?? null),
                        'months_pregnant' => (($v = imp_nullify($row['months_pregnant'] ?? null)) !== null && is_numeric($v)) ? (int)$v : null,
                        'health_concerns' => imp_nullify($row['health_concerns'] ?? null),
                        'age_first_pregnancy' => ($ageFirstPregnancy !== null && is_numeric($ageFirstPregnancy)) ? (int)$ageFirstPregnancy : null,
                        'living_children' => ($livingChildren !== null && is_numeric($livingChildren)) ? (int)$livingChildren : null,
                        'times_pregnant' => ($timesPregnant !== null && is_numeric($timesPregnant)) ? (int)$timesPregnant : null,
                        'family_planning' => $familyPlanning,
                        'occupation' => imp_nullify($row['occupation'] ?? null),
                        'monthly_income' => $monthlyIncome,
                        'ethnic_group' => imp_nullify($row['ethnicity'] ?? null),
                        'locked_barangay' => $barangay,
                        'locked_municipality' => $municipality,
                        'interview_date' => imp_nullify($row['interview_date'] ?? null),
                        'interviewed_by' => imp_nullify($row['interviewed_by'] ?? null),
                        'noted_by' => imp_nullify($row['noted_by'] ?? null),
                        'noted_date' => imp_nullify($row['noted_date'] ?? null),
                        'other_observations' => imp_nullify($row['other_observations'] ?? null),
                        'gov_assistance' => $programsJson,
                        'gov_assistance_details' => $govDetailsImport,
                        'gov_assistance_other' => imp_nullify($row['gov_assistance_other'] ?? null),
                        'partner_name' => $partnerNameImport,
                        'partner_surname' => $partnerSurnameImport,
                        'partner_first_name' => $partnerFirstNameImport,
                        'partner_middle_name' => $partnerMiddleNameImport,
                        'partner_age' => (($v = imp_nullify($row['partner_age'] ?? null)) !== null && is_numeric($v)) ? (int)$v : null,
                        'partner_dob' => imp_dateIso($row['partner_dob'] ?? null),
                        'partner_address' => imp_nullify($row['partner_address'] ?? null),
                        'partner_place_of_birth' => imp_nullify($row['partner_place_of_birth'] ?? null),
                        'partner_education' => imp_nullify($row['partner_education'] ?? null),
                        'partner_currently_enrolled' => imp_yesNo($row['partner_currently_enrolled'] ?? null),
                        'partner_year_level' => imp_nullify($row['partner_year_level'] ?? null),
                        'partner_currently_working' => imp_yesNo($row['partner_currently_working'] ?? null),
                        'partner_occupation' => imp_nullify($row['partner_occupation'] ?? null),
                        'partner_monthly_income' => $partnerMonthlyIncome,
                        'data_json' => $dataJson,
                        'encounter_date' => $encounterDate,
                        'programs_applied' => $programsJson,
                        'status' => $status,
                        'encoded_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
                        'created_at' => $encounterCreatedAt ?: $nowLocal,
                    ];

                    $cols = [];
                    $vals = [];
                    $bind = [];

                    foreach ($legacyData as $k => $v) {
                        if (imp_dbHasColumn($conn, 'adolescent_mothers', $k)) {
                            $cols[] = $k;
                            $vals[] = ':' . $k;
                            $bind[$k] = $v;
                        }
                    }

                    if ($cols) {
                        $sql = "INSERT INTO adolescent_mothers (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
                        $st = $conn->prepare($sql);
                        foreach ($bind as $k => $v) {
                            $st->bindValue(':' . $k, $v);
                        }
                        $st->execute();
                        $summary['legacy_synced']++;
                    }
                }

                $summary['imported']++;
                $importMessage = !empty($upsert['created'])
                    ? 'New adolescent profile imported successfully.'
                    : 'New encounter added for existing adolescent.';
                if (!empty($educationImport['warnings'])) {
                    $rowWarnings = array_merge($rowWarnings, $educationImport['warnings']);
                }
                if ($rowMissingNewFields) {
                    $rowWarnings[] = 'Some fields were not present in the old AHDIS file and were saved as blank/default values.';
                }
                if ($rowWarnings) {
                    $summary['warnings']++;
                    $importMessage .= ' Warning: ' . implode(' ', array_unique($rowWarnings));
                }
                $resultEntry = [
                    'row' => $rowNo,
                    'name' => $fullName,
                    'status' => 'Imported',
                    'message' => $importMessage
                ];
                if ($showSuccessfulRows || imp_resultNeedsAttention($resultEntry)) {
                    $results[] = $resultEntry;
                }

            } catch (Throwable $rowError) {
                $summary['failed']++;
                error_log('[AHDIS Import] Row ' . $rowNo . ' failed: ' . $rowError->getMessage());
                $results[] = [
                    'row' => $rowNo,
                    'name' => imp_buildFullNameFromRow($row) ?: imp_clean((string)($row['full_name'] ?? 'N/A')),
                    'status' => 'Failed',
                    'message' => 'This row could not be imported. Please review its identity fields and date values.'
                ];
            } finally {
                $rowsSinceCommit++;
                if ($rowsSinceCommit >= $transactionBatchSize) {
                    $conn->commit();
                    $conn->beginTransaction();
                    $rowsSinceCommit = 0;
                }
            }
        }

        if ($conn->inTransaction()) {
            $conn->commit();
        }

        $summary['adolescents_after'] = imp_countTable($conn, 'adolescents');
        $summary['encounters_after'] = imp_countTable($conn, 'encounters');
        try {
            $summary['linked_after'] = (int)$conn->query("
                SELECT COUNT(*)
                FROM adolescents a
                JOIN encounters e ON a.id = e.adolescent_id
            ")->fetchColumn();
        } catch (Throwable $e) {
            $summary['linked_after'] = 0;
        }

        if ($summary['adolescents_after'] > 0 && $summary['encounters_after'] === 0) {
            $summary['warnings']++;
            error_log('[AHDIS Import] Verification warning: adolescents exist but encounters table is empty. Dashboard/report visibility requires linked encounters.');
        }

        error_log('[AHDIS Import] Finished import. db_path=' . $summary['database_path'] . '; format=' . $summary['format'] . '; adolescents_after=' . $summary['adolescents_after'] . '; encounters_after=' . $summary['encounters_after'] . '; linked_after=' . $summary['linked_after'] . '; imported=' . $summary['imported'] . '; duplicates=' . $summary['duplicates_skipped'] . '; warnings=' . $summary['warnings'] . '; failed=' . $summary['failed']);

        imp_audit($conn, 'IMPORT', 'import_data', null, [
            'filename' => $originalName,
            'filesize' => $fileSize,
            'database_path' => $summary['database_path'],
            'format' => $summary['format'],
            'total' => $summary['total'],
            'imported' => $summary['imported'],
            'new_adolescents' => $summary['new_adolescents'],
            'updated_master' => $summary['updated_master'],
            'encounters_added' => $summary['encounters_added'],
            'legacy_synced' => $summary['legacy_synced'],
            'duplicates_skipped' => $summary['duplicates_skipped'],
            'missing_new_fields' => $summary['missing_new_fields'],
            'warnings' => $summary['warnings'],
            'skipped' => $summary['skipped'],
            'failed' => $summary['failed'],
            'adolescents_before' => $summary['adolescents_before'],
            'encounters_before' => $summary['encounters_before'],
            'adolescents_after' => $summary['adolescents_after'],
            'encounters_after' => $summary['encounters_after'],
            'linked_after' => $summary['linked_after'],
        ]);

        $successMsg = "Import completed. Imported records were saved to adolescents and linked encounters.";

    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log('[AHDIS Import] Fatal import failure: ' . $e->getMessage());
        $errorMsg = $e instanceof ImpValidationException
            ? $e->getMessage()
            : 'The import could not be completed. Please check that the file is a readable AHDIS XLSX or CSV backup.';
    }

    $_SESSION['csrf_import_data'] = bin2hex(random_bytes(32));
}

$attentionResults = array_values(array_filter($results, 'imp_resultNeedsAttention'));
$successfulResults = array_values(array_filter($results, static fn(array $r): bool => !imp_resultNeedsAttention($r)));
$displayResults = $showSuccessfulRows ? $results : $attentionResults;
$resultTotal = count($displayResults);
$resultTotalPages = max(1, (int)ceil($resultTotal / $resultPerPage));
if ($resultPage > $resultTotalPages) {
    $resultPage = $resultTotalPages;
}
$resultOffset = ($resultPage - 1) * $resultPerPage;
$pagedResults = array_slice($displayResults, $resultOffset, $resultPerPage);

require_once __DIR__ . "/../includes/header.php";
require_once __DIR__ . "/../includes/sidebar.php";
?>

<style>
:root{
    --imp-navy:#0f172a;
    --imp-deep:#163a73;
    --imp-blue:#1d4ed8;
    --imp-pink:#d889c7;
    --imp-soft:#f8fbff;
    --imp-border:#dbeafe;
    --imp-text:#0f172a;
    --imp-muted:#64748b;
    --imp-success-bg:#ecfdf5;
    --imp-success-text:#065f46;
    --imp-success-border:#a7f3d0;
    --imp-danger-bg:#fef2f2;
    --imp-danger-text:#991b1b;
    --imp-danger-border:#fecaca;
    --imp-info-bg:#eff6ff;
    --imp-info-text:#1d4ed8;
    --imp-info-border:#bfdbfe;
    --imp-warn-bg:#fff7ed;
    --imp-warn-text:#9a3412;
    --imp-warn-border:#fed7aa;
}

.import-page{
    max-width:1320px;
    margin:0 auto;
    padding:14px 0 36px;
}

.import-card{
    background:linear-gradient(180deg,#ffffff 0%,#fbfdff 100%);
    border:1px solid rgba(59,130,246,.12);
    border-radius:30px;
    box-shadow:0 24px 60px rgba(15,23,42,.10), 0 6px 20px rgba(37,99,235,.06);
    overflow:hidden;
}

.import-hero{
    padding:16px 20px;
    min-height:88px;
    color:#fff;
    display:flex;
    align-items:center;
    background:
        radial-gradient(circle at top right, rgba(255,255,255,.16), transparent 26%),
        linear-gradient(135deg, #0f172a 0%, #163a73 42%, #df82c6 75%, #cd87be 100%);
}

.import-hero h2{
    margin:0;
    font-size:28px;
    font-weight:900;
    letter-spacing:.2px;
}

.import-body{
    padding:16px;
    background:linear-gradient(180deg, #f8fbff 0%, #f4f8fd 100%);
}

.section-box{
    background:#fff;
    border:1px solid #dbe7f8;
    border-radius:20px;
    padding:18px;
    box-shadow:0 10px 24px rgba(15,23,42,.05);
    margin-bottom:16px;
}

.section-title{
    margin:0 0 8px;
    font-size:22px;
    font-weight:900;
    color:var(--imp-text);
}

.section-sub{
    margin:0 0 14px;
    color:var(--imp-muted);
    font-size:15px;
    font-weight:700;
    line-height:1.5;
}

.alert{
    margin-bottom:14px;
    padding:14px 16px;
    border-radius:14px;
    font-weight:800;
    border:1px solid transparent;
}
.alert.ok{
    background:var(--imp-success-bg);
    color:var(--imp-success-text);
    border-color:var(--imp-success-border);
}
.alert.bad{
    background:var(--imp-danger-bg);
    color:var(--imp-danger-text);
    border-color:var(--imp-danger-border);
}
.alert.info{
    background:var(--imp-info-bg);
    color:var(--imp-info-text);
    border-color:var(--imp-info-border);
}
.alert.warn{
    background:var(--imp-warn-bg);
    color:var(--imp-warn-text);
    border-color:var(--imp-warn-border);
}

.form-row{
    display:grid;
    grid-template-columns:1fr auto;
    gap:12px;
    align-items:end;
}

.input-file{
    width:100%;
    border:1px solid #cfe0fb;
    background:#fff;
    border-radius:14px;
    padding:14px;
    font-size:15px;
    font-weight:700;
}

.import-file-message{
    margin-top:8px;
    color:var(--imp-danger-text);
    font-size:13px;
    font-weight:800;
}

.import-file-message[hidden]{
    display:none;
}

.import-option{
    margin-top:12px;
    display:flex;
    align-items:center;
    gap:8px;
    color:#475569;
    font-size:13px;
    font-weight:800;
}

.import-loading{
    display:none;
    margin-top:12px;
    color:#1d4ed8;
    font-weight:900;
}

form.is-loading .import-loading{
    display:block;
}

form.is-loading .btn-primary{
    opacity:.68;
    pointer-events:none;
}

.btn{
    appearance:none;
    border:none;
    text-decoration:none;
    cursor:pointer;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    padding:12px 22px;
    min-height:48px;
    border-radius:999px;
    font-weight:900;
    font-size:16px;
    line-height:1;
    transition:
        background-color 120ms ease,
        color 120ms ease,
        border-color 120ms ease,
        box-shadow 120ms ease,
        opacity 120ms ease;
    white-space:nowrap;
}
.btn:hover{ transform:none; }

.btn-primary{
    background:linear-gradient(135deg, #2563eb 0%, #1d4ed8 55%, #163a73 100%);
    color:#fff;
    box-shadow:0 10px 22px rgba(29,78,216,.28);
}

.results-note{
    color:#475569;
    font-size:13px;
    font-weight:800;
    margin:0 0 12px;
}

.result-pager{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    flex-wrap:wrap;
    margin-top:12px;
    color:#475569;
    font-size:13px;
    font-weight:800;
}

.summary-grid{
    display:grid;
    grid-template-columns:repeat(3, minmax(180px,1fr));
    gap:14px;
    margin-top:16px;
}

.sum-box{
    background:#f8fbff;
    border:1px solid #dbeafe;
    border-radius:18px;
    padding:18px;
    box-shadow:0 8px 18px rgba(15,23,42,.04);
}

.sum-box .label{
    color:#64748b;
    font-size:12px;
    font-weight:900;
    letter-spacing:.08em;
    line-height:1.3;
    text-transform:uppercase;
}

.sum-box .value{
    margin-top:8px;
    font-size:34px;
    font-weight:900;
    color:#0f172a;
    line-height:1;
}

.table-wrap{
    overflow:auto;
    max-height:560px;
    border:1px solid #e2ebf8;
    border-radius:18px;
    background:#fff;
}

.result-table{
    width:100%;
    min-width:760px;
    border-collapse:collapse;
}

.result-table th,
.result-table td{
    padding:14px 12px;
    border-bottom:1px solid #edf2fb;
    text-align:left;
    vertical-align:top;
    font-size:14px;
}

.result-table th{
    position:sticky;
    top:0;
    background:linear-gradient(180deg, #dfeafb 0%, #d2e1f7 100%);
    font-size:14px;
    font-weight:900;
    z-index:2;
}

.badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:96px;
    padding:6px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:900;
    border:1px solid transparent;
}

.badge.ok{
    background:#ecfdf5;
    color:#065f46;
    border-color:#a7f3d0;
}
.badge.duplicate{
    background:#fff1f2;
    color:#9f1239;
    border-color:#fecdd3;
}
.badge.warn,
.badge.skip{
    background:#fff7ed;
    color:#9a3412;
    border-color:#fed7aa;
}
.badge.fail{
    background:#fef2f2;
    color:#991b1b;
    border-color:#fecaca;
}

.help-list{
    margin:10px 0 0;
    padding-left:18px;
    color:#475569;
    font-size:14px;
    font-weight:700;
    line-height:1.7;
}

@media (max-width: 1100px){
    .summary-grid{
        grid-template-columns:repeat(3, minmax(120px,1fr));
    }
}

@media (max-width: 700px){
    .form-row{
        grid-template-columns:1fr;
    }
    .summary-grid{
        grid-template-columns:1fr;
    }
    .import-hero h2{
        font-size:24px;
    }
}
</style>

<div class="import-page">
    <div class="import-card">
        <div class="import-hero">
            <h2>Restore Backup Data</h2>
        </div>

        <div class="import-body">
            <?php if ($successMsg !== ''): ?>
                <div class="alert ok">Backup restore finished successfully.</div>
            <?php endif; ?>

            <?php if ($errorMsg !== ''): ?>
                <div class="alert bad"><?= imp_h($errorMsg) ?></div>
            <?php endif; ?>

            <div class="section-box">
                <h3 class="section-title">Upload Backup File</h3>
                <p class="section-sub">
                    Upload the exported backup file to restore adolescent records if the original computer becomes unavailable,
                    damaged, or no longer turns on. Backup files may come from Google Drive, flash drive, or external hard drive.
                </p>

                <div class="alert warn">
                    Supported backup formats: <strong><?= imp_h($backupFormatLabels) ?></strong>. Maximum file size:
                    <strong>20MB</strong>. Use the most recent backup file to restore the latest records.
                </div>

                <form method="post" enctype="multipart/form-data" style="margin-top:14px;" class="js-import-form">
                    <input type="hidden" name="csrf_token" value="<?= imp_h($_SESSION['csrf_import_data']) ?>">

                    <div class="form-row">
                        <input
                            id="import-backup-file"
                            class="input-file"
                            type="file"
                            name="import_file"
                            accept="<?= imp_h($backupAcceptValue) ?>"
                            aria-describedby="import-file-message"
                            required
                        >
                        <button class="btn btn-primary js-import-submit" type="submit">Restore Data</button>
                    </div>
                    <div id="import-file-message" class="import-file-message" role="alert" aria-live="polite" hidden></div>
                    <label class="import-option">
                        <input type="checkbox" name="show_successful_rows" value="1" <?= $showSuccessfulRows ? 'checked' : '' ?>>
                        Show successful rows after import
                    </label>
                    <div class="import-loading">Loading... please wait while AHDIS processes the file.</div>
                </form>
            </div>

            <?php if ($summary['total'] > 0 || $summary['imported'] > 0 || $summary['duplicates_skipped'] > 0 || $summary['failed'] > 0 || $summary['skipped'] > 0): ?>
                <div class="section-box">
                    <h3 class="section-title">Restore Summary</h3>

                    <div class="summary-grid">
                        <div class="sum-box">
                            <div class="label">Total Rows</div>
                            <div class="value"><?= (int)$summary['total'] ?></div>
                        </div>

                        <div class="sum-box">
                            <div class="label">Restored</div>
                            <div class="value"><?= (int)$summary['imported'] ?></div>
                        </div>

                        <div class="sum-box">
                            <div class="label">Duplicate</div>
                            <div class="value"><?= (int)$summary['duplicates_skipped'] ?></div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($results): ?>
                <div class="section-box">
                    <h3 class="section-title">Row Results</h3>
                    <p class="results-note">
                        Showing <?= $showSuccessfulRows ? 'all row results' : 'duplicates, failed rows, and warnings only' ?>.
                        <?= !$showSuccessfulRows && count($successfulResults) > 0 ? imp_h((string)count($successfulResults)) . ' successful rows are hidden to keep the page smooth.' : '' ?>
                    </p>

                    <?php if (!$pagedResults): ?>
                        <div class="alert ok">No duplicate, failed, or warning rows to review.</div>
                    <?php else: ?>
                    <div class="table-wrap">
                        <table class="result-table">
                            <thead>
                                <tr>
                                    <th>Row</th>
                                    <th>Name</th>
                                    <th>Status</th>
                                    <th>Message</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pagedResults as $r): ?>
                                    <tr>
                                        <td><?= (int)$r['row'] ?></td>
                                        <td><?= imp_h($r['name']) ?></td>
                                        <td>
                                            <?php
                                            $rawStatus = (string)($r['status'] ?? '');
                                            $message = (string)($r['message'] ?? '');
                                            $isDuplicate = preg_match('/duplicate|existing encounter|same adolescent and same encounter date/i', $message) === 1;
                                            $hasWarning = preg_match('/warning/i', $message) === 1;
                                            $displayStatus = $rawStatus === 'Skipped' ? 'Warning' : $rawStatus;
                                            $cls = 'ok';
                                            if ($hasWarning) {
                                                $cls = 'warn';
                                                $displayStatus = 'Warning';
                                            }
                                            if ($rawStatus === 'Skipped') {
                                                $cls = $isDuplicate ? 'duplicate' : 'warn';
                                                $displayStatus = $isDuplicate ? 'Duplicate' : 'Warning';
                                            }
                                            if ($rawStatus === 'Failed') {
                                                $cls = 'fail';
                                                $displayStatus = 'Failed';
                                            }
                                            ?>
                                            <span class="badge <?= imp_h($cls) ?>"><?= imp_h($displayStatus) ?></span>
                                        </td>
                                        <td><?= imp_h((string)$r['message']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="result-pager">
                        <span>Page <?= (int)$resultPage ?> of <?= (int)$resultTotalPages ?> &middot; Showing <?= (int)count($pagedResults) ?> of <?= (int)$resultTotal ?> row results</span>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<script>
const backupFileConfig = <?= json_encode([
    'maxSize' => $maxFileSize,
    'formatLabels' => $backupFormatLabels,
    'formats' => $supportedBackupFormats,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

const getBackupExtension = (fileName) => {
    const dot = String(fileName || '').lastIndexOf('.');
    return dot >= 0 ? String(fileName).slice(dot + 1).toLowerCase() : '';
};

const setBackupFileMessage = (input, message) => {
    const messageBox = input.form?.querySelector('.import-file-message');
    input.setCustomValidity(message);
    if (!messageBox) return;
    messageBox.textContent = message;
    messageBox.hidden = message === '';
};

const getBackupFileError = (file) => {
    if (!file) {
        return `Please choose an ${backupFileConfig.formatLabels} backup file.`;
    }

    const extension = getBackupExtension(file.name);
    const format = backupFileConfig.formats[extension];
    if (!format) {
        return `Unsupported file type. Please choose an ${backupFileConfig.formatLabels} backup file.`;
    }

    if (file.size <= 0) {
        return 'The selected backup file is empty or unreadable.';
    }

    if (file.size > backupFileConfig.maxSize) {
        return 'File is too large. Maximum allowed size is 20MB.';
    }

    const browserMime = String(file.type || '').toLowerCase();
    const allowedMimes = format.mime_types.map((mime) => String(mime).toLowerCase());
    if (browserMime && !allowedMimes.includes(browserMime)) {
        return `The selected file does not appear to be a genuine ${format.label} backup file.`;
    }

    return '';
};

const validateBackupSelection = (input, showEmptyMessage = false) => {
    const file = input.files?.[0];
    if (!file) {
        setBackupFileMessage(input, showEmptyMessage ? `Please choose an ${backupFileConfig.formatLabels} backup file.` : '');
        return false;
    }

    const message = getBackupFileError(file);
    if (message) {
        setBackupFileMessage(input, message);
        input.value = '';
        return false;
    }

    setBackupFileMessage(input, '');
    return true;
};

document.querySelectorAll('.js-import-form').forEach((form) => {
    const fileInput = form.querySelector('input[type="file"][name="import_file"]');
    if (!fileInput) return;

    let lastValidFile = null;

    const restoreLastValidFile = () => {
        if (fileInput.files?.length || !lastValidFile) return false;
        const transfer = new DataTransfer();
        transfer.items.add(lastValidFile);
        fileInput.files = transfer.files;
        setBackupFileMessage(fileInput, '');
        return true;
    };

    fileInput.addEventListener('click', () => {
        if (fileInput.files?.[0]) {
            lastValidFile = fileInput.files[0];
        }
    });

    fileInput.addEventListener('cancel', () => {
        restoreLastValidFile();
    });

    fileInput.addEventListener('change', () => {
        if (restoreLastValidFile()) return;
        if (validateBackupSelection(fileInput, false)) {
            lastValidFile = fileInput.files[0];
        } else {
            lastValidFile = null;
        }
    });

    fileInput.addEventListener('drop', (event) => {
        const droppedFile = event.dataTransfer?.files?.[0];
        const message = getBackupFileError(droppedFile);
        if (message) {
            event.preventDefault();
            fileInput.value = '';
            lastValidFile = null;
            setBackupFileMessage(fileInput, message);
            return;
        }

        window.setTimeout(() => {
            if (validateBackupSelection(fileInput, false)) {
                lastValidFile = fileInput.files[0];
            }
        }, 0);
    });

    fileInput.addEventListener('invalid', () => {
        if (!fileInput.files?.length) {
            setBackupFileMessage(fileInput, `Please choose an ${backupFileConfig.formatLabels} backup file.`);
        }
    });

    form.addEventListener('submit', (event) => {
        if (!validateBackupSelection(fileInput, true)) {
            event.preventDefault();
            return;
        }

        form.classList.add('is-loading');
        form.querySelectorAll('button[type="submit"]').forEach((button) => {
            button.disabled = true;
            button.textContent = 'Loading...';
        });
    });
});
</script>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
