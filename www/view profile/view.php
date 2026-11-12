<?php
declare(strict_types=1);

require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/database.php";

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('baseUrl')) {
    function baseUrl(): string {
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        $dir = preg_replace('#/(auth|config|dashboard|profiling|includes|reports|view profile)$#', '', $dir);
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

if (!function_exists('view_pageUrl')) {
    function view_pageUrl(string $self, int $page, int $perPage): string {
        $query = $_GET;
        $query['page'] = max(1, $page);
        $query['per_page'] = $perPage;
        return $self . '?' . http_build_query($query);
    }
}

if (!function_exists('view_bindQueryParams')) {
    function view_bindQueryParams(PDOStatement $statement, array $params): void {
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }
}

if (!function_exists('view_tableExists')) {
    function view_tableExists(PDO $conn, string $table): bool {
        $stmt = $conn->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:t LIMIT 1");
        $stmt->execute([':t' => $table]);
        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('view_tableColumnsAssoc')) {
    function view_tableColumnsAssoc(PDO $conn, string $table): array {
        $table = trim($table);
        if ($table === '' || !preg_match('/^[A-Za-z0-9_]+$/', $table)) return [];

        try {
            $stmt = $conn->query("PRAGMA table_info(" . $table . ")");
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $out = [];
            foreach ($rows as $r) {
                $name = (string)($r['name'] ?? '');
                if ($name !== '') $out[$name] = true;
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('hasCol')) {
    function hasCol(array $cols, string $col): bool {
        return isset($cols[$col]);
    }
}

if (!function_exists('normalizeDobToIso')) {
    function normalizeDobToIso(?string $dobRaw): ?string {
        $dobRaw = trim((string)$dobRaw);
        if ($dobRaw === '') return null;

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dobRaw)) return $dobRaw;

        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $dobRaw, $m)) {
            $mm = (int)$m[1];
            $dd = (int)$m[2];
            $yy = (int)$m[3];
            if (!checkdate($mm, $dd, $yy)) return null;
            return sprintf('%04d-%02d-%02d', $yy, $mm, $dd);
        }

        if (preg_match('/^(\d{2})[-.](\d{2})[-.](\d{4})$/', $dobRaw, $m)) {
            $mm = (int)$m[1];
            $dd = (int)$m[2];
            $yy = (int)$m[3];
            if (!checkdate($mm, $dd, $yy)) return null;
            return sprintf('%04d-%02d-%02d', $yy, $mm, $dd);
        }

        $ts = strtotime($dobRaw);
        if ($ts === false) return null;
        return date('Y-m-d', $ts);
    }
}

if (!function_exists('isAdolescentAge')) {
    function isAdolescentAge(?string $dobIso): bool {
        $age = view_currentAge($dobIso);
        return $age !== null && $age >= 9 && $age <= 19;
    }
}

if (!function_exists('view_currentAge')) {
    function view_currentAge(?string $dobRaw): ?int {
        $dobIso = normalizeDobToIso($dobRaw);
        if ($dobIso === null) return null;

        $parts = array_map('intval', explode('-', $dobIso));
        if (count($parts) !== 3 || !checkdate($parts[1], $parts[2], $parts[0])) return null;

        try {
            $dob = new DateTimeImmutable($dobIso);
            $today = new DateTimeImmutable('today');
            if ($dob > $today) return null;
            return (int)$dob->diff($today)->y;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('view_ageSqlExpression')) {
    function view_ageSqlExpression(string $dobExpression, bool $phpAgeFunctionAvailable): string {
        if ($phpAgeFunctionAvailable) {
            return "ahdis_current_age($dobExpression)";
        }

        return "(
            CAST(strftime('%Y','now','localtime') AS INTEGER)
            - CAST(strftime('%Y',$dobExpression) AS INTEGER)
            - (strftime('%m-%d','now','localtime') < strftime('%m-%d',$dobExpression))
        )";
    }
}

if (!function_exists('view_groupUrl')) {
    function view_groupUrl(string $self, string $group): string {
        $query = $_GET;
        $query['group'] = $group === 'adult' ? 'adult' : 'adolescent';
        unset($query['page']);
        return $self . '?' . http_build_query($query);
    }
}

if (!function_exists('view_filterDateValue')) {
    function view_filterDateValue(string $value): string {
        $value = trim($value);
        if ($value === '') return '';
        $iso = normalizeDobToIso($value);
        return $iso ?: '';
    }
}

if (!function_exists('view_nameParts')) {
    function view_nameParts(string $name): array {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
        if ($name === '') return ['', ''];
        if (strpos($name, ',') !== false) {
            [$last, $rest] = array_map('trim', explode(',', $name, 2));
            $first = trim((preg_split('/\s+/', $rest) ?: [''])[0] ?? '');
            return [$last, $first];
        }
        $parts = preg_split('/\s+/', $name) ?: [];
        if (count($parts) === 1) return [$parts[0], ''];
        return [$parts[count($parts) - 1], $parts[0]];
    }
}

if (!function_exists('view_matchesSearchFilters')) {
    function view_matchesSearchFilters(array $row, array $filters): bool {
        $dob = normalizeDobToIso((string)($row['date_of_birth'] ?? ''));
        $barangay = trim((string)($row['barangay'] ?? ''));
        if ($barangay === '') {
            $barangay = trim((string)($row['locked_barangay'] ?? ''));
        }

        $filterBrgy = trim((string)($filters['barangay'] ?? ''));
        if ($filterBrgy !== '' && strcasecmp($barangay, $filterBrgy) !== 0) {
            return false;
        }

        $birthdate = view_filterDateValue((string)($filters['birthdate'] ?? ''));
        if ($birthdate !== '' && $dob !== $birthdate) {
            return false;
        }

        return true;
    }
}

if (!function_exists('view_sortRows')) {
    function view_sortRows(array &$rows, callable $latestResolver): void {
        usort($rows, static function(array $a, array $b) use ($latestResolver): int {
            $brgyA = strtolower(trim((string)($a['barangay'] ?? $a['locked_barangay'] ?? '')));
            $brgyB = strtolower(trim((string)($b['barangay'] ?? $b['locked_barangay'] ?? '')));
            if ($brgyA !== $brgyB) return $brgyA <=> $brgyB;

            [$lastA, $firstA] = view_nameParts((string)($a['name'] ?? ''));
            [$lastB, $firstB] = view_nameParts((string)($b['name'] ?? ''));
            $lastA = strtolower($lastA);
            $lastB = strtolower($lastB);
            if ($lastA !== $lastB) return $lastA <=> $lastB;

            $firstA = strtolower($firstA);
            $firstB = strtolower($firstB);
            if ($firstA !== $firstB) return $firstA <=> $firstB;

            $latestA = view_filterDateValue((string)$latestResolver($a));
            $latestB = view_filterDateValue((string)$latestResolver($b));
            if ($latestA !== $latestB) return $latestB <=> $latestA;

            return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
        });
    }
}

$projectRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$baseUrl     = baseUrl();

$SELF = resolveExistingRoute($baseUrl, $projectRoot, [
    'view profile/view.php',
    'profiling/view.php',
]);

$ADD_URL = resolveExistingRoute($baseUrl, $projectRoot, [
    'profiling/add.php',
    'add.php',
]);

$DETAIL_URL = resolveExistingRoute($baseUrl, $projectRoot, [
    'view profile/adolescent_view.php',
    'profiling/adolescent_view.php',
]);

$EXPORT_URL = resolveExistingRoute($baseUrl, $projectRoot, [
    'profiling/export_excel.php',
]);

if (!($conn instanceof PDO)) {
    http_response_code(500);
    die("DB connection not available.");
}
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$currentUserMunicipality = '';
try {
    $currentUserId = (int)($_SESSION['user_id'] ?? 0);
    if ($currentUserId > 0) {
        $stLocation = $conn->prepare("SELECT COALESCE(municipality,'') FROM users WHERE id = :id LIMIT 1");
        $stLocation->execute([':id' => $currentUserId]);
        $currentUserMunicipality = trim((string)$stLocation->fetchColumn());
    }
} catch (Throwable $e) {
    $currentUserMunicipality = '';
}

$viewPhpAgeFunctionAvailable = false;
if (method_exists($conn, 'sqliteCreateFunction')) {
    try {
        $conn->sqliteCreateFunction(
            'ahdis_current_age',
            static fn($dob) => view_currentAge(is_scalar($dob) ? (string)$dob : null),
            1
        );
        $viewPhpAgeFunctionAvailable = true;
    } catch (Throwable $e) {
        $viewPhpAgeFunctionAvailable = false;
    }
}

$search = trim((string)($_GET['search'] ?? ''));
$filterBrgy = trim((string)($_GET['barangay'] ?? ''));
$filterBirthdate = view_filterDateValue((string)($_GET['birthdate'] ?? ''));
$profileGroup = strtolower(trim((string)($_GET['group'] ?? 'adolescent')));
if (!in_array($profileGroup, ['adolescent', 'adult'], true)) {
    $profileGroup = 'adolescent';
}
$isAdultView = $profileGroup === 'adult';
$searchLike = '%' . $search . '%';
$allowedPerPage = [25, 50, 100];
$perPage = (int)($_GET['per_page'] ?? 50);
if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 50;
}
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;
$totalRows = 0;
$totalPages = 1;

$searchFilters = [
    'barangay' => $filterBrgy,
    'birthdate' => $filterBirthdate,
];

$clearQuery = ['group' => $profileGroup, 'per_page' => $perPage];
$clearUrl = $SELF . '?' . http_build_query($clearQuery);
$exportQuery = [
    'download' => 1,
    'kind' => 'snapshot',
    'age_group' => $profileGroup,
];
if ($search !== '') $exportQuery['search'] = $search;
if ($filterBrgy !== '') $exportQuery['barangay'] = $filterBrgy;
if ($filterBirthdate !== '') $exportQuery['birthdate'] = $filterBirthdate;
$categoryExportUrl = $EXPORT_URL . '?' . http_build_query($exportQuery);

$viewTitle = $isAdultView ? 'Profiles — 20 Years Old and Above' : 'View Profiles — Adolescents';
$viewSubtitle = $isAdultView
    ? 'Search and manage profiles whose current age is 20 years old or above.'
    : 'Search, review, and manage adolescent profile records aged 9–19.';
$viewTotalLabel = $isAdultView ? 'Total 20+ Profiles' : 'Total Adolescent Profiles';
$viewListTitle = $isAdultView ? '20 Years Old and Above' : 'Adolescent Profiles';

$useNew = false;
try {
    if (view_tableExists($conn, 'adolescents')) {
        $cnt = (int)$conn->query("SELECT COUNT(1) FROM adolescents")->fetchColumn();
        if ($cnt > 0) $useNew = true;
    }
} catch (Throwable $e) {
    $useNew = false;
}

$rows = [];
$statsByAdolescentId = [];
$latestEncounterIdByAdolescentId = [];
$latestSnapshotIdByAdolescentId = [];
$legacyStats = [];

if ($useNew) {
    $colsA = view_tableColumnsAssoc($conn, 'adolescents');

    $nameExpr = "COALESCE(NULLIF(TRIM(a.full_name),''), TRIM(COALESCE(a.first_name,'') || ' ' || COALESCE(a.middle_name,'') || ' ' || COALESCE(a.last_name,'')))";
    $dobCol   = hasCol($colsA, 'date_of_birth') ? 'date_of_birth' : null;
    $munCol   = hasCol($colsA, 'municipality') ? 'municipality' : null;
    $brgyCol  = hasCol($colsA, 'barangay') ? 'barangay' : null;
    $addrCol  = hasCol($colsA, 'present_address') ? 'present_address' : null;
    $hasEncounters = view_tableExists($conn, 'encounters');

    $where = [];
    $params = [];

    if ($munCol && $currentUserMunicipality !== '') {
        $where[] = "LOWER(TRIM(COALESCE(a.$munCol,''))) = LOWER(TRIM(:current_municipality))";
        $params[':current_municipality'] = $currentUserMunicipality;
    }

    if ($search !== '') {
        $searchParts = [
            "CAST(a.id AS TEXT) LIKE :search",
            "$nameExpr LIKE :search",
        ];
        if (hasCol($colsA, 'last_name')) $searchParts[] = "COALESCE(a.last_name,'') LIKE :search";
        if (hasCol($colsA, 'first_name')) $searchParts[] = "COALESCE(a.first_name,'') LIKE :search";
        if (hasCol($colsA, 'middle_name')) $searchParts[] = "COALESCE(a.middle_name,'') LIKE :search";
        if (hasCol($colsA, 'full_name')) $searchParts[] = "COALESCE(a.full_name,'') LIKE :search";
        if ($munCol) $searchParts[] = "COALESCE(a.$munCol,'') LIKE :search";
        if ($brgyCol) $searchParts[] = "COALESCE(a.$brgyCol,'') LIKE :search";
        if ($addrCol) $searchParts[] = "COALESCE(a.$addrCol,'') LIKE :search";
        $where[] = "(" . implode(" OR ", array_unique($searchParts)) . ")";
        $params[':search'] = $searchLike;
    }

    if ($brgyCol && $filterBrgy !== '') {
        $where[] = "LOWER(TRIM(COALESCE(a.$brgyCol,''))) = LOWER(TRIM(:brgy))";
        $params[':brgy'] = $filterBrgy;
    }

    if ($dobCol && $filterBirthdate !== '') {
        $where[] = "TRIM(COALESCE(a.$dobCol,'')) = :birthdate";
        $params[':birthdate'] = $filterBirthdate;
    }

    if ($dobCol) {
        $ageExpression = view_ageSqlExpression("a.$dobCol", $viewPhpAgeFunctionAvailable);
        if ($isAdultView) {
            $where[] = "$ageExpression >= :age_min";
            $params[':age_min'] = 20;
        } else {
            $where[] = "$ageExpression BETWEEN :age_min AND :age_max";
            $params[':age_min'] = 9;
            $params[':age_max'] = 19;
        }
    } else {
        $where[] = '1 = 0';
    }

    $whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";
    $statsJoin = $hasEncounters ? "
      LEFT JOIN (
        SELECT
          adolescent_id,
          COUNT(1) AS submissions,
          MAX(NULLIF(TRIM(COALESCE(encounter_date,'')),'')) AS latest
        FROM encounters
        GROUP BY adolescent_id
      ) es ON es.adolescent_id = a.id
    " : "";
    $statsSelect = $hasEncounters
        ? "COALESCE(es.submissions, 0) AS submissions,
           COALESCE(es.latest, '') AS latest_submission,
           (
             SELECT e2.id
             FROM encounters e2
             WHERE e2.adolescent_id = a.id
             ORDER BY
               COALESCE(NULLIF(TRIM(e2.encounter_date),''),'0000-00-00') DESC,
               COALESCE(NULLIF(TRIM(e2.created_at),''),'0000-00-00 00:00:00') DESC,
               e2.id DESC
             LIMIT 1
           ) AS latest_encounter_id"
        : "0 AS submissions, '' AS latest_submission, 0 AS latest_encounter_id";

    try {
        $countSql = "SELECT COUNT(1) FROM adolescents a $whereSql";
        $stCount = $conn->prepare($countSql);
        view_bindQueryParams($stCount, $params);
        $stCount->execute();
        $totalRows = (int)$stCount->fetchColumn();
        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $perPage;
        }

        $sql = "
          SELECT
            a.id,
            $nameExpr AS name,
            " . ($dobCol ? "a.$dobCol" : "''") . " AS date_of_birth,
            " . ($munCol ? "a.$munCol" : "''") . " AS municipality,
            " . ($brgyCol ? "a.$brgyCol" : "''") . " AS barangay,
            $statsSelect
          FROM adolescents a
          $statsJoin
          $whereSql
          ORDER BY
            LOWER(TRIM(" . ($brgyCol ? "COALESCE(a.$brgyCol,'')" : "''") . ")) ASC,
            LOWER(TRIM(COALESCE(a.last_name,''))) ASC,
            LOWER(TRIM(COALESCE(a.first_name,''))) ASC,
            " . ($hasEncounters ? "COALESCE(es.latest, '') DESC," : "") . "
            a.id ASC
          LIMIT :limit OFFSET :offset
        ";

        $st = $conn->prepare($sql);
        view_bindQueryParams($st, $params);
        $st->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $st->bindValue(':offset', $offset, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $rows = [];
        $totalRows = 0;
        $totalPages = 1;
    }

    $ids = [];
    foreach ($rows as $r) {
        $aid = (int)($r['id'] ?? 0);
        if ($aid > 0) {
            $ids[] = $aid;
            $statsByAdolescentId[$aid] = [
                'submissions' => (int)($r['submissions'] ?? 0),
                'latest'      => trim((string)($r['latest_submission'] ?? '')),
            ];
            $latestEncounterId = (int)($r['latest_encounter_id'] ?? 0);
            if ($latestEncounterId > 0) {
                $latestEncounterIdByAdolescentId[$aid] = $latestEncounterId;
            }
        }
    }
    $ids = array_values(array_unique($ids));

    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        if (view_tableExists($conn, 'adolescent_mothers')) {
            $colsL = view_tableColumnsAssoc($conn, 'adolescent_mothers');
            $aidCol = hasCol($colsL, 'adolescent_id') ? 'adolescent_id' : null;

            $dateOrderCol =
              (hasCol($colsL, 'interview_date') ? 'interview_date'
              : (hasCol($colsL, 'encounter_date') ? 'encounter_date'
              : (hasCol($colsL, 'created_at') ? 'created_at'
              : null)));

            if ($aidCol) {
                $sqlSnap = "
                  SELECT a.adolescent_id,
                        (
                          SELECT am.id
                          FROM adolescent_mothers am
                          WHERE am.adolescent_id = a.adolescent_id
                          ORDER BY
                            " . ($dateOrderCol ? "COALESCE(NULLIF(TRIM(am.$dateOrderCol),''),'0000-00-00') DESC," : "") . "
                            am.id DESC
                          LIMIT 1
                        ) AS latest_id
                  FROM (
                    SELECT DISTINCT adolescent_id
                    FROM adolescent_mothers
                    WHERE adolescent_id IN ($placeholders)
                  ) a
                ";

                try {
                    $stS = $conn->prepare($sqlSnap);
                    $stS->execute($ids);
                    foreach (($stS->fetchAll(PDO::FETCH_ASSOC) ?: []) as $sr) {
                        $aid = (int)($sr['adolescent_id'] ?? 0);
                        $lid = (int)($sr['latest_id'] ?? 0);
                        if ($aid > 0 && $lid > 0) {
                            $latestSnapshotIdByAdolescentId[$aid] = $lid;
                        }
                    }
                } catch (Throwable $e) {}
            }
        }

        foreach ($ids as $aid) {
            if (!isset($statsByAdolescentId[$aid])) {
                $statsByAdolescentId[$aid] = ['submissions' => 0, 'latest' => ''];
            }
        }
    }
} else {
    if (!view_tableExists($conn, 'adolescent_mothers')) {
        http_response_code(500);
        require_once __DIR__ . "/../includes/header.php";
        require_once __DIR__ . "/../includes/sidebar.php";
        echo "<div style='padding:16px;'><h2>Setup needed</h2><p>Table <code>adolescent_mothers</code> not found.</p></div>";
        require_once __DIR__ . "/../includes/footer.php";
        exit;
    }

    $colsL = view_tableColumnsAssoc($conn, 'adolescent_mothers');
    $nameCol = hasCol($colsL, 'name') ? 'name' : null;
    $dobCol  = hasCol($colsL, 'date_of_birth') ? 'date_of_birth' : null;

    if (!$nameCol || !$dobCol) {
        http_response_code(500);
        require_once __DIR__ . "/../includes/header.php";
        require_once __DIR__ . "/../includes/sidebar.php";
        echo "<div style='padding:16px;'><h2>Schema issue</h2><p>Missing required columns: <code>name</code> and/or <code>date_of_birth</code>.</p></div>";
        require_once __DIR__ . "/../includes/footer.php";
        exit;
    }

    $aidCol  = hasCol($colsL, 'adolescent_id') ? 'adolescent_id' : null;
    $munCol  = hasCol($colsL, 'locked_municipality') ? 'locked_municipality' : (hasCol($colsL, 'municipality') ? 'municipality' : null);
    $brgyCol = hasCol($colsL, 'locked_barangay') ? 'locked_barangay' : (hasCol($colsL, 'barangay') ? 'barangay' : null);
    $addrCol = hasCol($colsL, 'present_address') ? 'present_address' : null;

    $orderCol =
      (hasCol($colsL, 'interview_date') ? 'interview_date'
      : (hasCol($colsL, 'encounter_date') ? 'encounter_date'
      : (hasCol($colsL, 'created_at') ? 'created_at' : 'id')));
    $legacyBrgyOrderExpr = $brgyCol ? "COALESCE($brgyCol,'')" : "''";
    $legacyAmBrgyOrderExpr = $brgyCol ? "COALESCE(am.$brgyCol,'')" : "''";

    $where = [];
    $params = [];

    if ($munCol && $currentUserMunicipality !== '') {
        $where[] = "LOWER(TRIM(COALESCE($munCol,''))) = LOWER(TRIM(:current_municipality))";
        $params[':current_municipality'] = $currentUserMunicipality;
    }

    if ($search !== '') {
        $searchParts = [
            "COALESCE($nameCol,'') LIKE :search",
        ];
        if ($aidCol) $searchParts[] = "CAST($aidCol AS TEXT) LIKE :search";
        if (hasCol($colsL, 'last_name')) $searchParts[] = "COALESCE(last_name,'') LIKE :search";
        if (hasCol($colsL, 'first_name')) $searchParts[] = "COALESCE(first_name,'') LIKE :search";
        if (hasCol($colsL, 'middle_name')) $searchParts[] = "COALESCE(middle_name,'') LIKE :search";
        if ($brgyCol) $searchParts[] = "COALESCE($brgyCol,'') LIKE :search";
        if ($addrCol) $searchParts[] = "COALESCE($addrCol,'') LIKE :search";
        $where[] = "(" . implode(" OR ", array_unique($searchParts)) . ")";
        $params[':search'] = $searchLike;
    }

    if ($brgyCol && $filterBrgy !== '') {
        $where[] = "LOWER(TRIM(COALESCE($brgyCol,''))) = LOWER(TRIM(:brgy))";
        $params[':brgy'] = $filterBrgy;
    }

    if ($dobCol && $filterBirthdate !== '') {
        $where[] = "TRIM(COALESCE($dobCol,'')) = :birthdate";
        $params[':birthdate'] = $filterBirthdate;
    }

    $ageExpression = view_ageSqlExpression($dobCol, $viewPhpAgeFunctionAvailable);
    if ($isAdultView) {
        $where[] = "$ageExpression >= :age_min";
        $params[':age_min'] = 20;
    } else {
        $where[] = "$ageExpression BETWEEN :age_min AND :age_max";
        $params[':age_min'] = 9;
        $params[':age_max'] = 19;
    }

    $whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

    try {
        if ($aidCol) {
            $subWhere = $whereSql ? ($whereSql . " AND $aidCol IS NOT NULL AND $aidCol > 0")
                                  : ("WHERE $aidCol IS NOT NULL AND $aidCol > 0");

            $countSql = "
              SELECT COUNT(1)
              FROM (
                SELECT $aidCol
                FROM adolescent_mothers
                $subWhere
                GROUP BY $aidCol
              ) counted
            ";
            $stCount = $conn->prepare($countSql);
            view_bindQueryParams($stCount, $params);
            $stCount->execute();
            $totalRows = (int)$stCount->fetchColumn();
            $totalPages = max(1, (int)ceil($totalRows / $perPage));
            if ($page > $totalPages) {
                $page = $totalPages;
                $offset = ($page - 1) * $perPage;
            }

            $sql = "
              SELECT
                am.*,
                COALESCE(stats.submissions, 1) AS view_submissions,
                COALESCE(stats.latest, '') AS view_latest
              FROM adolescent_mothers am
              INNER JOIN (
                SELECT $aidCol AS adolescent_id, MAX(id) AS max_id
                FROM adolescent_mothers
                $subWhere
                GROUP BY $aidCol
                ORDER BY
                  LOWER(TRIM($legacyBrgyOrderExpr)) ASC,
                  LOWER(TRIM(COALESCE($nameCol,''))) ASC,
                  max_id ASC
                LIMIT :limit OFFSET :offset
              ) x
              ON x.max_id = am.id
              LEFT JOIN (
                SELECT
                  $aidCol AS adolescent_id,
                  COUNT(1) AS submissions,
                  MAX(NULLIF(TRIM(COALESCE($orderCol,'')),'')) AS latest
                FROM adolescent_mothers
                GROUP BY $aidCol
              ) stats ON stats.adolescent_id = x.adolescent_id
              ORDER BY
                LOWER(TRIM($legacyAmBrgyOrderExpr)) ASC,
                LOWER(TRIM(COALESCE(am.$nameCol,''))) ASC,
                am.id ASC
            ";
            $st = $conn->prepare($sql);
            view_bindQueryParams($st, $params);
            $st->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $st->bindValue(':offset', $offset, PDO::PARAM_INT);
            $st->execute();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as $r) {
                $aid = (int)($r[$aidCol] ?? 0);
                if ($aid > 0) {
                    $legacyStats[$aid] = [
                        'submissions' => (int)($r['view_submissions'] ?? 1),
                        'latest'      => trim((string)($r['view_latest'] ?? '')),
                    ];
                }
            }
        } else {
            $rows = [];
        }
    } catch (Throwable $e) {
        $rows = [];
        $totalRows = 0;
        $totalPages = 1;
    }
}

