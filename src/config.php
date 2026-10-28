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
const MAX_EVENT_NAME       = 64;
const MAX_EVENT_VALUE      = 256;
const MAX_EVENT_PROPS_JSON = 1024;
const LOG_MAX_ENTRIES      = 1000;
const DASHBOARD_TITLE      = 'MintyMetrics';
const LOGIN_GLOBAL_MAX_ATTEMPTS = 50;

/** .htaccess body that blocks all web access (Apache 2.4 and 2.2). */
const HTACCESS_DENY_ALL = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";

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

    // Only stored values are cached (as strings); a missing key returns this call's
    // own default instead of whatever default an earlier caller passed.
    try {
        $db = db();
        $stmt = $db->prepare('SELECT value FROM settings WHERE key = :key');
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);
    } catch (\Throwable $e) {
        return $default;
    }
    if (!$row) {
        return $default;
    }
    $cache[$key] = (string) $row['value'];
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

    $cache[$key] = (string) $value;
}

/**
 * Directory containing analytics.php itself. In drop-in mode SCRIPT_FILENAME is the
 * including page, so it must not be used to locate MintyMetrics' own files.
 */
function app_dir(): string {
    if (\defined('MM_TESTING') && isset($GLOBALS['_mm_app_dir'])) {
        return $GLOBALS['_mm_app_dir'];
    }
    return \dirname(\realpath(__FILE__) ?: __FILE__);
}

/**
 * Path of the marker file that records where this install's database lives.
 */
function db_marker_path(): string {
    return app_dir() . '/.mm_db_marker.php';
}

/**
 * Read the marker. Format: PHP deny prefix, then the DB suffix, then (since v1.2)
 * the absolute DB path on its own line. Returns [suffix, path|null] or null.
 */
function read_db_marker(): ?array {
    $markerFile = db_marker_path();
    $legacyMarker = app_dir() . '/.mm_db_marker';
    if (\is_file($markerFile)) {
        $h = @\fopen($markerFile, 'r');
        if (!$h) {
            throw new \RuntimeException('Cannot read ' . $markerFile);
        }
        \flock($h, LOCK_SH); // never observe a marker mid-write
        $raw = (string) \stream_get_contents($h);
        \flock($h, LOCK_UN);
        \fclose($h);
        if (\trim($raw) === '' && \is_file($legacyMarker)) {
            $raw = (string) \file_get_contents($legacyMarker);
        }
    } elseif (\is_file($legacyMarker)) {
        $raw = (string) \file_get_contents($legacyMarker);
    } else {
        return null;
    }
    return parse_db_marker($raw);
}

/**
 * Parse marker contents. Empty means "not created yet" (null); anything else that
 * does not parse is an error — guessing would risk switching to a different database.
 */
function parse_db_marker(string $raw): ?array {
    // Only a completely empty file means "not created yet". A file holding just the
    // PHP prefix is a truncated marker and must not be mistaken for a fresh install.
    if (\trim($raw) === '') {
        return null;
    }
    // Strip PHP deny prefix if present
    $body = \trim(\preg_replace('/^<\?php\s+\S+\s*/', '', $raw));
    $lines = \preg_split('/\R/', $body);
    $suffix = \trim($lines[0] ?? '');
    if (!\preg_match('/^[a-f0-9]{8,64}$/', $suffix)) {
        throw new \RuntimeException('Unrecognized contents in ' . db_marker_path());
    }
    $path = \trim($lines[1] ?? '');
    return [$suffix, $path !== '' ? $path : null];
}

/**
 * Marker file contents for a suffix and resolved database path.
 */
function db_marker_contents(string $suffix, string $path): string {
    return "<?php __HALT_COMPILER();\n{$suffix}\n{$path}\n";
}

/**
 * Write the marker with the resolved database path (used by tests and tooling;
 * normal resolution writes it under the lock in resolve_db_path()).
 */
function write_db_marker(string $suffix, string $path): bool {
    $ok = @\file_put_contents(db_marker_path(), db_marker_contents($suffix, $path), LOCK_EX) !== false;
    if ($ok && \file_exists(app_dir() . '/.mm_db_marker')) {
        @\unlink(app_dir() . '/.mm_db_marker');
    }
    return $ok;
}

/**
 * Get the path to the SQLite database file.
 *
 * The location is decided once, when the database is first created, and stored in
 * the marker. Later requests always reuse it: re-deciding per request (e.g. after a
 * permissions change) would silently start a new, empty database and reopen setup.
 */
function get_db_path(): string {
    static $paths = [];
    $dir = app_dir();
    if (isset($paths[$dir])) {
        return $paths[$dir];
    }
    return $paths[$dir] = resolve_db_path();
}

/**
 * Work out (and pin in the marker) where this install's database lives.
 */
