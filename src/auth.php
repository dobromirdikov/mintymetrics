<?php
namespace MintyMetrics;

/**
 * Check if the current user has a valid authenticated session.
 */
function auth_check(): bool {
    return !empty($_SESSION['_mm_authenticated']);
}

/**
 * Require authentication. If not authenticated, this halts execution and shows login.
 */
function auth_require(): void {
    if (!auth_check()) {
        \http_response_code(401);
        if (isset($_GET['api'])) {
            \header('Content-Type: application/json');
            echo \json_encode(['error' => 'session_expired']);
            exit;
        }
        render_login();
        exit;
    }
}

/**
 * Attempt to log in with the given password. Returns true on success.
 */
function auth_login(string $password): bool {
    $db = db();

    // Get auth record
    $row = $db->querySingle('SELECT * FROM auth LIMIT 1', true);
    if (!$row) {
        return false;
    }

    // Check lockout
    if ($row['lockout_until'] && $row['lockout_until'] > \time()) {
        return false;
    }

    // Verify password
    if (\password_verify($password, $row['password_hash'])) {
        // Reset failed attempts
        $stmt = $db->prepare('UPDATE auth SET failed_attempts = 0, last_failed_at = NULL, lockout_until = NULL WHERE id = :id');
        $stmt->bindValue(':id', $row['id'], SQLITE3_INTEGER);
        $stmt->execute();

        $_SESSION['_mm_authenticated'] = true;
        $_SESSION['_mm_created'] = \time();
        return true;
    }

    // Failed attempt
    $attempts = $row['failed_attempts'] + 1;
    $lockoutUntil = null;

    if ($attempts >= LOGIN_MAX_ATTEMPTS) {
        $lockoutUntil = \time() + (LOGIN_LOCKOUT_MINUTES * 60);
    }

    $stmt = $db->prepare('UPDATE auth SET failed_attempts = :attempts, last_failed_at = :now, lockout_until = :lockout WHERE id = :id');
    $stmt->bindValue(':attempts', $attempts, SQLITE3_INTEGER);
    $stmt->bindValue(':now', \time(), SQLITE3_INTEGER);
    $stmt->bindValue(':lockout', $lockoutUntil, $lockoutUntil ? SQLITE3_INTEGER : SQLITE3_NULL);
    $stmt->bindValue(':id', $row['id'], SQLITE3_INTEGER);
    $stmt->execute();

    return false;
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
    $row = db()->querySingle('SELECT password_hash FROM auth LIMIT 1', true);
    if (!$row) {
        return false;
    }
    return \password_verify($password, $row['password_hash']);
}

/**
 * Set or update the admin password.
 */
function auth_set_password(string $password): void {
    $hash = \password_hash($password, PASSWORD_BCRYPT);
    $db = db();

    // Check if auth record exists
    $existing = $db->querySingle('SELECT id FROM auth LIMIT 1');
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
}

/**
 * Check if a password has been set (i.e., setup has been run).
 */
function auth_has_password(): bool {
    try {
        $row = db()->querySingle('SELECT id FROM auth LIMIT 1');
        return $row !== null && $row !== false;
    } catch (\Exception $e) {
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
 * Set Content-Security-Policy headers with nonce for inline scripts/styles.
 */
function set_csp_headers(): void {
    $nonce = csp_nonce();
    \header("Content-Security-Policy: default-src 'self'; script-src 'nonce-{$nonce}'; style-src 'nonce-{$nonce}'; img-src 'self' data:; connect-src 'self'; frame-src 'none'; object-src 'none'; frame-ancestors 'self'");
}
