<?php
declare(strict_types=1);

/**
 * /import file/import_backup.php
 *
 * FINAL SAFE VERSION
 * - Upload CSV backup
 * - Restore data into adolescents + encounters
 * - Uses adolescent_id + full_name as primary identifier
 * - Falls back to full_name + date_of_birth if needed
 * - Updates existing adolescent master record when matched
 * - Skips duplicate encounter date for same adolescent
 * - Prevents duplicate master rows inside the same import file
 * - DOES NOT force insert the database primary key from the CSV
 * - Designed for emergency restore from backup files
 * - Maximum upload size: 20MB
 */

require_once __DIR__ . "/../config/auth.php";
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/education_options.php";
require_once __DIR__ . "/../includes/fertility_validation.php";

if (!($conn instanceof PDO)) {
    die("<h3>ERROR</h3><p>Database connection is not PDO.</p>");
}
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* =========================================================
   HELPERS
========================================================= */
function imp_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function imp_backup_resultNeedsAttention(array $result): bool {
    $status = strtolower(trim((string)($result['status'] ?? '')));
    $message = strtolower(trim((string)($result['message'] ?? '')));

    if (in_array($status, ['failed', 'skipped', 'warning', 'duplicate'], true)) {
        return true;
    }

    return str_contains($message, 'warning')
        || str_contains($message, 'duplicate')
        || str_contains($message, 'already exists')
        || str_contains($message, 'skipped')
        || str_contains($message, 'missing');
}

function imp_clean($v): string {
    $v = trim((string)$v);
    $v = str_replace("\xC2\xA0", ' ', $v);
    $v = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $v) ?? $v;
    $v = preg_replace('/\s+/', ' ', $v) ?? $v;
    return trim($v);
}

function imp_null($v): ?string {
    $v = imp_clean($v);
    if ($v === '') return null;

    $low = imp_lower($v);
    if (in_array($low, ['n/a', 'na', 'null', 'none', 'unknown', '-', '--'], true)) {
        return null;
    }

    return $v;
}

function imp_backup_value(array $data, array $keys): ?string {
    foreach ($keys as $key) {
        if (array_key_exists($key, $data) && imp_clean($data[$key]) !== '') {
            return imp_clean($data[$key]);
        }
    }
    return null;
}

