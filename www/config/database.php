<?php
declare(strict_types=1);

/**
 * /config/database.php
 * FULL FIXED UPDATED VERSION
 *
 * ✅ Forces database inside /www/data/app.db
 * ✅ Auto-creates /www/data folder
 * ✅ Uses one active SQLite database only; no hidden fallback DB
 * ✅ Keeps existing tables
 * ✅ Adds adolescents + encounters history model
 * ✅ Adds reports system
 * ✅ Safe schema evolution
 * ✅ Offline optimized SQLite
 * ✅ PHP Desktop deployment safe
 * ✅ Timezone fixed to Asia/Manila
 * ✅ Avoids UTC-based CURRENT_TIMESTAMP for new table defaults
 */

/* =========================
   APP TIMEZONE
========================= */
date_default_timezone_set('Asia/Manila');

/* =========================
   TIME HELPERS
========================= */
if (!function_exists('appNow')) {
    function appNow(): string
    {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('appToday')) {
    function appToday(): string
    {
        return date('Y-m-d');
    }
}

if (!function_exists('dbNowExpr')) {
    /**
     * SQLite expression for Manila local datetime.
     * Philippines is UTC+8 and does not use DST.
     */
    function dbNowExpr(): string
    {
        return "(strftime('%Y-%m-%d %H:%M:%S','now','+8 hours'))";
    }
}

/* =========================
   DATABASE AND LOG PATHS
   Production rule: one active SQLite database at /www/data/app.db.
========================= */

if (!function_exists('ahdisEnsureWritableDir')) {
    function ahdisEnsureWritableDir(string $dir): bool
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        if (!is_dir($dir)) {
            return false;
        }

        if (!is_writable($dir)) {
            @chmod($dir, 0777);
        }

        return is_writable($dir);
    }
}

$projectRoot = __DIR__ . '/../';
$projectDataDir = __DIR__ . '/../data';

$local = (string) getenv('LOCALAPPDATA');
$logBase = $local !== ''
    ? rtrim($local, "\\/") . DIRECTORY_SEPARATOR . 'AHDIS'
    : rtrim(sys_get_temp_dir(), "\\/") . DIRECTORY_SEPARATOR . 'AHDIS';

ahdisEnsureWritableDir($projectDataDir);
ahdisEnsureWritableDir($logBase);

$dbPath  = __DIR__ . '/../data/app.db';
$logFile = $logBase . DIRECTORY_SEPARATOR . 'php_error.log';
$dbPathForLog = is_file($dbPath) ? (realpath($dbPath) ?: $dbPath) : $dbPath;

if (!defined('AHDIS_DB_PATH')) {
    define('AHDIS_DB_PATH', $dbPath);
}

if (!defined('AHDIS_PHP_ERROR_LOG')) {
    define('AHDIS_PHP_ERROR_LOG', $logFile);
}

/* Ensure parent dir exists */
$dir = dirname($dbPath);
if (!is_dir($dir)) {
    @mkdir($dir, 0777, true);
}

/* =========================
   ERROR LOGGING
========================= */

@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('log_errors', '1');
@ini_set('error_log', $logFile);
error_reporting(E_ALL);

error_log('AHDIS DB PATH: ' . $dbPathForLog);

/* =========================
   ESCAPE HELPER
========================= */

