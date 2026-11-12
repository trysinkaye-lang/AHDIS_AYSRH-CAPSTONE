<?php
declare(strict_types=1);

require_once __DIR__ . "/../config/auth.php";

$pageTitle = "Dashboard";
$htmlClass = "dashboard-screen";
$bodyClass = "dashboard-screen";
require_once __DIR__ . "/../includes/header.php";
require_once __DIR__ . "/../includes/sidebar.php";

/* =====================
   HARD GUARD: Ensure PDO exists
   ===================== */
if (!isset($conn) || !($conn instanceof PDO)) {
    http_response_code(500);
    die("DB not available (PDO connection missing). Check config/database.php + auth.php.");
}
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* =====================
   HELPERS
   ===================== */
if (!function_exists('dash_labels')) {
    function dash_labels(array $rows): array {
        return array_map(static fn($r) => (string)($r['label'] ?? ''), $rows);
    }
}

if (!function_exists('dash_totals')) {
    function dash_totals(array $rows): array {
        return array_map(static fn($r) => (int)($r['total'] ?? 0), $rows);
    }
}

if (!function_exists('dash_cleanDist')) {
    function dash_cleanDist(array $dist): array {
        return array_values($dist);
    }
}

if (!function_exists('dash_monthNameFromNumber')) {
    function dash_monthNameFromNumber(string $mm): string {
        static $names = [
            '01' => 'January',
            '02' => 'February',
            '03' => 'March',
            '04' => 'April',
            '05' => 'May',
            '06' => 'June',
            '07' => 'July',
            '08' => 'August',
            '09' => 'September',
            '10' => 'October',
            '11' => 'November',
            '12' => 'December'
        ];
        return $names[$mm] ?? $mm;
    }
}

if (!function_exists('dash_formatMonthLabel')) {
    function dash_formatMonthLabel(string $ym, bool $multiYear): string {
        if (!preg_match('/^(\d{4})-(\d{2})$/', $ym, $m)) {
            return $ym;
        }

        $year = $m[1];
        $mm   = $m[2];
        $name = dash_monthNameFromNumber($mm);

        return $multiYear ? ($name . " " . $year) : $name;
    }
}

if (!function_exists('dash_norm')) {
    function dash_norm($value): string {
        $s = trim((string)$value);
        $s = str_replace("\xC2\xA0", ' ', $s);
        $s = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $s) ?? $s;
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return trim($s);
    }
}

if (!function_exists('dash_norm_lower')) {
    function dash_norm_lower($value): string {
        $s = dash_norm($value);
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }
}

if (!function_exists('dash_barangay_label')) {
    function dash_barangay_label($value): string {
        $barangay = dash_norm($value);
        $normalized = dash_norm_lower($barangay);

        if ($barangay === '' || in_array($normalized, [
            'unspecified',
            'unknown',
            'n/a',
            'na',
            'not applicable',
            'none',
            'null',
            'invalid',
            '-',
            '--',
        ], true)) {
            return 'Unspecified Barangay';
        }

        return $barangay;
    }
}

if (!function_exists('dash_yes_group')) {
    function dash_yes_group($value): ?string {
        $v = dash_norm_lower($value);

        if (in_array($v, [
            'yes', 'y', '1', 'true', 'oo', 'opo',
            'enrolled', 'currently enrolled', 'currently_enrolled',
            'in school', 'in-school', 'inschool'
        ], true)) {
            return 'Enrolled';
        }

        if (in_array($v, [
            'no', 'n', '0', 'false', 'wala', 'hindi',
            'not enrolled', 'not currently enrolled', 'not_currently_enrolled',
            'out of school', 'out-of-school', 'outschool', 'not in school'
        ], true)) {
            return 'Not Enrolled';
        }

        return null;
    }
}

if (!function_exists('dash_civil_group')) {
    function dash_civil_group($value): ?string {
        $v = dash_norm_lower($value);
        $v = str_replace(['_', '/'], ' ', $v);
        $v = preg_replace('/\s+/', ' ', $v) ?? $v;
        $v = trim($v);

        if (in_array($v, ['submitted', 'draft', 'pending', 'approved', 'verified', 'validated', 'rejected'], true)) {
            return null;
        }

        if ($v === 'single') {
            return 'Single';
        }

        if ($v === 'married') {
            return 'Married';
        }

        if (in_array($v, ['living in', 'living-in', 'living_in', 'live in', 'live-in'], true)) {
            return 'Living-in';
        }

        return null;
    }
}

if (!function_exists('dash_timesPregLabel')) {
    function dash_timesPregLabel($value): ?string {
        $raw = dash_norm($value);
        if ($raw === '') {
            return null;
        }

        if (is_numeric($raw)) {
            $n = (int)$raw;
        } else {
            preg_match('/-?\d+/', $raw, $m);
            if (empty($m)) {
                return null;
            }
            $n = (int)$m[0];
        }

        if ($n <= 0) return '0';
        if ($n === 1) return '1';
        if ($n === 2) return '2';
        if ($n === 3) return '3';
        return '4+';
    }
}

if (!function_exists('dash_inc')) {
    function dash_inc(array &$map, string $label, int $by = 1): void {
        if (!isset($map[$label])) {
            $map[$label] = 0;
        }
        $map[$label] += $by;
    }
}

if (!function_exists('dash_assocToDist')) {
    function dash_assocToDist(array $assoc, ?array $preferredOrder = null): array {
        $dist = [];

        if ($preferredOrder !== null) {
            foreach ($preferredOrder as $label) {
                $dist[] = [
                    'label' => (string)$label,
                    'total' => (int)($assoc[$label] ?? 0),
                ];
                unset($assoc[$label]);
            }
        }

        if (!empty($assoc)) {
            ksort($assoc, SORT_NATURAL | SORT_FLAG_CASE);
            foreach ($assoc as $label => $total) {
                $dist[] = [
                    'label' => (string)$label,
                    'total' => (int)$total,
                ];
            }
        }

        return $dist;
    }
}

if (!function_exists('getUnifiedReportRows')) {
    function getUnifiedReportRows(PDO $conn, string $currentMunicipality = ''): array {
        return [];
    }
}

if (!function_exists('dash_dbHasColumn')) {
    function dash_dbHasColumn(PDO $conn, string $table, string $col): bool {
        try {
            $table = preg_replace('/[^A-Za-z0-9_]/', '', $table) ?: '';
            if ($table === '') return false;
            $stmt = $conn->query("PRAGMA table_info(" . $table . ")");
            foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
                if (strcasecmp((string)($row['name'] ?? ''), $col) === 0) {
                    return true;
                }
            }
        } catch (Throwable $e) {
        }
        return false;
    }
}

