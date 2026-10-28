<?php
namespace MintyMetrics;

/**
 * Check if the current user has a valid authenticated session.
 * Sessions are tied to the password they were created with, so changing or
 * resetting the password signs out every other session.
 */
function auth_check(): bool {
    if (empty($_SESSION['_mm_authenticated'])) {
        return false;
    }
    $version = auth_credential_version();
    return $version !== '' && \hash_equals($version, (string) ($_SESSION['_mm_auth_ver'] ?? ''));
}

/**
 * Short fingerprint of the current password hash (changes whenever the password does).
 */
function auth_credential_version(): string {
    try {
        $hash = db()->querySingle('SELECT password_hash FROM auth ORDER BY id LIMIT 1');
    } catch (\Throwable $e) {
        return '';
    }
    return \is_string($hash) ? credential_version_for($hash) : '';
}

/**
 * Credential version for a specific password hash.
 */
function credential_version_for(string $hash): string {
    return $hash !== '' ? \substr(\hash('sha256', 'mm-session|' . $hash), 0, 32) : '';
}

/**
 * Mark the current session as signed in for the given (verified) password hash.
 *
 * The session ID is replaced first, with the old session deleted, so a session ID
 * that existed before this privilege change (e.g. one planted by an attacker) never
 * carries the signed-in state.
 */
function auth_establish_session(string $verifiedHash): void {
    if (\session_status() === PHP_SESSION_ACTIVE) {
        unset($_SESSION['_mm_obsolete']);
        \session_regenerate_id(true);
    }
    $_SESSION['_mm_authenticated'] = true;
    $_SESSION['_mm_auth_ver'] = credential_version_for($verifiedHash);
    $_SESSION['_mm_created'] = \time();
}

/**
 * Require authentication. If not authenticated, this halts execution and shows login.
 */
function auth_require(): void {
    if (!auth_check()) {
        \http_response_code(401);
        if (isset($_GET['api']) || isset($_GET['modal']) || isset($_GET['export'])) {
            \header('Content-Type: application/json');
            \header('Cache-Control: no-store');
            echo \json_encode(['error' => 'session_expired']);
            exit;
        }
        render_login();
        exit;
    }
}

/**
 * Secret used to key HMACs of client IPs (login throttling, rate limiting).
 * Persistent and per-install, so stored keys cannot be reversed with a lookup table.
 */
function install_secret(): string {
    static $secret = null;
    if ($secret !== null && !\defined('MM_TESTING')) {
        return $secret;
    }
    $db = db();
    $stmt = $db->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES ('install_secret', :v)");
    $stmt->bindValue(':v', \bin2hex(\random_bytes(32)), SQLITE3_TEXT);
    $stmt->execute();
    $secret = (string) $db->querySingle("SELECT value FROM settings WHERE key = 'install_secret'");
    return $secret;
}

/**
 * Key identifying the current client for login throttling.
 */
function login_client_key(): string {
    return \hash_hmac('sha256', 'login|' . normalize_ip((string) ($_SERVER['REMOTE_ADDR'] ?? '')), install_secret());
}

/**
 * Reserve one login attempt for this client before checking the password.
 *
 * The check-and-increment happens inside one write transaction, so parallel
 * requests cannot all see "0 failures" and bypass the limit. Limits:
 *  - per client: LOGIN_MAX_ATTEMPTS failures per LOGIN_LOCKOUT_MINUTES window, then locked
 *  - global: LOGIN_GLOBAL_MAX_ATTEMPTS failures per window (slows distributed guessing;
 *    a single client can no longer lock the admin out)
 *
 * Returns false if the client (or the install) is currently locked.
 */
