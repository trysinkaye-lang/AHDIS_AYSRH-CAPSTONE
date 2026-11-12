<?php
declare(strict_types=1);

require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/education_options.php";

date_default_timezone_set('Asia/Manila');

$requestedType = (string)($_GET['type'] ?? '');
$type = $requestedType === 'first_pregnancy_10_14'
    ? 'first_pregnancy_9_14'
    : $requestedType;

$ageGroups = ["9-14", "15-17", "18-19"];
$partnerAgeGroups = ["Below 18", "18-24", "25 and above"];
$reportError = '';

define('REPORT_MIN_AGE', 9);
define('REPORT_MAX_AGE', 19);


/* =========================================================
   BASIC HELPERS
========================================================= */

function tableExistsReport(PDO $conn, string $table): bool {
    try {
        $stmt = $conn->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:t LIMIT 1");
        $stmt->execute([':t' => $table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function dbHasColumnReport(PDO $conn, string $table, string $col): bool {
    try {
        $stmt = $conn->query("PRAGMA table_info($table)");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $r) {
            if (strcasecmp((string)($r['name'] ?? ''), $col) === 0) {
                return true;
            }
        }
        return false;
    } catch (Throwable $e) {
        return false;
    }
}

function isReportableAdolescentAge($age): bool {
    $age = (int)$age;
    return $age >= REPORT_MIN_AGE && $age <= REPORT_MAX_AGE;
}

function ageGroup($age): ?string {
    $age = (int)$age;
    if ($age >= 9 && $age <= 14) return "9-14";
    if ($age >= 15 && $age <= 17) return "15-17";
    if ($age >= 18 && $age <= 19) return "18-19";
    return null;
}

function partnerAgeGroup($age): ?string {
    $age = (int)$age;
    if ($age > 0 && $age < 18) return "Below 18";
    if ($age >= 18 && $age <= 24) return "18-24";
    if ($age >= 25) return "25 and above";
    return null;
}

function normalizeTextReport($value): string {
    $s = trim((string)$value);
    $s = str_replace("\xC2\xA0", ' ', $s);
    $s = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $s) ?? $s;
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return trim($s);
}

function normalizeLowerReport($value): string {
    $s = normalizeTextReport($value);
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

function parseJsonAssocReport($json): array {
    $json = trim((string)$json);
    if ($json === '') return [];
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function pickReportValue(array $row, array $json, string ...$keys): string {
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && $row[$key] !== null && trim((string)$row[$key]) !== '') {
            return trim((string)$row[$key]);
        }
        if (array_key_exists($key, $json) && $json[$key] !== null && trim((string)$json[$key]) !== '') {
            return trim((string)$json[$key]);
        }
    }
    return '';
}

function buildPartnerNameReport(array $row, array $json): string {
    $surname = pickReportValue($row, $json, 'partner_surname', 'partner_last_name');
    $first = pickReportValue($row, $json, 'partner_first_name');
    $middle = pickReportValue($row, $json, 'partner_middle_name');
    $given = trim($first . ($middle !== '' ? ' ' . $middle : ''));
    if ($surname !== '' && $given !== '') return $surname . ', ' . $given;
    return trim($surname . ' ' . $given);
}

function reportHasValue($value): bool {
    return normalizeTextReport($value) !== '';
}

function reportToInt($value, int $default = 0): int {
    if ($value === null) return $default;

    $raw = normalizeTextReport($value);
    if ($raw === '') return $default;

    if (is_numeric($raw)) return (int)$raw;

    $num = preg_replace('/[^\d\-]/', '', $raw);
    if ($num === '' || !is_numeric($num)) return $default;

    return (int)$num;
}

function reportExtractNumber($value): ?int {
    $raw = normalizeTextReport($value);
    if ($raw === '') return null;

    if (preg_match('/(\d+)/', $raw, $m)) {
        return (int)$m[1];
    }

    return null;
}

function reportNormalizeYesNo($value): string {
    $v = normalizeLowerReport($value);

    if (in_array($v, ['yes', 'y', '1', 'true', 'opo'], true)) return 'Yes';
    if (in_array($v, ['no', 'n', '0', 'false', 'hindi'], true)) return 'No';

    return 'Unknown';
}

function reportNormalizeCivilStatusValue($value): string {
    $status = normalizeLowerReport($value);
    $status = str_replace(['_', '/'], ' ', $status);
    $status = preg_replace('/\s+/', ' ', $status) ?? $status;
    $status = trim($status);

    if ($status === '' || in_array($status, [
        'submitted', 'draft', 'pending', 'approved', 'verified', 'validated', 'rejected', 'encoded'
    ], true)) {
        return '';
    }

    if ($status === 'single') return 'Single';
    if ($status === 'married') return 'Married';
    if (in_array($status, ['living in', 'living-in', 'live in', 'live-in', 'cohabiting', 'cohabitation'], true)) {
        return 'Living-in';
    }

    return '';
}

function computeAgeFromDobReport(string $dob): ?int {
    $dob = normalizeTextReport($dob);
    if ($dob === '') return null;

    $dt = null;
    $formats = [
        'Y-m-d',
        'm/d/Y',
        'd/m/Y',
        'Y/m/d',
        'm-d-Y',
        'd-m-Y',
        'm.d.Y',
        'd.m.Y',
        'F j, Y',
        'M j, Y',
    ];

    foreach ($formats as $fmt) {
        $tmp = DateTime::createFromFormat($fmt, $dob);
        if ($tmp instanceof DateTime && $tmp->format($fmt) === $dob) {
            $dt = $tmp;
            break;
        }
    }

    if (!$dt) {
        $ts = strtotime($dob);
        if ($ts !== false) {
            $dt = (new DateTime())->setTimestamp($ts);
        }
    }

    if (!$dt) return null;

    $today = new DateTime('today');
    $dt->setTime(0, 0, 0);
    if ($dt > $today) return null;
    return (int)$dt->diff($today)->y;
}

/* =========================================================
   CANONICAL VALUE HELPERS
========================================================= */

function reportCanonicalPregnant(array $row, array $json): string {
    $pregnant = pickReportValue($row, $json, 'pregnant');

    if (reportNormalizeYesNo($pregnant) !== 'Unknown') {
        return reportNormalizeYesNo($pregnant);
    }

    $monthsPregnant = pickReportValue($row, $json, 'months_pregnant');
    if (reportHasValue($monthsPregnant)) {
        $num = reportExtractNumber($monthsPregnant);
        if ($num !== null && $num > 0) return 'Yes';
        return 'Yes';
    }

    return 'Unknown';
}

function isMeaningfulFamilyPlanningReport(string $value): bool {
    $normalized = ahdisNormalizeFamilyPlanning($value);
    if ($normalized === '') return false;

    if (!isset(ahdisFamilyPlanningOptions()[$normalized])) {
        return false;
    }

    return !in_array($normalized, ['0', '99'], true);
}

function reportCanonicalFamilyPlanning(array $row, array $json): string {
    return pickReportValue($row, $json, 'family_planning', 'family_planning_method', 'fp_method');
}

function reportCanonicalPrenatalCare(array $row, array $json): string {
    $value = pickReportValue($row, $json, 'prenatal_care');
    if (reportNormalizeYesNo($value) !== 'Unknown') {
        return reportNormalizeYesNo($value);
    }
    return normalizeTextReport($value);
}

function reportCanonicalEducation(array $row, array $json): string {
    return pickReportValue(
        $row,
        $json,
        'education',
        'educational_attainment',
        'education_attainment',
        'highest_education',
        'highest_educational_attainment'
    );
}

function reportCanonicalCivilStatus(array $row, array $json): string {
    return reportNormalizeCivilStatusValue(pickReportValue(
        $row,
        $json,
        'civil_status',
        'marital_status',
        'status_civil'
    ));
}

function reportCanonicalMonthlyIncome(array $row, array $json): string {
    return pickReportValue(
        $row,
        $json,
        'monthly_income',
        'household_income',
        'income',
        'family_income'
    );
}

function reportCanonicalIs4ps(array $row, array $json): string {
    return pickReportValue($row, $json, 'is_4ps');
}

function reportCanonicalIsIps(array $row, array $json): string {
    return pickReportValue($row, $json, 'is_ips');
}

function reportCanonicalPartnerName(array $row, array $json): string {
    $name = pickReportValue($row, $json, 'partner_name');
    return $name !== '' ? $name : buildPartnerNameReport($row, $json);
}

function reportCanonicalPartnerAge(array $row, array $json): string {
    return pickReportValue($row, $json, 'partner_age', 'age_of_partner');
}

function reportCanonicalPartnerEducation(array $row, array $json): string {
    return pickReportValue(
        $row,
        $json,
        'partner_education',
        'partner_educational_attainment',
        'partner_highest_education'
    );
}

function reportCanonicalPartnerMonthlyIncome(array $row, array $json): string {
    return pickReportValue(
        $row,
        $json,
        'partner_monthly_income',
        'partner_income',
        'monthly_income_partner'
    );
}

function reportCanonicalAgeFirstPregnancy(array $row, array $json): string {
    return pickReportValue($row, $json, 'age_first_pregnancy');
}

function reportCanonicalTimesPregnant(array $row, array $json): string {
    return pickReportValue($row, $json, 'times_pregnant');
}

function reportCanonicalLivingChildren(array $row, array $json): string {
    return pickReportValue($row, $json, 'living_children');
}

function reportCanonicalGovAssistance(array $row, array $json): string {
    return pickReportValue($row, $json, 'gov_assistance', 'gov_assistance_selected', 'programs_applied');
}

function reportCanonicalGovAssistanceOther(array $row, array $json): string {
    return pickReportValue($row, $json, 'gov_assistance_other');
}


function reportCanonicalBarangay(array $row, array $json): string {
    return pickReportValue(
        $row,
        $json,
        'barangay',
        'barangay_name',
        'residence_barangay',
        'address_barangay'
    );
}

function reportCanonicalCurrentlyEnrolled(array $row, array $json): string {
    return pickReportValue(
        $row,
        $json,
        'currently_enrolled',
        'is_enrolled',
        'enrollment_status',
        'school_status',
        'enrolled',
        'in_school',
        'schooling_status',
        'currently_in_school'
    );
}

/* =========================================================
   BUCKET HELPERS
========================================================= */

function educationBucket($value): string {
    $canonical = ahdisNormalizeEducationAttainment((string)$value);
    if ($canonical !== '') {
        return $canonical;
    }

    $edu = normalizeLowerReport($value);

    if ($edu === '') return "Unknown";
    if ($edu === "no education") return "No Education";
    if ($edu === "elementary") return "Elementary";
    if ($edu === "junior hs" || $edu === "junior high school") return "Junior High School";
    if ($edu === "senior hs" || $edu === "senior high school") return "Senior High School";
    if ($edu === "vocational") return "Vocational";

    if (
        $edu === "college" ||
        $edu === "college level" ||
        $edu === "college level/graduate" ||
        $edu === "graduate" ||
        $edu === "college graduate"
    ) {
        return "College Level";
    }

    return "Unknown";
}

function incomeBucket($value): string {
    $raw = normalizeTextReport($value);
    if ($raw === '') return "No Income";

    $low = normalizeLowerReport($raw);
    if (in_array($low, ['no income', 'none', 'n/a', 'na', 'unknown', '-'], true)) {
        return "No Income";
    }

    if (preg_match('/20\s*,?\s*001|above|over|more than/i', $raw)) {
        return "20,001 and Above";
    }

    preg_match_all('/\d[\d,]*/', $raw, $matches);
    $numbers = [];
    foreach (($matches[0] ?? []) as $match) {
        $n = (int)str_replace(',', '', $match);
        if ($n > 0) $numbers[] = $n;
    }

    if (!$numbers) {
        if (str_contains($low, 'below') || str_contains($low, 'less')) return "1-10,000";
        return "No Income";
    }

    $num = max($numbers);

    if ($num <= 0) return "No Income";
    if ($num <= 10000) return "1-10,000";
    if ($num <= 20000) return "10,001 - 20,000";
    return "20,001 and Above";
}

function civilStatusBucket($value): ?string {
    $canonical = reportNormalizeCivilStatusValue($value);
    $status = normalizeLowerReport($canonical);

    if ($status === '') return null;
    if ($status === 'single') return 'single';
    if ($status === 'married') return 'married';
    if (in_array($status, ['living-in', 'living in', 'live in', 'live-in'], true)) return 'living-in';

    return null;
}

/* =========================================================
   SUPPORT SERVICES HELPERS
========================================================= */

function extractGovAssistanceList($rawValue, $otherValue = ''): array {
    $items = [];

    $rawValue = trim((string)$rawValue);
    if ($rawValue !== '') {
        $decoded = json_decode($rawValue, true);

        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                $item = normalizeTextReport($item);
                if ($item !== '') $items[] = $item;
            }
        } else {
            $parts = preg_split('/\s*,\s*/', $rawValue) ?: [];
            foreach ($parts as $item) {
                $item = normalizeTextReport($item);
                if ($item !== '') $items[] = $item;
            }
        }
    }

    $otherValue = normalizeTextReport($otherValue);
    if ($otherValue !== '') {
        $items[] = 'Others';
    }

    $items = array_values(array_unique(array_filter(array_map(
        static fn($v) => normalizeTextReport($v),
        $items
    ), static fn($v) => $v !== '')));

    return $items;
}