function resolve_db_path(): string {
    // Fast path: an already pinned marker only needs a shared-locked read
    $marker = read_db_marker();
    if ($marker !== null && $marker[1] !== null) {
        return $marker[1];
    }

    // Creating or pinning the marker happens under an exclusive lock, so concurrent
    // first requests agree on one database instead of each inventing their own.
    $markerFile = db_marker_path();
    $h = @\fopen($markerFile, 'c+');
    if (!$h) {
        // A read-only legacy marker can still be resolved, but only to the single existing
        // database: with copies in both locations the choice could change between requests
        $existing = $marker !== null ? legacy_db_candidates($marker[0]) : [];
        $legacy = \count($existing) === 1 ? $existing[0] : null;
        if ($legacy !== null) {
            \error_log("MintyMetrics: cannot update {$markerFile}; using existing database {$legacy}");
            return $legacy;
        }
        throw new \RuntimeException('Cannot write ' . $markerFile . '; make the folder writable by the web server.');
    }

    try {
        \flock($h, LOCK_EX);
        $raw = (string) \stream_get_contents($h);
        $legacyMarker = app_dir() . '/.mm_db_marker';
        if (\trim($raw) === '' && \is_file($legacyMarker)) {
            $raw = (string) \file_get_contents($legacyMarker);
        }
        $marker = parse_db_marker($raw);
        if ($marker !== null && $marker[1] !== null) {
            return $marker[1]; // pinned by another request while we waited
        }

        if ($marker !== null) {
            // Pre-1.2 marker: keep using the existing database, wherever it is
            $suffix = $marker[0];
            $path = legacy_db_location($suffix) ?? choose_db_location("mintymetrics-{$suffix}.sqlite");
        } else {
            $suffix = \bin2hex(\random_bytes(8)); // 16-char hex
            $path = choose_db_location("mintymetrics-{$suffix}.sqlite");
        }

        $contents = db_marker_contents($suffix, $path);
        \ftruncate($h, 0);
        \rewind($h);
        if (\fwrite($h, $contents) !== \strlen($contents) || !\fflush($h)) {
            throw new \RuntimeException('Cannot write ' . $markerFile . '; make the folder writable by the web server.');
        }
        if (\is_file($legacyMarker)) {
            @\unlink($legacyMarker);
        }
        return $path;
    } finally {
        \flock($h, LOCK_UN);
        \fclose($h);
    }
}

/**
 * Where a pre-1.2 install's database is: one directory up or next to analytics.php
 * (the larger file wins if, unusually, both exist). Null if neither exists.
 */
function legacy_db_location(string $suffix): ?string {
    $existing = legacy_db_candidates($suffix);
    \usort($existing, fn($a, $b) => \filesize($b) <=> \filesize($a));
    return $existing[0] ?? null;
}

/**
 * Existing database files for a pre-1.2 suffix (one directory up, then next to analytics.php).
 */
function legacy_db_candidates(string $suffix): array {
    $filename = "mintymetrics-{$suffix}.sqlite";
    return \array_values(\array_filter(
        [\dirname(app_dir()) . '/' . $filename, app_dir() . '/' . $filename],
        'is_file'
    ));
}

/**
 * Pick where a new database goes: one directory up when that is writable and
 * outside the web root, otherwise next to analytics.php (protected by .htaccess).
 */
function choose_db_location(string $filename): string {
    $parentDir = \dirname(app_dir());
    if (\is_writable($parentDir) && !path_in_docroot($parentDir)) {
        return $parentDir . '/' . $filename;
    }
    return app_dir() . '/' . $filename;
}

/**
 * Whether a path is inside the web server's document root (i.e. potentially downloadable).
 * When DOCUMENT_ROOT is unknown, anything inside the app directory counts as web-served.
 */
function path_in_docroot(string $path): bool {
    $norm = fn(string $p) => \rtrim(\str_replace('\\', '/', $p), '/') . '/';
    $real = \realpath($path) ?: $path;
    $docRoot = \realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
    $root = $docRoot !== '' ? $docRoot : app_dir();
    return \str_starts_with($norm($real), $norm($root));
}

/**
 * Get today's daily salt for visitor hashing. Auto-rotates daily.
 */