function auth_reserve_attempt(): bool {
    $db = db();
    $now = \time();
    $window = LOGIN_LOCKOUT_MINUTES * 60;
    $limits = [login_client_key() => LOGIN_MAX_ATTEMPTS, LOGIN_GLOBAL_KEY => LOGIN_GLOBAL_MAX_ATTEMPTS];

    $db->exec('BEGIN IMMEDIATE');
    try {
        $rows = [];
        foreach ($limits as $key => $max) {
            $rows[$key] = login_attempt_row($db, $key);
            if ($rows[$key] && (int) $rows[$key]['locked_until'] > $now) {
                $db->exec('COMMIT');
                return false;
            }
        }

        // Count this attempt as a failure up front; a correct password undoes it.
        // Each counter is a fixed window that starts at its first failure.
        foreach ($limits as $key => $max) {
            $row = $rows[$key];
            $inWindow = $row && (int) $row['window_start'] > $now - $window;
            $failures = ($inWindow ? (int) $row['failures'] : 0) + 1;
            $stmt = $db->prepare('INSERT OR REPLACE INTO login_attempts (client_key, failures, window_start, locked_until)
                VALUES (:k, :f, :ws, :lu)');
            $stmt->bindValue(':k', $key, SQLITE3_TEXT);
            $stmt->bindValue(':f', $failures, SQLITE3_INTEGER);
            $stmt->bindValue(':ws', $inWindow ? (int) $row['window_start'] : $now, SQLITE3_INTEGER);
            $stmt->bindValue(':lu', $failures >= $max ? $now + $window : 0, SQLITE3_INTEGER);
            $stmt->execute();
        }

        $db->exec('COMMIT');
        return true;
    } catch (\Throwable $e) {
        db_rollback($db);
        throw $e;
    }
}

/** login_attempts key for the install-wide counter. */
const LOGIN_GLOBAL_KEY = 'global';

/**
 * Read one login_attempts row.
 */
function login_attempt_row(\SQLite3 $db, string $key): array|false {
    $stmt = $db->prepare('SELECT failures, window_start, locked_until FROM login_attempts WHERE client_key = :k');
    $stmt->bindValue(':k', $key, SQLITE3_TEXT);
    return $stmt->execute()->fetchArray(SQLITE3_ASSOC);
}

/**
 * Undo the reservation for an attempt with the correct password: clear this
 * client's failures, and take the attempt back off the global counter (including
 * a lock that this very attempt triggered).
 */
function auth_release_attempt(): void {
    $db = db();
    $db->exec('BEGIN IMMEDIATE');
    try {
        $stmt = $db->prepare('DELETE FROM login_attempts WHERE client_key = :k');
        $stmt->bindValue(':k', login_client_key(), SQLITE3_TEXT);
        $stmt->execute();

        $stmt = $db->prepare('UPDATE login_attempts SET
                failures = MAX(failures - 1, 0),
                locked_until = CASE WHEN failures - 1 < :max THEN 0 ELSE locked_until END
            WHERE client_key = :k');
        $stmt->bindValue(':max', LOGIN_GLOBAL_MAX_ATTEMPTS, SQLITE3_INTEGER);
        $stmt->bindValue(':k', LOGIN_GLOBAL_KEY, SQLITE3_TEXT);
        $stmt->execute();
        $db->exec('COMMIT');
    } catch (\Throwable $e) {
        db_rollback($db);
        throw $e;
    }
}

/**
 * Remove all login throttling state (used by the CLI password reset).
 */
function auth_clear_login_limits(): void {
    $db = db();
    $db->exec('DELETE FROM login_attempts');
    $db->exec('UPDATE auth SET failed_attempts = 0, last_failed_at = NULL, lockout_until = NULL');
}

/**
 * Attempt to log in with the given password. Returns true on success.
 */
function auth_login(string $password): bool {
    if (!auth_reserve_attempt()) {
        return false;
    }

    // Read the hash after reserving, and pin the session to exactly the hash that was
    // verified: if the password changes concurrently, this session is already stale.
    $hash = db()->querySingle('SELECT password_hash FROM auth ORDER BY id LIMIT 1');
    if (!\is_string($hash) || !\password_verify($password, $hash)) {
        return false;
    }
    if (!\hash_equals(credential_version_for($hash), auth_credential_version())) {
        return false;
    }

    auth_release_attempt();
    auth_establish_session($hash);
    return true;
}

/**
 * Log out the current user.
 */
function auth_logout(): void {
    $_SESSION = [];
    if (\ini_get('session.use_cookies')) {
        $params = \session_get_cookie_params();
        \setcookie(\session_name(), '', \time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    \session_destroy();
}

/**
 * Verify a password against the stored hash (without login side-effects).
 */
function auth_verify_password(string $password): bool {
    $row = db()->querySingle('SELECT password_hash FROM auth ORDER BY id LIMIT 1', true);
    if (!$row) {
        return false;
    }
    return \password_verify($password, $row['password_hash']);
}

/**
 * Set or update the admin password. Existing sessions stop being valid because
 * their credential version no longer matches. Returns the exact hash written.
 */
function auth_set_password(string $password): string {
    $hash = \password_hash($password, PASSWORD_BCRYPT);
    $db = db();

    // Check if auth record exists
    $existing = $db->querySingle('SELECT id FROM auth ORDER BY id LIMIT 1');
    if ($existing) {
        $stmt = $db->prepare('UPDATE auth SET password_hash = :hash, failed_attempts = 0, lockout_until = NULL WHERE id = :id');
        $stmt->bindValue(':hash', $hash, SQLITE3_TEXT);
        $stmt->bindValue(':id', $existing, SQLITE3_INTEGER);
        $stmt->execute();
    } else {
        $stmt = $db->prepare('INSERT INTO auth (password_hash) VALUES (:hash)');
        $stmt->bindValue(':hash', $hash, SQLITE3_TEXT);
        $stmt->execute();
    }
    return $hash;
}

/**
 * Check if a password has been set (i.e., setup has been run).
 */
function auth_has_password(): bool {
    try {
        $row = db()->querySingle('SELECT id FROM auth LIMIT 1');
        return $row !== null && $row !== false;
    } catch (\Throwable $e) {
        return false;
    }
}

/**
 * Generate or retrieve the CSRF token for the current session.
 */
function csrf_token(): string {
    if (empty($_SESSION['_mm_csrf'])) {
        $_SESSION['_mm_csrf'] = \bin2hex(\random_bytes(32));
    }
    return $_SESSION['_mm_csrf'];
}

/**
 * Output a hidden CSRF form field.
 */
function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . csrf_token() . '">';
}

/**
 * Verify the CSRF token from POST data. Halts on failure.
 */
function csrf_verify(): void {
    $token = $_POST['_csrf'] ?? '';
    if (!\hash_equals(csrf_token(), $token)) {
        \http_response_code(403);
        echo 'Invalid CSRF token.';
        exit;
    }
}

/**
 * Generate a nonce for Content-Security-Policy.
 */
function csp_nonce(): string {
    static $nonce = null;
    if ($nonce === null) {
        $nonce = \bin2hex(\random_bytes(16));
    }
    return $nonce;
}

/**
 * Set Content-Security-Policy and related security headers for HTML pages.
 */
function set_csp_headers(): void {
    $nonce = csp_nonce();
    \header("Content-Security-Policy: default-src 'self'; script-src 'nonce-{$nonce}'; style-src 'nonce-{$nonce}'; img-src 'self' data:; connect-src 'self'; frame-src 'none'; object-src 'none'; frame-ancestors 'self'; base-uri 'none'; form-action 'self'");
    \header('X-Content-Type-Options: nosniff');
    \header('X-Frame-Options: SAMEORIGIN');
    \header('Referrer-Policy: same-origin');
    // Pages carry CSRF tokens and analytics data; never store them in shared caches
    \header('Cache-Control: no-store');
}