function hasGovAssist(array $items, string $needle): bool {
    $needle = normalizeLowerReport($needle);

    foreach ($items as $item) {
        $item = normalizeLowerReport($item);
        if ($item === $needle) return true;

        if ($needle === 'scholarship' && (str_contains($item, 'scholarship') || str_contains($item, 'school'))) return true;
        if ($needle === 'livelihood training' && str_contains($item, 'livelihood')) return true;
        if ($needle === 'skills training' && str_contains($item, 'skills')) return true;
        if ($needle === 'health services' && str_contains($item, 'health')) return true;
        if ($needle === 'others' && str_contains($item, 'other')) return true;
    }

    return false;
}

/* =========================================================
   PATH / LOGO HELPERS
========================================================= */

function appRootPath(): string {
    $root = realpath(dirname(__DIR__));
    return $root !== false ? $root : dirname(__DIR__);
}

function pathJoinReport(string ...$parts): string {
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

function getBaseUrlReport(): string {
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return preg_replace('#/(dashboard|reports)$#', '', $scriptDir) ?: '';
}

function getProvinceLogoPath(PDO $conn, string $province): string {
    if ($province === '') return '';

    try {
        if (!tableExistsReport($conn, 'province_logos')) {
            return '';
        }

        $stmt = $conn->prepare("
            SELECT logo_path
            FROM province_logos
            WHERE province = :province
            LIMIT 1
        ");
        $stmt->execute([':province' => $province]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (string)($row['logo_path'] ?? '') : '';
    } catch (Throwable $e) {
        return '';
    }
}

function getCurrentUserLocation(PDO $conn): array {
    $userId = (int)($_SESSION['user_id'] ?? 0);

    $result = [
        'province' => '',
        'municipality' => '',
        'location_locked' => 0,
    ];

    if ($userId <= 0) {
        return $result;
    }

    try {
        $hasProvince = dbHasColumnReport($conn, 'users', 'province');
        $hasMunicipality = dbHasColumnReport($conn, 'users', 'municipality');
        $hasLocked = dbHasColumnReport($conn, 'users', 'location_locked');

        $provinceSelect = $hasProvince ? "COALESCE(province, '') AS province" : "'' AS province";
        $municipalitySelect = $hasMunicipality ? "COALESCE(municipality, '') AS municipality" : "'' AS municipality";
        $lockedSelect = $hasLocked ? "COALESCE(location_locked, 0) AS location_locked" : "0 AS location_locked";

        $stmt = $conn->prepare("
            SELECT {$provinceSelect}, {$municipalitySelect}, {$lockedSelect}
            FROM users
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $userId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $result['province'] = (string)($row['province'] ?? '');
            $result['municipality'] = (string)($row['municipality'] ?? '');
            $result['location_locked'] = (int)($row['location_locked'] ?? 0);
        }
    } catch (Throwable $e) {
        // silent
    }

    if ($result['province'] === '') {
        $result['province'] = (string)($_SESSION['province'] ?? '');
    }
    if ($result['municipality'] === '') {
        $result['municipality'] = (string)($_SESSION['municipality'] ?? '');
    }

    return $result;
}

/* =========================================================
   MUNICIPALITY MATCH
========================================================= */

function municipalityMatchesReport(string $recordMunicipality, string $currentMunicipality): bool {
    $recordMunicipality = normalizeLowerReport($recordMunicipality);
    $currentMunicipality = normalizeLowerReport($currentMunicipality);

    if ($currentMunicipality === '') return true;
    if ($recordMunicipality === '') return true;
    if ($recordMunicipality === $currentMunicipality) return true;

    $base = preg_replace('/\s*city\s*$/i', '', $currentMunicipality);
    $base = preg_replace('/^city of\s+/i', '', $base);
    $base = preg_replace('/^municipality of\s+/i', '', $base);
    $base = trim((string)$base);

    $variants = array_filter(array_unique([
        $currentMunicipality,
        $base,
        'city of ' . $base,
        $base . ' city',
        'municipality of ' . $base,
    ]));

    return in_array($recordMunicipality, $variants, true);
}

/* =========================================================
   SNAPSHOT / DEDUPE HELPERS
   STRICT SOURCE OF TRUTH = encounter_date
========================================================= */

function normalizeReportDateValue(?string $value): string {
    $value = normalizeTextReport((string)$value);
    if ($value === '') return '';

    $formats = [
        'Y-m-d',
        'Y-m-d H:i:s',
        'Y-m-d H:i',
        'm/d/Y',
        'm/d/Y H:i:s',
        'm/d/Y H:i',
        'd/m/Y',
        'd/m/Y H:i:s',
        'Y/m/d',
        'm-d-Y',
        'd-m-Y',
    ];

    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $value);
        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d');
        }
    }

    $ts = strtotime($value);
    if ($ts !== false) {
        return date('Y-m-d', $ts);
    }

    return '';
}

