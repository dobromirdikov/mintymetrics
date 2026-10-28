<?php
namespace MintyMetrics;

const SCHEMA_VERSION = 4;

/**
 * Get the singleton SQLite3 database connection.
 */
function db(): \SQLite3 {
    /** @var \SQLite3|null */
    static $instance = null;

    // Test injection override — only active when MM_TESTING is defined
    if (\defined('MM_TESTING') && isset($GLOBALS['_mm_test_db'])) {
        return $GLOBALS['_mm_test_db'];
    }

    if ($instance !== null) {
        return $instance;
    }

    // Anything that calls db() while the connection is still being opened or migrated
    // (e.g. log_error from a failing migration) must not reopen and re-migrate recursively.
    static $opening = false;
    if ($opening) {
        throw new \RuntimeException('Database is still initializing');
    }
    $opening = true;
    try {
        $path = get_db_path();
        \clearstatcache(true, $path);
        $isNew = !\file_exists($path) || \filesize($path) === 0;
        $db = new \SQLite3($path);
        db_configure($db, $isNew);
        db_init_with($db);
    } finally {
        $opening = false;
    }

    $instance = $db;
    return $instance;
}

/**
 * Apply connection settings. Shared by db() and the test harness.
 *
 * Order matters: the busy timeout must be in place before anything that needs
 * a lock (including switching to WAL), and auto_vacuum only takes effect if it
 * is set before the first table is created and before WAL writes the header.
 */
function db_configure(\SQLite3 $db, bool $isNew = false): void {
    $db->enableExceptions(true);
    $db->busyTimeout(5000);

    if ($isNew) {
        $db->exec('PRAGMA auto_vacuum = INCREMENTAL');
    }

    // journal_mode is persistent; only switch when needed to avoid taking a lock per request
    if (\strtolower((string) $db->querySingle('PRAGMA journal_mode')) !== 'wal') {
        $db->exec('PRAGMA journal_mode = WAL');
    }
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA synchronous = NORMAL');
}

/**
 * Roll back the current transaction, ignoring "no transaction is active" errors.
 */
function db_rollback(\SQLite3 $db): void {
    try {
        $db->exec('ROLLBACK');
    } catch (\Throwable $e) {
        // Nothing to roll back
    }
}

/**
 * Initialize the database schema if tables don't exist.
 */
function db_init(): void {
    db(); // Triggers initialization via db_init_with()
}

/**
 * Create all tables on the given database connection, or migrate an older schema.
 */
function db_init_with(\SQLite3 $db): void {
    $schemaVersion = db_schema_version($db);

    if ($schemaVersion >= SCHEMA_VERSION) {
        return; // Schema is up to date
    }

    if ($schemaVersion === 0) {
        db_create_schema($db);
        return;
    }

    // Each migration runs in its own transaction and bumps the version only on success,
    // so a failed step is retried on the next request instead of being skipped.
    $migrations = [
        1 => 'db_migrate_v2',
        2 => 'db_migrate_v3',
        3 => 'db_migrate_v4',
    ];
    foreach ($migrations as $fromVersion => $migration) {
        if ($schemaVersion !== $fromVersion) {
            continue;
        }
        try {
            $db->exec('BEGIN IMMEDIATE');
            // Re-check inside the write lock: a concurrent request may have migrated already
            if (db_schema_version($db) === $fromVersion) {
                (__NAMESPACE__ . '\\' . $migration)($db);
                $db->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '" . ($fromVersion + 1) . "')");
            }
            $db->exec('COMMIT');
        } catch (\Throwable $e) {
            db_rollback($db);
            // Not log_error(): that needs db(), which is what is failing to initialize
            \error_log("MintyMetrics migration v{$fromVersion} to v" . ($fromVersion + 1) . ' failed: ' . $e->getMessage());
            throw $e;
        }
        $schemaVersion = db_schema_version($db);
    }
}

/**
 * Read the stored schema version (0 when the settings table does not exist yet).
 */