require_once __DIR__ . "/../includes/header.php";
require_once __DIR__ . "/../includes/sidebar.php";
?>
<style>
.view-page{
    width:100%;
}

.view-page .page-card{
    border-radius:24px;
    overflow:hidden;
    background:#ffffff;
    box-shadow:0 16px 34px rgba(15,23,42,.08);
    border:1px solid #dbe4f0;
}

.view-hero{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:16px;
    flex-wrap:wrap;
    padding:24px 22px 22px;
    border-bottom:1px solid rgba(255,255,255,.18);
    background:linear-gradient(90deg,  #0f172a 0%, #163a73 42%, #df82c6 75%, #cd87be 100%);
}

.view-hero-title{
    margin:0;
    font-size:24px;
    line-height:1.15;
    font-weight:900;
    color:#ffffff;
    letter-spacing:.2px;
}

.view-hero-subtitle{
    margin:8px 0 0;
    font-size:13px;
    color:rgba(255,255,255,.90);
    font-weight:700;
}

.view-total{
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

.view-total strong{
    color:#ffffff;
    font-size:14px;
    font-weight:900;
}

.view-stack{
    display:grid;
    gap:16px;
    padding:14px;
    background:linear-gradient(180deg,#f8fbff 0%, #f4f7fb 100%);
}

.view-section{
    background:#fff;
    border:1px solid #dbe4f0;
    border-radius:20px;
    overflow:hidden;
    box-shadow:0 8px 18px rgba(15,23,42,.05);
}

.view-section-head{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
    padding:14px 18px;
    border-bottom:1px solid #e8eef6;
    background:linear-gradient(180deg,#f9fbff 0%, #f3f8ff 100%);
}

.view-section-title{
    margin:0;
    font-size:15px;
    font-weight:900;
    color:#163a73;
}

.view-form{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    align-items:center;
    padding:12px 14px;
}

.view-category-nav{
    display:flex;
    align-items:center;
    gap:8px;
    flex-wrap:wrap;
}

.view-category-nav .view-btn{
    min-height:42px;
    padding-inline:18px;
}

.view-category-nav .view-btn.active{
    background:#163a73;
    border-color:#163a73;
    color:#fff;
    box-shadow:0 8px 18px rgba(22,58,115,.18);
}

.view-form .field-search{
    flex:1 1 320px;
    min-width:280px;
}

.view-form .field-brgy{
    flex:0 1 220px;
    min-width:170px;
}

.view-form .field-date{
    flex:0 0 170px;
    min-width:160px;
}

.view-form .field-size{
    flex:0 0 120px;
    min-width:110px;
}

.view-form .filter-actions{
    display:flex;
    align-items:center;
    gap:8px;
    flex:0 0 auto;
}

.view-form input,
.view-form select{
    width:100%;
    min-height:40px;
    padding:9px 12px;
    border-radius:10px;
    border:1px solid #d7e1ed;
    background:#fff;
    color:#1e293b;
    outline:none;
    font-weight:700;
    font-size:13px;
}

.view-form input:focus{
    border-color:#2563eb;
    box-shadow:0 0 0 3px rgba(37,99,235,.12);
}

.view-btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:40px;
    padding:0 15px;
    border-radius:10px;
    border:1px solid #dbe4f0;
    background:#fff;
    color:#163a73;
    text-decoration:none;
    font-weight:900;
    font-size:13px;
    line-height:1;
    white-space:nowrap;
    transition:
        background-color 120ms ease,
        color 120ms ease,
        border-color 120ms ease,
        box-shadow 120ms ease,
        opacity 120ms ease;
}

.view-btn:hover{
    transform:none;
    text-decoration:none;
}

.view-btn.search{
    background:#163a73;
    border-color:#163a73;
    color:#fff;
    box-shadow:0 10px 20px rgba(22,58,115,.16);
}

.view-btn.search:hover{
    background:#1d4f99;
    border-color:#1d4f99;
    color:#fff;
}

.view-btn.history{
    background:#fff;
    color:#163a73;
    border-color:#d7e3f0;
}

.view-btn.history:hover{
    background:#f8fbff;
    color:#0f2f66;
    border-color:#bfd1e4;
}

.view-btn.update{
    background:#eff6ff;
    color:#1d4ed8;
    border-color:#bfdbfe;
}

.view-btn.update:hover{
    background:#dbeafe;
    color:#1e40af;
    border-color:#93c5fd;
}

.view-btn.edit{
    background:#ecfeff;
    color:#0f766e;
    border-color:#99f6e4;
}

.view-btn.edit:hover{
    background:#cffafe;
    color:#0f766e;
    border-color:#5eead4;
}

.view-btn.disabled,
.view-btn[aria-disabled="true"]{
    background:#f8fafc;
    color:#94a3b8;
    border-color:#e2e8f0;
    box-shadow:none;
    cursor:not-allowed;
    pointer-events:none;
    transform:none;
}

.view-table-wrap{
    width:100%;
    overflow:auto;
}

.view-table{
    width:100%;
    min-width:1080px;
    border-collapse:separate;
    border-spacing:0;
}

.view-table thead th{
    position:sticky;
    top:0;
    z-index:2;
    background:linear-gradient(180deg,#eff6ff 0%, #dbeafe 100%);
    color:#163a73;
    text-align:left;
    padding:13px 12px;
    border-bottom:1px solid #d7e6fb;
    font-weight:900;
    font-size:13px;
    white-space:nowrap;
}

.view-table tbody td{
    padding:13px 12px;
    border-bottom:1px solid #e8eff6;
    vertical-align:middle;
    font-size:14px;
    color:#0f172a;
    background:#fff;
}

.view-table tbody tr:nth-child(even) td{
    background:#fbfdff;
}

.view-table tbody tr:hover td{
    background:#f3f8ff;
}

.view-table tbody td:last-child{
    width:1%;
    white-space:nowrap;
}

.view-name{
    font-weight:900;
    color:#163a73;
    font-size:14.5px;
    line-height:1.35;
}

.view-sub{
    color:#64748b;
    font-size:12px;
    margin-top:2px;
    font-weight:700;
}

.view-nowrap{
    white-space:nowrap;
}

.view-clip{
    max-width:220px;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
}

.view-actions{
    display:flex;
    align-items:center;
    gap:6px;
    flex-wrap:nowrap;
    min-width:max-content;
}

.view-empty{
    padding:28px 18px;
    text-align:center;
    color:#64748b;
    font-weight:800;
}

.view-btn.export{
    background:#166534;
    color:#fff;
    border-color:#166534;
    box-shadow:0 8px 18px rgba(22,101,52,.16);
}

.view-btn.export:hover{
    background:#15803d;
    border-color:#15803d;
    color:#fff;
}

.view-pagination{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
    padding:12px 14px;
    border-top:1px solid #e8eff6;
    background:#fbfdff;
}

.view-page-status{
    color:#475569;
    font-size:13px;
    font-weight:800;
}

.view-page-actions{
    display:flex;
    align-items:center;
    gap:8px;
}

@media (max-width: 768px){
    .view-hero{
        padding:18px 16px 16px;
    }

    .view-hero-title{
        font-size:21px;
    }

    .view-form{
        padding:14px;
    }

    .view-form .field-search,
    .view-form .field-brgy,
    .view-form .field-date,
    .view-form .field-size,
    .view-form .filter-actions{
        flex:1 1 100%;
        min-width:100%;
    }

    .view-form .filter-actions{
        display:grid;
        grid-template-columns:1fr 1fr;
    }

    .view-btn.search,
    .view-form .filter-actions .view-btn{
        width:100%;
    }

    .view-actions{
        overflow-x:auto;
        padding-bottom:2px;
    }
}
</style>

<div class="view-page">
    <div class="page-card">

        <div class="view-hero">
            <div>
                <h1 class="view-hero-title"><?= h($viewTitle) ?></h1>
                <div class="view-hero-subtitle"><?= h($viewSubtitle) ?></div>
            </div>

            <div class="view-total">
                <?= h($viewTotalLabel) ?>
                <strong><?= (int)$totalRows ?></strong>
            </div>
        </div>

        <div class="page-body">
            <div class="view-stack">

                <div class="view-category-nav" aria-label="Profile age categories">
                    <a class="view-btn <?= !$isAdultView ? 'active' : '' ?>" href="<?= h(view_groupUrl($SELF, 'adolescent')) ?>" <?= !$isAdultView ? 'aria-current="page"' : '' ?>>Adolescents</a>
                    <a class="view-btn <?= $isAdultView ? 'active' : '' ?>" href="<?= h(view_groupUrl($SELF, 'adult')) ?>" <?= $isAdultView ? 'aria-current="page"' : '' ?>>20 Years Old and Above</a>
                </div>

                <div class="view-section">
                    <div class="view-section-head">
                        <h2 class="view-section-title">Search & Filters</h2>
                    </div>

                    <form method="get" action="<?= h($SELF) ?>" class="view-form">
                        <input type="hidden" name="group" value="<?= h($profileGroup) ?>">
                        <div class="field-search">
                            <input type="text" name="search" value="<?= h($search) ?>" placeholder="Search by name, ID, barangay, or address" aria-label="Search by name, ID, barangay, or address">
                        </div>

                        <div class="field-brgy">
                            <input type="text" name="barangay" value="<?= h($filterBrgy) ?>" placeholder="Barangay" aria-label="Barangay">
                        </div>

                        <div class="field-date">
                            <input type="date" name="birthdate" value="<?= h($filterBirthdate) ?>" placeholder="Birthdate" aria-label="Birthdate" title="Birthdate">
                        </div>

                        <div class="field-size">
                            <select name="per_page" aria-label="Rows per page">
                                <?php foreach ($allowedPerPage as $choice): ?>
                                    <option value="<?= (int)$choice ?>" <?= $perPage === $choice ? 'selected' : '' ?>><?= (int)$choice ?> rows</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-actions">
                            <button class="view-btn search" type="submit">Search</button>
                            <a class="view-btn" href="<?= h($clearUrl) ?>">Clear</a>
                        </div>
                    </form>
                </div>

                <div class="view-section">
                    <div class="view-section-head">
                        <h2 class="view-section-title"><?= h($viewListTitle) ?></h2>
                        <?php if (!$isAdultView): ?>
                         
                        <?php endif; ?>
                    </div>

                    <div class="view-table-wrap">
                        <?php if (!$rows): ?>
                            <div class="view-empty">No <?= $isAdultView ? '20 years old and above' : 'adolescent' ?> profiles found.</div>
                        <?php else: ?>
                            <table class="view-table">
                                <thead>
                                    <tr>
                                        <th>Full Name</th>
                                        <th>Birthday</th>
                                        <th>Barangay</th>
                                        <th class="view-nowrap">Latest Submission</th>
                                        <th class="view-nowrap">Submissions</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if ($useNew): ?>
                                    <?php foreach ($rows as $r): ?>
                                        <?php
                                            $aid = (int)($r['id'] ?? 0);
                                            $name = trim((string)($r['name'] ?? ''));
                                            $dobIso = normalizeDobToIso((string)($r['date_of_birth'] ?? '')) ?: trim((string)($r['date_of_birth'] ?? ''));
                                            $brgy = trim((string)($r['barangay'] ?? ''));

                                            $stat = $statsByAdolescentId[$aid] ?? ['submissions'=>0, 'latest'=>''];
                                            $latestSubmission = trim((string)($stat['latest'] ?? ''));
                                            $submissions = (int)($stat['submissions'] ?? 0);
                                            if ($latestSubmission === '') $latestSubmission = '—';

                                            $viewHistory = $DETAIL_URL . "?adolescent_id=" . urlencode((string)$aid) . "#history";
                                            $profileExport = $EXPORT_URL . '?' . http_build_query([
                                                'download' => 1,
                                                'kind' => 'snapshot',
                                                'age_group' => 'adult',
                                                'profile_id' => $aid,
                                            ]);
                                            $newUpdate = '';
                                            $editLatest = '';

                                            if (!$isAdultView) {
                                                $newUpdate = $ADD_URL . "?adolescent_id=" . urlencode((string)$aid);
                                                $latestEncId  = (int)($latestEncounterIdByAdolescentId[$aid] ?? 0);
                                                $latestSnapId = (int)($latestSnapshotIdByAdolescentId[$aid] ?? 0);
                                                if ($latestEncId > 0) {
                                                    $editLatest = $ADD_URL
                                                        . "?adolescent_id=" . urlencode((string)$aid)
                                                        . "&encounter_id=" . urlencode((string)$latestEncId)
                                                        . "&edit_latest=1";
                                                } elseif ($latestSnapId > 0) {
                                                    $editLatest = $ADD_URL . "?id=" . urlencode((string)$latestSnapId) . "&edit_latest=1";
                                                }
                                            }
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="view-name view-clip" title="<?= h($name) ?>"><?= h($name !== '' ? $name : '—') ?></div>
                                                <div class="view-sub"><?= $isAdultView ? 'Profile ID' : 'Adolescent ID' ?>: <?= h((string)$aid) ?></div>
                                            </td>
                                            <td class="view-nowrap"><?= h($dobIso !== '' ? $dobIso : '—') ?></td>
                                            <td><div class="view-clip" title="<?= h($brgy) ?>"><?= h($brgy !== '' ? $brgy : '—') ?></div></td>
                                            <td class="view-nowrap"><?= h($latestSubmission) ?></td>
                                            <td class="view-nowrap"><?= h((string)$submissions) ?></td>
                                            <td>
                                                <div class="view-actions">
                                                    <a class="view-btn history" href="<?= h($viewHistory) ?>">History</a>
                                                    <?php if ($isAdultView): ?>
                                                        <a class="view-btn export" href="<?= h($profileExport) ?>">Export to Excel</a>
                                                    <?php else: ?>
                                                        <a class="view-btn update" href="<?= h($newUpdate) ?>">+ New Update</a>
                                                        <?php if ($editLatest !== ''): ?>
                                                            <a class="view-btn edit" href="<?= h($editLatest) ?>">Edit Latest</a>
                                                        <?php else: ?>
                                                            <span class="view-btn disabled" aria-disabled="true">Edit Latest</span>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <?php foreach ($rows as $r): ?>
                                        <?php
                                            $latestId = (int)($r['id'] ?? 0);
                                            $name = trim((string)($r['name'] ?? ''));
                                            $dobIso = normalizeDobToIso((string)($r['date_of_birth'] ?? '')) ?: trim((string)($r['date_of_birth'] ?? ''));
                                            $brgy = trim((string)($r['locked_barangay'] ?? $r['barangay'] ?? ''));
                                            $aidLegacy = (int)($r['adolescent_id'] ?? 0);
                                            $latestDate = trim((string)($legacyStats[$aidLegacy]['latest'] ?? $r['view_latest'] ?? $r['interview_date'] ?? $r['encounter_date'] ?? $r['created_at'] ?? ''));
                                            if ($latestDate === '') $latestDate = '—';
                                            $subs = (int)($legacyStats[$aidLegacy]['submissions'] ?? $r['view_submissions'] ?? 1);
                                            $viewHistory = $DETAIL_URL . "?id=" . urlencode((string)$latestId) . "#history";
                                            $profileExport = $EXPORT_URL . '?' . http_build_query([
                                                'download' => 1,
                                                'kind' => 'snapshot',
                                                'age_group' => 'adult',
                                                'profile_id' => $aidLegacy > 0 ? $aidLegacy : $latestId,
                                                'profile_key' => $aidLegacy > 0 ? 'adolescent' : 'encounter',
                                            ]);
                                            $addUpdate = $isAdultView ? '' : ($ADD_URL . "?id=" . urlencode((string)$latestId));
                                            $editLatest = $isAdultView ? '' : ($ADD_URL . "?id=" . urlencode((string)$latestId) . "&edit_latest=1");
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="view-name view-clip" title="<?= h($name) ?>"><?= h($name !== '' ? $name : '—') ?></div>
                                                <div class="view-sub">Legacy ID: <?= h((string)$latestId) ?></div>
                                            </td>
                                            <td class="view-nowrap"><?= h($dobIso !== '' ? $dobIso : '—') ?></td>
                                            <td><div class="view-clip" title="<?= h($brgy) ?>"><?= h($brgy !== '' ? $brgy : '—') ?></div></td>
                                            <td class="view-nowrap"><?= h($latestDate) ?></td>
                                            <td class="view-nowrap"><?= h((string)$subs) ?></td>
                                            <td>
                                                <div class="view-actions">
                                                    <a class="view-btn history" href="<?= h($viewHistory) ?>">History</a>
                                                    <?php if ($isAdultView): ?>
                                                        <a class="view-btn export" href="<?= h($profileExport) ?>">Export to Excel</a>
                                                    <?php else: ?>
                                                        <a class="view-btn update" href="<?= h($addUpdate) ?>">+ New Update</a>
                                                        <a class="view-btn edit" href="<?= h($editLatest) ?>">Edit Latest</a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>

                    <div class="view-pagination">
                        <div class="view-page-status">
                            Page <?= (int)$page ?> of <?= (int)$totalPages ?> &middot; Showing <?= (int)count($rows) ?> of <?= (int)$totalRows ?> profiles
                        </div>
                        <div class="view-page-actions">
                            <a class="view-btn <?= $page <= 1 ? 'disabled' : '' ?>" <?= $page <= 1 ? 'aria-disabled="true"' : 'href="' . h(view_pageUrl($SELF, $page - 1, $perPage)) . '"' ?>>Previous</a>
                            <a class="view-btn <?= $page >= $totalPages ? 'disabled' : '' ?>" <?= $page >= $totalPages ? 'aria-disabled="true"' : 'href="' . h(view_pageUrl($SELF, $page + 1, $perPage)) . '"' ?>>Next</a>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