function get_salt(): string {
    static $salt = null;
    if ($salt !== null) {
        return $salt;
    }
    $began = false;

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
        $began = true;

        // Re-check inside the write lock — another request may have rotated already
        $stmt = $db->prepare('SELECT value FROM settings WHERE key = :key');
        $stmt->bindValue(':key', 'salt_date', SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if ($row && $row['value'] === $today) {
            // Another request already rotated — use its salt
            $db->exec('ROLLBACK');
            $began = false;
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
        $began = false;
    } catch (\Throwable $e) {
        // Never leave the rotation transaction open: later tracking writes would
        // silently join it and be lost when the connection closes.
        if ($began) {
            db_rollback($db);
        }
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
    return \file_exists(db_marker_path()) || \file_exists(app_dir() . '/.mm_db_marker');
}

/**
 * Check if setup has been completed.
 * If no DB marker file exists, setup has never run — return false
 * without creating any files on disk.
 *
 * Database errors are thrown, not treated as "not set up": falling back to the
 * setup page on a configured install would let anyone overwrite the password.
 */
function setup_complete(): bool {
    if (!db_marker_exists()) {
        return false;
    }
    $value = db()->querySingle("SELECT value FROM settings WHERE key = 'setup_complete'");
    return $value === '1';
}

/**
 * Normalize a hostname or origin to a lowercase host without its port.
 */
function normalize_domain(string $value): string {
    $value = \trim($value);
    if ($value === '') {
        return '';
    }

    $bareIpv6 = \trim($value, '[]');
    if (\filter_var($bareIpv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        return \strtolower($bareIpv6);
    }

    $candidate = \str_contains($value, '://') ? $value : 'http://' . $value;
    $host = \parse_url($candidate, PHP_URL_HOST);
    if (!\is_string($host) || $host === '') {
        return '';
    }

    // parse_url() retains brackets around IPv6 hosts; a trailing dot is the DNS root.
    $host = \rtrim(\trim(\mb_strtolower($host), '[]'), '.');

    // Internationalized names become punycode. Stripping non-ASCII characters instead
    // would turn bücher.de into bcher.de and match an unrelated domain.
    if (\preg_match('/[^\x20-\x7e]/', $host)) {
        if (!\function_exists('idn_to_ascii')) {
            return '';
        }
        $ascii = \idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if (!\is_string($ascii) || $ascii === '') {
            return '';
        }
        $host = \strtolower($ascii);
    }

    if (!\preg_match('/^[a-z0-9.\-:]+$/', $host)) {
        return '';
    }
    return truncate($host, MAX_SITE_DOMAIN);
}

/**
 * Normalize and de-duplicate a list of configured domains.
 */
function normalize_domains(array $domains): array {
    $normalized = [];
    foreach ($domains as $domain) {
        $host = normalize_domain((string) $domain);
        if ($host !== '') {
            $normalized[$host] = true;
        }
    }
    return \array_keys($normalized);
}

/**
 * Check a hostname against an allow-list, including permitted subdomains.
 */
function domain_matches_allowed(string $site, array $allowed): bool {
    $site = normalize_domain($site);
    if ($site === '') {
        return false;
    }

    foreach (normalize_domains($allowed) as $domain) {
        if ($site === $domain || \str_ends_with($site, '.' . $domain)) {
            return true;
        }
    }
    return false;
}

/**
 * Validate a request authority while retaining its explicit port.
 */
function resolve_request_authority(string $authority, array $allowed, string $fallback): string {
    $authority = \preg_replace('/[^a-zA-Z0-9.\-:\[\]]/', '', $authority);
    $host = normalize_domain($authority);

    if ($host !== '' && (empty($allowed) || domain_matches_allowed($host, $allowed))) {
        return $authority;
    }

    $fallback = \preg_replace('/[^a-zA-Z0-9.\-:\[\]]/', '', $fallback);
    if (normalize_domain($fallback) !== '') {
        return $fallback;
    }

    $normalizedAllowed = normalize_domains($allowed);
    return $normalizedAllowed[0] ?? 'localhost';
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
    return \is_array($domains) ? normalize_domains($domains) : [];
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
    } catch (\Throwable $e) {
        // Silently fail — can't log a logging failure
    }
}

// ─── Timezone & Local Days ──────────────────────────────────────────────────
// All day boundaries (daily salt, summaries, charts, retention) use one timezone:
// the `timezone` setting, falling back to PHP's default.

/**
 * Check whether a string is a valid timezone identifier.
 */
function is_valid_timezone(string $tz): bool {
    return $tz !== '' && \in_array($tz, \DateTimeZone::listIdentifiers(), true);
}

/**
 * Apply the configured timezone to all date functions for this request.
 */
function apply_timezone(): void {
    try {
        $tz = (string) get_config('timezone', '');
    } catch (\Throwable $e) {
        return;
    }
    if (is_valid_timezone($tz)) {
        \date_default_timezone_set($tz);
    }
}

/**
 * Check a Y-m-d string is a real calendar date.
 */
function is_valid_date(string $date): bool {
    $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $dt !== false && $dt->format('Y-m-d') === $date;
}

/**
 * Unix timestamp of local midnight at the start of a Y-m-d day.
 */
function day_start(string $date): int {
    return (new \DateTimeImmutable($date . ' 00:00:00'))->getTimestamp();
}

/**
 * The Y-m-d day after the given one (DST-safe).
 */
function next_date(string $date): string {
    return (new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
}

/**
 * Local midnight that separates raw data from data due for summarization.
 * Always a day boundary, so a day is never partly summarized and partly raw.
 */
function retention_cutoff_ts(int $retentionDays): int {
    $date = (new \DateTimeImmutable('today'))->modify('-' . \max(1, $retentionDays) . ' days')->format('Y-m-d');
    return day_start($date);
}

/**
 * Replace any non-string request value (e.g. ?page[]=x) with an empty string.
 */
function scalar_input(array $input): array {
    foreach ($input as $key => $value) {
        if (!\is_string($value)) {
            $input[$key] = '';
        }
    }
    return $input;
}

/**
 * Truncate a string to a maximum byte length using mb_substr.
 */
function truncate(string $str, int $maxLen): string {
    return \mb_substr($str, 0, $maxLen);
}