function imp_backup_education(array $data): array {
    $warnings = [];
    $currentlyEnrolled = imp_null($data['currently_enrolled'] ?? null);
    if ($currentlyEnrolled !== null) {
        $low = imp_lower($currentlyEnrolled);
        if (in_array($low, ['yes', 'y', '1', 'true', 'opo'], true)) {
            $currentlyEnrolled = 'Yes';
        } elseif (in_array($low, ['no', 'n', '0', 'false', 'hindi'], true)) {
            $currentlyEnrolled = 'No';
        }
    }

    $education = ahdisNormalizeEducationAttainment((string)imp_backup_value($data, [
        'educational_attainment',
        'education',
        'educational attainment',
    ]));

    $gradeRaw = imp_backup_value($data, [
        'grade_year_level',
        'year_level',
        'grade / year level',
        'grade year level',
    ]);

    $gradeYear = null;
    if ($education !== '') {
        if ($education === 'No Education') {
            if ($gradeRaw !== null) {
                $warnings[] = 'Grade / Year Level cleared because Educational Attainment is No Education.';
            }
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

    $lastSchool = ahdisNormalizeLastSchoolAttendedType((string)imp_backup_value($data, [
        'last_school_attended_type',
        'last_school_attended',
        'last school attended',
    ])) ?: null;

    $reasonValues = ahdisNormalizeReasonsNotAttendingSchool(imp_backup_value($data, [
        'reason_not_attending_school',
        'reason_for_not_attending_school',
        'reason for not attending school',
    ]));
    $reason = $reasonValues ? ahdisSerializeReasonsNotAttendingSchool($reasonValues) : null;
    if ($currentlyEnrolled === 'Yes') {
        $reason = null;
    } elseif ($currentlyEnrolled === 'No' && $reason === null) {
        $warnings[] = 'Reason for not attending school is required when Currently Enrolled is No.';
    }

    $reasonOther = imp_backup_value($data, [
        'reason_not_attending_school_other',
        'other_reason_for_not_attending_school',
        'other reason for not attending school',
    ]);
    if (!ahdisReasonsNotAttendingSchoolContains($reason, 'Other')) {
        $reasonOther = null;
    }

    $vocationalOther = imp_backup_value($data, [
        'vocational_training_other',
        'other_vocational_training',
        'other vocational training',
    ]);
    if ($education === 'Vocational' && $vocationalOther !== null && ($gradeYear === null || $gradeYear === 'Other Vocational Training')) {
        $gradeYear = $vocationalOther;
    }
    $vocationalOther = null;

    return [
        'currently_enrolled' => $currentlyEnrolled,
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

function imp_lower(string $v): string {
    return function_exists('mb_strtolower')
        ? mb_strtolower($v, 'UTF-8')
        : strtolower($v);
}

function imp_norm($v): string {
    return imp_lower(imp_clean((string)$v));
}

function imp_parse_date(?string $value): ?string {
    $value = imp_clean((string)$value);
    if ($value === '') return null;

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }

    $formats = [
        'm/d/Y',
        'd/m/Y',
        'Y/m/d',
        'm-d-Y',
        'd-m-Y',
        'F j, Y',
        'M j, Y',
    ];

    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $value);
        if ($dt instanceof DateTime && $dt->format($fmt) === $value) {
            return $dt->format('Y-m-d');
        }
    }

    $ts = strtotime($value);
    if ($ts !== false) {
        return date('Y-m-d', $ts);
    }

    return null;
}

function imp_read_csv_assoc(string $path): array {
    $rows = [];
    $handle = fopen($path, 'r');

    if (!$handle) {
        throw new RuntimeException('Cannot read file.');
    }

    $headers = fgetcsv($handle);
    if (!$headers) {
        fclose($handle);
        throw new RuntimeException('Invalid CSV file.');
    }

    $headers = array_map(function ($h) {
        $h = (string)$h;
        $h = preg_replace('/^\xEF\xBB\xBF/', '', $h) ?? $h;
        $h = trim($h);
        return strtolower($h);
    }, $headers);

    while (($row = fgetcsv($handle)) !== false) {
        if ($row === [null] || $row === false) {
            continue;
        }

        $assoc = [];
        foreach ($headers as $i => $header) {
            $assoc[$header] = isset($row[$i]) ? trim((string)$row[$i]) : '';
        }

        $hasValue = false;
        foreach ($assoc as $v) {
            if (trim((string)$v) !== '') {
                $hasValue = true;
                break;
            }
        }

        if ($hasValue) {
            $rows[] = $assoc;
        }
    }

    fclose($handle);
    return [$headers, $rows];
}

function imp_has_required_headers(array $headers, array $required): array {
    $missing = [];
    $set = array_flip($headers);

    foreach ($required as $col) {
        if (!isset($set[strtolower($col)])) {
            $missing[] = $col;
        }
    }

    return $missing;
}

function imp_encounter_exists(PDO $conn, int $adolescentId, string $encounterDate): bool {
    $stmt = $conn->prepare("
        SELECT id
        FROM encounters
        WHERE adolescent_id = :aid
          AND TRIM(COALESCE(encounter_date,'')) = TRIM(:dt)
        LIMIT 1
    ");
    $stmt->execute([
        ':aid' => $adolescentId,
        ':dt'  => $encounterDate,
    ]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Match existing adolescent using:
 * 1) adolescent_id + full_name
 * 2) adolescent_id only, but only return when name also matches after normalization
 * 3) full_name + date_of_birth
 */
function imp_find_existing_adolescent(PDO $conn, int $fileId, string $name, ?string $dob): ?array {
    if ($fileId > 0 && $name !== '') {
        $stmt = $conn->prepare("
            SELECT *
            FROM adolescents
            WHERE id = :id
              AND LOWER(TRIM(COALESCE(full_name,''))) = LOWER(TRIM(:name))
            LIMIT 1
        ");
        $stmt->execute([
            ':id' => $fileId,
            ':name' => $name,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
    }

    if ($fileId > 0 && $name !== '') {
        $stmt = $conn->prepare("
            SELECT *
            FROM adolescents
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $fileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && imp_norm((string)($row['full_name'] ?? '')) === imp_norm($name)) {
            return $row;
        }
    }

    if ($name !== '' && $dob !== null) {
        $stmt = $conn->prepare("
            SELECT *
            FROM adolescents
            WHERE LOWER(TRIM(COALESCE(full_name,''))) = LOWER(TRIM(:name))
              AND TRIM(COALESCE(date_of_birth,'')) = TRIM(:dob)
            LIMIT 1
        ");
        $stmt->execute([
            ':name' => $name,
            ':dob' => $dob,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
    }

    return null;
}

/**
 * Prevent duplicate encounter rows inside the same imported CSV batch.
 */
function imp_make_batch_key(int $fileId, string $name, ?string $dob, string $encounterDate): string {
    return $fileId . '|' . imp_norm($name) . '|' . ($dob ?? '') . '|' . trim($encounterDate);
}

/* =========================================================
   ENSURE MINIMUM SCHEMA
========================================================= */
$conn->exec("
CREATE TABLE IF NOT EXISTS adolescents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    full_name TEXT,
    date_of_birth TEXT,
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
)
");

$conn->exec("
CREATE TABLE IF NOT EXISTS encounters (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    adolescent_id INTEGER NOT NULL,
    encounter_date TEXT NOT NULL,
    pregnant TEXT,
    times_pregnant INTEGER,
    living_children INTEGER,
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
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(adolescent_id) REFERENCES adolescents(id)
)
");

/* =========================================================
   CSRF
========================================================= */
if (empty($_SESSION['csrf_import_backup'])) {
    $_SESSION['csrf_import_backup'] = bin2hex(random_bytes(32));
}

$success = [];
$errors = [];
$rowResults = [];

$summary = [
    'total_rows' => 0,
    'restored' => 0,
    'new_master' => 0,
    'updated_master' => 0,
    'encounters_added' => 0,
    'duplicate_encounters' => 0,
    'duplicate_master_rows' => 0,
    'skipped' => 0,
    'failed' => 0,
];

$maxFileSize = 20 * 1024 * 1024;
$showSuccessfulRows = (string)($_POST['show_successful_rows'] ?? $_GET['show_successful_rows'] ?? '') === '1';
$resultPage = max(1, (int)($_GET['result_page'] ?? 1));
$resultPerPage = 100;
$transactionBatchSize = 500;

/* =========================
   HANDLE UPLOAD
========================= */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_FILES['file'])) {
    try {
        $postedToken = (string)($_POST['csrf_token'] ?? '');
        $sessionToken = (string)($_SESSION['csrf_import_backup'] ?? '');

        if ($sessionToken === '' || $postedToken === '' || !hash_equals($sessionToken, $postedToken)) {
            throw new RuntimeException('Invalid CSRF token.');
        }

        $file = $_FILES['file'];

        if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed.');
        }

        $filename = (string)($file['name'] ?? 'backup.csv');
        $filesize = (int)($file['size'] ?? 0);

        if ($filesize <= 0) {
            throw new RuntimeException('Uploaded file is empty.');
        }

        if ($filesize > $maxFileSize) {
            throw new RuntimeException('File is too large. Maximum allowed size is 20MB.');
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            throw new RuntimeException('Only CSV files are allowed.');
        }

        [$headers, $rows] = imp_read_csv_assoc((string)$file['tmp_name']);

        $requiredHeaders = ['adolescent_id', 'full_name', 'date_of_birth', 'barangay', 'municipality'];
        $missingHeaders = imp_has_required_headers($headers, $requiredHeaders);

        if ($missingHeaders) {
            throw new RuntimeException('Missing required column(s): ' . implode(', ', $missingHeaders));
        }

        $summary['total_rows'] = count($rows);

        $conn->beginTransaction();
        $rowsSinceCommit = 0;

        $seenInFile = [];

        foreach ($rows as $index => $data) {
            $rowNo = $index + 2;

            try {
                $fileId = (int)($data['adolescent_id'] ?? 0);
                $name = imp_clean($data['full_name'] ?? '');
                $dob  = imp_parse_date($data['date_of_birth'] ?? '');
                $barangay = imp_null($data['barangay'] ?? '');
                $municipality = imp_null($data['municipality'] ?? '');
                $presentAddress = imp_null($data['present_address'] ?? '');

                if ($presentAddress === null && $barangay && $municipality) {
                    $presentAddress = $barangay . ', ' . $municipality;
                }
                $educationImport = imp_backup_education($data);

                if ($fileId <= 0 || $name === '' || $dob === null || $barangay === null || $municipality === null) {
                    $summary['skipped']++;
                    $rowResults[] = [
                        'row' => $rowNo,
                        'name' => $name !== '' ? $name : 'N/A',
                        'status' => 'Skipped',
                        'message' => 'Missing required identifier fields.'
                    ];
                    continue;
                }

                // Validate before creating/updating the adolescent master row.
                // Living children is independent of the pregnancy count.
                $livingChildren = ahdisParseNonNegativeWholeNumber(
                    $data['living_children'] ?? null,
                    'Number of living children'
                );

                $encounterDate = imp_parse_date($data['encounter_date'] ?? '')
                    ?: imp_parse_date($data['interview_date'] ?? '')
                    ?: date('Y-m-d');

                $batchKey = imp_make_batch_key($fileId, $name, $dob, $encounterDate);
                if (isset($seenInFile[$batchKey])) {
                    $summary['duplicate_encounters']++;
                    $rowResults[] = [
                        'row' => $rowNo,
                        'name' => $name,
                        'status' => 'Skipped',
                        'message' => 'Duplicate encounter row found in the same import file.'
                    ];
                    continue;
                }
                $seenInFile[$batchKey] = true;

                /* =========================
                   CHECK EXISTING PERSON
                ========================= */
                $existing = imp_find_existing_adolescent($conn, $fileId, $name, $dob);
                $adolescentId = $existing ? (int)$existing['id'] : 0;

                /* =========================
                   INSERT OR UPDATE ADOLESCENT
                ========================= */
                if ($adolescentId <= 0) {
                    // SAFE INSERT: let DB generate its own ID
                    $insert = $conn->prepare("
                        INSERT INTO adolescents (
                            full_name,
                            date_of_birth,
                            barangay,
                            municipality,
                            present_address,
                            educational_attainment,
                            currently_enrolled,
                            grade_year_level,
                            last_school_attended_type,
                            reason_not_attending_school,
                            reason_for_not_attending_school,
                            reason_not_attending_school_other,
                            vocational_training_other,
                            created_at
                        ) VALUES (
                            :name,
                            :dob,
                            :brgy,
                            :mun,
                            :addr,
                            :educational_attainment,
                            :currently_enrolled,
                            :grade_year_level,
                            :last_school_attended_type,
                            :reason_not_attending_school,
                            :reason_for_not_attending_school,
                            :reason_not_attending_school_other,
                            :vocational_training_other,
                            CURRENT_TIMESTAMP
                        )
                    ");

                    $insert->execute([
                        ':name' => $name,
                        ':dob'  => $dob,
                        ':brgy' => $barangay,
                        ':mun'  => $municipality,
                        ':addr' => $presentAddress,
                        ':educational_attainment' => $educationImport['educational_attainment'],
                        ':currently_enrolled' => $educationImport['currently_enrolled'],
                        ':grade_year_level' => $educationImport['grade_year_level'],
                        ':last_school_attended_type' => $educationImport['last_school_attended_type'],
                        ':reason_not_attending_school' => $educationImport['reason_not_attending_school'],
                        ':reason_for_not_attending_school' => $educationImport['reason_for_not_attending_school'],
                        ':reason_not_attending_school_other' => $educationImport['reason_not_attending_school_other'],
                        ':vocational_training_other' => $educationImport['vocational_training_other']
                    ]);

                    $adolescentId = (int)$conn->lastInsertId();
                    $summary['new_master']++;
                } else {
                    $existingName = imp_clean((string)($existing['full_name'] ?? ''));
                    if (imp_norm($existingName) !== imp_norm($name)) {
                        $summary['skipped']++;
                        $rowResults[] = [
                            'row' => $rowNo,
                            'name' => $name,
                            'status' => 'Skipped',
                            'message' => 'ID number already exists but is linked to a different name.'
                        ];
                        continue;
                    }

                    $update = $conn->prepare("
                        UPDATE adolescents
                        SET full_name = :name,
                            date_of_birth = COALESCE(:dob, date_of_birth),
                            barangay = COALESCE(:brgy, barangay),
                            municipality = COALESCE(:mun, municipality),
                            present_address = COALESCE(:addr, present_address),
                            educational_attainment = COALESCE(:educational_attainment, educational_attainment),
                            currently_enrolled = COALESCE(:currently_enrolled, currently_enrolled),
                            grade_year_level = :grade_year_level,
                            last_school_attended_type = COALESCE(:last_school_attended_type, last_school_attended_type),
                            reason_not_attending_school = CASE
                                WHEN :currently_enrolled = 'Yes' THEN NULL
                                ELSE COALESCE(:reason_not_attending_school, reason_not_attending_school)
                            END,
                            reason_for_not_attending_school = CASE
                                WHEN :currently_enrolled = 'Yes' THEN NULL
                                ELSE COALESCE(:reason_for_not_attending_school, reason_for_not_attending_school)
                            END,
                            reason_not_attending_school_other = :reason_not_attending_school_other,
                            vocational_training_other = :vocational_training_other,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = :id
                    ");

                    $update->execute([
                        ':name' => $name,
                        ':dob'  => $dob,
                        ':brgy' => $barangay,
                        ':mun'  => $municipality,
                        ':addr' => $presentAddress,
                        ':educational_attainment' => $educationImport['educational_attainment'],
                        ':currently_enrolled' => $educationImport['currently_enrolled'],
                        ':grade_year_level' => $educationImport['grade_year_level'],
                        ':last_school_attended_type' => $educationImport['last_school_attended_type'],
                        ':reason_not_attending_school' => $educationImport['reason_not_attending_school'],
                        ':reason_for_not_attending_school' => $educationImport['reason_for_not_attending_school'],
                        ':reason_not_attending_school_other' => $educationImport['reason_not_attending_school_other'],
                        ':vocational_training_other' => $educationImport['vocational_training_other'],
                        ':id'   => $adolescentId
                    ]);

                    $summary['updated_master']++;
                }

                /* =========================
                   ENCOUNTER DATE
                ========================= */
                if (imp_encounter_exists($conn, $adolescentId, $encounterDate)) {
                    $summary['duplicate_encounters']++;
                    $rowResults[] = [
                        'row' => $rowNo,
                        'name' => $name,
                        'status' => 'Skipped',
                        'message' => 'The adolescent already existed and the encounter was skipped because the same encounter date already exists.'
                    ];
                    continue;
                }

                /* =========================
                   INSERT ENCOUNTER
                ========================= */
                $encounter = $conn->prepare("
                    INSERT INTO encounters (
                        adolescent_id,
                        encounter_date,
                        pregnant,
                        times_pregnant,
                        living_children,
                        educational_attainment,
                        currently_enrolled,
                        grade_year_level,
                        last_school_attended_type,
                        reason_not_attending_school,
                        reason_for_not_attending_school,
                        reason_not_attending_school_other,
                        vocational_training_other,
                        programs_applied,
                        data_json,
                        created_at
                    ) VALUES (
                        :aid,
                        :date,
                        :preg,
                        :times,
                        :children,
                        :educational_attainment,
                        :currently_enrolled,
                        :grade_year_level,
                        :last_school_attended_type,
                        :reason_not_attending_school,
                        :reason_for_not_attending_school,
                        :reason_not_attending_school_other,
                        :vocational_training_other,
                        :prog,
                        :json,
                        CURRENT_TIMESTAMP
                    )
                ");

                $jsonPayload = array_merge($data, [
                    'currently_enrolled' => $educationImport['currently_enrolled'],
                    'education' => $educationImport['educational_attainment'],
                    'educational_attainment' => $educationImport['educational_attainment'],
                    'year_level' => $educationImport['grade_year_level'],
                    'grade_year_level' => $educationImport['grade_year_level'],
                    'last_school_attended_type' => $educationImport['last_school_attended_type'],
                    'reason_not_attending_school' => $educationImport['reason_not_attending_school'],
                    'reason_for_not_attending_school' => $educationImport['reason_for_not_attending_school'],
                    'reason_not_attending_school_other' => $educationImport['reason_not_attending_school_other'],
                    'vocational_training_other' => $educationImport['vocational_training_other'],
                ]);

                $jsonData = json_encode($jsonPayload, JSON_UNESCAPED_UNICODE);
                if ($jsonData === false) {
                    $jsonData = '{}';
                }

                $encounter->execute([
                    ':aid'      => $adolescentId,
                    ':date'     => $encounterDate,
                    ':preg'     => imp_null($data['pregnant'] ?? null),
                    ':times'    => is_numeric($data['times_pregnant'] ?? null) ? (int)$data['times_pregnant'] : null,
                    ':children' => $livingChildren,
                    ':educational_attainment' => $educationImport['educational_attainment'],
                    ':currently_enrolled' => $educationImport['currently_enrolled'],
                    ':grade_year_level' => $educationImport['grade_year_level'],
                    ':last_school_attended_type' => $educationImport['last_school_attended_type'],
                    ':reason_not_attending_school' => $educationImport['reason_not_attending_school'],
                    ':reason_for_not_attending_school' => $educationImport['reason_for_not_attending_school'],
                    ':reason_not_attending_school_other' => $educationImport['reason_not_attending_school_other'],
                    ':vocational_training_other' => $educationImport['vocational_training_other'],
                    ':prog'     => imp_null($data['programs_applied'] ?? null),
                    ':json'     => $jsonData
                ]);

                $summary['encounters_added']++;
                $summary['restored']++;
                $restoreMessage = 'Backup row restored successfully.';
                if (!empty($educationImport['warnings'])) {
                    $restoreMessage .= ' Warning: ' . implode(' ', array_unique($educationImport['warnings']));
                }

                $resultEntry = [
                    'row' => $rowNo,
                    'name' => $name,
                    'status' => 'Restored',
                    'message' => $restoreMessage
                ];
                if ($showSuccessfulRows || imp_backup_resultNeedsAttention($resultEntry)) {
                    $rowResults[] = $resultEntry;
                }
            } catch (Throwable $rowError) {
                $summary['failed']++;
                error_log('[AHDIS Backup Restore] Row ' . $rowNo . ' failed: ' . $rowError->getMessage());
                $rowResults[] = [
                    'row' => $rowNo,
                    'name' => imp_clean($data['full_name'] ?? 'N/A'),
                    'status' => 'Failed',
                    'message' => 'This backup row could not be restored. Please review its identifier and date values.'
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
        $success[] = "Backup restore completed successfully.";

    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log('[AHDIS Backup Restore] Fatal restore failure: ' . $e->getMessage());
        $errors[] = 'The backup restore could not be completed. Please check that the file is a readable AHDIS CSV backup.';
    }

    $_SESSION['csrf_import_backup'] = bin2hex(random_bytes(32));
}

$attentionResults = array_values(array_filter($rowResults, 'imp_backup_resultNeedsAttention'));
$successfulResults = array_values(array_filter($rowResults, static fn(array $r): bool => !imp_backup_resultNeedsAttention($r)));
$displayResults = $showSuccessfulRows ? $rowResults : $attentionResults;
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
    --imp-blue:#2563eb;
    --imp-pink:#d98ac8;
    --imp-soft:#f8fbff;
    --imp-border:#dbe7f5;
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
    max-width:1280px;
    margin:0 auto;
    padding:14px 0 36px;
}

.import-card{
    background:linear-gradient(180deg,#ffffff 0%,#fbfdff 100%);
    border:1px solid rgba(59,130,246,.12);
    border-radius:28px;
    box-shadow:0 24px 60px rgba(15,23,42,.10), 0 6px 20px rgba(37,99,235,.06);
    overflow:hidden;
}

.import-hero{
    padding:16px 22px;
    min-height:88px;
    color:#fff;
    display:flex;
    align-items:center;
    background:
        radial-gradient(circle at top right, rgba(255,255,255,.16), transparent 26%),
        linear-gradient(135deg, #0f172a 0%, #163a73 42%, #db89c7 76%, #d694c8 100%);
}

.import-hero h2{
    margin:0;
    font-size:30px;
    font-weight:900;
    letter-spacing:.2px;
}

.import-body{
    padding:18px;
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
    line-height:1.6;
}

.alert{
    margin-bottom:14px;
    padding:14px 16px;
    border-radius:14px;
    font-weight:800;
    border:1px solid transparent;
    line-height:1.5;
}

.alert.success{
    background:var(--imp-success-bg);
    color:var(--imp-success-text);
    border-color:var(--imp-success-border);
}

.alert.error{
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
    box-sizing:border-box;
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

form.is-loading .btn{
    opacity:.68;
    pointer-events:none;
}

.btn{
    padding:13px 24px;
    border:none;
    border-radius:999px;
    background:linear-gradient(135deg, #2563eb 0%, #1d4ed8 55%, #163a73 100%);
    color:#fff;
    font-weight:900;
    font-size:16px;
    cursor:pointer;
    box-shadow:0 10px 22px rgba(29,78,216,.28);
    transition:
        background-color 120ms ease,
        color 120ms ease,
        border-color 120ms ease,
        box-shadow 120ms ease,
        opacity 120ms ease;
}

.btn:hover{
    transform:none;
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
    max-height:520px;
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
}

.result-table th{
    position:sticky;
    top:0;
    background:linear-gradient(180deg, #dfeafb 0%, #d2e1f7 100%);
    font-size:14px;
    font-weight:900;
}

.badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:92px;
    padding:6px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:900;
}

.badge.ok{
    background:#ecfdf5;
    color:#065f46;
    border:1px solid #a7f3d0;
}

.badge.duplicate{
    background:#fff1f2;
    color:#9f1239;
    border:1px solid #fecdd3;
}

.badge.warn,
.badge.skip{
    background:#fff7ed;
    color:#9a3412;
    border:1px solid #fed7aa;
}

.badge.fail{
    background:#fef2f2;
    color:#991b1b;
    border:1px solid #fecaca;
}

@media (max-width: 1080px){
    .summary-grid{
        grid-template-columns:repeat(3, minmax(120px,1fr));
    }
}

@media (max-width: 768px){
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

            <?php foreach ($success as $s): ?>
                <div class="alert success">Backup restore finished successfully.</div>
            <?php endforeach; ?>

            <?php foreach ($errors as $e): ?>
                <div class="alert error"><?= imp_h($e) ?></div>
            <?php endforeach; ?>

            <div class="section-box">
                <h3 class="section-title">Upload Backup File</h3>
                <p class="section-sub">
                    Upload the exported backup file to restore adolescent records if the original computer becomes unavailable,
                    damaged, or no longer turns on. Backup files may come from Google Drive, flash drive, or external hard drive.
                </p>

                <div class="alert warn">
                    Maximum file size: <strong>20MB</strong>. Use the most recent backup file to restore the latest records.
                </div>

                <form method="POST" enctype="multipart/form-data" class="js-import-form">
                    <input type="hidden" name="csrf_token" value="<?= imp_h($_SESSION['csrf_import_backup']) ?>">
                    <div class="form-row">
                        <input class="input-file" type="file" name="file" accept=".csv" required>
                        <button class="btn" type="submit">Restore Data</button>
                    </div>
                    <label class="import-option">
                        <input type="checkbox" name="show_successful_rows" value="1" <?= $showSuccessfulRows ? 'checked' : '' ?>>
                        Show successful rows after restore
                    </label>
                    <div class="import-loading">Loading... please wait while AHDIS restores the file.</div>
                </form>
            </div>

            <?php if ($summary['total_rows'] > 0): ?>
                <div class="section-box">
                    <h3 class="section-title">Restore Summary</h3>

                    <div class="summary-grid">
                        <div class="sum-box">
                            <div class="label">Total Rows</div>
                            <div class="value"><?= (int)$summary['total_rows'] ?></div>
                        </div>
                        <div class="sum-box">
                            <div class="label">Restored</div>
                            <div class="value"><?= (int)$summary['restored'] ?></div>
                        </div>
                        <div class="sum-box">
                            <div class="label">Duplicate</div>
                            <div class="value"><?= (int)($summary['duplicate_encounters'] + $summary['duplicate_master_rows']) ?></div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($rowResults)): ?>
                <div class="section-box">
                    <h3 class="section-title">Row Results</h3>
                    <p class="results-note">
                        Showing <?= $showSuccessfulRows ? 'all row results' : 'duplicates, failed rows, and warnings only' ?>.
                        <?= !$showSuccessfulRows && count($successfulResults) > 0 ? imp_h((string)count($successfulResults)) . ' successful rows are hidden to keep the page smooth.' : '' ?>
                    </p>

                    <?php if (!$pagedResults): ?>
                        <div class="alert success">No duplicate, failed, or warning rows to review.</div>
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
                                    <?php
                                    $rawStatus = (string)($r['status'] ?? '');
                                    $message = (string)($r['message'] ?? '');
                                    $isDuplicate = preg_match('/duplicate|already exists|duplicate encounter/i', $message) === 1;
                                    $hasWarning = preg_match('/warning/i', $message) === 1;
                                    $displayStatus = $rawStatus === 'Skipped' ? 'Warning' : $rawStatus;
                                    $badgeClass = 'ok';
                                    if ($hasWarning) {
                                        $badgeClass = 'warn';
                                        $displayStatus = 'Warning';
                                    }
                                    if ($rawStatus === 'Skipped') {
                                        $badgeClass = $isDuplicate ? 'duplicate' : 'warn';
                                        $displayStatus = $isDuplicate ? 'Duplicate' : 'Warning';
                                    }
                                    if ($rawStatus === 'Failed') {
                                        $badgeClass = 'fail';
                                        $displayStatus = 'Failed';
                                    }
                                    ?>
                                    <tr>
                                        <td><?= (int)$r['row'] ?></td>
                                        <td><?= imp_h($r['name']) ?></td>
                                        <td><span class="badge <?= imp_h($badgeClass) ?>"><?= imp_h($displayStatus) ?></span></td>
                                        <td><?= imp_h($r['message']) ?></td>
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
document.querySelectorAll('.js-import-form').forEach((form) => {
    form.addEventListener('submit', () => {
        form.classList.add('is-loading');
        form.querySelectorAll('button[type="submit"]').forEach((button) => {
            button.disabled = true;
            button.textContent = 'Loading...';
        });
    });
});
</script>

<?php require_once __DIR__ . "/../includes/footer.php"; ?>
