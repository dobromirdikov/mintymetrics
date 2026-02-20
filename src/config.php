<?php
namespace MintyMetrics;

// ─── Default Configuration Constants ────────────────────────────────────────

const DATA_RETENTION_DAYS  = 90;
const MAX_DB_SIZE_MB       = 100;
const RESPECT_DNT          = true;
const ENABLE_GEO           = true;
const RATE_LIMIT_SECONDS   = 1;
const LOGIN_MAX_ATTEMPTS   = 5;
const LOGIN_LOCKOUT_MINUTES = 15;
const LIVE_VISITOR_MINUTES  = 5;
const MAX_PAGE_PATH        = 2048;
const MAX_REFERRER         = 2048;
const MAX_UTM_FIELD        = 256;
const MAX_SCREEN_RES       = 20;
const MAX_LANGUAGE         = 20;
const MAX_SITE_DOMAIN      = 253;
const LOG_MAX_ENTRIES      = 1000;
const DASHBOARD_TITLE      = 'MintyMetrics';

// ─── Favicon (inline SVG data URI) ──────────────────────────────────────────

const FAVICON_SVG = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 32 32%22%3E%3Crect width=%2232%22 height=%2232%22 rx=%227%22 fill=%22%231E2A30%22/%3E%3Crect x=%225%22 y=%2219%22 width=%224%22 height=%227%22 rx=%221%22 fill=%22%232AB090%22 opacity=%22.5%22/%3E%3Crect x=%2211%22 y=%2214%22 width=%224%22 height=%2212%22 rx=%221%22 fill=%22%232AB090%22 opacity=%22.65%22/%3E%3Crect x=%2217%22 y=%2210%22 width=%224%22 height=%2216%22 rx=%221%22 fill=%22%232AB090%22 opacity=%22.8%22/%3E%3Crect x=%2223%22 y=%226%22 width=%224%22 height=%2220%22 rx=%221%22 fill=%22%232AB090%22/%3E%3C/svg%3E';

/** Inline SVG logo icon (bar chart + leaf). */
function logo_svg(int $size = 28): string {
    return '<svg viewBox="0 0 32 32" width="' . $size . '" height="' . $size . '" xmlns="http://www.w3.org/2000/svg">'
        . '<rect width="32" height="32" rx="7" fill="#1E2A30"/>'
        . '<rect x="5" y="19" width="4" height="7" rx="1" fill="#2AB090" opacity=".5"/>'
        . '<rect x="11" y="14" width="4" height="12" rx="1" fill="#2AB090" opacity=".65"/>'
        . '<rect x="17" y="10" width="4" height="16" rx="1" fill="#2AB090" opacity=".8"/>'
        . '<rect x="23" y="6" width="4" height="20" rx="1" fill="#2AB090"/>'
        . '</svg>';
}

// ─── Configuration Functions ────────────────────────────────────────────────

/** @internal Shared config cache for get_config/set_config. */
function &_cfg_cache(): array {
    static $c = [];
    return $c;
}

/**
 * Get a config value from the settings table, falling back to a default.
 */
function get_config(string $key, mixed $default = null): mixed {
    $cache = &_cfg_cache();

    // In test mode, always read from DB (no stale cache across tests)
    if (!\defined('MM_TESTING') && \array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $db = db();
        $stmt = $db->prepare('SELECT value FROM settings WHERE key = :key');
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $cache[$key] = $row ? $row['value'] : $default;
    } catch (\Exception $e) {
        $cache[$key] = $default;
    }

    return $cache[$key];
}

/**
 * Set a config value in the settings table.
 */
function set_config(string $key, mixed $value): void {
    $cache = &_cfg_cache();

    $db = db();
    $stmt = $db->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (:key, :value)');
    $stmt->bindValue(':key', $key, SQLITE3_TEXT);
    $stmt->bindValue(':value', (string) $value, SQLITE3_TEXT);
    $stmt->execute();

    $cache[$key] = $value;
}

/**
 * Get the path to the SQLite database file.
 * Tries to store outside web root; falls back to same directory.
 */
function get_db_path(): string {
    static $path = null;
    if ($path !== null) {
        return $path;
    }

    $scriptDir = \dirname(\realpath($_SERVER['SCRIPT_FILENAME'] ?? __FILE__));
    $suffix = null;

    // Try to read existing suffix from a marker file (.php to prevent direct web access)
    $markerFile = $scriptDir . '/.mm_db_marker.php';
    $legacyMarker = $scriptDir . '/.mm_db_marker';
    if (\file_exists($markerFile)) {
        $raw = \file_get_contents($markerFile);
        // Strip PHP deny prefix if present
        $suffix = \trim(\preg_replace('/^<\?php\s+\S+\s*/', '', $raw));
    } elseif (\file_exists($legacyMarker)) {
        // Migrate legacy plain-text marker
        $suffix = \trim(\file_get_contents($legacyMarker));
        @\file_put_contents($markerFile, "<?php __HALT_COMPILER();\n" . $suffix);
        @\unlink($legacyMarker);
    }

    if (!$suffix) {
        $suffix = \bin2hex(\random_bytes(8)); // 16-char hex
        @\file_put_contents($markerFile, "<?php __HALT_COMPILER();\n" . $suffix);
    }

    $filename = "mintymetrics-{$suffix}.sqlite";

    // Try one directory above web root
    $parentDir = \dirname($scriptDir);
    $parentPath = $parentDir . '/' . $filename;
    if (\file_exists($parentPath) && \is_writable($parentPath)) {
        $path = $parentPath;
        return $path;
    }
    if (\is_writable($parentDir) && !\file_exists($parentPath)) {
        if (@\touch($parentPath)) {
            $path = $parentPath;
            return $path;
        }
    }

    // Fall back to same directory
    $path = $scriptDir . '/' . $filename;
    return $path;
}

