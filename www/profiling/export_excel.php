<?php
declare(strict_types=1);

require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/education_options.php";

/* Ensure PDO */
if (!($conn instanceof PDO)) {
    die("<h3>ERROR</h3><p>Database connection is NOT PDO.</p>");
}
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* =========================
   Helpers
========================= */
function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function baseUrl(): string {
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $dir = preg_replace('#/(auth|config|dashboard|profiling|reports|includes)$#', '', $dir);
    return $dir ?: '';
}

function expTableExists(PDO $conn, string $table): bool {
    try {
        $stmt = $conn->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:t LIMIT 1");
        $stmt->execute([':t' => $table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function expTableRowCount(PDO $conn, string $table): int {
    try {
        $stmt = $conn->query("SELECT COUNT(*) FROM {$table}");
        return $stmt ? max(0, (int)$stmt->fetchColumn()) : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

function expGetTableColumns(PDO $conn, string $table): array {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return [];
    try {
        $stmt = $conn->query("PRAGMA table_info($table)");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $cols = [];
        foreach ($rows as $r) {
            $name = (string)($r['name'] ?? '');
            if ($name !== '') $cols[] = $name;
        }
        return $cols;
    } catch (Throwable $e) {
        return [];
    }
}

function hasColumn(array $cols, string $name): bool {
    $set = array_flip(array_map('strtolower', $cols));
    return isset($set[strtolower($name)]);
}

function cleanText($v): string {
    $s = trim((string)$v);
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return trim($s);
}

function csvValue($v): string {
    if ($v === null) return '';
    if (is_bool($v)) return $v ? '1' : '0';
    if (is_array($v)) return json_encode($v, JSON_UNESCAPED_UNICODE) ?: '';
    return trim((string)$v);
}

function firstNonEmpty(...$values) {
    foreach ($values as $v) {
        if (is_array($v)) {
            if (!empty($v)) return $v;
            continue;
        }
        if ($v === null) continue;
        if (is_bool($v)) return $v;
        if (is_int($v) || is_float($v)) return $v;
        $s = trim((string)$v);
        if ($s !== '') return $v;
    }
    return null;
}

function excelColumnName(int $index): string {
    $name = '';
    while ($index > 0) {
        $index--;
        $name = chr(65 + ($index % 26)) . $name;
        $index = intdiv($index, 26);
    }
    return $name;
}

/* =========================
   Date / Age helpers
========================= */
function parseDateFlexible(?string $raw): ?DateTime {
    $raw = trim((string)$raw);
    if ($raw === '') return null;

    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw)) {
        $raw = substr($raw, 0, 10);
    }

    $formats = [
        'Y-m-d', 'm/d/Y', 'd/m/Y', 'Y/m/d',
        'm-d-Y', 'd-m-Y', 'm.d.Y', 'd.m.Y',
        'F j, Y', 'M j, Y'
    ];

    foreach ($formats as $fmt) {
        $try = DateTime::createFromFormat($fmt, $raw);
        if ($try instanceof DateTime && $try->format($fmt) === $raw) {
            return $try;
        }
    }

    $ts = strtotime($raw);
    if ($ts === false) return null;

    return (new DateTime())->setTimestamp($ts);
}

function normalizeDateIso(?string $raw): string {
    $d = parseDateFlexible($raw);
    return $d ? $d->format('Y-m-d') : '';
}

function ageAtDate(?string $dob, ?string $asOfDate = null): ?int {
    $d = parseDateFlexible($dob);
    if (!$d) return null;

    $asOf = $asOfDate ? parseDateFlexible($asOfDate) : null;
    if (!$asOf) $asOf = new DateTime('today');

    $asOf->setTime(0, 0, 0);
    $d->setTime(0, 0, 0);

    if ($d > $asOf) return null;

    return (int)$d->diff($asOf)->y;
}

function isAdolescentToday(?string $dob): bool {
    $age = ageAtDate($dob, (new DateTime('today'))->format('Y-m-d'));
    return $age !== null && $age >= 9 && $age <= 19;
}

/* =========================
   JSON helpers
========================= */
function jsonDecodeArray($raw): array {
    if (!is_string($raw) || trim($raw) === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function jsonGet(array $data, string $key, $default = null) {
    return array_key_exists($key, $data) ? $data[$key] : $default;
}

function normalizeProgramsToCsv($v): string {
    $raw = trim((string)($v ?? ''));
    if ($raw === '') return '';

    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $clean = array_values(array_filter(array_map(
            fn($x) => cleanText((string)$x),
            $decoded
        ), fn($x) => $x !== ''));
        return $clean ? implode(', ', $clean) : '';
    }

    return $raw;
}

function normalizeGovernmentAssistanceDetails($details, $legacyOther = null): string {
    $items = [];
    $decoded = is_array($details) ? $details : json_decode(trim((string)($details ?? '')), true);

    if (is_array($decoded)) {
        foreach ($decoded as $program => $detail) {
            $detailText = cleanText(is_scalar($detail) ? (string)$detail : '');
            if ($detailText === '') continue;
            $programText = is_string($program) ? cleanText($program) : '';
            $items[] = $programText !== '' ? ($programText . ': ' . $detailText) : $detailText;
        }
    } else {
        $plain = cleanText($details);
        if ($plain !== '') $items[] = $plain;
    }

    $otherText = cleanText($legacyOther);
    if ($otherText !== '') {
        $otherEntry = 'Others: ' . $otherText;
        $alreadyIncluded = false;
        foreach ($items as $item) {
            if (strcasecmp($item, $otherEntry) === 0 || strcasecmp($item, $otherText) === 0) {
                $alreadyIncluded = true;
                break;
            }
        }
        if (!$alreadyIncluded) $items[] = $otherEntry;
    }

    return implode('; ', array_values(array_unique($items)));
}

function includeLegacyOtherAssistance(string $selected, $legacyOther): string {
    if (cleanText($legacyOther) === '') return $selected;

    $values = array_values(array_filter(array_map('trim', preg_split('/\s*(?:,|;)\s*/', $selected) ?: [])));
    foreach ($values as $value) {
        if (strcasecmp($value, 'Others') === 0 || strcasecmp($value, 'Other') === 0) return $selected;
    }
    $values[] = 'Others';
    return implode(', ', $values);
}

function normalizeCivilStatusExport($value): string {
    $raw = cleanText($value);
    if ($raw === '') return '';
    $low = strtolower(str_replace(['_', '/'], ' ', $raw));
    $low = preg_replace('/\s+/', ' ', $low) ?? $low;
    $low = trim($low);

    if (in_array($low, ['submitted', 'draft', 'pending', 'approved', 'verified', 'validated', 'rejected'], true)) {
        return '';
    }
    if ($low === 'single') return 'Single';
    if ($low === 'married') return 'Married';
    if ($low === 'separated') return 'Separated';
    if ($low === 'widowed') return 'Widowed';
    if (in_array($low, ['living in', 'living-in', 'live in', 'live-in', 'cohabiting', 'cohabitation'], true)) {
        return 'Living-in';
    }

    return '';
}

function normalizeIncomeRangeExport($value): string {
    $raw = cleanText($value);
    if ($raw === '') return '';
    $low = strtolower($raw);

    if (in_array($low, ['no income', 'none', 'n/a', 'na', 'unknown', '-', '--', '0'], true)) {
        return 'No Income';
    }
    if (preg_match('/20\s*,?\s*001|above|over|more than/i', $raw)) {
        return '20,001 and Above';
    }

    preg_match_all('/\d[\d,]*/', $raw, $matches);
    $numbers = [];
    foreach (($matches[0] ?? []) as $match) {
        $n = (int)str_replace(',', '', $match);
        if ($n > 0) $numbers[] = $n;
    }
    if (!$numbers) return $raw;

    $max = max($numbers);
    if ($max <= 0) return 'No Income';
    if ($max <= 10000) return '1-10,000';
    if ($max <= 20000) return '10,001 - 20,000';
    return '20,001 and Above';
}

function intFromExportValue($value): ?int {
    $raw = cleanText($value);
    if ($raw === '') return null;
    if (is_numeric($raw)) return (int)$raw;
    if (preg_match('/\b(\d{1,3})\b/', $raw, $m)) return (int)$m[1];
    return null;
}

function ageForExportRow(array $row): ?int {
    $dob = (string)($row['date_of_birth'] ?? $row['dob'] ?? $row['birthdate'] ?? '');
    return ageAtDate($dob, date('Y-m-d'));
}

function isAdolescentExportRow(array $row): bool {
    $age = ageForExportRow($row);
    return $age !== null && $age >= 9 && $age <= 19;
}

function isAdultExportRow(array $row): bool {
    $age = ageForExportRow($row);
    return $age !== null && $age >= 20;
}

function profileExportFilenameSegment(string $name, int $fallbackId): string {
    $name = cleanText($name);
    $name = strtr($name, ['Ñ' => 'N', 'ñ' => 'n']);
    if ($name !== '' && function_exists('iconv')) {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if (is_string($ascii) && trim($ascii) !== '') $name = $ascii;
    }
    $name = preg_replace('/[^A-Za-z0-9]+/', '_', $name) ?? '';
    $name = trim($name, '_');
    return $name !== '' ? substr($name, 0, 80) : ('Profile_' . $fallbackId);
}

/* =========================
   Name helpers
========================= */
function splitName(string $full): array {
    $full = cleanText($full);
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
    $last = $parts[count($parts) - 1];
    $middle = implode(' ', array_slice($parts, 1, -1));
    return [$last, $first, $middle];
}

function buildPartnerNameForExport(?string $surname, ?string $first, ?string $middle, $fallback = null): string {
    $surname = csvValue($surname);
    $first = csvValue($first);
    $middle = csvValue($middle);
    $given = trim($first . ($middle !== '' ? ' ' . $middle : ''));
    if ($surname !== '' && $given !== '') return $surname . ', ' . $given;
    $name = trim($surname . ' ' . $given);
    return $name !== '' ? $name : csvValue($fallback);
}

function normNameKey(?string $name): string {
    $name = cleanText((string)$name);
    if (function_exists('mb_strtolower')) return mb_strtolower($name, 'UTF-8');
    return strtolower($name);
}

function dedupeLegacyRows(array $rows, array $legacyCols): array {
    $hasAid = hasColumn($legacyCols, 'adolescent_id');
    $hasDob = hasColumn($legacyCols, 'date_of_birth');
    $hasCreatedAt = hasColumn($legacyCols, 'created_at');

    $best = [];

    foreach ($rows as $r) {
        $id = (int)($r['id'] ?? 0);

        $aid = $hasAid ? (int)($r['adolescent_id'] ?? 0) : 0;
        $nameKey = normNameKey((string)($r['name'] ?? ''));

        $dobRaw = '';
        if ($hasDob) $dobRaw = (string)($r['date_of_birth'] ?? '');
        if ($dobRaw === '') $dobRaw = (string)($r['dob'] ?? $r['birthdate'] ?? '');
        $dobKey = normalizeDateIso($dobRaw);

        if ($aid > 0) {
            $key = 'A:' . $aid;
        } else {
            $key = ($nameKey === '') ? 'ID:' . $id : 'N:' . $nameKey . '|' . $dobKey;
        }

        if (!isset($best[$key])) {
            $best[$key] = $r;
            continue;
        }

        $cur = $best[$key];
        $curId = (int)($cur['id'] ?? 0);
        $pickNew = false;

        if ($id > $curId) $pickNew = true;

        if (!$pickNew && $hasCreatedAt) {
            $tNew = strtotime((string)($r['created_at'] ?? '')) ?: 0;
            $tCur = strtotime((string)($cur['created_at'] ?? '')) ?: 0;
            if ($tNew > $tCur) $pickNew = true;
        }

        if ($pickNew) $best[$key] = $r;
    }

    $out = array_values($best);

    usort($out, function($a, $b) {
        $ai = (int)($a['id'] ?? 0);
        $bi = (int)($b['id'] ?? 0);
        if ($ai !== $bi) return $bi <=> $ai;
        $at = strtotime((string)($a['created_at'] ?? '')) ?: 0;
        $bt = strtotime((string)($b['created_at'] ?? '')) ?: 0;
        return $bt <=> $at;
    });

    return $out;
}

/* =========================
   Canonical export schema
   Questionnaire fields follow profiling/add.php from top to bottom.
   Record/system fields are intentionally grouped at the far right.
========================= */
function canonicalExportSchema(bool $includeRestorePayload = false): array {
    $schema = [
        // Part A - I. Identifying Information
        ['key' => 'last_name', 'header' => 'Surname'],
        ['key' => 'first_name', 'header' => 'First Name'],
        ['key' => 'middle_name', 'header' => 'Middle Name'],
        ['key' => 'municipality', 'header' => 'Municipality'],
        ['key' => 'barangay', 'header' => 'Barangay'],
        ['key' => 'place_of_birth', 'header' => 'Place of Birth'],
        ['key' => 'date_of_birth', 'header' => 'Date of Birth', 'format' => 'date'],
        ['key' => 'age_today', 'header' => 'Age'],

        // Part A - II. Educational Attainment
        ['key' => 'education', 'header' => 'Educational Attainment'],
        ['key' => 'year_level', 'header' => 'Grade / Year Level'],
        ['key' => 'last_school_attended_type', 'header' => 'Last School Attended'],
        ['key' => 'reason_not_attending_school', 'header' => 'Reason for Not Attending School'],
        ['key' => 'reason_not_attending_school_other', 'header' => 'Other Reason for Not Attending School'],
        ['key' => 'vocational_training_other', 'header' => 'Other Vocational Training'],
        ['key' => 'currently_enrolled', 'header' => 'Currently Enrolled'],

        // Part A - III to X
        ['key' => 'civil_status', 'header' => 'Civil Status'],
        ['key' => 'living_situation', 'header' => 'Living Situation'],
        ['key' => 'living_others', 'header' => 'Living With Others (Specify)'],
        ['key' => 'pregnant', 'header' => 'Currently Pregnant'],
        ['key' => 'months_pregnant', 'header' => 'Months Pregnant'],
        ['key' => 'prenatal_care', 'header' => 'Attended Prenatal Care'],
        ['key' => 'health_concerns', 'header' => 'Health Concerns During Pregnancy'],
        ['key' => 'age_first_pregnancy', 'header' => 'Age of First Pregnancy'],
        ['key' => 'times_pregnant', 'header' => 'Times Pregnant'],
        ['key' => 'living_children', 'header' => 'Number of Living Children'],
        ['key' => 'child_under_one', 'header' => 'Has Child Under 1 Year Old'],
        ['key' => 'family_planning', 'header' => 'Family Planning Method Currently Used'],
        ['key' => 'is_4ps', 'header' => '4Ps Beneficiary'],
        ['key' => 'is_ips', 'header' => 'IP Member'],
        ['key' => 'ethnicity', 'header' => 'Ethnicity / IP Group'],
        ['key' => 'currently_working', 'header' => 'Currently Working'],
        ['key' => 'monthly_income', 'header' => 'Monthly Income'],
        ['key' => 'occupation', 'header' => 'Occupation'],
        ['key' => 'father_occupation', 'header' => 'Father Occupation'],
        ['key' => 'mother_occupation', 'header' => 'Mother Occupation'],
        ['key' => 'gov_assistance_selected', 'header' => 'Government Assistance Selected'],
        ['key' => 'gov_assistance_details', 'header' => 'Government Assistance Details'],

        // Part B. Husband's / Partner's Information
        ['key' => 'partner_surname', 'header' => 'Partner Surname'],
        ['key' => 'partner_first_name', 'header' => 'Partner First Name'],
        ['key' => 'partner_middle_name', 'header' => 'Partner Middle Name'],
        ['key' => 'partner_address', 'header' => 'Partner Present Address'],
        ['key' => 'partner_place_of_birth', 'header' => 'Partner Place of Birth'],
        ['key' => 'partner_dob', 'header' => 'Partner Date of Birth', 'format' => 'date'],
        ['key' => 'partner_age', 'header' => 'Partner Age'],
        ['key' => 'partner_education', 'header' => 'Partner Educational Attainment'],
        ['key' => 'partner_currently_enrolled', 'header' => 'Partner Currently Enrolled'],
        ['key' => 'partner_year_level', 'header' => 'Partner Grade / Year Level'],
        ['key' => 'partner_currently_working', 'header' => 'Partner Currently Working'],
        ['key' => 'partner_monthly_income', 'header' => 'Partner Monthly Income'],
        ['key' => 'partner_occupation', 'header' => 'Partner Occupation'],

        // Final profiling/interview fields
        ['key' => 'other_observations', 'header' => 'Other Observations / Information Noted'],
        ['key' => 'interviewed_by', 'header' => 'Interviewed By (Barangay Population Volunteer)'],
        ['key' => 'interview_date', 'header' => 'Interview Date', 'format' => 'date'],
        ['key' => 'noted_by', 'header' => 'Noted By (Municipal Population Officer)'],
        ['key' => 'noted_date', 'header' => 'Noted Date', 'format' => 'date'],
        ['key' => 'encoded_by', 'header' => 'Encoded By'],
        ['key' => 'date_encoded', 'header' => 'Date Encoded', 'format' => 'date'],

        // Record / System Information
        ['key' => 'adolescent_id', 'header' => 'Adolescent ID'],
        ['key' => 'encounter_id', 'header' => 'Latest Update ID'],
        ['key' => 'status', 'header' => 'Record Status'],
        ['key' => 'verified_by', 'header' => 'Verified By'],
        ['key' => 'verified_at', 'header' => 'Verified Date / Time', 'format' => 'datetime'],
        ['key' => 'adolescent_created_at', 'header' => 'Profile Created At', 'format' => 'datetime'],
        ['key' => 'adolescent_updated_at', 'header' => 'Profile Updated At', 'format' => 'datetime'],
        ['key' => 'encounter_created_at', 'header' => 'Latest Update Saved At', 'format' => 'datetime'],
    ];

    if ($includeRestorePayload) {
       
    }

    return $schema;
}

function exportSchemaColumns(array $schema): array {
    return array_column($schema, 'key');
}

function exportSchemaHeaderLabels(array $schema): array {
    $labels = [];
    foreach ($schema as $column) {
        $labels[(string)$column['key']] = (string)$column['header'];
    }
    return $labels;
}

/* =========================
   Legacy latest snapshot helpers
========================= */
function getLatestLegacySnapshotsByAdolescent(PDO $conn, array $adolescentIds): array {
    $out = [];

    if (!$adolescentIds) return $out;
    if (!expTableExists($conn, 'adolescent_mothers')) return $out;

    $legacyCols = expGetTableColumns($conn, 'adolescent_mothers');
    if (!hasColumn($legacyCols, 'adolescent_id')) return $out;

    $ids = array_values(array_unique(array_filter(array_map('intval', $adolescentIds), fn($x) => $x > 0)));
    if (!$ids) return $out;

    $ph = implode(',', array_fill(0, count($ids), '?'));

    $sql = "
        SELECT am.*
        FROM adolescent_mothers am
        INNER JOIN (
            SELECT adolescent_id, MAX(id) AS max_id
            FROM adolescent_mothers
            WHERE adolescent_id IN ($ph)
            GROUP BY adolescent_id
        ) x
        ON x.max_id = am.id
    ";

    try {
        $st = $conn->prepare($sql);
        $st->execute($ids);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $aid = (int)($r['adolescent_id'] ?? 0);
            if ($aid > 0) $out[$aid] = $r;
        }
    } catch (Throwable $e) {
        return [];
    }

    return $out;
}

function getLegacyAllRowsByAdolescent(PDO $conn, array $adolescentIds): array {
    $out = [];

    if (!$adolescentIds) return $out;
    if (!expTableExists($conn, 'adolescent_mothers')) return $out;

    $legacyCols = expGetTableColumns($conn, 'adolescent_mothers');
    if (!hasColumn($legacyCols, 'adolescent_id')) return $out;

    $ids = array_values(array_unique(array_filter(array_map('intval', $adolescentIds), fn($x) => $x > 0)));
    if (!$ids) return $out;

    $ph = implode(',', array_fill(0, count($ids), '?'));

    $sql = "
        SELECT *
        FROM adolescent_mothers
        WHERE adolescent_id IN ($ph)
        ORDER BY adolescent_id ASC, id ASC
    ";

    try {
        $st = $conn->prepare($sql);
        $st->execute($ids);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $aid = (int)($r['adolescent_id'] ?? 0);
            if ($aid > 0) {
                if (!isset($out[$aid])) $out[$aid] = [];
                $out[$aid][] = $r;
            }
        }
    } catch (Throwable $e) {
        return [];
    }

    return $out;
}

/* =========================
   Audit
========================= */
function ensureAuditLogTable(PDO $conn): void {
    try {
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
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
        ");
    } catch (Throwable $e) {}
}

function audit(PDO $conn, string $action, string $entity, ?int $entityId = null, array $details = []): void {
    try {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $username = (string)($_SESSION['username'] ?? '');
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $payload = $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null;

        $stmt = $conn->prepare("
            INSERT INTO audit_log (user_id, username, action, entity, entity_id, ip, user_agent, details)
            VALUES (:user_id, :username, :action, :entity, :entity_id, :ip, :ua, :details)
        ");
        $stmt->execute([
            ':user_id' => $userId ?: null,
            ':username' => $username ?: null,
            ':action' => $action,
            ':entity' => $entity,
            ':entity_id' => $entityId,
            ':ip' => $ip ?: null,
            ':ua' => $ua ?: null,
            ':details' => $payload,
        ]);
    } catch (Throwable $e) {}
}

ensureAuditLogTable($conn);

/* =========================
   Mode / base
========================= */
$base = baseUrl();
$self = basename(__FILE__);
$requestedAgeGroup = strtolower(trim((string)($_GET['age_group'] ?? 'adolescent')));
$downloadRequested = (int)($_GET['download'] ?? 0) === 1;
$requestedKind = strtolower(trim((string)($_GET['kind'] ?? 'snapshot')));
$requestedProfileIdRaw = max(0, (int)($_GET['profile_id'] ?? 0));

/*
 * Export File is always the adolescent 9-19 population. The only adult mode
 * retained here is the existing row-level individual export launched from
 * View Profiles > 20 Years Old and Above.
 */
$isAdultIndividualRequest = $downloadRequested
    && $requestedKind === 'snapshot'
    && $requestedAgeGroup === 'adult'
    && $requestedProfileIdRaw > 0;
$ageGroup = $isAdultIndividualRequest ? 'adult' : 'adolescent';
$isAdultExport = $ageGroup === 'adult';
$requestedProfileId = $isAdultExport ? $requestedProfileIdRaw : 0;
$requestedProfileKey = strtolower(trim((string)($_GET['profile_key'] ?? 'adolescent')));
if (!in_array($requestedProfileKey, ['adolescent', 'encounter'], true)) {
    $requestedProfileKey = 'adolescent';
}
$isIndividualProfileExport = $requestedProfileId > 0;

$currentExportMunicipality = '';
try {
    $currentUserId = (int)($_SESSION['user_id'] ?? 0);
    if ($currentUserId > 0) {
        $stLocation = $conn->prepare("SELECT COALESCE(municipality,'') FROM users WHERE id = :id LIMIT 1");
        $stLocation->execute([':id' => $currentUserId]);
        $currentExportMunicipality = cleanText((string)$stLocation->fetchColumn());
    }
} catch (Throwable $e) {
    $currentExportMunicipality = '';
}

$exportFilters = [
    'submission_month' => normalizeDateIso((string)($_GET['submission_month'] ?? '')) !== ''
        ? substr(normalizeDateIso((string)($_GET['submission_month'] ?? '')), 0, 7)
        : (preg_match('/^\d{4}-\d{2}$/', (string)($_GET['submission_month'] ?? '')) ? (string)$_GET['submission_month'] : ''),
    'name_prefix' => cleanText((string)($_GET['name_prefix'] ?? '')),
    'search' => cleanText((string)($_GET['search'] ?? '')),
    'birth_month' => (int)($_GET['birth_month'] ?? 0),
    'birth_year' => (int)($_GET['birth_year'] ?? 0),
    'birthdate' => normalizeDateIso((string)($_GET['birthdate'] ?? '')),
    'barangay' => cleanText((string)firstNonEmpty($_GET['barangay'] ?? null, $_GET['brgy'] ?? null)),
    'municipality' => $currentExportMunicipality,
    'latest_from' => normalizeDateIso((string)($_GET['latest_from'] ?? '')),
    'latest_to' => normalizeDateIso((string)($_GET['latest_to'] ?? '')),
    'profile_id' => $requestedProfileId,
    'profile_key' => $requestedProfileKey,
];

/* =========================
   Data source checks
========================= */
$hasNewTables = expTableExists($conn, 'adolescents') && expTableExists($conn, 'encounters');
$hasLegacy = expTableExists($conn, 'adolescent_mothers');

$newHasData = $hasNewTables && (expTableRowCount($conn, 'adolescents') > 0 || expTableRowCount($conn, 'encounters') > 0);
$legacyHasData = $hasLegacy && expTableRowCount($conn, 'adolescent_mothers') > 0;

/* NEW MODEL IS PRIMARY. LEGACY IS FALLBACK ONLY. */
$useNew = $newHasData || (!$legacyHasData && $hasNewTables);

$previewRows = [];
$backupRows = [];
$latestLegacySnapshotsByAid = [];
$allLegacyRowsByAid = [];
$source = $useNew ? 'new' : 'legacy';

/* =========================
   Load NEW data
========================= */
if ($useNew) {
    try {
        /* PREVIEW / EXPORT EXCEL = latest snapshot only */
        $sqlPreview = "
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
                a.educational_attainment AS adolescent_educational_attainment,
                a.currently_enrolled AS adolescent_currently_enrolled,
                a.grade_year_level AS adolescent_grade_year_level,
                a.last_school_attended_type AS adolescent_last_school_attended_type,
                a.reason_not_attending_school AS adolescent_reason_not_attending_school,
                a.reason_not_attending_school_other AS adolescent_reason_not_attending_school_other,
                a.vocational_training_other AS adolescent_vocational_training_other,
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
                e.educational_attainment,
                e.currently_enrolled,
                e.grade_year_level,
                e.last_school_attended_type,
                e.reason_not_attending_school,
                e.reason_not_attending_school_other,
                e.vocational_training_other,
                e.programs_applied,
                e.data_json,
                e.encoded_by,
                e.date_encoded,
                e.created_at AS encounter_created_at,
                u2.username AS verified_by,
                e.verified_at

            FROM adolescents a
            LEFT JOIN (
                SELECT e1.*
                FROM encounters e1
            ) e ON e.id = (
                SELECT e2.id
                FROM encounters e2
                WHERE e2.adolescent_id = a.id
                ORDER BY
                    COALESCE(NULLIF(TRIM(e2.encounter_date),''),'0000-00-00') DESC,
                    COALESCE(NULLIF(TRIM(e2.created_at),''),'0000-00-00 00:00:00') DESC,
                    e2.id DESC
                LIMIT 1
            )
            LEFT JOIN users u2 ON u2.id = e.verified_by_user_id
            ORDER BY
                LOWER(TRIM(COALESCE(a.barangay,''))) ASC,
                LOWER(TRIM(COALESCE(a.last_name, a.full_name,''))) ASC,
                LOWER(TRIM(COALESCE(a.first_name,''))) ASC,
                COALESCE(e.encounter_date, e.created_at, a.created_at) DESC,
                a.id DESC
        ";
        $stPreview = $conn->query($sqlPreview);
        $previewRows = $stPreview ? ($stPreview->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        $previewRows = [];
    }

    try {
        /* BACKUP = full history */
        $sqlBackup = "
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
                a.educational_attainment AS adolescent_educational_attainment,
                a.currently_enrolled AS adolescent_currently_enrolled,
                a.grade_year_level AS adolescent_grade_year_level,
                a.last_school_attended_type AS adolescent_last_school_attended_type,
                a.reason_not_attending_school AS adolescent_reason_not_attending_school,
                a.reason_not_attending_school_other AS adolescent_reason_not_attending_school_other,
                a.vocational_training_other AS adolescent_vocational_training_other,
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
                e.educational_attainment,
                e.currently_enrolled,
                e.grade_year_level,
                e.last_school_attended_type,
                e.reason_not_attending_school,
                e.reason_not_attending_school_other,
                e.vocational_training_other,
                e.programs_applied,
                e.data_json,
                e.encoded_by,
                e.date_encoded,
                e.created_at AS encounter_created_at,
                u2.username AS verified_by,
                e.verified_at

            FROM adolescents a
            LEFT JOIN encounters e ON e.adolescent_id = a.id
            LEFT JOIN users u2 ON u2.id = e.verified_by_user_id
            ORDER BY
                LOWER(TRIM(COALESCE(a.barangay,''))) ASC,
                LOWER(TRIM(COALESCE(a.last_name, a.full_name,''))) ASC,
                LOWER(TRIM(COALESCE(a.first_name,''))) ASC,
                COALESCE(e.encounter_date, e.created_at, a.created_at) DESC,
                e.id DESC
        ";
        $stBackup = $conn->query($sqlBackup);
        $backupRows = $stBackup ? ($stBackup->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        $backupRows = [];
    }

    if (($previewRows || $backupRows) && $hasLegacy) {
        $aidList = array_values(array_unique(array_filter(array_map(
            fn($r) => (int)($r['adolescent_id'] ?? 0),
            array_merge($previewRows, $backupRows)
        ), fn($x) => $x > 0)));

        if ($aidList) {
            $latestLegacySnapshotsByAid = getLatestLegacySnapshotsByAdolescent($conn, $aidList);
            $allLegacyRowsByAid = getLegacyAllRowsByAdolescent($conn, $aidList);
        }
    }

    if (!$previewRows && !$backupRows && $hasLegacy) {
        try {
            $stLegacy = $conn->query("SELECT * FROM adolescent_mothers ORDER BY id DESC");
            $legacyRows = $stLegacy ? ($stLegacy->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            $previewRows = $legacyRows;
            $backupRows = $legacyRows;
            $useNew = false;
            $source = 'legacy';
        } catch (Throwable $e) {
            $previewRows = [];
            $backupRows = [];
        }
    }
} elseif ($hasLegacy) {
    try {
        $st = $conn->query("SELECT * FROM adolescent_mothers ORDER BY id DESC");
        $legacyRows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        $previewRows = $legacyRows;
        $backupRows = $legacyRows;
    } catch (Throwable $e) {
        $previewRows = [];
        $backupRows = [];
    }
}

/* =========================
   Legacy dedupe for preview
========================= */
$legacyCols = $hasLegacy ? expGetTableColumns($conn, 'adolescent_mothers') : [];
$legacyBefore = 0;
$legacyAfter = 0;

if (!$useNew && $hasLegacy && $previewRows) {
    $legacyBefore = count($previewRows);
    $previewRows = dedupeLegacyRows($previewRows, $legacyCols);
    $legacyAfter = count($previewRows);
}

/* =========================
   FILTER RULES
   - Normal export = current ages 9-19
   - Adult export = current ages 20 and above
   - BACKUP / backupRows = ALL records, ALL ages
========================= */
$previewRows = array_values(array_filter($previewRows, static function(array $row) use ($isAdultExport): bool {
    return $isAdultExport ? isAdultExportRow($row) : isAdolescentExportRow($row);
}));

/*
 IMPORTANT:
 DO NOT FILTER $backupRows.
 BACKUP must include all records/history,
 including records already 20 years old and above.
*/

$previewRows = filterExportRows($previewRows, $useNew, $exportFilters, $latestLegacySnapshotsByAid);
sortExportRows($previewRows, $useNew, $latestLegacySnapshotsByAid, false);
sortExportRows($backupRows, $useNew, [], true);

/* =========================
   Schemas
========================= */
$snapshotSchema = canonicalExportSchema(false);
$backupSchema = canonicalExportSchema(true);
$snapshotColumns = exportSchemaColumns($snapshotSchema);
$backupColumns = exportSchemaColumns($backupSchema);

/* =========================
   Shared row mapping
========================= */
function buildNormalizedRowForNew(array $r, array $legacy = [], bool $forBackup = false): array {
    $json = jsonDecodeArray((string)($r['data_json'] ?? ''));
    $legacyJson = jsonDecodeArray((string)($legacy['data_json'] ?? ''));

    $programsApplied = normalizeProgramsToCsv(firstNonEmpty(
        $legacy['programs_applied'] ?? null,
        $legacy['gov_assistance'] ?? null,
        jsonGet($legacyJson, 'programs_applied'),
        jsonGet($legacyJson, 'gov_assistance'),
        $r['programs_applied'] ?? null,
        jsonGet($json, 'programs_applied'),
        jsonGet($json, 'gov_assistance')
    ));

    $govSelected = normalizeProgramsToCsv(firstNonEmpty(
        $legacy['gov_assistance'] ?? null,
        jsonGet($legacyJson, 'gov_assistance'),
        $r['programs_applied'] ?? null,
        jsonGet($json, 'gov_assistance')
    ));

    $govDetailsRaw = firstNonEmpty(
        $legacy['gov_assistance_details'] ?? null,
        jsonGet($legacyJson, 'gov_assistance_details'),
        jsonGet($json, 'gov_assistance_details')
    );
    $govOtherRaw = firstNonEmpty(
        $legacy['gov_assistance_other'] ?? null,
        jsonGet($legacyJson, 'gov_assistance_other'),
        jsonGet($json, 'gov_assistance_other')
    );
    $govSelected = includeLegacyOtherAssistance($govSelected, $govOtherRaw);
    $govDetailsValue = normalizeGovernmentAssistanceDetails($govDetailsRaw, $govOtherRaw);

    $additionalDetails = [];
    if ($forBackup) {
        $knownKeys = [
            'source_backup_id','currently_enrolled','year_level','grade_year_level','education','educational_attainment',
            'last_school_attended_type','reason_not_attending_school','reason_for_not_attending_school','reason_not_attending_school_other','vocational_training_other',
            'civil_status','living_situation',
            'living_others','child_under_one','currently_working','monthly_income','occupation','ethnicity',
            'is_4ps','is_ips','father_occupation','mother_occupation','health_concerns','prenatal_care',
            'months_pregnant','interview_date','interviewed_by','noted_by','noted_date','encoded_by','date_encoded','other_observations',
            'partner_name','partner_surname','partner_first_name','partner_middle_name',
            'partner_age','partner_dob','partner_address','partner_place_of_birth',
            'partner_education','partner_currently_enrolled','partner_year_level','partner_currently_working',
            'partner_occupation','partner_monthly_income','gov_assistance','gov_assistance_details',
            'gov_assistance_other','programs_applied','name','date_of_birth','present_address',
            'locked_barangay','locked_municipality','barangay','municipality','place_of_birth','sex',
            'pregnant','times_pregnant','age_first_pregnancy','living_children','family_planning'
        ];
        $knownSet = array_flip($knownKeys);
        foreach ($json as $k => $v) {
            if (!isset($knownSet[(string)$k])) {
                $additionalDetails[$k] = $v;
            }
        }
    }

    $educationRaw = firstNonEmpty(
        $legacy['educational_attainment'] ?? null,
        $legacy['education'] ?? null,
        jsonGet($legacyJson, 'educational_attainment'),
        jsonGet($legacyJson, 'education'),
        $r['educational_attainment'] ?? null,
        $r['adolescent_educational_attainment'] ?? null,
        jsonGet($json, 'educational_attainment'),
        jsonGet($json, 'education')
    );
    $educationValue = ahdisNormalizeEducationAttainment((string)$educationRaw);
    if ($educationValue === '') {
        $educationValue = csvValue($educationRaw);
    }

    $gradeRaw = firstNonEmpty(
        $legacy['grade_year_level'] ?? null,
        $legacy['year_level'] ?? null,
        jsonGet($legacyJson, 'grade_year_level'),
        jsonGet($legacyJson, 'year_level'),
        $r['grade_year_level'] ?? null,
        $r['adolescent_grade_year_level'] ?? null,
        jsonGet($json, 'grade_year_level'),
        jsonGet($json, 'year_level')
    );
    $gradeValue = $educationValue === 'No Education'
        ? ''
        : (ahdisNormalizeGradeYearLevel($educationValue, (string)$gradeRaw) ?: csvValue($gradeRaw));

    $lastSchoolValue = csvValue(firstNonEmpty(
        $legacy['last_school_attended_type'] ?? null,
        jsonGet($legacyJson, 'last_school_attended_type'),
        $r['last_school_attended_type'] ?? null,
        $r['adolescent_last_school_attended_type'] ?? null,
        jsonGet($json, 'last_school_attended_type')
    ));
    $reasonRaw = firstNonEmpty(
        $legacy['reason_not_attending_school'] ?? null,
        $legacy['reason_for_not_attending_school'] ?? null,
        jsonGet($legacyJson, 'reason_not_attending_school'),
        jsonGet($legacyJson, 'reason_for_not_attending_school'),
        $r['reason_not_attending_school'] ?? null,
        $r['adolescent_reason_not_attending_school'] ?? null,
        jsonGet($json, 'reason_not_attending_school'),
        jsonGet($json, 'reason_for_not_attending_school')
    );
    $reasonValue = ahdisFormatReasonsNotAttendingSchool($reasonRaw);
    $reasonOtherValue = csvValue(firstNonEmpty(
        $legacy['reason_not_attending_school_other'] ?? null,
        jsonGet($legacyJson, 'reason_not_attending_school_other'),
        $r['reason_not_attending_school_other'] ?? null,
        $r['adolescent_reason_not_attending_school_other'] ?? null,
        jsonGet($json, 'reason_not_attending_school_other')
    ));
    $currentlyEnrolledValue = csvValue(firstNonEmpty(
        $r['currently_enrolled'] ?? null,
        $r['adolescent_currently_enrolled'] ?? null,
        jsonGet($json, 'currently_enrolled'),
        $legacy['currently_enrolled'] ?? null,
        jsonGet($legacyJson, 'currently_enrolled')
    ));
    if ($currentlyEnrolledValue === 'Yes') {
        $reasonValue = '';
        $reasonOtherValue = '';
    }
    $vocationalOtherValue = csvValue(firstNonEmpty(
        $legacy['vocational_training_other'] ?? null,
        jsonGet($legacyJson, 'vocational_training_other'),
        $r['vocational_training_other'] ?? null,
        $r['adolescent_vocational_training_other'] ?? null,
        jsonGet($json, 'vocational_training_other')
    ));
    if ($educationValue === 'Vocational' && $vocationalOtherValue !== '' && ($gradeValue === '' || $gradeValue === 'Other Vocational Training')) {
        $gradeValue = $vocationalOtherValue;
    }

    $partnerSurnameValue = csvValue(firstNonEmpty(
        $legacy['partner_surname'] ?? null,
        jsonGet($legacyJson, 'partner_surname'),
        $r['partner_surname'] ?? null,
        jsonGet($json, 'partner_surname'),
        jsonGet($json, 'partner_last_name')
    ));
    $partnerFirstNameValue = csvValue(firstNonEmpty(
        $legacy['partner_first_name'] ?? null,
        jsonGet($legacyJson, 'partner_first_name'),
        $r['partner_first_name'] ?? null,
        jsonGet($json, 'partner_first_name')
    ));
    $partnerMiddleNameValue = csvValue(firstNonEmpty(
        $legacy['partner_middle_name'] ?? null,
        jsonGet($legacyJson, 'partner_middle_name'),
        $r['partner_middle_name'] ?? null,
        jsonGet($json, 'partner_middle_name')
    ));
    $partnerNameValue = buildPartnerNameForExport(
        $partnerSurnameValue,
        $partnerFirstNameValue,
        $partnerMiddleNameValue,
        firstNonEmpty(
            $legacy['partner_name'] ?? null,
            jsonGet($legacyJson, 'partner_name'),
            $r['partner_name'] ?? null,
            jsonGet($json, 'partner_name')
        )
    );

    $dobForExport = normalizeDateIso((string)($r['date_of_birth'] ?? ''));
    $ageTodayValue = ageAtDate($dobForExport, date('Y-m-d'));
    if ($ageTodayValue === null) {
        $ageTodayValue = intFromExportValue(firstNonEmpty(jsonGet($json, 'age'), jsonGet($json, 'imported_age')));
    }
    $civilStatusValue = normalizeCivilStatusExport(firstNonEmpty(jsonGet($json, 'civil_status')));
    $monthlyIncomeValue = normalizeIncomeRangeExport(firstNonEmpty(jsonGet($json, 'monthly_income')));
    $partnerMonthlyIncomeValue = normalizeIncomeRangeExport(firstNonEmpty(jsonGet($json, 'partner_monthly_income')));

    return [
        'adolescent_id' => csvValue($r['adolescent_id'] ?? null),
        'full_name' => csvValue($r['full_name'] ?? null),
        'last_name' => csvValue($r['last_name'] ?? null),
        'first_name' => csvValue($r['first_name'] ?? null),
        'middle_name' => csvValue($r['middle_name'] ?? null),
        'date_of_birth' => $dobForExport,
        'age_today' => $ageTodayValue === null ? '' : (string)$ageTodayValue,
        'sex' => csvValue(firstNonEmpty($legacy['sex'] ?? null, jsonGet($legacyJson, 'sex'), $r['sex'] ?? null)),
        'barangay' => csvValue(firstNonEmpty($legacy['locked_barangay'] ?? null, $legacy['barangay'] ?? null, jsonGet($legacyJson, 'locked_barangay'), jsonGet($legacyJson, 'barangay'), $r['barangay'] ?? null)),
        'municipality' => csvValue(firstNonEmpty($legacy['locked_municipality'] ?? null, $legacy['municipality'] ?? null, jsonGet($legacyJson, 'locked_municipality'), jsonGet($legacyJson, 'municipality'), $r['municipality'] ?? null)),
        'present_address' => csvValue(firstNonEmpty($legacy['present_address'] ?? null, jsonGet($legacyJson, 'present_address'), $r['present_address'] ?? null)),
        'place_of_birth' => csvValue(firstNonEmpty($legacy['place_of_birth'] ?? null, jsonGet($legacyJson, 'place_of_birth'), jsonGet($json, 'place_of_birth'))),

        'encounter_id' => csvValue($r['encounter_id'] ?? null),
        'encounter_date' => normalizeDateIso((string)firstNonEmpty(
            $r['encounter_date'] ?? null,
            jsonGet($json, 'interview_date')
        )),
        'status' => csvValue($r['status'] ?? null),
        'pregnant' => csvValue(firstNonEmpty($r['pregnant'] ?? null, jsonGet($json, 'pregnant'))),
        'times_pregnant' => csvValue(firstNonEmpty($r['times_pregnant'] ?? null, jsonGet($json, 'times_pregnant'))),
        'age_first_pregnancy' => csvValue(firstNonEmpty($r['age_first_pregnancy'] ?? null, jsonGet($json, 'age_first_pregnancy'))),
        'living_children' => csvValue(firstNonEmpty($r['living_children'] ?? null, jsonGet($json, 'living_children'))),
        'family_planning' => csvValue(ahdisFamilyPlanningDisplay(firstNonEmpty($r['family_planning'] ?? null, jsonGet($json, 'family_planning')))),
        'programs_applied' => $programsApplied,

        'education' => $educationValue,
        'educational_attainment' => $educationValue,
        'year_level' => $gradeValue,
        'grade_year_level' => $gradeValue,
        'last_school_attended_type' => $lastSchoolValue,
        'reason_not_attending_school' => $reasonValue,
        'reason_for_not_attending_school' => $reasonValue,
        'reason_not_attending_school_other' => ahdisReasonsNotAttendingSchoolContains($reasonRaw, 'Other') ? $reasonOtherValue : '',
        'vocational_training_other' => $vocationalOtherValue,
        'currently_enrolled' => $currentlyEnrolledValue,
        'civil_status' => $civilStatusValue,
        'living_situation' => csvValue(firstNonEmpty(jsonGet($json, 'living_situation'))),
        'living_others' => csvValue(firstNonEmpty(jsonGet($json, 'living_others'))),
        'child_under_one' => csvValue(firstNonEmpty(jsonGet($json, 'child_under_one'))),
        'currently_working' => csvValue(firstNonEmpty(jsonGet($json, 'currently_working'))),
        'monthly_income' => $monthlyIncomeValue,
        'occupation' => csvValue(firstNonEmpty(jsonGet($json, 'occupation'))),
        'ethnicity' => csvValue(firstNonEmpty(jsonGet($json, 'ethnicity'))),
        'is_4ps' => csvValue(firstNonEmpty(jsonGet($json, 'is_4ps'))),
        'is_ips' => csvValue(firstNonEmpty(jsonGet($json, 'is_ips'))),
        'father_occupation' => csvValue(firstNonEmpty(jsonGet($json, 'father_occupation'))),
        'mother_occupation' => csvValue(firstNonEmpty(jsonGet($json, 'mother_occupation'))),
        'health_concerns' => csvValue(firstNonEmpty(jsonGet($json, 'health_concerns'))),
        'prenatal_care' => csvValue(firstNonEmpty(jsonGet($json, 'prenatal_care'))),
        'months_pregnant' => csvValue(firstNonEmpty(jsonGet($json, 'months_pregnant'))),
        'interview_date' => normalizeDateIso((string)firstNonEmpty(
            jsonGet($json, 'interview_date'),
            $r['encounter_date'] ?? null
        )),
        'interviewed_by' => csvValue(firstNonEmpty(jsonGet($json, 'interviewed_by'))),
        'noted_by' => csvValue(firstNonEmpty(jsonGet($json, 'noted_by'))),
        'noted_date' => normalizeDateIso((string)firstNonEmpty(jsonGet($json, 'noted_date'))),
        'encoded_by' => csvValue(firstNonEmpty(
            $r['encoded_by'] ?? null,
            jsonGet($json, 'encoded_by'),
            $legacy['encoded_by'] ?? null,
            jsonGet($legacyJson, 'encoded_by')
        )),
        'date_encoded' => normalizeDateIso((string)firstNonEmpty(
            $r['date_encoded'] ?? null,
            jsonGet($json, 'date_encoded'),
            $legacy['date_encoded'] ?? null,
            jsonGet($legacyJson, 'date_encoded')
        )),
        'other_observations' => csvValue(firstNonEmpty(jsonGet($json, 'other_observations'))),

        'partner_name' => $partnerNameValue,
        'partner_surname' => $partnerSurnameValue,
        'partner_first_name' => $partnerFirstNameValue,
        'partner_middle_name' => $partnerMiddleNameValue,
        'partner_age' => csvValue(firstNonEmpty(jsonGet($json, 'partner_age'))),
        'partner_dob' => normalizeDateIso((string)firstNonEmpty(jsonGet($json, 'partner_dob'))),
        'partner_address' => csvValue(firstNonEmpty(jsonGet($json, 'partner_address'))),
        'partner_place_of_birth' => csvValue(firstNonEmpty(jsonGet($json, 'partner_place_of_birth'))),
        'partner_education' => csvValue(firstNonEmpty(jsonGet($json, 'partner_education'))),
        'partner_currently_enrolled' => csvValue(firstNonEmpty(jsonGet($json, 'partner_currently_enrolled'))),
        'partner_year_level' => csvValue(firstNonEmpty(jsonGet($json, 'partner_year_level'))),
        'partner_currently_working' => csvValue(firstNonEmpty(jsonGet($json, 'partner_currently_working'))),
        'partner_occupation' => csvValue(firstNonEmpty(jsonGet($json, 'partner_occupation'))),
        'partner_monthly_income' => $partnerMonthlyIncomeValue,

        'gov_assistance_selected' => $govSelected,
        'gov_assistance_details' => $govDetailsValue,
        'gov_assistance_other' => csvValue(firstNonEmpty(jsonGet($json, 'gov_assistance_other'))),

        'verified_by' => csvValue(firstNonEmpty($r['verified_by'] ?? null, jsonGet($json, 'verified_by'), jsonGet($legacyJson, 'verified_by'))),
        'verified_at' => csvValue(firstNonEmpty($r['verified_at'] ?? null, jsonGet($json, 'verified_at'), jsonGet($legacyJson, 'verified_at'))),
        'adolescent_created_at' => csvValue(firstNonEmpty($r['adolescent_created_at'] ?? null, jsonGet($json, 'adolescent_created_at'))),
        'adolescent_updated_at' => csvValue(firstNonEmpty($r['adolescent_updated_at'] ?? null, jsonGet($json, 'adolescent_updated_at'))),
        'encounter_created_at' => csvValue(firstNonEmpty($r['encounter_created_at'] ?? null, jsonGet($json, 'encounter_created_at'), jsonGet($legacyJson, 'encounter_created_at'))),
        'data_json' => $forBackup ? csvValue($r['data_json'] ?? null) : '',
        'additional_details' => $forBackup && $additionalDetails
            ? (json_encode($additionalDetails, JSON_UNESCAPED_UNICODE) ?: '')
            : '',
    ];
}

function buildNormalizedRowForLegacy(array $r, bool $forBackup = false): array {
    $legacyJson = jsonDecodeArray((string)($r['data_json'] ?? ''));

    $fullName = cleanText((string)($r['name'] ?? ''));
    [$lastName, $firstName, $middleName] = splitName($fullName);

    $dob = normalizeDateIso((string)($r['date_of_birth'] ?? $r['dob'] ?? $r['birthdate'] ?? ''));

    $govDetailsRaw = firstNonEmpty(
        $r['gov_assistance_details'] ?? null,
        jsonGet($legacyJson, 'gov_assistance_details')
    );
    $govOtherRaw = firstNonEmpty(
        $r['gov_assistance_other'] ?? null,
        jsonGet($legacyJson, 'gov_assistance_other')
    );
    $govSelectedValue = includeLegacyOtherAssistance(
        normalizeProgramsToCsv($r['gov_assistance'] ?? jsonGet($legacyJson, 'gov_assistance')),
        $govOtherRaw
    );
    $govDetailsValue = normalizeGovernmentAssistanceDetails($govDetailsRaw, $govOtherRaw);

    $educationRaw = firstNonEmpty(
        $r['educational_attainment'] ?? null,
        $r['education'] ?? null,
        jsonGet($legacyJson, 'educational_attainment'),
        jsonGet($legacyJson, 'education')
    );
    $educationValue = ahdisNormalizeEducationAttainment((string)$educationRaw);
    if ($educationValue === '') {
        $educationValue = csvValue($educationRaw);
    }

    $gradeRaw = firstNonEmpty(
        $r['grade_year_level'] ?? null,
        $r['year_level'] ?? null,
        jsonGet($legacyJson, 'grade_year_level'),
        jsonGet($legacyJson, 'year_level')
    );
    $gradeValue = $educationValue === 'No Education'
        ? ''
        : (ahdisNormalizeGradeYearLevel($educationValue, (string)$gradeRaw) ?: csvValue($gradeRaw));

    $lastSchoolValue = csvValue(firstNonEmpty($r['last_school_attended_type'] ?? null, jsonGet($legacyJson, 'last_school_attended_type')));
    $reasonRaw = firstNonEmpty(
        $r['reason_not_attending_school'] ?? null,
        $r['reason_for_not_attending_school'] ?? null,
        jsonGet($legacyJson, 'reason_not_attending_school'),
        jsonGet($legacyJson, 'reason_for_not_attending_school')
    );
    $reasonValue = ahdisFormatReasonsNotAttendingSchool($reasonRaw);
    $reasonOtherValue = csvValue(firstNonEmpty($r['reason_not_attending_school_other'] ?? null, jsonGet($legacyJson, 'reason_not_attending_school_other')));
    $currentlyEnrolledValue = csvValue(firstNonEmpty(
        $r['currently_enrolled'] ?? null,
        $r['adolescent_currently_enrolled'] ?? null,
        jsonGet($legacyJson, 'currently_enrolled')
    ));
    if ($currentlyEnrolledValue === 'Yes') {
        $reasonValue = '';
        $reasonOtherValue = '';
    }
    $vocationalOtherValue = csvValue(firstNonEmpty($r['vocational_training_other'] ?? null, jsonGet($legacyJson, 'vocational_training_other')));
    if ($educationValue === 'Vocational' && $vocationalOtherValue !== '' && ($gradeValue === '' || $gradeValue === 'Other Vocational Training')) {
        $gradeValue = $vocationalOtherValue;
    }

    $partnerSurnameValue = csvValue(firstNonEmpty(
        $r['partner_surname'] ?? null,
        jsonGet($legacyJson, 'partner_surname'),
        jsonGet($legacyJson, 'partner_last_name')
    ));
    $partnerFirstNameValue = csvValue(firstNonEmpty(
        $r['partner_first_name'] ?? null,
        jsonGet($legacyJson, 'partner_first_name')
    ));
    $partnerMiddleNameValue = csvValue(firstNonEmpty(
        $r['partner_middle_name'] ?? null,
        jsonGet($legacyJson, 'partner_middle_name')
    ));
    $partnerNameValue = buildPartnerNameForExport(
        $partnerSurnameValue,
        $partnerFirstNameValue,
        $partnerMiddleNameValue,
        firstNonEmpty($r['partner_name'] ?? null, jsonGet($legacyJson, 'partner_name'))
    );

    $ageTodayValue = ageAtDate($dob, date('Y-m-d'));
    if ($ageTodayValue === null) {
        $ageTodayValue = intFromExportValue($r['age'] ?? ($legacyJson['age'] ?? ($legacyJson['imported_age'] ?? null)));
    }
    $civilStatusValue = normalizeCivilStatusExport($r['civil_status'] ?? jsonGet($legacyJson, 'civil_status'));
    $monthlyIncomeValue = normalizeIncomeRangeExport($r['monthly_income'] ?? jsonGet($legacyJson, 'monthly_income'));
    $partnerMonthlyIncomeValue = normalizeIncomeRangeExport($r['partner_monthly_income'] ?? jsonGet($legacyJson, 'partner_monthly_income'));

    return [
        'adolescent_id' => csvValue($r['adolescent_id'] ?? null),
        'full_name' => $fullName,
        'last_name' => $lastName,
        'first_name' => $firstName,
        'middle_name' => $middleName,
        'date_of_birth' => $dob,
        'age_today' => $ageTodayValue === null ? '' : (string)$ageTodayValue,
        'sex' => csvValue($r['sex'] ?? jsonGet($legacyJson, 'sex')),
        'barangay' => csvValue($r['locked_barangay'] ?? jsonGet($legacyJson, 'locked_barangay') ?? $r['barangay'] ?? ''),
        'municipality' => csvValue($r['locked_municipality'] ?? jsonGet($legacyJson, 'locked_municipality') ?? $r['municipality'] ?? ''),
        'present_address' => csvValue($r['present_address'] ?? jsonGet($legacyJson, 'present_address')),
        'place_of_birth' => csvValue($r['place_of_birth'] ?? jsonGet($legacyJson, 'place_of_birth')),

        'encounter_id' => csvValue($r['id'] ?? null),
        'encounter_date' => normalizeDateIso((string)($r['encounter_date'] ?? $r['interview_date'] ?? '')),
        'status' => csvValue($r['status'] ?? 'submitted'),
        'pregnant' => csvValue($r['pregnant'] ?? null),
        'times_pregnant' => csvValue($r['times_pregnant'] ?? null),
        'age_first_pregnancy' => csvValue($r['age_first_pregnancy'] ?? null),
        'living_children' => csvValue($r['living_children'] ?? null),
        'family_planning' => csvValue(ahdisFamilyPlanningDisplay($r['family_planning'] ?? null)),
        'programs_applied' => normalizeProgramsToCsv($r['programs_applied'] ?? ($r['gov_assistance'] ?? null)),

        'education' => $educationValue,
        'educational_attainment' => $educationValue,
        'year_level' => $gradeValue,
        'grade_year_level' => $gradeValue,
        'last_school_attended_type' => $lastSchoolValue,
        'reason_not_attending_school' => $reasonValue,
        'reason_for_not_attending_school' => $reasonValue,
        'reason_not_attending_school_other' => ahdisReasonsNotAttendingSchoolContains($reasonRaw, 'Other') ? $reasonOtherValue : '',
        'vocational_training_other' => $vocationalOtherValue,
        'currently_enrolled' => $currentlyEnrolledValue,
        'civil_status' => $civilStatusValue,
        'living_situation' => csvValue($r['living_situation'] ?? jsonGet($legacyJson, 'living_situation')),
        'living_others' => csvValue($r['living_others'] ?? jsonGet($legacyJson, 'living_others')),
        'child_under_one' => csvValue($r['child_under_one'] ?? jsonGet($legacyJson, 'child_under_one')),
        'currently_working' => csvValue($r['currently_working'] ?? jsonGet($legacyJson, 'currently_working')),
        'monthly_income' => $monthlyIncomeValue,
        'occupation' => csvValue($r['occupation'] ?? jsonGet($legacyJson, 'occupation')),
        'ethnicity' => csvValue($r['ethnic_group'] ?? $r['ethnicity'] ?? jsonGet($legacyJson, 'ethnicity')),
        'is_4ps' => csvValue($r['is_4ps'] ?? jsonGet($legacyJson, 'is_4ps')),
        'is_ips' => csvValue($r['is_ips'] ?? jsonGet($legacyJson, 'is_ips')),
        'father_occupation' => csvValue($r['father_occupation'] ?? jsonGet($legacyJson, 'father_occupation')),
        'mother_occupation' => csvValue($r['mother_occupation'] ?? jsonGet($legacyJson, 'mother_occupation')),
        'health_concerns' => csvValue($r['health_concerns'] ?? jsonGet($legacyJson, 'health_concerns')),
        'prenatal_care' => csvValue($r['prenatal_care'] ?? jsonGet($legacyJson, 'prenatal_care')),
        'months_pregnant' => csvValue($r['months_pregnant'] ?? jsonGet($legacyJson, 'months_pregnant')),
        'interview_date' => normalizeDateIso((string)firstNonEmpty(
            $r['interview_date'] ?? null,
            jsonGet($legacyJson, 'interview_date'),
            $r['encounter_date'] ?? null
        )),
        'interviewed_by' => csvValue($r['interviewed_by'] ?? jsonGet($legacyJson, 'interviewed_by')),
        'noted_by' => csvValue($r['noted_by'] ?? jsonGet($legacyJson, 'noted_by')),
        'noted_date' => normalizeDateIso((string)($r['noted_date'] ?? jsonGet($legacyJson, 'noted_date', ''))),
        'encoded_by' => csvValue(firstNonEmpty($r['encoded_by'] ?? null, jsonGet($legacyJson, 'encoded_by'))),
        'date_encoded' => normalizeDateIso((string)firstNonEmpty($r['date_encoded'] ?? null, jsonGet($legacyJson, 'date_encoded'))),
        'other_observations' => csvValue($r['other_observations'] ?? jsonGet($legacyJson, 'other_observations')),

        'partner_name' => $partnerNameValue,
        'partner_surname' => $partnerSurnameValue,
        'partner_first_name' => $partnerFirstNameValue,
        'partner_middle_name' => $partnerMiddleNameValue,
        'partner_age' => csvValue($r['partner_age'] ?? jsonGet($legacyJson, 'partner_age')),
        'partner_dob' => normalizeDateIso((string)($r['partner_dob'] ?? jsonGet($legacyJson, 'partner_dob', ''))),
        'partner_address' => csvValue($r['partner_address'] ?? jsonGet($legacyJson, 'partner_address')),
        'partner_place_of_birth' => csvValue($r['partner_place_of_birth'] ?? jsonGet($legacyJson, 'partner_place_of_birth')),
        'partner_education' => csvValue($r['partner_education'] ?? jsonGet($legacyJson, 'partner_education')),
        'partner_currently_enrolled' => csvValue($r['partner_currently_enrolled'] ?? jsonGet($legacyJson, 'partner_currently_enrolled')),
        'partner_year_level' => csvValue($r['partner_year_level'] ?? jsonGet($legacyJson, 'partner_year_level')),
        'partner_currently_working' => csvValue($r['partner_currently_working'] ?? jsonGet($legacyJson, 'partner_currently_working')),
        'partner_occupation' => csvValue($r['partner_occupation'] ?? jsonGet($legacyJson, 'partner_occupation')),
        'partner_monthly_income' => $partnerMonthlyIncomeValue,

        'gov_assistance_selected' => $govSelectedValue,
        'gov_assistance_details' => $govDetailsValue,
        'gov_assistance_other' => csvValue($r['gov_assistance_other'] ?? jsonGet($legacyJson, 'gov_assistance_other')),

        'verified_by' => csvValue(firstNonEmpty($r['verified_by'] ?? null, jsonGet($legacyJson, 'verified_by'))),
        'verified_at' => csvValue(firstNonEmpty($r['verified_at'] ?? null, jsonGet($legacyJson, 'verified_at'))),
        'adolescent_created_at' => csvValue(firstNonEmpty($r['created_at'] ?? null, jsonGet($legacyJson, 'adolescent_created_at'))),
        'adolescent_updated_at' => csvValue(firstNonEmpty(jsonGet($legacyJson, 'adolescent_updated_at'))),
        'encounter_created_at' => csvValue(firstNonEmpty($r['created_at'] ?? null, jsonGet($legacyJson, 'encounter_created_at'))),
        'data_json' => $forBackup ? csvValue($r['data_json'] ?? null) : '',
        'additional_details' => '',
    ];
}

function exportRowMatchesFilters(array $mapped, array $filters): bool {
    $name = cleanText((string)($mapped['full_name'] ?? ''));
    $dob = normalizeDateIso((string)($mapped['date_of_birth'] ?? ''));
    $latest = normalizeDateIso((string)($mapped['encounter_date'] ?? ''));
    $barangay = cleanText((string)($mapped['barangay'] ?? ''));
    $municipality = cleanText((string)($mapped['municipality'] ?? ''));

    $filterMunicipality = cleanText((string)($filters['municipality'] ?? ''));
    if ($filterMunicipality !== '' && strcasecmp($municipality, $filterMunicipality) !== 0) {
        return false;
    }

    $profileId = max(0, (int)($filters['profile_id'] ?? 0));
    if ($profileId > 0) {
        $profileKey = (string)($filters['profile_key'] ?? 'adolescent');
        $mappedId = $profileKey === 'encounter'
            ? (int)($mapped['encounter_id'] ?? 0)
            : (int)($mapped['adolescent_id'] ?? 0);
        if ($mappedId !== $profileId) return false;
    }

    $search = strtolower(cleanText((string)($filters['search'] ?? '')));
    if ($search !== '') {
        $haystack = strtolower(implode(' ', [
            cleanText((string)($mapped['adolescent_id'] ?? '')),
            $name,
            cleanText((string)($mapped['last_name'] ?? '')),
            cleanText((string)($mapped['first_name'] ?? '')),
            cleanText((string)($mapped['middle_name'] ?? '')),
            cleanText((string)($mapped['municipality'] ?? '')),
            $barangay,
            cleanText((string)($mapped['present_address'] ?? '')),
        ]));
        if (!str_contains($haystack, $search)) return false;
    }

    $prefix = strtolower(cleanText((string)($filters['name_prefix'] ?? '')));
    if ($prefix !== '' && strncmp(strtolower($name), $prefix, strlen($prefix)) !== 0) {
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

    $birthdate = normalizeDateIso((string)($filters['birthdate'] ?? ''));
    if ($birthdate !== '' && $dob !== $birthdate) {
        return false;
    }

    $filterBrgy = cleanText((string)($filters['barangay'] ?? ''));
    if ($filterBrgy !== '' && strcasecmp($barangay, $filterBrgy) !== 0) {
        return false;
    }

    $submissionMonth = cleanText((string)($filters['submission_month'] ?? ''));
    if ($submissionMonth !== '' && ($latest === '' || substr($latest, 0, 7) !== $submissionMonth)) {
        return false;
    }

    $latestFrom = cleanText((string)($filters['latest_from'] ?? ''));
    if ($latestFrom !== '' && ($latest === '' || $latest < $latestFrom)) {
        return false;
    }

    $latestTo = cleanText((string)($filters['latest_to'] ?? ''));
    if ($latestTo !== '' && ($latest === '' || $latest > $latestTo)) {
        return false;
    }

    return true;
}

function filterExportRows(array $rows, bool $useNew, array $filters, array $legacyLookup = []): array {
    return array_values(array_filter($rows, function(array $row) use ($useNew, $filters, $legacyLookup): bool {
        if ($useNew) {
            $aid = (int)($row['adolescent_id'] ?? 0);
            $mapped = buildNormalizedRowForNew($row, $legacyLookup[$aid] ?? [], false);
        } else {
            $mapped = buildNormalizedRowForLegacy($row, false);
        }
        return exportRowMatchesFilters($mapped, $filters);
    }));
}

function sortExportRows(array &$rows, bool $useNew, array $legacyLookup = [], bool $forBackup = false): void {
    usort($rows, function(array $a, array $b) use ($useNew, $legacyLookup, $forBackup): int {
        if ($useNew) {
            $aidA = (int)($a['adolescent_id'] ?? 0);
            $aidB = (int)($b['adolescent_id'] ?? 0);
            $ma = buildNormalizedRowForNew($a, $legacyLookup[$aidA] ?? [], $forBackup);
            $mb = buildNormalizedRowForNew($b, $legacyLookup[$aidB] ?? [], $forBackup);
        } else {
            $ma = buildNormalizedRowForLegacy($a, $forBackup);
            $mb = buildNormalizedRowForLegacy($b, $forBackup);
        }

        foreach (['barangay', 'last_name', 'first_name'] as $key) {
            $va = strtolower(cleanText((string)($ma[$key] ?? '')));
            $vb = strtolower(cleanText((string)($mb[$key] ?? '')));
            if ($va !== $vb) return $va <=> $vb;
        }

        $da = normalizeDateIso((string)($ma['encounter_date'] ?? ''));
        $db = normalizeDateIso((string)($mb['encounter_date'] ?? ''));
        if ($da !== $db) return $db <=> $da;

        return ((int)($b['encounter_id'] ?? 0)) <=> ((int)($a['encounter_id'] ?? 0));
    });
}

/* =========================
   Builders
========================= */
function buildXlsxRows(array $rows, array $columns, array $headerLabels, bool $useNew, array $legacyLookup = [], bool $forBackup = false): array {
    $allRows = [];

    $headerRow = [];
    foreach ($columns as $col) {
        $headerRow[] = $headerLabels[$col] ?? $col;
    }
    $allRows[] = $headerRow;

    foreach ($rows as $r) {
        if ($useNew) {
            $aid = (int)($r['adolescent_id'] ?? 0);
            $legacy = $legacyLookup[$aid] ?? [];
            $rowData = buildNormalizedRowForNew($r, $legacy, $forBackup);
        } else {
            $rowData = buildNormalizedRowForLegacy($r, $forBackup);
        }

        $line = [];
        foreach ($columns as $col) {
            $line[] = $rowData[$col] ?? '';
        }
        $allRows[] = $line;
    }

    return $allRows;
}

/* =========================
   XLSX export
========================= */
function exportAsXlsx(array $excelRows, array $schema, string $filename, string $sheetTitle): void {
    $autoload = __DIR__ . '/../vendor/autoload.php';

    if (!is_file($autoload)) {
        die('<h3>ERROR</h3><p>PhpSpreadsheet is not installed. Missing: <code>' . h($autoload) . '</code></p>');
    }

    require_once $autoload;

    if (
        !class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class) ||
        !class_exists(\PhpOffice\PhpSpreadsheet\Writer\Xlsx::class)
    ) {
        die('<h3>ERROR</h3><p>PhpSpreadsheet classes are not available. Please run Composer install again.</p>');
    }

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle(substr($sheetTitle, 0, 31));
    $columns = exportSchemaColumns($schema);

    $sheet->fromArray($excelRows, null, 'A1', true);

    $lastCol = excelColumnName(count($columns));
    $lastRow = count($excelRows);

    $sheet->freezePane('A2');
    $sheet->setAutoFilter("A1:{$lastCol}1");

    $headerRange = "A1:{$lastCol}1";
    $sheet->getStyle($headerRange)->getFont()->setBold(true);
    $sheet->getStyle($headerRange)->getAlignment()->setWrapText(true);
    $sheet->getStyle($headerRange)->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

    $dataRange = "A2:{$lastCol}{$lastRow}";
    if ($lastRow >= 2) {
        $sheet->getStyle($dataRange)->getAlignment()->setWrapText(true);
        $sheet->getStyle($dataRange)->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);
    }

    foreach ($schema as $index => $columnDefinition) {
        $format = (string)($columnDefinition['format'] ?? '');
        if ($format === '' || $lastRow < 2) continue;

        $columnName = excelColumnName($index + 1);
        for ($rowNumber = 2; $rowNumber <= $lastRow; $rowNumber++) {
            $cell = $sheet->getCell($columnName . $rowNumber);
            $raw = trim((string)$cell->getValue());
            if ($raw === '') continue;

            try {
                $dateValue = new DateTimeImmutable($raw);
                $cell->setValue(\PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($dateValue));
            } catch (Throwable $ignored) {
                continue;
            }
        }

        $numberFormat = $format === 'datetime' ? 'yyyy-mm-dd hh:mm:ss' : 'yyyy-mm-dd';
        $sheet->getStyle($columnName . '2:' . $columnName . $lastRow)
            ->getNumberFormat()
            ->setFormatCode($numberFormat);
    }

    for ($i = 1; $i <= count($columns); $i++) {
        $col = excelColumnName($i);
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
    $sheet->calculateColumnWidths();
    for ($i = 1; $i <= count($columns); $i++) {
        $col = excelColumnName($i);
        $dimension = $sheet->getColumnDimension($col);
        $calculatedWidth = (float)$dimension->getWidth();
        $dimension->setAutoSize(false);
        $dimension->setWidth(max(12.0, min(45.0, $calculatedWidth)));
    }

    $sheet->getRowDimension(1)->setRowHeight(28);
    $sheet->getDefaultRowDimension()->setRowHeight(-1);

    $spreadsheet->getProperties()
        ->setCreator((string)($_SESSION['username'] ?? 'AHDIS'))
        ->setLastModifiedBy((string)($_SESSION['username'] ?? 'AHDIS'))
        ->setTitle($sheetTitle)
        ->setSubject($sheetTitle)
        ->setDescription($sheetTitle)
        ->setCompany('AHDIS');

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header('Cache-Control: max-age=0');
    header('Cache-Control: max-age=1');
    header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
    header('Cache-Control: cache, must-revalidate');
    header('Pragma: public');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);
    exit;
}

/* =========================
   Download handling
========================= */
if (isset($_GET['download']) && (int)($_GET['download']) === 1) {
    $kind = strtolower(trim((string)($_GET['kind'] ?? 'snapshot')));
    if (!in_array($kind, ['snapshot', 'backup'], true)) {
        $kind = 'snapshot';
    }

    $timestamp = date("Y-m-d_H-i-s");

    if ($kind === 'backup') {
        $legacyLookup = [];
        $excelRows = buildXlsxRows(
            $backupRows,
            $backupColumns,
            exportSchemaHeaderLabels($backupSchema),
            $useNew,
            $legacyLookup,
            true
        );

        audit($conn, 'EXPORT_BACKUP', $useNew ? 'adolescents+encounters' : 'adolescent_mothers', null, [
            'kind' => 'backup',
            'rows' => count($backupRows),
            'source' => $source,
        ]);

        $filename = "adolescent_records_backup_{$timestamp}.xlsx";
        exportAsXlsx($excelRows, $backupSchema, $filename, 'Backup Records');
    }

    $individualMappedRow = null;
    if ($isIndividualProfileExport) {
        if (!$previewRows) {
            http_response_code(404);
            die('<h3>Profile unavailable</h3><p>The requested 20 years old and above profile was not found.</p>');
        }
        $selectedRow = $previewRows[0];
        if ($useNew) {
            $selectedAid = (int)($selectedRow['adolescent_id'] ?? 0);
            $individualMappedRow = buildNormalizedRowForNew(
                $selectedRow,
                $latestLegacySnapshotsByAid[$selectedAid] ?? [],
                false
            );
        } else {
            $individualMappedRow = buildNormalizedRowForLegacy($selectedRow, false);
        }
    }

    $excelRows = buildXlsxRows(
        $previewRows,
        $snapshotColumns,
        exportSchemaHeaderLabels($snapshotSchema),
        $useNew,
        $latestLegacySnapshotsByAid,
        false
    );

    audit($conn, $isIndividualProfileExport ? 'EXPORT_PROFILE' : 'EXPORT_SNAPSHOT', $useNew ? 'adolescents+latest_encounter' : 'adolescent_mothers', $isIndividualProfileExport ? $requestedProfileId : null, [
        'kind' => $isIndividualProfileExport ? 'individual_profile' : 'snapshot',
        'age_group' => $ageGroup,
        'profile_key' => $isIndividualProfileExport ? $requestedProfileKey : null,
        'rows' => count($previewRows),
        'source' => $source,
    ]);

    if ($isAdultExport) {
        if ($isIndividualProfileExport && is_array($individualMappedRow)) {
            $safeName = profileExportFilenameSegment(
                (string)($individualMappedRow['full_name'] ?? ''),
                $requestedProfileId
            );
            $filename = 'AHDIS_Profile_' . $safeName . '_' . date('Y-m-d') . '.xlsx';
            exportAsXlsx($excelRows, $snapshotSchema, $filename, 'Individual Profile');
        }
        $filename = 'AHDIS_20_Years_Old_and_Above_' . date('Y-m-d') . '.xlsx';
        exportAsXlsx($excelRows, $snapshotSchema, $filename, 'Profiles 20 Years and Above');
    }

    $filename = "adolescent_records_export_{$timestamp}.xlsx";
    exportAsXlsx($excelRows, $snapshotSchema, $filename, 'Latest Snapshot');
}

/* =========================
   Preview mode
========================= */
require_once __DIR__ . "/../includes/header.php";
require_once __DIR__ . "/../includes/sidebar.php";

$isDup = (isset($_GET['duplicate']) && (string)($_GET['duplicate'] ?? '') === '1');
$dupId = (int)($_GET['id'] ?? 0);
$dupReason = trim((string)($_GET['reason'] ?? ''));

$downloadSnapshotUrl = $self . "?download=1&kind=snapshot&age_group=" . rawurlencode($ageGroup);
$downloadBackupUrl = $self . "?download=1&kind=backup";
$previewAgeLabel = $isAdultExport ? '20 Years Old and Above' : 'Adolescent Mothers (9–19)';
$previewExportDescription = $isAdultExport
    ? 'Export the current 20 years old and above preview as Excel. Full backup remains available separately and includes all saved records and history.'
    : 'Export the current adolescent preview aged 9–19 as Excel, or create a complete backup that includes all saved records and history for restore purposes.';

$newPreviewCols = 10;
$legacyPreviewCols = 10;
?>

<style>
:root{
  --p2528-navy:#0f172a;
  --p2528-deep:#163a73;
  --p2528-blue:#1d4ed8;
  --p2528-bright:#2563eb;
  --p2528-pink:#d889c7;
  --p2528-rose:#cd87be;
  --p2528-text:#0f172a;
  --p2528-muted:#64748b;
  --p2528-success-bg:#ecfdf5;
  --p2528-success-text:#065f46;
  --p2528-success-border:#a7f3d0;
  --p2528-danger-bg:#fef2f2;
  --p2528-danger-text:#991b1b;
  --p2528-danger-border:#fecaca;
  --p2528-info-bg:#eff6ff;
  --p2528-info-text:#1d4ed8;
  --p2528-info-border:#bfdbfe;
}

.export-page{
  width:100%;
}

.exp-card{
  border-radius:24px;
  overflow:hidden;
  background:#ffffff;
  box-shadow:0 16px 34px rgba(15,23,42,.08);
  border:1px solid #dbe4f0;
}

.exp-hero{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:16px;
  flex-wrap:wrap;
  padding:24px 22px 22px;
  border-bottom:1px solid rgba(255,255,255,.18);
  color:#fff;
  background:linear-gradient(90deg, #0f172a 0%, #163a73 42%, #df82c6 75%, #cd87be 100%);
}

.exp-hero-row{
  width:100%;
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:16px;
  flex-wrap:wrap;
}

.exp-hero-title{
  margin:0;
  font-size:24px;
  line-height:1.15;
  font-weight:900;
  color:#ffffff;
  letter-spacing:.2px;
}

.exp-hero-subtitle{
  margin:8px 0 0;
  font-size:13px;
  color:rgba(255,255,255,.90);
  font-weight:700;
}

.hero-count-pill{
  display:inline-flex;
  align-items:center;
  gap:10px;
  padding:12px 18px;
  border-radius:999px;
  background:rgba(255,255,255,.12);
  color:#ffffff;
  border:1px solid rgba(255,255,255,.18);
  box-shadow:inset 0 1px 0 rgba(255,255,255,.08);
  font-size:13px;
  font-weight:900;
  white-space:nowrap;
}

.hero-count-pill strong{
  color:#ffffff;
  font-size:14px;
  font-weight:900;
}

.exp-body{
  padding:14px;
  background:linear-gradient(180deg,#f8fbff 0%, #f4f7fb 100%);
}

.export-stack{
  display:grid;
  gap:16px;
}

.exp-section{
  background:#fff;
  border:1px solid #dbe4f0;
  border-radius:20px;
  overflow:hidden;
  box-shadow:0 8px 18px rgba(15,23,42,.05);
}

.exp-section-head{
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:12px;
  flex-wrap:wrap;
  padding:14px 18px;
  border-bottom:1px solid #e8eef6;
  background:linear-gradient(180deg,#f9fbff 0%, #f3f8ff 100%);
}

.exp-section-title{
  margin:0;
  font-size:15px;
  font-weight:900;
  color:#163a73;
}

.export-actions-panel{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:14px;
  flex-wrap:wrap;
  padding:16px;
}

.export-actions-copy{
  flex:1 1 420px;
  max-width:780px;
  font-size:14px;
  line-height:1.6;
  color:#475569;
  font-weight:700;
}

.export-actions-buttons{
  display:flex;
  align-items:center;
  gap:10px;
  flex-wrap:wrap;
}

.alert{
  margin-bottom:14px;
  padding:14px 16px;
  border-radius:14px;
  font-weight:800;
  border:1px solid transparent;
}

.alert.ok{
  background:var(--p2528-success-bg);
  color:var(--p2528-success-text);
  border-color:var(--p2528-success-border);
}

.alert.bad{
  background:var(--p2528-danger-bg);
  color:var(--p2528-danger-text);
  border-color:var(--p2528-danger-border);
}

.alert.info{
  background:var(--p2528-info-bg);
  color:var(--p2528-info-text);
  border-color:var(--p2528-info-border);
}

.alert .row{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:14px;
  flex-wrap:wrap;
}

.alert .actions{
  display:flex;
  align-items:center;
  gap:10px;
  flex-wrap:wrap;
}

.table-shell{
  background:#fff;
  border:1px solid #dbe4f0;
  border-radius:20px;
  box-shadow:0 8px 18px rgba(15,23,42,.05);
  overflow:hidden;
}

.table-head{
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:12px;
  flex-wrap:wrap;
  padding:14px 18px;
  border-bottom:1px solid #e8eef6;
  background:linear-gradient(180deg,#f9fbff 0%, #f3f8ff 100%);
}

.table-title{
  margin:0;
  font-size:15px;
  font-weight:900;
  color:#163a73;
}

.table-head-actions{
  display:flex;
  align-items:center;
  gap:12px;
  flex-wrap:wrap;
}

.btn{
  appearance:none;
  border:1px solid #dbe4f0;
  text-decoration:none;
  cursor:pointer;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:8px;
  min-height:42px;
  padding:0 16px;
  border-radius:12px;
  font-weight:900;
  font-size:13px;
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

.btn-export{
  background:#059669;
  border-color:#059669;
  color:#ffffff;
  box-shadow:0 10px 20px rgba(5,150,105,.18);
}

.btn-export:hover{
  background:#047857;
  border-color:#047857;
  color:#ffffff;
}

.btn-backup{
  background:#1d4ed8;
  border-color:#1d4ed8;
  color:#ffffff;
  box-shadow:0 10px 20px rgba(29,78,216,.18);
}

.btn-backup:hover{
  background:#1e40af;
  border-color:#1e40af;
  color:#ffffff;
}

.btn-view{
  background:#163a73;
  border-color:#163a73;
  color:#ffffff;
  box-shadow:0 10px 20px rgba(22,58,115,.16);
  min-height:42px;
  padding:0 16px;
  font-size:13px;
}

.table-wrap{
  width:100%;
  overflow-x:auto;
  overflow-y:auto;
  max-width:100%;
  max-height:620px;
}

.preview-table{
  width:100%;
  min-width:1460px;
  border-collapse:separate;
  border-spacing:0;
  table-layout:fixed;
  font-size:13px;
}

.preview-table thead th{
  position:sticky;
  top:0;
  z-index:2;
  background:linear-gradient(180deg,#eff6ff 0%, #dbeafe 100%);
  color:#163a73;
  text-align:left;
  padding:13px 12px;
  font-weight:900;
  font-size:13px;
  border-bottom:1px solid #d7e6fb;
  white-space:normal;
  line-height:1.25;
}

.preview-table tbody td{
  padding:13px 12px;
  border-bottom:1px solid #e8eff6;
  color:#0f172a;
  vertical-align:middle;
  font-size:14px;
  line-height:1.45;
  word-wrap:break-word;
  overflow-wrap:break-word;
  white-space:normal;
  background:#fff;
}

.preview-table tbody tr:nth-child(even) td{ background:#fbfdff; }
.preview-table tbody tr:hover td{ background:#f3f8ff; }
.preview-table tbody td:last-child{ width:1%; white-space:nowrap; }

.preview-table th:nth-child(1),
.preview-table td:nth-child(1){ width:300px; }

.preview-table th:nth-child(2),
.preview-table td:nth-child(2){ width:130px; }

.preview-table th:nth-child(3),
.preview-table td:nth-child(3){ width:190px; }

.preview-table th:nth-child(4),
.preview-table td:nth-child(4){ width:140px; }

.preview-table th:nth-child(5),
.preview-table td:nth-child(5){ width:280px; }

.preview-table th:nth-child(6),
.preview-table td:nth-child(6){ width:150px; }

.preview-table th:nth-child(7),
.preview-table td:nth-child(7){ width:150px; }

.preview-table th:nth-child(8),
.preview-table td:nth-child(8){ width:130px; }

.preview-table th:nth-child(9),
.preview-table td:nth-child(9){ width:250px; }

.preview-table th:nth-child(10),
.preview-table td:nth-child(10){ width:180px; }

.preview-name{
  font-weight:900;
  color:#163a73;
  font-size:14.5px;
  line-height:1.35;
}

.preview-sub{
  color:#64748b;
  font-size:12px;
  margin-top:2px;
  font-weight:700;
}

.preview-nowrap{
  white-space:nowrap;
}

.preview-clip{
  display:block;
  overflow:hidden;
  text-overflow:ellipsis;
  white-space:nowrap;
}

.preview-clip.address{
  max-width:260px;
}

.preview-clip.programs{
  max-width:230px;
}

.preview-clip.person{
  max-width:170px;
}

.empty-state{
  padding:28px 18px;
  text-align:center;
  color:#64748b;
  font-weight:800;
  font-size:16px;
}

@media (max-width: 900px){
  .exp-hero{
    padding:18px 16px 16px;
  }

  .exp-hero-row{
    flex-direction:column;
    align-items:flex-start;
    gap:10px;
  }

  .exp-hero-title{ font-size:21px; }

  .hero-count-pill{
    align-self:flex-start;
  }

  .exp-body{ padding:14px; }

  .export-actions-panel{
    flex-direction:column;
    align-items:flex-start;
  }

  .export-actions-copy,
  .export-actions-buttons{
    width:100%;
  }

  .table-head{
    padding:14px 16px;
  }

  .table-title{ font-size:18px; }

  .btn{
    min-height:42px;
  }

  .preview-table{
    font-size:15px;
    min-width:1360px;
  }

  .preview-table thead th{
    font-size:16px;
    padding:14px 12px;
  }

  .preview-table tbody td{
    padding:16px 12px;
  }
}

@media (max-width: 640px){
  .exp-card{ border-radius:24px; }
  .exp-section{ border-radius:18px; }
  .table-shell{ border-radius:18px; }
  .table-head{ padding:12px 14px; }
  .exp-hero-title{ font-size:21px; }
  .export-actions-buttons{
    flex-direction:column;
    align-items:stretch;
  }

  .export-actions-buttons .btn{
    width:100%;
  }
}
</style>

<div class="export-page">
  <div class="exp-card">

    <div class="exp-hero">
      <div class="exp-hero-row">
        <div class="exp-hero-copy">
          <h2 class="exp-hero-title">Export Records</h2>
          <div class="exp-hero-subtitle">Review <?= $isAdultExport ? '20 years old and above' : 'adolescent' ?> records and download exports or full backups.</div>
        </div>
        <div class="hero-count-pill">
          Total <?= h($previewAgeLabel) ?>: <strong><?= (int)count($previewRows) ?></strong>
        </div>
      </div>
    </div>

    <div class="exp-body">

      <?php if(!$useNew && $legacyBefore > 0 && $legacyAfter > 0 && $legacyAfter < $legacyBefore): ?>
        <div class="alert info">
          Legacy duplicates hidden: <b><?= (int)($legacyBefore - $legacyAfter) ?></b> duplicate snapshot row(s) were removed from this preview.
        </div>
      <?php endif; ?>

      <?php if ($isDup): ?>
        <div class="alert bad">
          <div class="row">
            <div>
              <?php if ($dupReason === 'barangay_mismatch'): ?>
                Duplicate person detected (same Name + DOB) but the <b>barangay</b> is different. Save was blocked.
              <?php elseif ($dupReason === 'legacy_barangay_mismatch'): ?>
                Duplicate detected in LEGACY snapshot (same Name + DOB) but the <b>barangay</b> is different. Save was blocked.
              <?php else: ?>
                Duplicate detected. Save was blocked.
              <?php endif; ?>
            </div>

            <div class="actions">
              <?php if ($dupId > 0 && $useNew): ?>
                <a class="btn btn-view" href="<?= h($base . "/view profile/adolescent_view.php?adolescent_id=" . $dupId) ?>">View Adolescent</a>
              <?php elseif ($dupId > 0): ?>
                <a class="btn btn-view" href="<?= h($base . "/view profile/view.php?id=" . $dupId) ?>">View Record</a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <?php if(isset($_GET['saved']) && $_GET['saved'] === '1'): ?>
        <div class="alert ok">Record saved successfully.</div>
      <?php endif; ?>

      <?php if(isset($_GET['updated']) && $_GET['updated'] === '1'): ?>
        <div class="alert ok">Record updated successfully.</div>
      <?php endif; ?>

      <div class="export-stack">
        <div class="exp-section">
          <div class="exp-section-head">
            <h3 class="exp-section-title">Export Actions</h3>
          </div>

          <div class="export-actions-panel">
            <div class="export-actions-copy">
              <?= h($previewExportDescription) ?>
            </div>

            <div class="export-actions-buttons">
              <a class="btn btn-export" href="<?= h($downloadSnapshotUrl) ?>">⬇ EXPORT EXCEL</a>
              <a class="btn btn-backup" href="<?= h($downloadBackupUrl) ?>">⬇ BACKUP</a>
            </div>
          </div>
        </div>

        <div class="table-shell">
          <div class="table-head">
            <h3 class="table-title">Records Preview</h3>
          </div>

          <div class="table-wrap">
            <table class="preview-table">
              <thead>
                <?php if ($useNew || $hasLegacy): ?>
                  <tr>
                    <th>Full Name</th>
                    <th>Birthday</th>
                    <th>Municipality</th>
                    <th>Barangay</th>
                    <th>Present Address</th>
                    <th>Latest Submission</th>
                    <th>Currently Pregnant</th>
                    <th>Number of Living Children</th>
                    <th>Programs Applied</th>
                    <th>Encoded By</th>
                  </tr>
                <?php endif; ?>
              </thead>
              <tbody>
                <?php if (!$previewRows): ?>
                  <tr>
                    <td colspan="<?= $useNew ? $newPreviewCols : $legacyPreviewCols ?>" class="empty-state">No records found.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($previewRows as $row): ?>
                    <?php if ($useNew): ?>
                      <?php
                        $aid = (int)($row['adolescent_id'] ?? 0);
                        $legacy = $latestLegacySnapshotsByAid[$aid] ?? [];
                        $mapped = buildNormalizedRowForNew($row, $legacy, false);
                        $previewId = trim((string)($mapped['adolescent_id'] ?? ''));
                      ?>
                      <tr>
                        <td>
                          <div class="preview-name preview-clip" title="<?= h($mapped['full_name']) ?>"><?= h($mapped['full_name'] !== '' ? $mapped['full_name'] : '—') ?></div>
                          <div class="preview-sub">Adolescent ID: <?= h($previewId !== '' ? $previewId : '—') ?></div>
                        </td>
                        <td class="preview-nowrap"><?= h($mapped['date_of_birth'] !== '' ? $mapped['date_of_birth'] : '—') ?></td>
                        <td><div class="preview-clip" title="<?= h($mapped['municipality']) ?>"><?= h($mapped['municipality'] !== '' ? $mapped['municipality'] : '—') ?></div></td>
                        <td><div class="preview-clip" title="<?= h($mapped['barangay']) ?>"><?= h($mapped['barangay'] !== '' ? $mapped['barangay'] : '—') ?></div></td>
                        <td><div class="preview-clip address" title="<?= h($mapped['present_address']) ?>"><?= h($mapped['present_address'] !== '' ? $mapped['present_address'] : '—') ?></div></td>
                        <td class="preview-nowrap"><?= h($mapped['encounter_date'] !== '' ? $mapped['encounter_date'] : '—') ?></td>
                        <td><?= h($mapped['pregnant'] !== '' ? $mapped['pregnant'] : '—') ?></td>
                        <td><?= h($mapped['living_children'] !== '' ? $mapped['living_children'] : '—') ?></td>
                        <td><div class="preview-clip programs" title="<?= h($mapped['programs_applied']) ?>"><?= h($mapped['programs_applied'] !== '' ? $mapped['programs_applied'] : '—') ?></div></td>
                        <td><div class="preview-clip person" title="<?= h($mapped['encoded_by']) ?>"><?= h($mapped['encoded_by'] !== '' ? $mapped['encoded_by'] : '—') ?></div></td>
                      </tr>
                    <?php else: ?>
                      <?php
                        $mapped = buildNormalizedRowForLegacy($row, false);
                        $previewId = trim((string)firstNonEmpty($mapped['adolescent_id'] ?? '', $row['id'] ?? ''));
                      ?>
                      <tr>
                        <td>
                          <div class="preview-name preview-clip" title="<?= h($mapped['full_name']) ?>"><?= h($mapped['full_name'] !== '' ? $mapped['full_name'] : '—') ?></div>
                          <div class="preview-sub">Record ID: <?= h($previewId !== '' ? $previewId : '—') ?></div>
                        </td>
                        <td class="preview-nowrap"><?= h($mapped['date_of_birth'] !== '' ? $mapped['date_of_birth'] : '—') ?></td>
                        <td><div class="preview-clip" title="<?= h($mapped['municipality']) ?>"><?= h($mapped['municipality'] !== '' ? $mapped['municipality'] : '—') ?></div></td>
                        <td><div class="preview-clip" title="<?= h($mapped['barangay']) ?>"><?= h($mapped['barangay'] !== '' ? $mapped['barangay'] : '—') ?></div></td>
                        <td><div class="preview-clip address" title="<?= h($mapped['present_address']) ?>"><?= h($mapped['present_address'] !== '' ? $mapped['present_address'] : '—') ?></div></td>
                        <td class="preview-nowrap"><?= h($mapped['encounter_date'] !== '' ? $mapped['encounter_date'] : '—') ?></td>
                        <td><?= h($mapped['pregnant'] !== '' ? $mapped['pregnant'] : '—') ?></td>
                        <td><?= h($mapped['living_children'] !== '' ? $mapped['living_children'] : '—') ?></td>
                        <td><div class="preview-clip programs" title="<?= h($mapped['programs_applied']) ?>"><?= h($mapped['programs_applied'] !== '' ? $mapped['programs_applied'] : '—') ?></div></td>
                        <td><div class="preview-clip person" title="<?= h($mapped['encoded_by']) ?>"><?= h($mapped['encoded_by'] !== '' ? $mapped['encoded_by'] : '—') ?></div></td>
                      </tr>
                    <?php endif; ?>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