function reportRowSortTimestamp(array $row): int {
    $date = normalizeReportDateValue((string)($row['encounter_date'] ?? ''));
    if ($date === '') {
        return 0;
    }

    $ts = strtotime($date . ' 23:59:59');
    return $ts !== false ? $ts : 0;
}

function reportRowIdentityKey(array $row): string {
    $adolescentId = (int)($row['adolescent_id'] ?? 0);
    if ($adolescentId > 0) {
        return 'id:' . $adolescentId;
    }

    $fullName = normalizeLowerReport((string)($row['full_name'] ?? ''));
    $dob = normalizeReportDateValue((string)($row['date_of_birth'] ?? ''));
    return 'namedob:' . $fullName . '|' . $dob;
}

function dedupeLatestRowsByPerson(array $rows): array {
    $best = [];

    foreach ($rows as $row) {
        $key = reportRowIdentityKey($row);

        if (!isset($best[$key])) {
            $best[$key] = $row;
            continue;
        }

        $currentTs = reportRowSortTimestamp($row);
        $bestTs = reportRowSortTimestamp($best[$key]);

        $currentEncounterId = (int)($row['encounter_id'] ?? 0);
        $bestEncounterId = (int)($best[$key]['encounter_id'] ?? 0);

        if (
            $currentTs > $bestTs ||
            ($currentTs === $bestTs && $currentEncounterId > $bestEncounterId)
        ) {
            $best[$key] = $row;
        }
    }

    $rows = array_values($best);

    usort($rows, static function (array $a, array $b): int {
        $ta = reportRowSortTimestamp($a);
        $tb = reportRowSortTimestamp($b);
        if ($ta === $tb) {
            return ((int)($b['encounter_id'] ?? 0)) <=> ((int)($a['encounter_id'] ?? 0));
        }
        return $tb <=> $ta;
    });

    return $rows;
}

function reportSubmissionMonthFilter(): string {
    $value = normalizeTextReport((string)($_GET['submission_month'] ?? ''));
    if ($value === '') return date('Y-m');
    if (preg_match('/^\d{4}-\d{2}$/', $value)) return $value;

    $date = normalizeReportDateValue($value);
    return $date !== '' ? substr($date, 0, 7) : date('Y-m');
}

function reportCurrentFilters(): array {
    return [
        'submission_month' => normalizeTextReport((string)($_GET['submission_month'] ?? '')),
        'name_prefix' => normalizeTextReport((string)($_GET['name_prefix'] ?? '')),
        'birth_month' => (int)($_GET['birth_month'] ?? 0),
        'birth_year' => (int)($_GET['birth_year'] ?? 0),
        'barangay' => normalizeTextReport((string)($_GET['brgy'] ?? '')),
        'latest_from' => normalizeReportDateValue((string)($_GET['latest_from'] ?? '')),
        'latest_to' => normalizeReportDateValue((string)($_GET['latest_to'] ?? '')),
    ];
}

function reportRowMatchesAdvancedFilters(array $row, array $filters): bool {
    $name = normalizeLowerReport((string)($row['full_name'] ?? ''));
    $dob = normalizeReportDateValue((string)($row['date_of_birth'] ?? ''));
    $latest = normalizeReportDateValue((string)($row['encounter_date'] ?? ''));
    $barangay = normalizeTextReport((string)($row['barangay'] ?? ''));

    $prefix = normalizeLowerReport((string)($filters['name_prefix'] ?? ''));
    if ($prefix !== '' && strncmp($name, $prefix, strlen($prefix)) !== 0) {
        return false;
    }

    $birthMonth = (int)($filters['birth_month'] ?? 0);
    if ($birthMonth > 0 && ($dob === '' || (int)substr($dob, 5, 2) !== $birthMonth)) {
        return false;
    }

    $birthYear = (int)($filters['birth_year'] ?? 0);
    if ($birthYear > 0 && ($dob === '' || (int)substr($dob, 0, 4) !== $birthYear)) {
        return false;
    }

    $filterBrgy = normalizeTextReport((string)($filters['barangay'] ?? ''));
    if ($filterBrgy !== '' && strcasecmp($barangay, $filterBrgy) !== 0) {
        return false;
    }

    $submissionMonth = normalizeTextReport((string)($filters['submission_month'] ?? ''));
    if ($submissionMonth !== '') {
        if (!preg_match('/^\d{4}-\d{2}$/', $submissionMonth)) {
            $monthDate = normalizeReportDateValue($submissionMonth);
            $submissionMonth = $monthDate !== '' ? substr($monthDate, 0, 7) : '';
        }
        if ($submissionMonth !== '' && ($latest === '' || substr($latest, 0, 7) !== $submissionMonth)) {
            return false;
        }
    }

    $latestFrom = normalizeTextReport((string)($filters['latest_from'] ?? ''));
    if ($latestFrom !== '' && ($latest === '' || $latest < $latestFrom)) {
        return false;
    }

    $latestTo = normalizeTextReport((string)($filters['latest_to'] ?? ''));
    if ($latestTo !== '' && ($latest === '' || $latest > $latestTo)) {
        return false;
    }

    return true;
}

