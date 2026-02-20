<?php
namespace MintyMetrics;

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

    $path = get_db_path();
    $instance = new \SQLite3($path);

    // Performance and reliability settings
    $instance->exec('PRAGMA journal_mode = WAL');
    $instance->exec('PRAGMA busy_timeout = 5000');
    $instance->exec('PRAGMA foreign_keys = ON');
    $instance->exec('PRAGMA synchronous = NORMAL');
    $instance->exec('PRAGMA auto_vacuum = INCREMENTAL');

    db_init_with($instance);

    return $instance;
}

/**
 * Initialize the database schema if tables don't exist.
 */
function db_init(): void {
    db(); // Triggers initialization via db_init_with()
}

/**
 * Create all tables on the given database connection.
 */
function db_init_with(\SQLite3 $db): void {
    $schemaVersion = 0;
    try {
        $result = @$db->querySingle("SELECT value FROM settings WHERE key = 'schema_version'");
        if ($result !== null && $result !== false) {
            $schemaVersion = (int) $result;
        }
    } catch (\Exception $e) {
        // Table doesn't exist yet, will be created below
    }

    if ($schemaVersion >= 2) {
        return; // Schema is up to date
    }

    try {
    $db->exec('BEGIN');

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

    // Daily summaries (kept indefinitely)
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

    // Set schema version
    $db->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '2')");

    $db->exec('COMMIT');
    } catch (\Exception $e) {
        @$db->exec('ROLLBACK');
        throw $e;
    }

    // Migration: v1 → v2 (add last_active_at column)
    if ($schemaVersion === 1) {
        try {
            $db->exec('BEGIN');
            $db->exec('ALTER TABLE hits ADD COLUMN last_active_at INTEGER DEFAULT NULL');
            $db->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '2')");
            $db->exec('COMMIT');
        } catch (\Exception $e) {
            @$db->exec('ROLLBACK');
            log_error('migration v1→v2: ' . $e->getMessage());
        }
    }
}

/**
 * Get the database file size in MB.
 */
function db_size_mb(): float {
    $path = get_db_path();
    if (!\file_exists($path)) {
        return 0.0;
    }
    return \round(\filesize($path) / 1048576, 2);
}