if (!function_exists('dash_currentUserLocation')) {
    function dash_currentUserLocation(PDO $conn): array {
        $result = [
            'province' => (string)($_SESSION['province'] ?? ''),
            'municipality' => (string)($_SESSION['municipality'] ?? ''),
            'location_locked' => 0,
        ];

        $userId = (int)($_SESSION['user_id'] ?? ($_SESSION['id'] ?? 0));
        if ($userId <= 0) return $result;

        try {
            $provinceSelect = dash_dbHasColumn($conn, 'users', 'province') ? "COALESCE(province, '') AS province" : "'' AS province";
            $municipalitySelect = dash_dbHasColumn($conn, 'users', 'municipality') ? "COALESCE(municipality, '') AS municipality" : "'' AS municipality";
            $lockedSelect = dash_dbHasColumn($conn, 'users', 'location_locked') ? "COALESCE(location_locked, 0) AS location_locked" : "0 AS location_locked";
            $stmt = $conn->prepare("SELECT {$provinceSelect}, {$municipalitySelect}, {$lockedSelect} FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            if (trim((string)($row['province'] ?? '')) !== '') $result['province'] = (string)$row['province'];
            if (trim((string)($row['municipality'] ?? '')) !== '') $result['municipality'] = (string)$row['municipality'];
            $result['location_locked'] = (int)($row['location_locked'] ?? 0);
        } catch (Throwable $e) {
        }

        return $result;
    }
}

if (!function_exists('dash_fetchDist')) {
    function dash_fetchDist(PDO $conn, string $cte, string $selectSql, array $params = []): array {
        try {
            $stmt = $conn->prepare($cte . "\n" . $selectSql);
            $stmt->execute($params);
            return array_values(array_filter(
                $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
                static fn($r): bool => (int)($r['total'] ?? 0) > 0 && trim((string)($r['label'] ?? '')) !== ''
            ));
        } catch (Throwable $e) {
            error_log('[AHDIS Dashboard] Aggregation failed: ' . $e->getMessage());
            return [];
        }
    }
}

/* =====================
   CURRENT USER LOCATION
   ===================== */
$locationInfo = function_exists('getCurrentUserLocation')
    ? getCurrentUserLocation($conn)
    : [
        'province' => '',
        'municipality' => '',
        'location_locked' => 0,
    ];

$currentMunicipality = trim((string)($locationInfo['municipality'] ?? ''));
$currentProvince     = trim((string)($locationInfo['province'] ?? ''));

/* =====================
   SOURCE OF TRUTH:
   USE REPORT DATASET
   - only ages 9-19 are returned
   - 20+ stay stored in DB but are excluded from dashboard
   ===================== */
$rows = getUnifiedReportRows($conn, $currentMunicipality);

/* =====================
   KPI
   ===================== */
$total = 0;

/* =====================
   CHART DISTRIBUTIONS
   ===================== */
$barangayCounts = [];
$ageCounts = [
    '9–14' => 0,
    '15–17' => 0,
    '18–19' => 0,
];
$civilCounts = [
    'Single'    => 0,
    'Married'   => 0,
    'Living-in' => 0,
];
$enrollCounts = [
    'Enrolled'     => 0,
    'Not Enrolled' => 0,
];
$timesPregCounts = [
    '0'  => 0,
    '1'  => 0,
    '2'  => 0,
    '3'  => 0,
    '4+' => 0,
];
$monthCounts = [];

foreach ($rows as $row) {
    $age = (int)($row['age'] ?? 0);

    if ($age >= 9 && $age <= 14) {
        $ageCounts['9–14']++;
    } elseif ($age >= 15 && $age <= 17) {
        $ageCounts['15–17']++;
    } elseif ($age >= 18 && $age <= 19) {
        $ageCounts['18–19']++;
    } else {
        continue;
    }

    $total++;
    dash_inc($barangayCounts, dash_barangay_label($row['barangay'] ?? null));

    $civil = dash_civil_group($row['civil_status'] ?? '');
    if ($civil !== null) {
        $civilCounts[$civil]++;
    }

    $enroll = dash_yes_group($row['education'] ?? '');
    if ($enroll === null) {
        $enroll = dash_yes_group($row['currently_enrolled'] ?? '');
    }
    if ($enroll !== null) {
        $enrollCounts[$enroll]++;
    }

    $timesPregLabel = dash_timesPregLabel($row['times_pregnant'] ?? '');
    if ($timesPregLabel !== null) {
        $timesPregCounts[$timesPregLabel]++;
    }

    $monthSource = dash_norm($row['encounter_date'] ?? '');
    if ($monthSource === '') {
        $monthSource = dash_norm($row['encounter_created_at'] ?? '');
    }
    if ($monthSource === '') {
        $monthSource = dash_norm($row['adolescent_created_at'] ?? '');
    }

    if ($monthSource !== '') {
        $ts = strtotime($monthSource);
        if ($ts !== false) {
            $ym = date('Y-m', $ts);
            dash_inc($monthCounts, $ym);
        }
    }
}

/* Barangay dist: every barangay shown individually, sorted by total. */
$barangayDist = [];
foreach ($barangayCounts as $label => $count) {
    $barangayDist[] = ['label' => $label, 'total' => (int)$count];
}
usort($barangayDist, static function (array $a, array $b): int {
    $byTotal = ((int)($b['total'] ?? 0)) <=> ((int)($a['total'] ?? 0));
    if ($byTotal !== 0) {
        return $byTotal;
    }
    return strnatcasecmp((string)($a['label'] ?? ''), (string)($b['label'] ?? ''));
});
$barangayDist = dash_cleanDist($barangayDist);

/* Age dist */
$ageDist = dash_assocToDist($ageCounts, ['9–14', '15–17', '18–19']);
$ageDist = array_values(array_filter(
    $ageDist,
    static fn($r) => (int)($r['total'] ?? 0) > 0
));
$ageDist = dash_cleanDist($ageDist);

/* Civil dist */
$civilDist = array_values(array_filter(
    dash_assocToDist($civilCounts, ['Single', 'Married', 'Living-in']),
    static fn($r) => (int)($r['total'] ?? 0) > 0
));
$civilDist = dash_cleanDist($civilDist);

/* Enrollment dist */
$enrollDist = array_values(array_filter(
    dash_assocToDist($enrollCounts, ['Enrolled', 'Not Enrolled']),
    static fn($r) => (int)($r['total'] ?? 0) > 0
));
$enrollDist = dash_cleanDist($enrollDist);

/* Times pregnant dist */
$timesPregDist = array_values(array_filter(
    dash_assocToDist($timesPregCounts, ['0', '1', '2', '3', '4+']),
    static fn($r) => (int)($r['total'] ?? 0) > 0
));
$timesPregDist = dash_cleanDist($timesPregDist);

/* Monthly dist */
ksort($monthCounts, SORT_STRING);
$multiYear = count(array_unique(array_map(
    static fn($ym) => substr((string)$ym, 0, 4),
    array_keys($monthCounts)
))) > 1;

$monthDist = [];
foreach ($monthCounts as $ym => $count) {
    $monthDist[] = [
        'label' => dash_formatMonthLabel((string)$ym, $multiYear),
        'total' => (int)$count,
    ];
}
$monthDist = dash_cleanDist($monthDist);

/* =====================
   SQL AGGREGATED DATASET
   - keeps 20+ records stored, but excludes them from dashboard counts
   - does not fetch full profile rows into PHP
   ===================== */
$locationInfo = dash_currentUserLocation($conn);
$currentMunicipality = trim((string)($locationInfo['municipality'] ?? ''));
$currentProvince = trim((string)($locationInfo['province'] ?? ''));

$dashboardParams = [];
$municipalityWhere = '';
if ($currentMunicipality !== '') {
    $municipalityWhere = "AND LOWER(TRIM(COALESCE(a.municipality,''))) = LOWER(TRIM(:municipality))";
    $dashboardParams[':municipality'] = $currentMunicipality;
}

$dashboardCte = "
WITH latest AS (
    SELECT
        a.id AS adolescent_id,
        COALESCE(NULLIF(TRIM(a.barangay),''), 'Unspecified Barangay') AS barangay,
        CAST(
            strftime('%Y','now','localtime') - strftime('%Y', a.date_of_birth)
            - (strftime('%m-%d','now','localtime') < strftime('%m-%d', a.date_of_birth))
            AS INTEGER
        ) AS age,
        e.encounter_date,
        e.created_at AS encounter_created_at,
        a.created_at AS adolescent_created_at,
        e.times_pregnant,
        COALESCE(
            NULLIF(TRIM(e.currently_enrolled),''),
            CASE WHEN json_valid(e.data_json) THEN NULLIF(TRIM(json_extract(e.data_json, '$.currently_enrolled')), '') END,
            CASE WHEN json_valid(e.data_json) THEN NULLIF(TRIM(json_extract(e.data_json, '$.is_enrolled')), '') END
        ) AS enrolled_value,
        CASE WHEN json_valid(e.data_json) THEN NULLIF(TRIM(json_extract(e.data_json, '$.civil_status')), '') END AS civil_value
    FROM adolescents a
    INNER JOIN encounters e
        ON e.id = (
            SELECT e2.id
            FROM encounters e2
            WHERE e2.adolescent_id = a.id
            ORDER BY
                COALESCE(NULLIF(TRIM(e2.encounter_date),''),'0000-00-00') DESC,
                COALESCE(NULLIF(TRIM(e2.created_at),''),'0000-00-00 00:00:00') DESC,
                e2.id DESC
            LIMIT 1
        )
    WHERE TRIM(COALESCE(a.date_of_birth,'')) <> ''
      AND CAST(
            strftime('%Y','now','localtime') - strftime('%Y', a.date_of_birth)
            - (strftime('%m-%d','now','localtime') < strftime('%m-%d', a.date_of_birth))
            AS INTEGER
        ) BETWEEN 9 AND 19
      $municipalityWhere
)
";

try {
    $stmtTotal = $conn->prepare($dashboardCte . "\nSELECT COUNT(1) FROM latest");
    $stmtTotal->execute($dashboardParams);
    $total = (int)$stmtTotal->fetchColumn();
} catch (Throwable $e) {
    $total = 0;
    error_log('[AHDIS Dashboard] Total aggregation failed: ' . $e->getMessage());
}

$barangayDist = dash_fetchDist($conn, $dashboardCte, "
    SELECT barangay AS label, COUNT(1) AS total
    FROM latest
    GROUP BY barangay
    ORDER BY total DESC, label COLLATE NOCASE ASC
", $dashboardParams);

$ageDist = dash_fetchDist($conn, $dashboardCte, "
    SELECT
        CASE
            WHEN age BETWEEN 9 AND 14 THEN '9-14'
            WHEN age BETWEEN 15 AND 17 THEN '15-17'
            WHEN age BETWEEN 18 AND 19 THEN '18-19'
        END AS label,
        COUNT(1) AS total
    FROM latest
    GROUP BY label
    ORDER BY MIN(age) ASC
", $dashboardParams);

$civilDist = dash_fetchDist($conn, $dashboardCte, "
    SELECT
        CASE
            WHEN LOWER(REPLACE(civil_value, '_', ' ')) = 'single' THEN 'Single'
            WHEN LOWER(REPLACE(civil_value, '_', ' ')) = 'married' THEN 'Married'
            WHEN LOWER(REPLACE(civil_value, '_', ' ')) IN ('living in', 'living-in', 'live in', 'live-in') THEN 'Living-in'
        END AS label,
        COUNT(1) AS total
    FROM latest
    GROUP BY label
    ORDER BY CASE label WHEN 'Single' THEN 1 WHEN 'Married' THEN 2 WHEN 'Living-in' THEN 3 ELSE 99 END
", $dashboardParams);

$enrollDist = dash_fetchDist($conn, $dashboardCte, "
    SELECT
        CASE
            WHEN LOWER(enrolled_value) IN ('yes','y','1','true','oo','opo','enrolled','currently enrolled','currently_enrolled','in school','in-school','inschool') THEN 'Enrolled'
            WHEN LOWER(enrolled_value) IN ('no','n','0','false','wala','hindi','not enrolled','not currently enrolled','not_currently_enrolled','out of school','out-of-school','outschool','not in school') THEN 'Not Enrolled'
        END AS label,
        COUNT(1) AS total
    FROM latest
    GROUP BY label
    ORDER BY CASE label WHEN 'Enrolled' THEN 1 WHEN 'Not Enrolled' THEN 2 ELSE 99 END
", $dashboardParams);

$timesPregDist = dash_fetchDist($conn, $dashboardCte, "
    SELECT
        CASE
            WHEN CAST(COALESCE(times_pregnant, 0) AS INTEGER) <= 0 THEN '0'
            WHEN CAST(times_pregnant AS INTEGER) = 1 THEN '1'
            WHEN CAST(times_pregnant AS INTEGER) = 2 THEN '2'
            WHEN CAST(times_pregnant AS INTEGER) = 3 THEN '3'
            WHEN CAST(times_pregnant AS INTEGER) >= 4 THEN '4+'
        END AS label,
        COUNT(1) AS total
    FROM latest
    GROUP BY label
    ORDER BY CASE label WHEN '0' THEN 1 WHEN '1' THEN 2 WHEN '2' THEN 3 WHEN '3' THEN 4 WHEN '4+' THEN 5 ELSE 99 END
", $dashboardParams);

$monthRows = dash_fetchDist($conn, $dashboardCte, "
    SELECT SUBSTR(COALESCE(NULLIF(TRIM(encounter_date),''), NULLIF(TRIM(encounter_created_at),''), adolescent_created_at), 1, 7) AS label, COUNT(1) AS total
    FROM latest
    GROUP BY label
    ORDER BY label ASC
", $dashboardParams);
$multiYear = count(array_unique(array_map(
    static fn($row) => substr((string)($row['label'] ?? ''), 0, 4),
    $monthRows
))) > 1;
$monthDist = array_map(static function(array $row) use ($multiYear): array {
    return [
        'label' => dash_formatMonthLabel((string)($row['label'] ?? ''), $multiYear),
        'total' => (int)($row['total'] ?? 0),
    ];
}, $monthRows);

/* =====================
   SEND TO JS
   ===================== */
$js = [
    'barangayDist'   => ['labels' => dash_labels($barangayDist),   'data' => dash_totals($barangayDist)],
    'ageDist'        => ['labels' => dash_labels($ageDist),        'data' => dash_totals($ageDist)],
    'civilDist'      => ['labels' => dash_labels($civilDist),      'data' => dash_totals($civilDist)],
    'enrollDist'     => ['labels' => dash_labels($enrollDist),     'data' => dash_totals($enrollDist)],
    'timesPregDist'  => ['labels' => dash_labels($timesPregDist),  'data' => dash_totals($timesPregDist)],
    'monthDist'      => ['labels' => dash_labels($monthDist),      'data' => dash_totals($monthDist)],
];
?>

<style>
:root{
    --p2528-navy:#08152f;
    --p2528-deep:#163a73;
    --p2528-blue:#2948a8;
    --p2528-royal:#3959bb;
    --p2528-purple:#7c3aed;
    --p2528-pink:#e481c1;
    --p2528-pink-deep:#d96bb2;
    --p2528-soft:#f4f7fc;
    --p2528-card:#ffffff;
    --p2528-border:#dbe5f2;
    --p2528-text:#10213f;
    --p2528-muted:#66768f;
    --p2528-shadow:0 16px 36px rgba(13, 25, 55, .08);
    --p2528-shadow-soft:0 10px 24px rgba(13, 25, 55, .06);
}

html,
body{
    height:100%;
    min-height:100%;
    overflow-x:hidden;
    overflow-y:hidden;
    background:#eef3f9;
}

body{
    color:var(--p2528-text);
}

.app-main{
    min-height:100vh;
    height:100vh;
    overflow-x:hidden;
    overflow-y:hidden;
    padding:18px 18px 0 !important;
}

.page-content{
    width:100%;
    max-width:none;
    height:100%;
    min-height:0;
    overflow:hidden;
    display:flex;
    flex-direction:column;
}

.reports-page{
    width:100%;
    max-width:none;
    flex:1 1 auto;
    height:100%;
    min-height:0;
    margin:0;
    padding:0;
    overflow:hidden;
}

.reports-shell{
    height:100%;
    min-height:0;
    display:flex;
    flex-direction:column;
    border:1px solid var(--p2528-border);
    border-radius:28px;
    overflow:hidden;
    background:linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
    box-shadow:0 24px 50px rgba(15,23,42,.08);
}

.reports-hero{
    position:relative;
    padding:18px 22px;
    min-height:50px;
    color:#fff;
    display:flex;
    align-items:center;
    background:
        radial-gradient(circle at top right, rgba(255,255,255,.16), transparent 26%),
        linear-gradient(135deg, #0f172a 0%, #163a73 42%, #df82c6 75%, #cd87be 100%);
}

.reports-hero::after{
    content:"";
    position:absolute;
    inset:auto 0 0 0;
    height:1px;
    background:rgba(255,255,255,.18);
}

.reports-hero > *{
    position:relative;
    z-index:1;
}

.reports-hero-row{
    width:100%;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
}

.reports-hero-main{
    display:flex;
    flex-direction:column;
    gap:0;
    min-width:0;
}

.reports-eyebrow{
    margin:0 0 4px;
    font-size:11px;
    letter-spacing:.18em;
    text-transform:uppercase;
    font-weight:800;
    opacity:.86;
}

.reports-hero h1{
    margin:0;
    font-weight:900;
    font-size:25px;
    line-height:1.2;
    letter-spacing:0;
    text-shadow:0 1px 1px rgba(0,0,0,.14);
}

.reports-subtitle{
    margin:4px 0 0;
    font-size:12px;
    font-weight:700;
    color:rgba(255,255,255,.88);
}

.reports-kpi{
    background:rgba(255,255,255,.18);
    border:1px solid rgba(255,255,255,.30);
    padding:8px 12px;
    border-radius:999px;
    font-weight:900;
    font-size:12px;
    color:#fff;
    display:inline-flex;
    align-items:center;
    gap:8px;
    white-space:nowrap;
    box-shadow:0 8px 20px rgba(0,0,0,.12);
    backdrop-filter:blur(6px);
}

.reports-kpi strong{
    font-size:12px;
    font-weight:900;
    line-height:1;
    color:#fff;
}

.reports-body{
    flex:1 1 auto;
    min-height:0;
    padding:14px;
    overflow:hidden;
    background:
        radial-gradient(circle at top right, rgba(57,89,187,.04), transparent 18%),
        linear-gradient(180deg, #f7fafe 0%, #f1f5fb 100%);
    display:flex;
    flex-direction:column;
}

.dashboard-board{
    flex:1 1 auto;
    min-height:0;
    display:flex;
    flex-direction:column;
    border:1px solid var(--p2528-border);
    border-radius:22px;
    background:rgba(255,255,255,.88);
    backdrop-filter:blur(10px);
    box-shadow:var(--p2528-shadow-soft);
    overflow:hidden;
}

.dashboard-board-head{
    padding:12px 18px;
    border-bottom:1px solid #e8eef6;
    background:linear-gradient(180deg, rgba(255,255,255,.92) 0%, rgba(244,248,253,.96) 100%);
}

.dashboard-board-head h2{
    margin:0;
    font-size:18px;
    font-weight:900;
    color:#143978;
    letter-spacing:-.01em;
}

.dashboard-board-head p{
    margin:4px 0 0;
    font-size:12px;
    font-weight:700;
    color:var(--p2528-muted);
}

.dashboard-board-body{
    flex:1 1 auto;
    min-height:0;
    padding:10px;
    overflow:hidden;
}

.dashboard-grid{
    height:100%;
    display:grid;
    grid-template-columns:repeat(3, minmax(260px, 1fr));
    grid-template-rows:repeat(2, minmax(0, 1fr));
    gap:10px;
}

.dashboard-chart-card{
    height:100%;
    min-height:0;
    display:flex;
    flex-direction:column;
    padding:9px 10px 8px;
    border:1px solid var(--p2528-border);
    border-radius:18px;
    background:
        linear-gradient(180deg, rgba(255,255,255,1) 0%, rgba(250,252,255,1) 100%);
    box-shadow:0 8px 18px rgba(15,23,42,.045);
    cursor:pointer;
    user-select:none;
    transition:
        box-shadow .16s ease,
        border-color .16s ease,
        background-color .16s ease;
    overflow:hidden;
}

.footer-bleed{
    display:none !important;
}

.dashboard-chart-card:hover{
    border-color:rgba(41,72,168,.22);
    box-shadow:0 16px 30px rgba(20,57,120,.10);
    background:
        linear-gradient(180deg, rgba(255,255,255,1) 0%, rgba(245,249,255,1) 100%);
}

.dashboard-chart-head{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:8px;
    margin-bottom:6px;
}

.dashboard-chart-title{
    margin:0;
    font-size:12px;
    line-height:1.24;
    font-weight:900;
    color:#163a73;
    letter-spacing:.1px;
}

.dashboard-expand{
    flex:0 0 auto;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:5px 10px;
    border-radius:999px;
    border:1px solid rgba(41,72,168,.14);
    color:#2951c4;
    background:linear-gradient(180deg, #f5f9ff 0%, #e9f1ff 100%);
    font-size:10px;
    font-weight:900;
    white-space:nowrap;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.8);
}

.dashboard-canvas{
    flex:1 1 auto;
    min-height:160px;
    position:relative;
    overflow:hidden;
}

.dashboard-chart-frame{
    position:relative;
    width:100%;
    height:100%;
    min-height:100%;
}

.dashboard-chart-frame.has-empty-state{
    display:grid;
    place-items:center;
    min-height:220px;
}

.dashboard-chart-frame.has-empty-state canvas{
    display:none !important;
}

.dashboard-empty-state{
    width:100%;
    min-height:140px;
    display:grid;
    place-items:center;
    text-align:center;
    color:#66768f;
    font-size:13px;
    font-weight:900;
}

.dashboard-chart-card.is-empty{
    cursor:default;
}

.dashboard-chart-card.is-empty:hover{
    border-color:var(--p2528-border);
    box-shadow:0 8px 18px rgba(15,23,42,.045);
    background:linear-gradient(180deg, rgba(255,255,255,1) 0%, rgba(250,252,255,1) 100%);
}

.dashboard-chart-card.is-empty .dashboard-expand{
    visibility:hidden;
}

.dashboard-chart-card[data-chart="barangayBar"] .dashboard-canvas{
    overflow-y:auto;
    overflow-x:hidden;
    padding-right:2px;
}

.dashboard-canvas canvas{
    width:100% !important;
    height:100% !important;
    display:block;
}

.ds-modal{
    position:fixed;
    inset:0;
    background:rgba(9,16,34,.58);
    backdrop-filter:blur(5px);
    display:none;
    align-items:center;
    justify-content:center;
    z-index:9999;
    padding:18px;
}

.ds-modal.show{
    display:flex;
}

.ds-modal-box{
    width:min(980px, 96vw);
    height:min(680px, 90vh);
    background:#fff;
    border-radius:24px;
    box-shadow:0 32px 90px rgba(0,0,0,.30);
    border:1px solid rgba(30,58,138,.10);
    overflow:hidden;
    display:flex;
    flex-direction:column;
    opacity:0;
    transition:opacity .12s ease;
}

.ds-modal.show .ds-modal-box{
    opacity:1;
}

.ds-modal-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:13px 15px;
    border-bottom:1px solid rgba(30,58,138,.08);
    background:linear-gradient(180deg, #fff7fc 0%, #ffffff 100%);
}

.ds-modal-title{
    font-weight:900;
    font-size:14px;
    color:#163a73;
}

.ds-modal-close{
    border:1px solid rgba(41,72,168,.14);
    background:#edf4ff;
    border-radius:12px;
    padding:7px 12px;
    cursor:pointer;
    font-weight:900;
    color:#2951c4;
}

.ds-modal-close:hover{
    background:#e5efff;
}

.ds-modal-insights{
    padding:10px 12px;
    border-bottom:1px solid rgba(30,58,138,.08);
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    align-items:center;
    background:#fcfdff;
}

.ds-chip{
    display:inline-flex;
    gap:8px;
    align-items:center;
    padding:7px 11px;
    border-radius:999px;
    border:1px solid rgba(30,58,138,.12);
    background:rgba(255,255,255,.96);
    font-size:12px;
    font-weight:800;
    color:#0f172a;
}

.ds-chip small{
    font-size:11px;
    font-weight:800;
    color:#64748b;
}

.ds-chip strong{
    font-weight:900;
    color:#0f172a;
}

.ds-chip.good{
    border-color:rgba(41,72,168,.16);
    background:#eef4ff;
}

.ds-chip.bad{
    border-color:rgba(228,129,193,.18);
    background:#fff2fa;
}

.ds-modal-body{
    flex:1;
    padding:12px;
    min-height:0;
    overflow:auto;
}

.ds-modal-chart-frame{
    position:relative;
    width:100%;
    height:100%;
    min-height:100%;
}

.ds-modal-body canvas{
    width:100% !important;
    height:100% !important;
    min-height:100%;
    display:block;
}

@media (min-width:1201px){
    .dashboard-board-head{
        padding:11px 17px;
    }

    .dashboard-board-head h2{
        font-size:17px;
    }

    .dashboard-board-head p{
        font-size:11px;
    }

    .dashboard-chart-card{
        padding:8px 9px 7px;
    }

    .dashboard-chart-title{
        font-size:11px;
    }

    .dashboard-expand{
        font-size:9px;
        padding:4px 9px;
    }
}

@media (max-width:1000px){
    html,
    body{
        overflow:auto;
    }

    .app-main{
        height:auto !important;
        overflow:auto !important;
    }

    .page-content{
        height:auto;
        overflow:visible;
    }

    .reports-page{
        height:auto;
        overflow:visible;
    }

    .reports-shell{
        height:auto;
        display:block;
    }

    .reports-body{
        overflow:visible;
        display:block;
    }

    .dashboard-board{
        display:block;
    }

    .dashboard-board-body{
        overflow:visible;
    }

    .dashboard-grid{
        height:auto;
        grid-template-columns:repeat(2, minmax(0,1fr));
        grid-template-rows:none;
    }

    .dashboard-chart-card{
        min-height:280px;
    }
}

@media (max-width:700px){
    .reports-hero{
        padding:18px 18px;
        min-height:auto;
    }

    .reports-hero-row{
        flex-direction:column;
        align-items:flex-start;
    }

    .reports-hero h1{
        font-size:18px;
    }

    .reports-subtitle{
        font-size:11px;
    }

    .reports-body{
        padding:10px;
    }

    .dashboard-grid{
        grid-template-columns:1fr;
    }

    .dashboard-chart-card{
        min-height:300px;
    }

    .dashboard-chart-head{
        flex-wrap:wrap;
    }
}
</style>

<div class="reports-page dashboard-page">
    <div class="reports-shell">

        <div class="reports-hero">
            <div class="reports-hero-row">
                <div class="reports-hero-main">
                    <h1>Dashboard</h1>
                </div>

                <div class="reports-kpi">
                    Total Adolescents (9–19):
                    <strong><?= (int)$total ?></strong>
                </div>
            </div>
        </div>

        <div class="reports-body">
            <div class="dashboard-board">
                <div class="dashboard-board-head">
                    <h2>Core Insights</h2>
                    <p>Click any chart to expand and view details.</p>
                </div>

                <div class="dashboard-board-body">
                    <div class="dashboard-grid">
                        <div class="dashboard-chart-card" data-chart="barangayBar" data-title="Statistics per Barangay">
                            <div class="dashboard-chart-head">
                                <h3 class="dashboard-chart-title">Total per Barangay</h3>
                                <div class="dashboard-expand">Expand</div>
                            </div>
                            <div class="dashboard-canvas">
                                <div class="dashboard-chart-frame">
                                    <canvas id="barangayBar"></canvas>
                                </div>
                            </div>
                        </div>

                        <div class="dashboard-chart-card" data-chart="ageDist" data-title="Age Distribution (9–19)">
                            <div class="dashboard-chart-head">
                                <h3 class="dashboard-chart-title">Age Distribution</h3>
                                <div class="dashboard-expand">Expand</div>
                            </div>
                            <div class="dashboard-canvas">
                                <div class="dashboard-chart-frame">
                                    <canvas id="ageDist"></canvas>
                                </div>
                            </div>
                        </div>

                        <div class="dashboard-chart-card" data-chart="civilDonut" data-title="Civil Status Distribution">
                            <div class="dashboard-chart-head">
                                <h3 class="dashboard-chart-title">Civil Status</h3>
                                <div class="dashboard-expand">Expand</div>
                            </div>
                            <div class="dashboard-canvas">
                                <div class="dashboard-chart-frame">
                                    <canvas id="civilDonut"></canvas>
                                </div>
                            </div>
                        </div>

                        <div class="dashboard-chart-card" data-chart="enrollDonut" data-title="Enrollment Status">
                            <div class="dashboard-chart-head">
                                <h3 class="dashboard-chart-title">Enrollment Status</h3>
                                <div class="dashboard-expand">Expand</div>
                            </div>
                            <div class="dashboard-canvas">
                                <div class="dashboard-chart-frame">
                                    <canvas id="enrollDonut"></canvas>
                                </div>
                            </div>
                        </div>

                        <div class="dashboard-chart-card" data-chart="timesPregBarH" data-title="Pregnancy Counts (Times Pregnant)">
                            <div class="dashboard-chart-head">
                                <h3 class="dashboard-chart-title">Times Pregnant</h3>
                                <div class="dashboard-expand">Expand</div>
                            </div>
                            <div class="dashboard-canvas">
                                <div class="dashboard-chart-frame">
                                    <canvas id="timesPregBarH"></canvas>
                                </div>
                            </div>
                        </div>

                        <div class="dashboard-chart-card" data-chart="monthInterviewBar" data-title="Monthly Interview Trends">
                            <div class="dashboard-chart-head">
                                <h3 class="dashboard-chart-title">Monthly Adolescent Count</h3>
                                <div class="dashboard-expand">Expand</div>
                            </div>
                            <div class="dashboard-canvas">
                                <div class="dashboard-chart-frame">
                                    <canvas id="monthInterviewBar"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<div class="ds-modal" id="dsModal" aria-hidden="true">
    <div class="ds-modal-box" role="dialog" aria-modal="true">
        <div class="ds-modal-head">
            <div class="ds-modal-title" id="dsModalTitle">Chart</div>
            <button class="ds-modal-close" id="dsModalClose" type="button">Close</button>
        </div>
        <div class="ds-modal-insights" id="dsModalInsights"></div>
        <div class="ds-modal-body">
            <div class="ds-modal-chart-frame">
                <canvas id="dsModalCanvas"></canvas>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    if (typeof Chart === 'undefined') {
        console.error('Chart.js is not loaded. Check includes/header.php');
        return;
    }

    const DATA = <?= json_encode($js, JSON_UNESCAPED_UNICODE) ?>;

    const BRAND = '#2948a8';
    const DEEP = '#143978';
    const PURPLE = '#7c3aed';
    const ACCENT = '#e481c1';
    const THIRD = '#70AD47';
    const ORANGE = '#ED7D31';
    const LIGHT_BLUE = '#5B9BD5';
    const GRAY = '#A5A5A5';

    function hexToRgb(hex){
        const h = String(hex).replace('#','').trim();
        if (h.length !== 6) return {r:41,g:72,b:168};
        return {
            r: parseInt(h.slice(0,2),16),
            g: parseInt(h.slice(2,4),16),
            b: parseInt(h.slice(4,6),16)
        };
    }

    function rgba(hex, a){
        const {r,g,b} = hexToRgb(hex);
        return `rgba(${r},${g},${b},${a})`;
    }

    function buildPalette(length, preferredColors = []){
        const fallback = [
            BRAND,
            ACCENT,
            THIRD,
            ORANGE,
            LIGHT_BLUE,
            GRAY
        ];

        const source = preferredColors.length ? preferredColors : fallback;
        const result = [];

        for (let i = 0; i < length; i++) {
            result.push(rgba(source[i % source.length], 0.92));
        }

        return result;
    }

    function parseRgb(str){
        if (!str) return {r:15,g:23,b:42,a:1};
        const s = String(str).trim();
        const m1 = s.match(/^rgba?\(\s*([0-9.]+)\s*,\s*([0-9.]+)\s*,\s*([0-9.]+)\s*(?:,\s*([0-9.]+)\s*)?\)$/i);
        if (m1) return { r:+m1[1], g:+m1[2], b:+m1[3], a:(m1[4]!==undefined?+m1[4]:1) };
        const m2 = s.match(/^#([0-9a-f]{6})$/i);
        if (m2){
            const h = m2[1];
            return {
                r:parseInt(h.slice(0,2),16),
                g:parseInt(h.slice(2,4),16),
                b:parseInt(h.slice(4,6),16),
                a:1
            };
        }
        return {r:15,g:23,b:42,a:1};
    }

    function relLuminance(obj){
        const {r,g,b} = obj;
        const srgb = [r,g,b].map(v=>{
            v = v/255;
            return v <= 0.03928 ? v/12.92 : Math.pow((v+0.055)/1.055, 2.4);
        });
        return 0.2126*srgb[0] + 0.7152*srgb[1] + 0.0722*srgb[2];
    }

    function smartLabelColor(ctx){
        const ds = ctx.dataset || {};
        let bg = ds.backgroundColor;
        if (Array.isArray(bg)) bg = bg[ctx.dataIndex];
        const rgb = parseRgb(bg);
        const lum = relLuminance(rgb);
        return lum < 0.46 ? '#ffffff' : '#0b1220';
    }

    const GRID = rgba(BRAND, 0.10);
    const TICK = 'rgba(11,18,32,0.90)';

    const INT_TICKS = {
        precision: 0,
        callback: (v) => (Number.isInteger(v) ? v : '')
    };

    function barangayCanvasHeight(labels, expanded){
        const count = Array.isArray(labels) ? labels.length : 0;
        if (count <= 0) {
            return expanded ? 420 : 220;
        }

        const rowHeight = expanded ? 34 : 28;
        const basePadding = expanded ? 92 : 58;
        const minHeight = expanded ? 420 : 220;
        return Math.max(minHeight, (count * rowHeight) + basePadding);
    }

    function sizeBarangayCanvas(canvas, labels, expanded){
        if (!canvas) return;
        const frame = canvas.parentElement;
        if (!frame) return;
        frame.style.setProperty('height', `${barangayCanvasHeight(labels, expanded)}px`, 'important');
    }

    function resetCanvasSizing(canvas){
        if (!canvas) return;
        canvas.style.removeProperty('height');
        const frame = canvas.parentElement;
        if (frame) {
            frame.style.removeProperty('height');
        }
    }

    function ensureEmptyState(canvas){
        const frame = canvas?.parentElement;
        if (!frame) return null;

        let emptyState = frame.querySelector('.dashboard-empty-state');
        if (!emptyState) {
            emptyState = document.createElement('div');
            emptyState.className = 'dashboard-empty-state';
            emptyState.textContent = 'No data available';
            emptyState.hidden = true;
            frame.appendChild(emptyState);
        }

        return emptyState;
    }

    function setEmptyState(canvas, show){
        if (!canvas) return;

        const frame = canvas.parentElement;
        const card = canvas.closest('.dashboard-chart-card');
        const emptyState = ensureEmptyState(canvas);

        if (show) {
            canvas.hidden = true;
            frame?.classList.add('has-empty-state');
            card?.classList.add('is-empty');
            card?.setAttribute('aria-disabled', 'true');
            if (emptyState) emptyState.hidden = false;
            resetCanvasSizing(canvas);
        } else {
            canvas.hidden = false;
            frame?.classList.remove('has-empty-state');
            card?.classList.remove('is-empty');
            card?.removeAttribute('aria-disabled');
            if (emptyState) emptyState.hidden = true;
        }
    }

    function sanitizeChartConfigData(config){
        const labels = Array.isArray(config.data?.labels) ? config.data.labels : [];
        const datasets = Array.isArray(config.data?.datasets) ? config.data.datasets : [];
        const keepIndexes = [];

        labels.forEach((rawLabel, index) => {
            const label = String(rawLabel ?? '').trim();
            if (label === '' || label.toLowerCase() === 'no data') {
                return;
            }

            const indexTotal = datasets.reduce((sum, ds) => {
                const value = Number(Array.isArray(ds.data) ? ds.data[index] : 0);
                return Number.isFinite(value) ? sum + value : sum;
            }, 0);

            if (indexTotal > 0) {
                keepIndexes.push(index);
            }
        });

        const sanitizedLabels = keepIndexes.map(index => labels[index]);
        const sanitizedDatasets = datasets.map(ds => ({
            ...ds,
            data: keepIndexes.map(index => {
                const value = Number(Array.isArray(ds.data) ? ds.data[index] : 0);
                return Number.isFinite(value) ? value : 0;
            })
        }));
        const total = sanitizedDatasets.reduce((sum, ds) => {
            return sum + (Array.isArray(ds.data) ? ds.data.reduce((a, v) => a + v, 0) : 0);
        }, 0);

        config.data = config.data || {};
        config.data.labels = sanitizedLabels;
        config.data.datasets = sanitizedDatasets;

        return total;
    }

    Chart.defaults.font.family = "Inter, system-ui, -apple-system, Segoe UI, Roboto, Arial";
    Chart.defaults.color = "#0b1220";
    Chart.defaults.animation = false;
    Chart.defaults.animations = {
        colors: false,
        x: false,
        y: false,
        radius: false,
        tension: false
    };
    if (Chart.defaults.transitions) {
        Object.keys(Chart.defaults.transitions).forEach((key) => {
            Chart.defaults.transitions[key].animation = false;
        });
    }
    Chart.defaults.plugins.tooltip.backgroundColor = rgba(DEEP, 0.96);
    Chart.defaults.plugins.tooltip.titleColor = "#fff";
    Chart.defaults.plugins.tooltip.bodyColor = "#fff";
    Chart.defaults.plugins.tooltip.borderColor = rgba(DEEP, 0.16);
    Chart.defaults.plugins.tooltip.borderWidth = 1;

    const Donut3D = {
        id: 'donut3d',
        beforeDatasetDraw(chart, args){
            if(chart.config.type !== 'doughnut') return;
            const {ctx} = chart;
            const meta = chart.getDatasetMeta(args.index);
            if (!meta || !meta.data) return;

            ctx.save();
            ctx.globalAlpha = 0.14;
            ctx.shadowColor = 'rgba(0,0,0,.32)';
            ctx.shadowBlur = 16;
            ctx.shadowOffsetY = 10;

            meta.data.forEach(arc => {
                const p = arc.getProps(['x','y','startAngle','endAngle','innerRadius','outerRadius'], true);
                ctx.beginPath();
                ctx.arc(p.x, p.y + 7, p.outerRadius, p.startAngle, p.endAngle);
                ctx.arc(p.x, p.y + 7, p.innerRadius, p.endAngle, p.startAngle, true);
                ctx.closePath();
                ctx.fillStyle = '#000';
                ctx.fill();
            });

            ctx.restore();
        }
    };

    try {
        if (typeof ChartDataLabels !== 'undefined') {
            Chart.register(ChartDataLabels, Donut3D);
        } else {
            Chart.register(Donut3D);
        }
    } catch(e) {
        try { Chart.register(Donut3D); } catch(e2) {}
    }

    const el = (id)=>document.getElementById(id);
    const CHART_CONFIGS = {};

    function makeChart(canvasId, config){
        const canvas = el(canvasId);
        if(!canvas) return null;
        const existing = Chart.getChart(canvas);
        if (existing) existing.destroy();

        const total = sanitizeChartConfigData(config);
        if (total <= 0) {
            CHART_CONFIGS[canvasId] = null;
            setEmptyState(canvas, true);
            return null;
        }

        setEmptyState(canvas, false);
        CHART_CONFIGS[canvasId] = config;
        return new Chart(canvas, config);
    }

    function safeCloneOptions(obj){
        if (!obj) return {};
        if (typeof structuredClone === "function") {
            try { return structuredClone(obj); } catch(e) {}
        }
        return obj;
    }

    function cloneConfigKeepFns(cfg){
        return {
            type: cfg.type,
            data: {
                labels: Array.isArray(cfg.data?.labels) ? [...cfg.data.labels] : [],
                datasets: (cfg.data?.datasets || []).map(ds => ({
                    ...ds,
                    data: Array.isArray(ds.data) ? [...ds.data] : []
                }))
            },
            options: safeCloneOptions(cfg.options),
            plugins: cfg.plugins ? [...cfg.plugins] : undefined
        };
    }

    function pct(part, total){
        if (!total || total <= 0) return 0;
        return Math.round((part / total) * 100);
    }

    function computeMostLeast(labels, values){
        const items = labels.map((l, i) => ({ label: String(l), value: Number(values[i] || 0) }))
            .filter(x => x.label.trim() !== '' && Number.isFinite(x.value));
        const sum = items.reduce((a,b)=>a+b.value,0);
        const nonZero = items.filter(x => x.value > 0);
        const poolForLeast = nonZero.length ? nonZero : items;

        const most = items.reduce((best, x) => x.value > best.value ? x : best, items[0] || {label:'-', value:0});
        const least = poolForLeast.reduce((best, x) => x.value < best.value ? x : best, poolForLeast[0] || {label:'-', value:0});
        const top3 = items.slice().sort((a,b)=>b.value - a.value).slice(0,3);
        return { sum, most, least, top3 };
    }

    function setInsightsHTML(html){
        const box = document.getElementById('dsModalInsights');
        if (!box) return;
        box.innerHTML = html;
    }

    function buildInsightsForChart(chartId){
        const map = {
            barangayBar:      { src: DATA.barangayDist },
            ageDist:          { src: DATA.ageDist },
            civilDonut:       { src: DATA.civilDist },
            enrollDonut:      { src: DATA.enrollDist },
            timesPregBarH:    { src: DATA.timesPregDist },
            monthInterviewBar:{ src: DATA.monthDist }
        };

        const m = map[chartId];
        if (!m || !m.src) {
            setInsightsHTML('<span class="ds-chip"><small>INSIGHTS</small><strong>No data available</strong></span>');
            return;
        }

        const labels = m.src.labels || [];
        const data = m.src.data || [];

        if (!labels.length || !data.length) {
            setInsightsHTML('<span class="ds-chip"><small>INSIGHTS</small><strong>No data available</strong></span>');
            return;
        }

        const r = computeMostLeast(labels, data);
        if (!r.sum) {
            setInsightsHTML('<span class="ds-chip"><small>INSIGHTS</small><strong>No data available</strong></span>');
            return;
        }

        const mostPct = pct(r.most.value, r.sum);
        const leastPct = pct(r.least.value, r.sum);
        const top3Text = r.top3.map(x => `${x.label} (${x.value})`).join(' • ');

        setInsightsHTML(`
            <span class="ds-chip"><small>TOTAL (shown)</small><strong>${r.sum}</strong></span>
            <span class="ds-chip good"><small>MOST</small><strong>${r.most.label} — ${r.most.value} (${mostPct}%)</strong></span>
            <span class="ds-chip bad"><small>LEAST</small><strong>${r.least.label} — ${r.least.value} (${leastPct}%)</strong></span>
            <span class="ds-chip"><small>TOP 3</small><strong>${top3Text || '-'}</strong></span>
        `);
    }

    const BIG_LEGEND_LABELS = {
        color: '#0b1220',
        boxWidth: 16,
        boxHeight: 10,
        padding: 10,
        usePointStyle: true,
        pointStyle: 'rectRounded',
        font: { size: 11, weight: '800' }
    };

    function verticalBar(id, labels, data, labelText, axisTitles = {}){
        return makeChart(id, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: labelText || 'Count',
                    data,
                    borderWidth: 0,
                    borderRadius: 8,
                    backgroundColor: rgba(BRAND, 0.92),
                    hoverBackgroundColor: rgba(DEEP, 0.98),
                    maxBarThickness: 28
                }]
            },
            options: {
                indexAxis: 'x',
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                layout: { padding: { top: 4, bottom: 2, left: 2, right: 2 } },
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: (i)=> `${labelText || 'Count'}: ${i.raw}` } },
                    datalabels: (typeof ChartDataLabels !== 'undefined') ? {
                        anchor: 'end',
                        align: 'top',
                        formatter: (v)=> v,
                        font: { weight: '900', size: 9 },
                        color: '#0b1220',
                        clamp: true
                    } : {}
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: GRID },
                        ticks: { color: TICK, ...INT_TICKS, font: { size: 10 } },
                        title: {
                            display: !!axisTitles.y,
                            text: axisTitles.y || '',
                            color: TICK,
                            font: { size: 11, weight: '800' }
                        }
                    },
                    x: {
                        ticks: {
                            color: TICK,
                            autoSkip: false,
                            maxRotation: 28,
                            minRotation: 0,
                            font: { size: 9 }
                        },
                        grid: { display:false },
                        title: {
                            display: !!axisTitles.x,
                            text: axisTitles.x || '',
                            color: TICK,
                            font: { size: 11, weight: '800' }
                        }
                    }
                }
            }
        });
    }

    function donut3D(id, labels, data, preferredColors = []){
        const total = data.reduce((a,b)=>a+b,0);
        const bg = buildPalette(labels.length, preferredColors);

        return makeChart(id, {
            type: 'doughnut',
            data: {
                labels,
                datasets: [{
                    data,
                    borderWidth: 0,
                    hoverOffset: 6,
                    spacing: 2,
                    backgroundColor: bg
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                cutout: '62%',
                layout: { padding: { top: 2, bottom: 2, left: 2, right: 2 } },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: BIG_LEGEND_LABELS
                    },
                    tooltip: { callbacks: { label: (i)=> `${i.label}: ${i.raw}` } },
                    datalabels: (typeof ChartDataLabels !== 'undefined') ? {
                        display: (ctx) => (ctx.dataset.data[ctx.dataIndex] || 0) > 0,
                        formatter: (v) => total > 0 ? `${v}\n${Math.round((v/total)*100)}%` : `${v}`,
                        font: { weight: '950', size: 9 },
                        color: (ctx)=> smartLabelColor(ctx),
                        textStrokeColor: 'rgba(0,0,0,.45)',
                        textStrokeWidth: 2,
                        textAlign: 'center',
                        clamp: true
                    } : {}
                }
            }
        });
    }

    function horizontalBar(id, labels, data, labelText = 'Count', axisTitles = {}){
        const isBarangay = id === 'barangayBar';
        if (isBarangay) {
            sizeBarangayCanvas(el(id), labels, false);
        }

        return makeChart(id, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: labelText || 'Count',
                    data,
                    borderWidth: 0,
                    borderRadius: 8,
                    backgroundColor: rgba(BRAND, 0.90),
                    hoverBackgroundColor: rgba(DEEP, 0.98),
                    maxBarThickness: isBarangay ? 28 : 24
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                layout: { padding: { top: 2, bottom: 2, left: 2, right: 2 } },
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: (i)=> `${labelText || 'Count'}: ${i.raw}` } },
                    datalabels: (typeof ChartDataLabels !== 'undefined') ? {
                        anchor: 'end',
                        align: 'right',
                        formatter: (v)=> v,
                        font: { weight: '950', size: isBarangay ? 10 : 9 },
                        color: '#0b1220',
                        clamp: true
                    } : {}
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        grid: { color: GRID },
                        ticks: { color: TICK, ...INT_TICKS, font: { size: 10 } },
                        title: {
                            display: !!axisTitles.x,
                            text: axisTitles.x || '',
                            color: TICK,
                            font: { size: 11, weight: '800' }
                        }
                    },
                    y: {
                        ticks: {
                            color: TICK,
                            autoSkip: false,
                            font: { size: isBarangay ? 11 : 10, weight: isBarangay ? '900' : '700' }
                        },
                        grid: { display:false },
                        title: {
                            display: !!axisTitles.y,
                            text: axisTitles.y || '',
                            color: TICK,
                            font: { size: 11, weight: '800' }
                        }
                    }
                }
            }
        });
    }

    horizontalBar('barangayBar', DATA.barangayDist.labels, DATA.barangayDist.data, 'Total');
    horizontalBar('ageDist', DATA.ageDist.labels, DATA.ageDist.data, 'Total');

    donut3D(
        'civilDonut',
        DATA.civilDist.labels,
        DATA.civilDist.data,
        ['#7C3AED', '#2948A8', '#E481C1']
    );

    donut3D('enrollDonut', DATA.enrollDist.labels, DATA.enrollDist.data);
    horizontalBar('timesPregBarH', DATA.timesPregDist.labels, DATA.timesPregDist.data);
    verticalBar('monthInterviewBar', DATA.monthDist.labels, DATA.monthDist.data, 'Count');

    const modal = el('dsModal');
    const modalCanvas = el('dsModalCanvas');
    const modalTitle = el('dsModalTitle');
    const modalClose = el('dsModalClose');

    function openModal(chartId, titleText){
        const cfg = CHART_CONFIGS[chartId];
        if(!cfg) return;

        modalTitle.textContent = titleText || 'Chart';
        buildInsightsForChart(chartId);

        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');

        const existing = Chart.getChart(modalCanvas);
        if (existing) existing.destroy();

        const cfgClone = cloneConfigKeepFns(cfg);

        cfgClone.options = cfgClone.options || {};
        cfgClone.options.animation = false;
        cfgClone.options.animations = {
            colors: false,
            x: false,
            y: false,
            radius: false,
            tension: false
        };
        cfgClone.options.transitions = {
            active: { animation: false },
            resize: { animation: false },
            show: { animation: false },
            hide: { animation: false }
        };
        cfgClone.options.plugins = cfgClone.options.plugins || {};
        cfgClone.options.plugins.legend = cfgClone.options.plugins.legend || {};
        cfgClone.options.plugins.legend.display = true;
        cfgClone.options.plugins.legend.position = 'top';
        cfgClone.options.plugins.legend.align = 'start';

        cfgClone.options.plugins.legend.labels = Object.assign({
            color: '#0b1220',
            boxWidth: 20,
            boxHeight: 14,
            padding: 12,
            usePointStyle: true,
            pointStyle: 'rectRounded',
            font: { size: 13, weight: '800' }
        }, cfgClone.options.plugins.legend.labels || {});

        cfgClone.options.layout = cfgClone.options.layout || {};
        cfgClone.options.layout.padding = Object.assign({ top: 6, bottom: 6 }, cfgClone.options.layout.padding || {});

        resetCanvasSizing(modalCanvas);
        if (chartId === 'barangayBar') {
            sizeBarangayCanvas(modalCanvas, cfgClone.data.labels || [], true);

            cfgClone.options.scales = cfgClone.options.scales || {};
            const yScale = cfgClone.options.scales.y || {};
            cfgClone.options.scales.y = Object.assign({}, yScale, {
                ticks: Object.assign({}, yScale.ticks || {}, {
                    color: '#0b1220',
                    autoSkip: false,
                    font: { size: 13, weight: '900' }
                })
            });

            const xScale = cfgClone.options.scales.x || {};
            cfgClone.options.scales.x = Object.assign({}, xScale, {
                ticks: Object.assign({}, xScale.ticks || {}, {
                    color: '#0b1220',
                    font: { size: 12, weight: '800' }
                })
            });

            cfgClone.options.plugins.datalabels = Object.assign({}, cfgClone.options.plugins.datalabels || {}, {
                font: { weight: '950', size: 11 },
                color: '#0b1220',
                clamp: true
            });

            (cfgClone.data.datasets || []).forEach(ds => {
                ds.maxBarThickness = 30;
            });
        }

        const c = new Chart(modalCanvas, cfgClone);
        c.resize();
        c.update('none');
    }

    function closeModal(){
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        const existing = Chart.getChart(modalCanvas);
        if (existing) existing.destroy();
    }

    document.querySelectorAll('.dashboard-chart-card[data-chart]').forEach(card=>{
        card.addEventListener('click', ()=>{
            if (card.classList.contains('is-empty')) return;
            const chartId = card.getAttribute('data-chart');
            const title = card.getAttribute('data-title') || 'Chart';
            openModal(chartId, title);
        });
    });

    modalClose.addEventListener('click', closeModal);
    modal.addEventListener('click', (e)=>{ if(e.target === modal) closeModal(); });
    document.addEventListener('keydown', (e)=>{ if(e.key === 'Escape' && modal.classList.contains('show')) closeModal(); });
})();
</script>
<?php require_once __DIR__ . "/../includes/footer.php"; ?>