function isCurrentReportingMonthReport(?string $dateValue): bool {
    $date = normalizeReportDateValue((string)$dateValue);
    if ($date === '') return false;
    return substr($date, 0, 7) === reportSubmissionMonthFilter();
}

/* =========================================================
   DATASET BUILDER - ENCOUNTERS ONLY
   STRICT LATEST BY encounter_date
========================================================= */

function buildEncounterSnapshotRows(PDO $conn, string $currentMunicipality = ''): array {
    if (!tableExistsReport($conn, 'adolescents') || !tableExistsReport($conn, 'encounters')) {
        return [];
    }

    $rows = [];

    $sql = "
        SELECT
            a.id AS adolescent_id,
            a.full_name,
            a.last_name,
            a.first_name,
            a.middle_name,
            a.date_of_birth,
            a.sex,
            a.barangay,
            a.municipality,
            a.present_address,
            a.created_at AS adolescent_created_at,
            a.updated_at AS adolescent_updated_at,

            e.id AS encounter_id,
            e.encounter_date,
            e.status,
            e.pregnant,
            e.times_pregnant,
            e.age_first_pregnancy,
            e.living_children,
            e.family_planning,
            e.programs_applied,
            e.data_json,
            e.created_at AS encounter_created_at
        FROM adolescents a
        INNER JOIN encounters e
            ON e.id = (
                SELECT e2.id
                FROM encounters e2
                WHERE e2.adolescent_id = a.id
                  AND TRIM(COALESCE(e2.encounter_date,'')) <> ''
                ORDER BY
                    e2.encounter_date DESC,
                    e2.id DESC
                LIMIT 1
            )
        WHERE TRIM(COALESCE(a.date_of_birth,'')) <> ''
          AND CAST(
                strftime('%Y','now','localtime') - strftime('%Y', a.date_of_birth)
                - (strftime('%m-%d','now','localtime') < strftime('%m-%d', a.date_of_birth))
                AS INTEGER
            ) BETWEEN 9 AND 19
        ORDER BY
            e.encounter_date DESC,
            e.id DESC
    ";

    $stmt = $conn->query($sql);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $json = parseJsonAssocReport((string)($row['data_json'] ?? ''));

        $municipality = pickReportValue(
            $row,
            $json,
            'municipality',
            'locked_municipality'
        );

        if ($municipality === '') {
            $municipality = (string)($row['municipality'] ?? '');
        }

        if (!municipalityMatchesReport($municipality, $currentMunicipality)) {
            continue;
        }

        $dob = pickReportValue($row, $json, 'date_of_birth');
        if ($dob === '') {
            $dob = (string)($row['date_of_birth'] ?? '');
        }

        $computedAge = computeAgeFromDobReport($dob);
        if ($computedAge === null) {
            continue;
        }
        $age = $computedAge;

        $g = ageGroup($age);
        if (!$g || !isReportableAdolescentAge($age)) {
            continue;
        }

        $fullName = pickReportValue($row, $json, 'full_name', 'name');
        if ($fullName === '') {
            $fullName = (string)($row['full_name'] ?? '');
        }

        $rows[] = [
            'source' => 'encounters_latest',
            'encounter_id' => (int)($row['encounter_id'] ?? 0),
            'adolescent_id' => (int)($row['adolescent_id'] ?? 0),
            'encounter_date' => (string)($row['encounter_date'] ?? ''),
            'encounter_created_at' => (string)($row['encounter_created_at'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'full_name' => $fullName,
            'date_of_birth' => $dob,
            'age' => $age,
            'pregnant' => reportCanonicalPregnant($row, $json),
            'family_planning' => reportCanonicalFamilyPlanning($row, $json),
            'education' => reportCanonicalEducation($row, $json),
            'civil_status' => reportCanonicalCivilStatus($row, $json),
            'prenatal_care' => reportCanonicalPrenatalCare($row, $json),
            'age_first_pregnancy' => reportCanonicalAgeFirstPregnancy($row, $json),
            'times_pregnant' => reportCanonicalTimesPregnant($row, $json),
            'living_children' => reportCanonicalLivingChildren($row, $json),
            'is_4ps' => reportCanonicalIs4ps($row, $json),
            'is_ips' => reportCanonicalIsIps($row, $json),
            'monthly_income' => reportCanonicalMonthlyIncome($row, $json),
            'gov_assistance' => reportCanonicalGovAssistance($row, $json),
            'gov_assistance_other' => reportCanonicalGovAssistanceOther($row, $json),
            'partner_name' => reportCanonicalPartnerName($row, $json),
            'partner_age' => reportCanonicalPartnerAge($row, $json),
            'partner_education' => reportCanonicalPartnerEducation($row, $json),
            'partner_monthly_income' => reportCanonicalPartnerMonthlyIncome($row, $json),
            'barangay' => (($tmpBarangay = reportCanonicalBarangay($row, $json)) !== '' ? $tmpBarangay : (string)($row['barangay'] ?? '')),
            'currently_enrolled' => ($tmpEnrollment = reportCanonicalCurrentlyEnrolled($row, $json)),
            'is_enrolled' => $tmpEnrollment,
            'enrollment_status' => $tmpEnrollment,
            'school_status' => $tmpEnrollment,
            'municipality' => $municipality,
            'adolescent_created_at' => (string)($row['adolescent_created_at'] ?? ''),
            'adolescent_updated_at' => (string)($row['adolescent_updated_at'] ?? ''),
        ];
    }

    return dedupeLatestRowsByPerson($rows);
}

function getUnifiedReportRows(PDO $conn, string $currentMunicipality = ''): array {
    $rows = buildEncounterSnapshotRows($conn, $currentMunicipality);
    $filters = reportCurrentFilters();
    return array_values(array_filter($rows, static function(array $row) use ($filters): bool {
        return reportRowMatchesAdvancedFilters($row, $filters);
    }));
}

/* =========================================================
   LOCATION + LOGO
========================================================= */

$locationInfo = getCurrentUserLocation($conn);

$province = trim((string)$locationInfo['province']);
$municipality = trim((string)$locationInfo['municipality']);

$locationText = trim($province . ($province !== '' && $municipality !== '' ? ' - ' : '') . $municipality);
if ($locationText === '') {
    $locationText = 'Province - Municipality';
}

$municipalityName = $locationText;

$municipalityLogo = '';
$logoPath = getProvinceLogoPath($conn, $province);

if ($logoPath !== '') {
    $baseUrl = getBaseUrlReport();
    $absLogo = pathJoinReport(appRootPath(), ltrim($logoPath, '/\\'));
    $v = is_file($absLogo) ? (string)@filemtime($absLogo) : (string)time();
    $municipalityLogo = $baseUrl . $logoPath . '?v=' . rawurlencode($v);
} elseif (!empty($_SESSION['province_logo'])) {
    $baseUrl = getBaseUrlReport();
    $sessionLogo = (string)$_SESSION['province_logo'];
    $absLogo = pathJoinReport(appRootPath(), ltrim($sessionLogo, '/\\'));
    $v = is_file($absLogo) ? (string)@filemtime($absLogo) : (string)time();
    $municipalityLogo = $baseUrl . $sessionLogo . '?v=' . rawurlencode($v);
}

/* =========================================================
   REPORT TITLE
========================================================= */

$title = "Adolescent Report";

switch ($type) {
    case "monthly_pregnant_status":
        $title = "Monthly Status of Pregnant Adolescents and Adolescent Mothers by Age Group";
        break;
    case "family_planning":
        $title = "Distribution of Adolescent Mothers Practicing Family Planning by Age Group";
        break;
    case "education":
        $title = "Educational Attainment Among Pregnant and Adolescent Mothers by Age Group";
        break;
    case "civil_status":
        $title = "Civil Status Among Pregnant and Adolescent Mothers by Age Group";
        break;
    case "prenatal":
        $title = "Prenatal Care Attendance of Pregnant Adolescents by Age Group";
        break;
    case "first_pregnancy_9_14":
        $title = "Adolescent Mothers Aged 9-14 by Age at First Pregnancy";
        break;
    case "first_pregnancy_15_17":
        $title = "Adolescent Mothers Aged 15-17 by Age at First Pregnancy";
        break;
    case "first_pregnancy_18_19":
        $title = "Adolescent Mothers Aged 18-19 by Age at First Pregnancy";
        break;
    case "pregnancies_living_children":
        $title = "Adolescent Mothers by Total Number of Pregnancies, Total Number of Living Children, and Age Group";
        break;
    case "membership":
        $title = "Number of Pregnant Adolescents and Adolescent Mothers by 4Ps and IP Membership and Age Group";
        break;
    case "income":
        $title = "Distribution of Pregnant Adolescents and Adolescent Mothers by Household Income Level and Age Group";
        break;
    case "support_services":
        $title = "Distribution of Pregnant Adolescents and Adolescent Mothers by Expressed Needs for Government Support Services";
        break;
    case "partner_education":
        $title = "Educational Attainment of Partners, by Age Group";
        break;
    case "partner_income":
        $title = "Monthly Income Range of Partners, by Age Group";
        break;
}

/* =========================================================
   INITIAL DATA
========================================================= */

$data = [
    "9-14" => [],
    "15-17" => [],
    "18-19" => []
];

$reportRows = [];
try {
    $reportRows = getUnifiedReportRows($conn, $municipality);
} catch (Throwable $e) {
    $reportError = "Failed to load report dataset: " . $e->getMessage();
}

/* =========================================================
   MONTHLY PREGNANT STATUS
========================================================= */

if ($type === "monthly_pregnant_status" && $reportError === '') {
    try {
        foreach ($reportRows as $row) {
            $g = ageGroup((int)($row["age"] ?? 0));
            if (!$g) continue;

            if (!isset($data[$g]["new_identified"])) $data[$g]["new_identified"] = 0;
            if (!isset($data[$g]["total_mothers"])) $data[$g]["total_mothers"] = 0;

            $pregnant = reportNormalizeYesNo((string)($row["pregnant"] ?? ""));
            $encounterDate = (string)($row["encounter_date"] ?? "");

            if ($pregnant === "Yes" && isCurrentReportingMonthReport($encounterDate)) {
                $data[$g]["new_identified"]++;
            }

            $data[$g]["total_mothers"]++;
        }
    } catch (Throwable $e) {
        $reportError = "Failed to load Monthly Status report: " . $e->getMessage();
    }
}

/* =========================================================
   FAMILY PLANNING
========================================================= */

if ($type === "family_planning" && $reportError === '') {
    try {
        foreach ($reportRows as $row) {
            $g = ageGroup((int)($row["age"] ?? 0));
            if (!$g) continue;

            if (!isset($data[$g]["yes"])) $data[$g]["yes"] = 0;

            $familyPlanning = trim((string)($row["family_planning"] ?? ""));
            if (isMeaningfulFamilyPlanningReport($familyPlanning)) {
                $data[$g]["yes"]++;
            }
        }
    } catch (Throwable $e) {
        $reportError = "Failed to load Family Planning report: " . $e->getMessage();
    }
}

/* =========================================================
   EDUCATION
========================================================= */

if ($type === "education" && $reportError === '') {
    try {
        foreach ($reportRows as $row) {
            $g = ageGroup((int)($row["age"] ?? 0));
            if (!$g) continue;

            $key = educationBucket((string)($row["education"] ?? ""));
            if ($key === "Unknown") continue;

            if (!isset($data[$g][$key])) $data[$g][$key] = 0;
            $data[$g][$key]++;
        }
    } catch (Throwable $e) {
        $reportError = "Failed to load Education report: " . $e->getMessage();
    }
}

/* =========================================================
   CIVIL STATUS
========================================================= */

if ($type === "civil_status" && $reportError === '') {
    try {
        foreach ($reportRows as $row) {
            $g = ageGroup((int)($row["age"] ?? 0));
            if (!$g) continue;

            $key = civilStatusBucket((string)($row["civil_status"] ?? ""));
            if ($key === null) {
                continue;
            }

            if (!isset($data[$g][$key])) $data[$g][$key] = 0;
            $data[$g][$key]++;
        }
    } catch (Throwable $e) {
        $reportError = "Failed to load Civil Status report: " . $e->getMessage();
    }
}

/* =========================================================
   PRENATAL CARE
========================================================= */

if ($type === "prenatal" && $reportError === '') {
    try {
        foreach ($reportRows as $row) {
            $g = ageGroup((int)($row["age"] ?? 0));
            if (!$g) continue;

            $pregnant = reportNormalizeYesNo((string)($row["pregnant"] ?? ""));
            if ($pregnant !== 'Yes') continue;

            if (!isset($data[$g]["total"])) $data[$g]["total"] = 0;
            if (!isset($data[$g]["attended"])) $data[$g]["attended"] = 0;
            if (!isset($data[$g]["not"])) $data[$g]["not"] = 0;

            $data[$g]["total"]++;

            if (reportNormalizeYesNo((string)($row["prenatal_care"] ?? "")) === "Yes") {
                $data[$g]["attended"]++;
            } else {
                $data[$g]["not"]++;
            }
        }
    } catch (Throwable $e) {
        $reportError = "Failed to load Prenatal report: " . $e->getMessage();
    }
}

/* =========================================================
   FIRST PREGNANCY + PREGNANCIES/LIVING CHILDREN
========================================================= */

if (
    in_array($type, [
        "first_pregnancy_9_14",
        "first_pregnancy_15_17",
        "first_pregnancy_18_19",
        "pregnancies_living_children"
    ], true) && $reportError === ''
) {
    try {
        foreach ($reportRows as $row) {
            $group = ageGroup((int)($row["age"] ?? 0));
            if (!$group) continue;

            $firstAge = reportToInt($row["age_first_pregnancy"] ?? 0, 0);
            $timesPregnant = reportToInt($row["times_pregnant"] ?? 0, 0);
            $livingChildren = reportToInt($row["living_children"] ?? 0, 0);

            if (!isset($data[$group]["first_age"])) $data[$group]["first_age"] = [];
            if (!isset($data[$group]["total_pregnancies"])) $data[$group]["total_pregnancies"] = 0;
            if (!isset($data[$group]["living_children"])) $data[$group]["living_children"] = 0;

            if ($firstAge >= 9 && $firstAge <= 19) {
                if (!isset($data[$group]["first_age"][$firstAge])) {
                    $data[$group]["first_age"][$firstAge] = 0;
                }
                $data[$group]["first_age"][$firstAge]++;
            }

            $data[$group]["total_pregnancies"] += $timesPregnant;
            $data[$group]["living_children"] += $livingChildren;
        }
    } catch (Throwable $e) {
        $reportError = "Failed to load First Pregnancy report: " . $e->getMessage();
    }
}

/* =========================================================
   MEMBERSHIP
========================================================= */

if ($type === "membership" && $reportError === '') {
    try {
        foreach ($reportRows as $row) {
            $g = ageGroup((int)($row["age"] ?? 0));
            if (!$g) continue;

            if (!isset($data[$g]["is_4ps"])) $data[$g]["is_4ps"] = 0;
            if (!isset($data[$g]["is_ips"])) $data[$g]["is_ips"] = 0;
            if (!isset($data[$g]["both"])) $data[$g]["both"] = 0;

            $is4ps = reportNormalizeYesNo((string)($row["is_4ps"] ?? ""));
            $isIps = reportNormalizeYesNo((string)($row["is_ips"] ?? ""));

            if ($is4ps === "Yes") $data[$g]["is_4ps"]++;
            if ($isIps === "Yes") $data[$g]["is_ips"]++;
            if ($is4ps === "Yes" && $isIps === "Yes") $data[$g]["both"]++;
        }
    } catch (Throwable $e) {
        $reportError = "Failed to load Membership report: " . $e->getMessage();
    }
}

/* =========================================================
   INCOME
========================================================= */

if ($type === "income" && $reportError === '') {
    try {
        foreach ($reportRows as $row) {
            $g = ageGroup((int)($row["age"] ?? 0));
            if (!$g) continue;

            $bucket = incomeBucket((string)($row["monthly_income"] ?? ""));
            if (!isset($data[$g][$bucket])) $data[$g][$bucket] = 0;
            $data[$g][$bucket]++;
        }
    } catch (Throwable $e) {
        $reportError = "Failed to load Income report: " . $e->getMessage();
    }
}

/* =========================================================
   SUPPORT SERVICES
========================================================= */

if ($type === "support_services" && $reportError === '') {
    try {
        foreach ($reportRows as $row) {
            $g = ageGroup((int)($row["age"] ?? 0));
            if (!$g) continue;

            $services = extractGovAssistanceList(
                (string)($row["gov_assistance"] ?? ""),
                (string)($row["gov_assistance_other"] ?? "")
            );

            $serviceLabels = [
                "Livelihood training" => hasGovAssist($services, "livelihood training"),
                "Skills training"     => hasGovAssist($services, "skills training"),
                "Health services"     => hasGovAssist($services, "health services"),
                "Scholarship"         => hasGovAssist($services, "scholarship"),
                "Others"              => hasGovAssist($services, "others"),
            ];

            foreach ($serviceLabels as $label => $matched) {
                if (!isset($data[$g][$label])) $data[$g][$label] = 0;
                if ($matched) $data[$g][$label]++;
            }
        }
    } catch (Throwable $e) {
        $reportError = "Failed to load Support Services report: " . $e->getMessage();
    }
}

/* =========================================================
   PARTNER EDUCATION / PARTNER INCOME
   FIXED
========================================================= */

if (in_array($type, ["partner_education", "partner_income"], true) && $reportError === '') {
    try {
        $partnerEducationLabels = [
            "No Education",
            "Elementary",
            "Junior High School",
            "Senior High School",
            "Vocational",
            "College Level"
        ];

        $partnerIncomeLabels = [
            "No Income",
            "1-10,000",
            "10,001 - 20,000",
            "20,001 and Above"
        ];

        $data = [
            "Below 18" => [
                "education" => array_fill_keys($partnerEducationLabels, 0),
                "income" => array_fill_keys($partnerIncomeLabels, 0),
            ],
            "18-24" => [
                "education" => array_fill_keys($partnerEducationLabels, 0),
                "income" => array_fill_keys($partnerIncomeLabels, 0),
            ],
            "25 and above" => [
                "education" => array_fill_keys($partnerEducationLabels, 0),
                "income" => array_fill_keys($partnerIncomeLabels, 0),
            ],
        ];

        foreach ($reportRows as $row) {
            $partnerName = trim((string)($row["partner_name"] ?? ''));
            $partnerAgeRaw = trim((string)($row["partner_age"] ?? ''));
            $partnerEducationRaw = trim((string)($row["partner_education"] ?? ''));
            $partnerIncomeRaw = trim((string)($row["partner_monthly_income"] ?? ''));

            $hasAnyPartnerData =
                $partnerName !== '' ||
                $partnerAgeRaw !== '' ||
                $partnerEducationRaw !== '' ||
                $partnerIncomeRaw !== '';

            if (!$hasAnyPartnerData) continue;

            $partnerAge = reportToInt($partnerAgeRaw, 0);
            $pg = partnerAgeGroup($partnerAge);
            if (!$pg) continue;

            $eduBucket = educationBucket($partnerEducationRaw);
            $incomeBkt = incomeBucket($partnerIncomeRaw);

            if ($eduBucket !== "Unknown" && isset($data[$pg]["education"][$eduBucket])) {
                $data[$pg]["education"][$eduBucket]++;
            }

            if (isset($data[$pg]["income"][$incomeBkt])) {
                $data[$pg]["income"][$incomeBkt]++;
            }
        }
    } catch (Throwable $e) {
        $reportError = "Failed to load Partner report: " . $e->getMessage();
    }
}

/* =========================================================
   GRAPH HELPERS
========================================================= */

function reportGraphMax(array $datasets): int {
    $max = 0;
    foreach ($datasets as $set) {
        foreach ((array)($set['data'] ?? []) as $v) {
            $max = max($max, (int)$v);
        }
    }

    if ($max <= 0) return 1;
    return (int)ceil($max * 1.2);
}

function reportGraphStep(int $max): int {
    if ($max <= 5) return 1;
    if ($max <= 10) return 2;
    if ($max <= 20) return 5;
    if ($max <= 50) return 10;
    return (int)max(10, ceil($max / 5));
}

/* =========================================================
   PREP GRAPH DATA
========================================================= */

$graphData = [];

if ($type === "monthly_pregnant_status") {
    $datasets = [
        [
            'label' => 'New Identified Adolescents (Current Reporting Month)',
            'data' => [
                $data["9-14"]["new_identified"] ?? 0,
                $data["15-17"]["new_identified"] ?? 0,
                $data["18-19"]["new_identified"] ?? 0
            ],
            'backgroundColor' => '#4472C4'
        ],
        [
            'label' => 'Total Adolescent Mothers in the City/Municipality',
            'data' => [
                $data["9-14"]["total_mothers"] ?? 0,
                $data["15-17"]["total_mothers"] ?? 0,
                $data["18-19"]["total_mothers"] ?? 0
            ],
            'backgroundColor' => '#ED7D31'
        ]
    ];

    $max = reportGraphMax($datasets);

    $graphData = [
        'labels' => $ageGroups,
        'datasets' => $datasets,
        'title' => $title,
        'type' => 'bar',
        'legendPosition' => 'bottom',
        'xMax' => $max,
        'stepSize' => reportGraphStep($max)
    ];
}

if ($type === "family_planning") {
    $datasets = [
        [
            'label' => 'Adolescent Mothers Practicing Family Planning',
            'data' => [
                $data["9-14"]["yes"] ?? 0,
                $data["15-17"]["yes"] ?? 0,
                $data["18-19"]["yes"] ?? 0
            ],
            'backgroundColor' => '#4472C4'
        ]
    ];

    $max = reportGraphMax($datasets);

    $graphData = [
        'labels' => $ageGroups,
        'datasets' => $datasets,
        'title' => 'Adolescent Mothers Practicing Family Planning',
        'type' => 'bar',
        'legendPosition' => 'bottom',
        'xMax' => $max,
        'stepSize' => reportGraphStep($max)
    ];
}

if ($type === "education") {
    $labels = ["No Education", "Elementary", "Junior High School", "Senior High School", "Vocational", "College Level"];
    $datasets = [
        [
            'label' => '9-14',
            'data' => array_map(fn($label) => $data["9-14"][$label] ?? 0, $labels),
            'backgroundColor' => '#4472C4'
        ],
        [
            'label' => '15-17',
            'data' => array_map(fn($label) => $data["15-17"][$label] ?? 0, $labels),
            'backgroundColor' => '#ED7D31'
        ],
        [
            'label' => '18-19',
            'data' => array_map(fn($label) => $data["18-19"][$label] ?? 0, $labels),
            'backgroundColor' => '#A5A5A5'
        ]
    ];

    $max = reportGraphMax($datasets);

    $graphData = [
        'labels' => $labels,
        'datasets' => $datasets,
        'title' => 'Educational Attainment Among Pregnant and Adolescent Mothers by Age Group',
        'type' => 'bar',
        'legendPosition' => 'bottom',
        'xMax' => $max,
        'stepSize' => reportGraphStep($max)
    ];
}

if ($type === "civil_status") {
    $datasets = [
        [
            'label' => 'Single',
            'data' => [
                $data["9-14"]["single"] ?? 0,
                $data["15-17"]["single"] ?? 0,
                $data["18-19"]["single"] ?? 0
            ],
            'backgroundColor' => '#4472C4'
        ],
        [
            'label' => 'Married',
            'data' => [
                $data["9-14"]["married"] ?? 0,
                $data["15-17"]["married"] ?? 0,
                $data["18-19"]["married"] ?? 0
            ],
            'backgroundColor' => '#ED7D31'
        ],
        [
            'label' => 'Living-in',
            'data' => [
                $data["9-14"]["living-in"] ?? 0,
                $data["15-17"]["living-in"] ?? 0,
                $data["18-19"]["living-in"] ?? 0
            ],
            'backgroundColor' => '#A5A5A5'
        ]
    ];

    $max = reportGraphMax($datasets);

    $graphData = [
        'labels' => $ageGroups,
        'datasets' => $datasets,
        'title' => 'Civil Status Among Pregnant and Adolescent Mothers by Age Group',
        'type' => 'bar',
        'legendPosition' => 'bottom',
        'xMax' => $max,
        'stepSize' => reportGraphStep($max)
    ];
}

if ($type === "prenatal") {
    $datasets = [
        [
            'label' => 'Attended Prenatal Care',
            'data' => [
                $data["9-14"]["attended"] ?? 0,
                $data["15-17"]["attended"] ?? 0,
                $data["18-19"]["attended"] ?? 0
            ],
            'backgroundColor' => '#70AD47'
        ],
        [
            'label' => 'Did Not Attend Prenatal Care',
            'data' => [
                $data["9-14"]["not"] ?? 0,
                $data["15-17"]["not"] ?? 0,
                $data["18-19"]["not"] ?? 0
            ],
            'backgroundColor' => '#C00000'
        ]
    ];

    $max = reportGraphMax($datasets);

    $graphData = [
        'labels' => $ageGroups,
        'datasets' => $datasets,
        'title' => 'Prenatal Care Attendance of Pregnant Mothers by Age Group',
        'type' => 'bar',
        'legendPosition' => 'bottom',
        'xMax' => $max,
        'stepSize' => reportGraphStep($max)
    ];
}

if ($type === "membership") {
    $datasets = [
        [
            'label' => '18-19',
            'data' => [
                $data["18-19"]["both"] ?? 0,
                $data["18-19"]["is_ips"] ?? 0,
                $data["18-19"]["is_4ps"] ?? 0
            ],
            'backgroundColor' => '#A5A5A5'
        ],
        [
            'label' => '15-17',
            'data' => [
                $data["15-17"]["both"] ?? 0,
                $data["15-17"]["is_ips"] ?? 0,
                $data["15-17"]["is_4ps"] ?? 0
            ],
            'backgroundColor' => '#ED7D31'
        ],
        [
            'label' => '9-14',
            'data' => [
                $data["9-14"]["both"] ?? 0,
                $data["9-14"]["is_ips"] ?? 0,
                $data["9-14"]["is_4ps"] ?? 0
            ],
            'backgroundColor' => '#4472C4'
        ]
    ];

    $max = reportGraphMax($datasets);

    $graphData = [
        'labels' => [
            [
                'Total Number of Pregnant Adolescents and',
                'Adolescent Mothers who are both 4Ps and IPs',
                'Members'
            ],
            [
                'Total Number of Pregnant Adolescents and',
                'Adolescent Mothers who are IPs Members'
            ],
            [
                'Total Number of Pregnant Adolescents and',
                'Adolescent Mothers who are 4Ps Members'
            ]
        ],
        'datasets' => $datasets,
        'title' => 'Number of Pregnant Adolescents and Adolescent Mothers by 4Ps and IP Membership and Age Group',
        'type' => 'bar',
        'indexAxis' => 'y',
        'legendPosition' => 'bottom',
        'xMax' => $max,
        'stepSize' => reportGraphStep($max)
    ];
}

if ($type === "income") {
    $incomeLabels = [
        "No Income",
        "1-10,000",
        "10,001 - 20,000",
        "20,001 and Above"
    ];

    $datasets = [
        [
            'label' => '9-14',
            'data' => array_map(fn($label) => $data["9-14"][$label] ?? 0, $incomeLabels),
            'backgroundColor' => '#4472C4'
        ],
        [
            'label' => '15-17',
            'data' => array_map(fn($label) => $data["15-17"][$label] ?? 0, $incomeLabels),
            'backgroundColor' => '#ED7D31'
        ],
        [
            'label' => '18-19',
            'data' => array_map(fn($label) => $data["18-19"][$label] ?? 0, $incomeLabels),
            'backgroundColor' => '#A5A5A5'
        ]
    ];

    $max = reportGraphMax($datasets);

    $graphData = [
        'labels' => $incomeLabels,
        'datasets' => $datasets,
        'title' => $title,
        'type' => 'bar',
        'indexAxis' => 'y',
        'legendPosition' => 'bottom',
        'xMax' => $max,
        'stepSize' => reportGraphStep($max)
    ];
}

if ($type === "support_services") {
    $serviceLabels = [
        "Livelihood training",
        "Skills training",
        "Health services",
        "Scholarship",
        "Others"
    ];

    $datasets = [
        [
            'label' => '9-14',
            'data' => array_map(fn($label) => $data["9-14"][$label] ?? 0, $serviceLabels),
            'backgroundColor' => '#4472C4'
        ],
        [
            'label' => '15-17',
            'data' => array_map(fn($label) => $data["15-17"][$label] ?? 0, $serviceLabels),
            'backgroundColor' => '#ED7D31'
        ],
        [
            'label' => '18-19',
            'data' => array_map(fn($label) => $data["18-19"][$label] ?? 0, $serviceLabels),
            'backgroundColor' => '#A5A5A5'
        ]
    ];

    $max = reportGraphMax($datasets);

    $graphData = [
        'labels' => $serviceLabels,
        'datasets' => $datasets,
        'title' => $title,
        'type' => 'bar',
        'indexAxis' => 'y',
        'legendPosition' => 'bottom',
        'xMax' => $max,
        'stepSize' => reportGraphStep($max)
    ];
}

if ($type === "partner_education") {
    $partnerEduLabels = [
        "No Education",
        "Elementary",
        "Junior High School",
        "Senior High School",
        "Vocational",
        "College Level"
    ];

    $datasets = [
        [
            'label' => 'Below 18',
            'data' => array_map(fn($label) => $data["Below 18"]["education"][$label] ?? 0, $partnerEduLabels),
            'backgroundColor' => '#4472C4'
        ],
        [
            'label' => '18-24',
            'data' => array_map(fn($label) => $data["18-24"]["education"][$label] ?? 0, $partnerEduLabels),
            'backgroundColor' => '#ED7D31'
        ],
        [
            'label' => '25 and above',
            'data' => array_map(fn($label) => $data["25 and above"]["education"][$label] ?? 0, $partnerEduLabels),
            'backgroundColor' => '#A5A5A5'
        ]
    ];

    $max = reportGraphMax($datasets);

    $graphData = [
        'labels' => $partnerEduLabels,
        'datasets' => $datasets,
        'title' => 'Educational Attainment of Partners, by Age Group',
        'type' => 'bar',
        'legendPosition' => 'bottom',
        'indexAxis' => 'y',
        'xMax' => $max,
        'stepSize' => reportGraphStep($max)
    ];
}

if ($type === "partner_income") {
    $partnerIncomeLabels = [
        "No Income",
        "1-10,000",
        "10,001 - 20,000",
        "20,001 and Above"
    ];

    $datasets = [
        [
            'label' => 'Below 18',
            'data' => array_map(fn($label) => $data["Below 18"]["income"][$label] ?? 0, $partnerIncomeLabels),
            'backgroundColor' => '#4472C4'
        ],
        [
            'label' => '18-24',
            'data' => array_map(fn($label) => $data["18-24"]["income"][$label] ?? 0, $partnerIncomeLabels),
            'backgroundColor' => '#ED7D31'
        ],
        [
            'label' => '25 and above',
            'data' => array_map(fn($label) => $data["25 and above"]["income"][$label] ?? 0, $partnerIncomeLabels),
            'backgroundColor' => '#A5A5A5'
        ]
    ];

    $max = reportGraphMax($datasets);

    $graphData = [
        'labels' => $partnerIncomeLabels,
        'datasets' => $datasets,
        'title' => 'Monthly Income Range of Partners, by Age Group',
        'type' => 'bar',
        'legendPosition' => 'bottom',
        'indexAxis' => 'y',
        'xMax' => $max,
        'stepSize' => reportGraphStep($max)
    ];
}

if ($type === "first_pregnancy_9_14") {
    $datasets = [[
        'label' => '9-14',
        'data' => [
            $data["9-14"]["first_age"][9] ?? 0,
            $data["9-14"]["first_age"][10] ?? 0,
            $data["9-14"]["first_age"][11] ?? 0,
            $data["9-14"]["first_age"][12] ?? 0,
            $data["9-14"]["first_age"][13] ?? 0,
            $data["9-14"]["first_age"][14] ?? 0
        ],
        'backgroundColor' => '#4472C4'
    ]];

    $max = reportGraphMax($datasets);

    $graphData = [
        'labels' => ['9 years old', '10 years old', '11 years old', '12 years old', '13 years old', '14 years old'],
        'datasets' => $datasets,
        'title' => 'Age at First Pregnancy among Adolescent Mothers Aged 9-14',
        'type' => 'bar',
        'legendPosition' => 'bottom',
        'xMax' => $max,
        'stepSize' => reportGraphStep($max)
    ];
}

if ($type === "first_pregnancy_15_17") {
    $datasets = [[
        'label' => '15-17',
        'data' => [
            ($data["15-17"]["first_age"][9] ?? 0) +
            ($data["15-17"]["first_age"][10] ?? 0) +
            ($data["15-17"]["first_age"][11] ?? 0) +
            ($data["15-17"]["first_age"][12] ?? 0) +
            ($data["15-17"]["first_age"][13] ?? 0) +
            ($data["15-17"]["first_age"][14] ?? 0),
            $data["15-17"]["first_age"][15] ?? 0,
            $data["15-17"]["first_age"][16] ?? 0,
            $data["15-17"]["first_age"][17] ?? 0
        ],
        'backgroundColor' => '#ED7D31'
    ]];

    $max = reportGraphMax($datasets);

    $graphData = [
        'labels' => ['9-14 years old', '15 years old', '16 years old', '17 years old'],
        'datasets' => $datasets,
        'title' => 'Age at First Pregnancy among Adolescent Mothers Aged 15-17',
        'type' => 'bar',
        'legendPosition' => 'bottom',
        'xMax' => $max,
        'stepSize' => reportGraphStep($max)
    ];
}

if ($type === "first_pregnancy_18_19") {
    $datasets = [[
        'label' => '18-19',
        'data' => [
            ($data["18-19"]["first_age"][9] ?? 0) +
            ($data["18-19"]["first_age"][10] ?? 0) +
            ($data["18-19"]["first_age"][11] ?? 0) +
            ($data["18-19"]["first_age"][12] ?? 0) +
            ($data["18-19"]["first_age"][13] ?? 0) +
            ($data["18-19"]["first_age"][14] ?? 0),
            $data["18-19"]["first_age"][15] ?? 0,
            $data["18-19"]["first_age"][16] ?? 0,
            $data["18-19"]["first_age"][17] ?? 0,
            $data["18-19"]["first_age"][18] ?? 0,
            $data["18-19"]["first_age"][19] ?? 0
        ],
        'backgroundColor' => '#A5A5A5'
    ]];

    $max = reportGraphMax($datasets);

    $graphData = [
        'labels' => ['9-14 years old', '15 years old', '16 years old', '17 years old', '18 years old', '19 years old'],
        'datasets' => $datasets,
        'title' => 'Age at First Pregnancy among Adolescent Mothers Aged 18-19',
        'type' => 'bar',
        'legendPosition' => 'bottom',
        'xMax' => $max,
        'stepSize' => reportGraphStep($max)
    ];
}

if ($type === "pregnancies_living_children") {
    $datasets = [
        [
            'label' => 'Total Pregnancies',
            'data' => [
                $data["9-14"]["total_pregnancies"] ?? 0,
                $data["15-17"]["total_pregnancies"] ?? 0,
                $data["18-19"]["total_pregnancies"] ?? 0
            ],
            'backgroundColor' => '#4472C4'
        ],
        [
            'label' => 'Total Living Children',
            'data' => [
                $data["9-14"]["living_children"] ?? 0,
                $data["15-17"]["living_children"] ?? 0,
                $data["18-19"]["living_children"] ?? 0
            ],
            'backgroundColor' => '#ED7D31'
        ]
    ];

    $max = reportGraphMax($datasets);

    $graphData = [
        'labels' => ['9-14', '15-17', '18-19'],
        'datasets' => $datasets,
        'title' => 'Adolescent Mothers by Total Number of Pregnancies, Total Number of Living Children, and Age Group',
        'type' => 'bar',
        'legendPosition' => 'bottom',
        'xMax' => $max,
        'stepSize' => reportGraphStep($max)
    ];
}