if (!function_exists('hdb')) {
    function hdb(string $v): string {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}

/* =========================
   SAFE IDENTIFIERS
========================= */

if (!function_exists('dbIdent')) {
    function dbIdent(string $name): string {
        $name = trim($name);
        if ($name === '' || !preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new RuntimeException("Invalid identifier: " . $name);
        }
        return $name;
    }
}

if (!function_exists('dbColumnType')) {
    function dbColumnType(string $type): string {
        $type = trim($type);

        if ($type === '') {
            throw new RuntimeException("Invalid column type");
        }

        if (!preg_match('/^(TEXT|INTEGER|REAL|BLOB)(.*)$/i', $type)) {
            throw new RuntimeException("Invalid column type: " . $type);
        }

        return $type;
    }
}

/* =========================
   SCHEMA HELPERS
========================= */

if (!function_exists('tableExists')) {
    function tableExists(PDO $conn, string $table): bool {
        $table = dbIdent($table);

        $stmt = $conn->prepare("
            SELECT 1
            FROM sqlite_master
            WHERE type = 'table' AND name = :t
        ");

        $stmt->execute([":t" => $table]);

        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('dbHasColumn')) {
    function dbHasColumn(PDO $conn, string $table, string $col): bool {
        $table = dbIdent($table);
        $col   = dbIdent($col);

        $stmt = $conn->query("PRAGMA table_info(" . $table . ")");
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

        foreach ($rows as $r) {
            if (strcasecmp((string)($r["name"] ?? ''), $col) === 0) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('addColumnIfMissing')) {
    function addColumnIfMissing(PDO $conn, string $table, string $col, string $type): void {
        $table = dbIdent($table);
        $col   = dbIdent($col);
        $type  = dbColumnType($type);

        if (!tableExists($conn, $table)) {
            return;
        }

        if (dbHasColumn($conn, $table, $col)) {
            return;
        }

        $conn->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$type}");
    }
}

if (!function_exists('tableColumns')) {
    function tableColumns(PDO $conn, string $table): array {
        $table = dbIdent($table);

        $stmt = $conn->query("PRAGMA table_info(" . $table . ")");
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

        return array_column($rows, "name");
    }
}

if (!function_exists('seedMetaIfMissing')) {
    function seedMetaIfMissing(PDO $conn, string $key, string $value): void {
        $stmt = $conn->prepare("
            INSERT INTO app_meta (k, v)
            SELECT :k, :v
            WHERE NOT EXISTS (
                SELECT 1 FROM app_meta WHERE k = :k2
            )
        ");
        $stmt->execute([
            ':k'  => $key,
            ':v'  => $value,
            ':k2' => $key,
        ]);
    }
}

/* =========================
   DATABASE CONNECTION
========================= */

try {
    $conn = new PDO(
        "sqlite:" . $dbPath,
        null,
        null,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

    /* SQLite tuning */
    $conn->exec("PRAGMA foreign_keys = ON;");
    $conn->exec("PRAGMA busy_timeout = 10000;");
    $conn->exec("PRAGMA temp_store = MEMORY;");
    $conn->exec("PRAGMA cache_size = -20000;");

    try {
        $conn->exec("PRAGMA journal_mode = DELETE;");
    } catch (Throwable $journalError) {
        error_log("Unable to set SQLite DELETE journal mode: " . $journalError->getMessage());
    }

    try {
        $conn->exec("PRAGMA synchronous = NORMAL;");
    } catch (Throwable $ignored) {
    }

    $dbNow = dbNowExpr();

    /* =========================
       TABLES
    ========================= */

    /* app_meta */
    $conn->exec("
        CREATE TABLE IF NOT EXISTS app_meta (
            k TEXT PRIMARY KEY,
            v TEXT
        );
    ");

    /* USERS */
    $conn->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'user',
            status TEXT NOT NULL DEFAULT 'active',
            municipality_id INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT {$dbNow}
        );
    ");

    /* ADOLESCENT MOTHERS */
    $conn->exec("
        CREATE TABLE IF NOT EXISTS adolescent_mothers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,

            name TEXT,
            present_address TEXT,
            date_of_birth TEXT,
            age INTEGER,

            education TEXT,
            currently_enrolled TEXT,
            year_level TEXT,

            civil_status TEXT,
            living_situation TEXT,
            living_others TEXT,

            pregnant TEXT,
            prenatal_care TEXT,
            months_pregnant INTEGER,
            health_concerns TEXT,
            age_first_pregnancy INTEGER,
            living_children INTEGER,
            times_pregnant INTEGER,
            family_planning TEXT,

            occupation TEXT,
            place_of_work TEXT,
            monthly_income TEXT,
            ethnic_group TEXT,

            encoded_by TEXT,
            date_encoded TEXT,

            created_at TEXT NOT NULL DEFAULT {$dbNow}
        );
    ");

    /* ADOLESCENTS MASTER */
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

            created_at TEXT NOT NULL DEFAULT {$dbNow},
            updated_at TEXT
        );
    ");

    /* ENCOUNTERS */
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

            encoded_by TEXT,
            date_encoded TEXT,

            created_by_user_id INTEGER,

            created_at TEXT NOT NULL DEFAULT {$dbNow},

            verified_by_user_id INTEGER,
            verified_at TEXT,

            FOREIGN KEY(adolescent_id) REFERENCES adolescents(id)
        );
    ");

    /* AUDIT LOG */
    $conn->exec("
        CREATE TABLE IF NOT EXISTS audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,

            created_at TEXT NOT NULL DEFAULT {$dbNow},

            user_id INTEGER,
            username TEXT,

            action TEXT,
            entity TEXT,
            entity_id INTEGER,

            ip TEXT,
            user_agent TEXT,

            details TEXT
        );
    ");

    /* REPORTS TABLE */
    $conn->exec("
        CREATE TABLE IF NOT EXISTS reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,

            title TEXT NOT NULL,
            type TEXT UNIQUE NOT NULL,

            icon TEXT DEFAULT '📊',

            description TEXT,

            status INTEGER DEFAULT 1,

            created_at TEXT NOT NULL DEFAULT {$dbNow}
        );
    ");

    /* =========================
       SAFE SCHEMA EVOLUTION
    ========================= */

    addColumnIfMissing($conn, 'users', 'status', 'TEXT NOT NULL DEFAULT \'active\'');
    addColumnIfMissing($conn, 'users', 'municipality_id', 'INTEGER NOT NULL DEFAULT 0');
    addColumnIfMissing($conn, 'users', 'province', 'TEXT');
    addColumnIfMissing($conn, 'users', 'municipality', 'TEXT');
    addColumnIfMissing($conn, 'users', 'location_locked', 'INTEGER NOT NULL DEFAULT 0');

    addColumnIfMissing($conn, 'adolescents', 'source_backup_id', 'INTEGER');
    addColumnIfMissing($conn, 'adolescents', 'last_name', 'TEXT');
    addColumnIfMissing($conn, 'adolescents', 'first_name', 'TEXT');
    addColumnIfMissing($conn, 'adolescents', 'middle_name', 'TEXT');
    addColumnIfMissing($conn, 'adolescents', 'full_name', 'TEXT');
    addColumnIfMissing($conn, 'adolescents', 'date_of_birth', 'TEXT');
    addColumnIfMissing($conn, 'adolescents', 'sex', 'TEXT');
    addColumnIfMissing($conn, 'adolescents', 'barangay', 'TEXT');
    addColumnIfMissing($conn, 'adolescents', 'municipality', 'TEXT');
    addColumnIfMissing($conn, 'adolescents', 'present_address', 'TEXT');
    addColumnIfMissing($conn, 'adolescents', 'educational_attainment', 'TEXT');
    addColumnIfMissing($conn, 'adolescents', 'currently_enrolled', 'TEXT');
    addColumnIfMissing($conn, 'adolescents', 'grade_year_level', 'TEXT');
    addColumnIfMissing($conn, 'adolescents', 'last_school_attended_type', 'TEXT');
    addColumnIfMissing($conn, 'adolescents', 'reason_not_attending_school', 'TEXT');
    addColumnIfMissing($conn, 'adolescents', 'reason_for_not_attending_school', 'TEXT');
    addColumnIfMissing($conn, 'adolescents', 'reason_not_attending_school_other', 'TEXT');
    addColumnIfMissing($conn, 'adolescents', 'vocational_training_other', 'TEXT');
    addColumnIfMissing($conn, 'adolescents', 'created_at', 'TEXT');
    addColumnIfMissing($conn, 'adolescents', 'updated_at', 'TEXT');

    addColumnIfMissing($conn, 'encounters', 'status', 'TEXT DEFAULT \'draft\'');
    addColumnIfMissing($conn, 'encounters', 'pregnant', 'TEXT');
    addColumnIfMissing($conn, 'encounters', 'times_pregnant', 'INTEGER');
    addColumnIfMissing($conn, 'encounters', 'age_first_pregnancy', 'INTEGER');
    addColumnIfMissing($conn, 'encounters', 'living_children', 'INTEGER');
    addColumnIfMissing($conn, 'encounters', 'family_planning', 'TEXT');
    addColumnIfMissing($conn, 'encounters', 'educational_attainment', 'TEXT');
    addColumnIfMissing($conn, 'encounters', 'currently_enrolled', 'TEXT');
    addColumnIfMissing($conn, 'encounters', 'grade_year_level', 'TEXT');
    addColumnIfMissing($conn, 'encounters', 'last_school_attended_type', 'TEXT');
    addColumnIfMissing($conn, 'encounters', 'reason_not_attending_school', 'TEXT');
    addColumnIfMissing($conn, 'encounters', 'reason_for_not_attending_school', 'TEXT');
    addColumnIfMissing($conn, 'encounters', 'reason_not_attending_school_other', 'TEXT');
    addColumnIfMissing($conn, 'encounters', 'vocational_training_other', 'TEXT');
    addColumnIfMissing($conn, 'encounters', 'programs_applied', 'TEXT');
    addColumnIfMissing($conn, 'encounters', 'data_json', 'TEXT');
    addColumnIfMissing($conn, 'encounters', 'encoded_by', 'TEXT');
    addColumnIfMissing($conn, 'encounters', 'date_encoded', 'TEXT');
    addColumnIfMissing($conn, 'encounters', 'created_by_user_id', 'INTEGER');
    addColumnIfMissing($conn, 'encounters', 'created_at', 'TEXT');
    addColumnIfMissing($conn, 'encounters', 'verified_by_user_id', 'INTEGER');
    addColumnIfMissing($conn, 'encounters', 'verified_at', 'TEXT');

    addColumnIfMissing($conn, 'audit_log', 'created_at', 'TEXT');
    addColumnIfMissing($conn, 'audit_log', 'user_id', 'INTEGER');
    addColumnIfMissing($conn, 'audit_log', 'username', 'TEXT');
    addColumnIfMissing($conn, 'audit_log', 'action', 'TEXT');
    addColumnIfMissing($conn, 'audit_log', 'entity', 'TEXT');
    addColumnIfMissing($conn, 'audit_log', 'entity_id', 'INTEGER');
    addColumnIfMissing($conn, 'audit_log', 'ip', 'TEXT');
    addColumnIfMissing($conn, 'audit_log', 'user_agent', 'TEXT');
    addColumnIfMissing($conn, 'audit_log', 'details', 'TEXT');

    addColumnIfMissing($conn, 'reports', 'description', 'TEXT');
    addColumnIfMissing($conn, 'reports', 'status', 'INTEGER DEFAULT 1');
    addColumnIfMissing($conn, 'reports', 'created_at', 'TEXT');

    /* Legacy table safety additions if already present */
    addColumnIfMissing($conn, 'adolescent_mothers', 'created_at', 'TEXT');
    addColumnIfMissing($conn, 'adolescent_mothers', 'educational_attainment', 'TEXT');
    addColumnIfMissing($conn, 'adolescent_mothers', 'currently_enrolled', 'TEXT');
    addColumnIfMissing($conn, 'adolescent_mothers', 'grade_year_level', 'TEXT');
    addColumnIfMissing($conn, 'adolescent_mothers', 'last_school_attended_type', 'TEXT');
    addColumnIfMissing($conn, 'adolescent_mothers', 'reason_not_attending_school', 'TEXT');
    addColumnIfMissing($conn, 'adolescent_mothers', 'reason_for_not_attending_school', 'TEXT');
    addColumnIfMissing($conn, 'adolescent_mothers', 'reason_not_attending_school_other', 'TEXT');
    addColumnIfMissing($conn, 'adolescent_mothers', 'vocational_training_other', 'TEXT');
    addColumnIfMissing($conn, 'adolescent_mothers', 'encoded_by', 'TEXT');
    addColumnIfMissing($conn, 'adolescent_mothers', 'date_encoded', 'TEXT');
    addColumnIfMissing($conn, 'adolescent_mothers', 'partner_name', 'TEXT');
    addColumnIfMissing($conn, 'adolescent_mothers', 'partner_surname', 'TEXT');
    addColumnIfMissing($conn, 'adolescent_mothers', 'partner_first_name', 'TEXT');
    addColumnIfMissing($conn, 'adolescent_mothers', 'partner_middle_name', 'TEXT');

    /* =========================
       NORMALIZE NULL / EMPTY TIMESTAMPS
       ONLY FOR MISSING VALUES
       (does NOT auto-convert old UTC rows)
    ========================= */

    $nowLocal = appNow();

    $conn->prepare("
        UPDATE users
        SET created_at = :now
        WHERE created_at IS NULL OR TRIM(created_at) = ''
    ")->execute([':now' => $nowLocal]);

    $conn->prepare("
        UPDATE adolescent_mothers
        SET created_at = :now
        WHERE created_at IS NULL OR TRIM(created_at) = ''
    ")->execute([':now' => $nowLocal]);

    $conn->prepare("
        UPDATE adolescents
        SET created_at = :now
        WHERE created_at IS NULL OR TRIM(created_at) = ''
    ")->execute([':now' => $nowLocal]);

    $conn->prepare("
        UPDATE encounters
        SET created_at = :now
        WHERE created_at IS NULL OR TRIM(created_at) = ''
    ")->execute([':now' => $nowLocal]);

    $conn->prepare("
        UPDATE audit_log
        SET created_at = :now
        WHERE created_at IS NULL OR TRIM(created_at) = ''
    ")->execute([':now' => $nowLocal]);

    $conn->prepare("
        UPDATE reports
        SET created_at = :now
        WHERE created_at IS NULL OR TRIM(created_at) = ''
    ")->execute([':now' => $nowLocal]);

    /* =========================
       INDEXES
    ========================= */

    $conn->exec("CREATE INDEX IF NOT EXISTS idx_users_username ON users(username);");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_users_location ON users(province, municipality);");
    if (dbHasColumn($conn, 'adolescents', 'adolescent_id')) {
        $conn->exec("CREATE INDEX IF NOT EXISTS idx_adolescents_adolescent_id ON adolescents(adolescent_id);");
    } else {
        $conn->exec("CREATE INDEX IF NOT EXISTS idx_adolescents_id ON adolescents(id);");
    }
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_adolescents_name ON adolescents(last_name, first_name, middle_name);");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_adolescents_location ON adolescents(municipality, barangay);");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_adolescents_birthdate ON adolescents(date_of_birth);");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_adolescents_created_at ON adolescents(created_at);");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_adolescents_updated_at ON adolescents(updated_at);");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_adolescents_full_name ON adolescents(full_name);");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_adolescents_dob ON adolescents(date_of_birth);");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_adolescents_barangay ON adolescents(barangay);");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_adolescents_municipality ON adolescents(municipality);");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_adolescents_source_backup_id ON adolescents(source_backup_id);");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_encounters_adolescent_id ON encounters(adolescent_id);");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_encounters_date ON encounters(encounter_date);");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_encounters_created_at ON encounters(created_at);");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_encounters_adolescent_date ON encounters(adolescent_id, encounter_date);");
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_encounters_adolescent_created ON encounters(adolescent_id, created_at);");
    if (dbHasColumn($conn, 'adolescent_mothers', 'civil_status')) {
        $conn->exec("CREATE INDEX IF NOT EXISTS idx_adolescent_mothers_civil_status ON adolescent_mothers(civil_status);");
    }
    if (dbHasColumn($conn, 'adolescent_mothers', 'date_of_birth')) {
        $conn->exec("CREATE INDEX IF NOT EXISTS idx_adolescent_mothers_birthdate ON adolescent_mothers(date_of_birth);");
    }
    if (dbHasColumn($conn, 'adolescent_mothers', 'created_at')) {
        $conn->exec("CREATE INDEX IF NOT EXISTS idx_adolescent_mothers_created_at ON adolescent_mothers(created_at);");
    }
    $conn->exec("CREATE INDEX IF NOT EXISTS idx_reports_type ON reports(type);");

    /* =========================
       META
    ========================= */
    seedMetaIfMissing($conn, 'app_timezone', 'Asia/Manila');
    seedMetaIfMissing($conn, 'database_bootstrapped_at', appNow());

    /* =========================
       SEED DEFAULT ADMIN
    ========================= */

    $countUsers = (int)$conn->query("SELECT COUNT(*) FROM users")->fetchColumn();

    if ($countUsers === 0) {
        $hash = password_hash("admin123", PASSWORD_DEFAULT);

        $stmt = $conn->prepare("
            INSERT INTO users (username, password_hash, role, status, created_at)
            VALUES (:u, :p, 'admin', 'active', :created_at)
        ");

        $stmt->execute([
            ":u"          => "admin",
            ":p"          => $hash,
            ":created_at" => appNow(),
        ]);
    }

    /* =========================
       SEED REPORTS
    ========================= */

    $reportCount = (int)$conn->query("SELECT COUNT(*) FROM reports")->fetchColumn();

    if ($reportCount === 0) {
        $reports = [
            ["Monthly Status of Pregnant Adolescent and Adolescent Mothers by Age Group", "monthly_pregnant_status", "📊"],
            ["Distribution of Adolescent Mothers Practicing Family Planning by Age Group", "family_planning", "👶"],
            ["Educational Attainment Among Pregnant and Adolescent Mothers by Age Group", "education", "🎓"],
            ["Civil Status Among Pregnant and Adolescent Mothers by Age Group", "civil_status", "💍"],
            ["Prenatal Care Attendance of Pregnant Adolescent by Month of Pregnancy and Age Group", "prenatal", "🩺"],
            ["Adolescent Mothers by Age at First Pregnancy, Total Pregnancies, Living Children and Age Group", "first_pregnancy", "📈"],
            ["Number of Pregnant Adolescents and Adolescent Mothers by 4Ps and IPs Membership and Age Group", "membership", "🏠"],
            ["Distribution of Pregnant Adolescents and Adolescent Mothers by Household Income Level and Age Group", "income", "💰"],
            ["Distribution of Pregnant Adolescents and Adolescent Mothers by Expressed Needs for Government Support Services", "support_services", "🤝"],
            ["Characteristics of Partners of Pregnant Adolescents and Adolescent Mothers", "partner_characteristics", "👥"],
        ];

        $stmt = $conn->prepare("
            INSERT INTO reports (title, type, icon, created_at)
            VALUES (:title, :type, :icon, :created_at)
        ");

        $createdAt = appNow();

        foreach ($reports as $r) {
            $stmt->execute([
                ":title"      => $r[0],
                ":type"       => $r[1],
                ":icon"       => $r[2],
                ":created_at" => $createdAt,
            ]);
        }
    }

} catch (Throwable $e) {
    error_log("Database connection/bootstrap failed: " . $e->getMessage());
    http_response_code(500);
    exit('Internal Server Error');
}