function db_schema_version(\SQLite3 $db): int {
    if (!$db->querySingle("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'settings'")) {
        return 0; // Fresh database
    }
    $result = $db->querySingle("SELECT value FROM settings WHERE key = 'schema_version'");
    return ($result !== null && $result !== false) ? (int) $result : 0;
}

/**
 * Create the full, current schema on an empty database.
 */
function db_create_schema(\SQLite3 $db): void {
    try {
    $db->exec('BEGIN IMMEDIATE');

    // Raw hit data (pruned after DATA_RETENTION_DAYS)
    $db->exec("
        CREATE TABLE IF NOT EXISTS hits (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            site         TEXT NOT NULL DEFAULT '',
            visitor_hash TEXT NOT NULL,
            page_path    TEXT NOT NULL,
            referrer_url TEXT DEFAULT NULL,
            referrer_domain TEXT DEFAULT NULL,
            utm_source   TEXT DEFAULT NULL,
            utm_medium   TEXT DEFAULT NULL,
            utm_campaign TEXT DEFAULT NULL,
            utm_term     TEXT DEFAULT NULL,
            utm_content  TEXT DEFAULT NULL,
            country_code TEXT DEFAULT NULL,
            device_type  TEXT DEFAULT NULL,
            browser      TEXT DEFAULT NULL,
            browser_ver  TEXT DEFAULT NULL,
            os           TEXT DEFAULT NULL,
            os_ver       TEXT DEFAULT NULL,
            screen_res   TEXT DEFAULT NULL,
            language     TEXT DEFAULT NULL,
            time_on_page    INTEGER DEFAULT NULL,
            last_active_at  INTEGER DEFAULT NULL,
            created_at      INTEGER NOT NULL DEFAULT (strftime('%s', 'now'))
        )
    ");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_hits_site_created ON hits(site, created_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_hits_visitor ON hits(visitor_hash, created_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_hits_page ON hits(site, page_path, created_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_hits_created ON hits(created_at)');

    // Daily summaries (kept indefinitely). Dates are local days in the configured timezone.
    // visitor_days/bounced/time_sum/time_count let ratios be weighted correctly across a range.
    $db->exec("
        CREATE TABLE IF NOT EXISTS daily_summaries (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            date             TEXT NOT NULL,
            site             TEXT NOT NULL DEFAULT '',
            pageviews        INTEGER NOT NULL DEFAULT 0,
            unique_visitors  INTEGER NOT NULL DEFAULT 0,
            bounce_rate      REAL DEFAULT NULL,
            avg_time_on_page REAL DEFAULT NULL,
            top_pages        TEXT DEFAULT NULL,
            top_referrers    TEXT DEFAULT NULL,
            top_countries    TEXT DEFAULT NULL,
            devices          TEXT DEFAULT NULL,
            browsers         TEXT DEFAULT NULL,
            operating_systems TEXT DEFAULT NULL,
            utm_summary      TEXT DEFAULT NULL,
            screens          TEXT DEFAULT NULL,
            languages        TEXT DEFAULT NULL,
            visitor_days     INTEGER NOT NULL DEFAULT 0,
            bounced          INTEGER NOT NULL DEFAULT 0,
            time_sum         REAL NOT NULL DEFAULT 0,
            time_count       INTEGER NOT NULL DEFAULT 0,
            UNIQUE(date, site)
        )
    ");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_summaries_site_date ON daily_summaries(site, date)');

    // Authentication
    $db->exec("
        CREATE TABLE IF NOT EXISTS auth (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            password_hash   TEXT NOT NULL,
            failed_attempts INTEGER NOT NULL DEFAULT 0,
            last_failed_at  INTEGER DEFAULT NULL,
            lockout_until   INTEGER DEFAULT NULL
        )
    ");

    // Key-value settings
    $db->exec("
        CREATE TABLE IF NOT EXISTS settings (
            key   TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )
    ");

    // Rate limiting (pruned aggressively)
    $db->exec("
        CREATE TABLE IF NOT EXISTS rate_limit (
            ip_hash     TEXT PRIMARY KEY,
            last_hit_at REAL NOT NULL
        )
    ");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_ratelimit_time ON rate_limit(last_hit_at)');

    // Error logs
    $db->exec("
        CREATE TABLE IF NOT EXISTS mm_logs (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            level      TEXT NOT NULL DEFAULT 'error',
            message    TEXT NOT NULL,
            created_at INTEGER NOT NULL
        )
    ");

    // Custom events (pruned with same retention as hits)
    db_create_events_table($db);

    $db->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '" . SCHEMA_VERSION . "')");

    $db->exec('COMMIT');
    } catch (\Throwable $e) {
        db_rollback($db);
        throw $e;
    }
}

/**
 * Create the events table and its indexes.
 */
function db_create_events_table(\SQLite3 $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS events (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            site         TEXT NOT NULL DEFAULT '',
            visitor_hash TEXT NOT NULL,
            name         TEXT NOT NULL,
            value        TEXT DEFAULT NULL,
            props        TEXT DEFAULT NULL,
            page_path    TEXT DEFAULT NULL,
            country_code TEXT DEFAULT NULL,
            device_type  TEXT DEFAULT NULL,
            browser      TEXT DEFAULT NULL,
            os           TEXT DEFAULT NULL,
            created_at   INTEGER NOT NULL DEFAULT (strftime('%s', 'now'))
        )
    ");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_events_site_created ON events(site, created_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_events_site_name ON events(site, name, created_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_events_visitor ON events(visitor_hash, created_at)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_events_created ON events(created_at)');
}

/**
 * List the column names of a table.
 */
function db_table_columns(\SQLite3 $db, string $table): array {
    $columns = [];
    $result = $db->query('PRAGMA table_info(' . $table . ')');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $columns[] = $row['name'];
    }
    return $columns;
}

/** Migration v1 → v2: beacon activity column. */
function db_migrate_v2(\SQLite3 $db): void {
    if (!\in_array('last_active_at', db_table_columns($db, 'hits'), true)) {
        $db->exec('ALTER TABLE hits ADD COLUMN last_active_at INTEGER DEFAULT NULL');
    }
}

/** Migration v2 → v3: custom events. */
function db_migrate_v3(\SQLite3 $db): void {
    db_create_events_table($db);
}

/**
 * Migration v3 → v4: additive counters on daily summaries so bounce rate and average
 * time can be weighted correctly across ranges. Older rows only stored ratios, so their
 * counters are reconstructed approximately (time is weighted by pageviews).
 */
function db_migrate_v4(\SQLite3 $db): void {
    $existing = db_table_columns($db, 'daily_summaries');
    $columns = [
        'visitor_days' => 'INTEGER NOT NULL DEFAULT 0',
        'bounced'      => 'INTEGER NOT NULL DEFAULT 0',
        'time_sum'     => 'REAL NOT NULL DEFAULT 0',
        'time_count'   => 'INTEGER NOT NULL DEFAULT 0',
    ];
    foreach ($columns as $name => $definition) {
        if (!\in_array($name, $existing, true)) {
            $db->exec("ALTER TABLE daily_summaries ADD COLUMN {$name} {$definition}");
        }
    }
    $db->exec("
        UPDATE daily_summaries SET
            visitor_days = unique_visitors,
            bounced      = CAST(ROUND(COALESCE(bounce_rate, 0) * unique_visitors) AS INTEGER),
            time_sum     = CASE WHEN avg_time_on_page IS NULL THEN 0 ELSE avg_time_on_page * pageviews END,
            time_count   = CASE WHEN avg_time_on_page IS NULL THEN 0 ELSE pageviews END
    ");
    $db->exec('CREATE INDEX IF NOT EXISTS idx_events_created ON events(created_at)');
}

/**
 * Get the size of the live data in MB (excludes free pages awaiting reuse).
 */
function db_size_mb(): float {
    try {
        $db = db();
        $pages = (int) $db->querySingle('PRAGMA page_count') - (int) $db->querySingle('PRAGMA freelist_count');
        return \round(\max(0, $pages) * (int) $db->querySingle('PRAGMA page_size') / 1048576, 2);
    } catch (\Throwable $e) {
        $path = get_db_path();
        return \file_exists($path) ? \round(\filesize($path) / 1048576, 2) : 0.0;
    }
}