/**
 * Get today's daily salt for visitor hashing. Auto-rotates daily.
 */
function get_salt(): string {
    static $salt = null;
    if ($salt !== null) {
        return $salt;
    }

    $today = \date('Y-m-d');

    try {
        $db = db();
        $stmt = $db->prepare('SELECT value FROM settings WHERE key = :key');

        $stmt->bindValue(':key', 'salt_date', SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $saltDate = $row ? $row['value'] : null;

        if ($saltDate === $today) {
            $stmt = $db->prepare('SELECT value FROM settings WHERE key = :key');
            $stmt->bindValue(':key', 'daily_salt', SQLITE3_TEXT);
            $result = $stmt->execute();
            $row = $result->fetchArray(SQLITE3_ASSOC);
            $salt = $row['value'] ?? null;
            if ($salt !== null) {
                return $salt;
            }
        }

        // Rotate salt — use BEGIN IMMEDIATE to acquire write lock before reading,
        // preventing race conditions where two concurrent requests both rotate.
        $salt = \bin2hex(\random_bytes(32));

        $db->exec('BEGIN IMMEDIATE');

        // Re-check inside the write lock — another request may have rotated already
        $stmt = $db->prepare('SELECT value FROM settings WHERE key = :key');
        $stmt->bindValue(':key', 'salt_date', SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if ($row && $row['value'] === $today) {
            // Another request already rotated — use its salt
            $db->exec('ROLLBACK');
            $stmt = $db->prepare('SELECT value FROM settings WHERE key = :key');
            $stmt->bindValue(':key', 'daily_salt', SQLITE3_TEXT);
            $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
            $salt = $row['value'] ?? $salt;
            return $salt;
        }

        $stmt = $db->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (:key, :value)');
        $stmt->bindValue(':key', 'daily_salt', SQLITE3_TEXT);
        $stmt->bindValue(':value', $salt, SQLITE3_TEXT);
        $stmt->execute();

        $stmt = $db->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (:key, :value)');
        $stmt->bindValue(':key', 'salt_date', SQLITE3_TEXT);
        $stmt->bindValue(':value', $today, SQLITE3_TEXT);
        $stmt->execute();
        $db->exec('COMMIT');
    } catch (\Exception $e) {
        // Fallback: generate ephemeral salt (won't persist but won't crash)
        if ($salt === null) {
            $salt = \bin2hex(\random_bytes(32));
        }
    }

    return $salt;
}

/**
 * Check whether the DB marker file exists (without creating it).
 * Returns true if a database has been initialised at some point.
 */
function db_marker_exists(): bool {
    $scriptDir = \dirname(\realpath($_SERVER['SCRIPT_FILENAME'] ?? __FILE__));
    return \file_exists($scriptDir . '/.mm_db_marker.php')
        || \file_exists($scriptDir . '/.mm_db_marker');
}

/**
 * Check if setup has been completed.
 * If no DB marker file exists, setup has never run — return false
 * without creating any files on disk.
 */
function setup_complete(): bool {
    if (!db_marker_exists()) {
        return false;
    }
    try {
        db_init();
        return get_config('setup_complete', '0') === '1';
    } catch (\Exception $e) {
        return false;
    }
}

/**
 * Get allowed domains list.
 * Returns empty array if the database hasn't been created yet.
 */
function get_allowed_domains(): array {
    if (!db_marker_exists()) {
        return [];
    }
    $json = get_config('allowed_domains', '[]');
    $domains = \json_decode($json, true);
    return \is_array($domains) ? $domains : [];
}

/**
 * Normalize an IP address for consistent handling.
 * For IPv6: returns the /64 prefix for rate limiting.
 * For IPv4: returns as-is.
 */
function normalize_ip(string $ip): string {
    // Handle IPv4-mapped IPv6 (::ffff:1.2.3.4)
    if (\str_starts_with($ip, '::ffff:')) {
        $ip = \substr($ip, 7);
    }

    // Check if IPv6
    if (\str_contains($ip, ':')) {
        // Expand IPv6 and take the /64 prefix
        $packed = @\inet_pton($ip);
        if ($packed === false) {
            return $ip;
        }
        // Zero out the last 8 bytes (keep /64 prefix)
        $packed = \substr($packed, 0, 8) . \str_repeat("\0", 8);
        return \inet_ntop($packed);
    }

    return $ip;
}

/**
 * Simple error logging to SQLite.
 */
function log_error(string $message, string $level = 'error'): void {
    try {
        $db = db();
        $stmt = $db->prepare('INSERT INTO mm_logs (level, message, created_at) VALUES (:level, :message, :ts)');
        $stmt->bindValue(':level', $level, SQLITE3_TEXT);
        $stmt->bindValue(':message', \mb_substr($message, 0, 2000), SQLITE3_TEXT);
        $stmt->bindValue(':ts', \time(), SQLITE3_INTEGER);
        $stmt->execute();

        // Auto-prune old entries
        $db->exec('DELETE FROM mm_logs WHERE id NOT IN (SELECT id FROM mm_logs ORDER BY id DESC LIMIT ' . LOG_MAX_ENTRIES . ')');
    } catch (\Exception $e) {
        // Silently fail — can't log a logging failure
    }
}

/**
 * Truncate a string to a maximum byte length using mb_substr.
 */
function truncate(string $str, int $maxLen): string {
    return \mb_substr($str, 0, $maxLen);
}
