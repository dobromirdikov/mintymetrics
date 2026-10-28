<?php
namespace MintyMetrics;

// ─── HTTP, Session & CLI Helpers ────────────────────────────────────────────

/**
 * Whether the current request reached the client over HTTPS. X-Forwarded-Proto is
 * only trusted from a private/loopback peer (a reverse proxy on the same host or
 * network), since anyone can send that header directly.
 */
function request_is_https(): bool {
    $https = \strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off') {
        return true;
    }
    if (\strtolower((string) ($_SERVER['REQUEST_SCHEME'] ?? '')) === 'https') {
        return true;
    }
    if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }
    $peer = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $peerIsProxy = $peer !== '' && \filter_var($peer, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    if ($peerIsProxy) {
        $proto = \strtolower(\trim(\explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        if ($proto === 'https' || \strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on') {
            return true;
        }
    }
    return false;
}

/**
 * URL path of this script, e.g. /stats/analytics.php.
 */
function script_path(): string {
    // In drop-in mode, __FILE__ is the analytics script while SCRIPT_NAME
    // points to the including page. Derive the URL path from DOCUMENT_ROOT.
    $filePath = \str_replace('\\', '/', \realpath(__FILE__) ?: __FILE__);
    $docRoot = \str_replace('\\', '/', \realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '');

    if ($docRoot !== '' && \str_starts_with($filePath, \rtrim($docRoot, '/') . '/')) {
        return \substr($filePath, \strlen(\rtrim($docRoot, '/')));
    }
    return $_SERVER['SCRIPT_NAME'] ?? '/analytics.php';
}

/**
 * Get the URL to this script (for self-referencing links and the tracking endpoint).
 */
function script_url(): string {
    $scheme = request_is_https() ? 'https' : 'http';
    $allowed = get_allowed_domains();
    $fallback = $_SERVER['SERVER_NAME'] ?? ($allowed[0] ?? 'localhost');
    $host = resolve_request_authority($_SERVER['HTTP_HOST'] ?? 'localhost', $allowed, $fallback);

    return "{$scheme}://{$host}" . script_path();
}

/**
 * Redirect with a path-only Location so the browser keeps its own scheme and host
 * (correct behind TLS-terminating proxies, and never points at a spoofed Host).
 */
function redirect(string $path): void {
    \header('Location: ' . $path, true, 303);
}

/**
 * Initialize session with hardened settings. Called only for dashboard routes.
 */
function session_init(): void {
    if (\session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // Set GC lifetime to 30 days so "remember me" sessions survive server-side
    \ini_set('session.gc_maxlifetime', (string) (30 * 24 * 60 * 60));
    // Reject session IDs the server never issued (blocks session fixation)
    \ini_set('session.use_strict_mode', '1');
    \ini_set('session.use_only_cookies', '1');

    // Use a dedicated session directory so system-wide GC crons and other PHP apps
    // cannot prune our long-lived sessions. Only once setup has created the database:
    // a fresh install must not create files before the admin has claimed it.
    if (db_marker_exists()) {
        $sessionDir = \dirname(get_db_path()) . '/mm_sessions';
        if (ensure_private_dir($sessionDir)) {
            \ini_set('session.save_path', $sessionDir);
        }
    }

    \session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => request_is_https(),
        'httponly'  => true,
        'samesite' => 'Strict',
    ]);

    \session_name('mm_session');
    \session_start();

    // A session replaced by regeneration stays usable for a minute so requests that
    // were already in flight finish; after that it is treated as logged out.
    if (isset($_SESSION['_mm_obsolete'])) {
        if ($_SESSION['_mm_obsolete'] < \time() - 60) {
            $_SESSION = [];
        }
        return;
    }

    if (!isset($_SESSION['_mm_created'])) {
        $_SESSION['_mm_created'] = \time();
    }

    // "Remember me" sessions: regenerate daily, then refresh the 30-day cookie.
    // Default sessions: regenerate every 30 minutes.
    $maxAge = !empty($_SESSION['_mm_remember']) ? 86400 : 1800;
    if (\time() - $_SESSION['_mm_created'] > $maxAge) {
        session_rotate();
    }
    if (!empty($_SESSION['_mm_remember'])) {
        set_remember_cookie();
    }
}

/**
 * Issue a new session ID. The old session is kept but marked obsolete (rather than
 * deleted) so concurrent dashboard requests using it do not get logged out mid-flight.
 */
function session_rotate(): void {
    $_SESSION['_mm_obsolete'] = \time();
    \session_regenerate_id(false);
    unset($_SESSION['_mm_obsolete']);
    $_SESSION['_mm_created'] = \time();
}

/**
 * Set the persistent 30-day "remember me" cookie for the current session ID.
 */
function set_remember_cookie(): void {
    \setcookie(\session_name(), \session_id(), [
        'expires'  => \time() + 30 * 24 * 60 * 60,
        'path'     => '/',
        'secure'   => request_is_https(),
        'httponly'  => true,
        'samesite' => 'Strict',
    ]);
}

/**
 * Create a directory for private files (sessions) and block web access to it in
 * case it sits inside the web root.
 */
function ensure_private_dir(string $dir): bool {
    if (!\is_dir($dir)) {
        @\mkdir($dir, 0700, true);
    }
    if (!\is_dir($dir) || !\is_writable($dir)) {
        return false;
    }
    if (!\file_exists($dir . '/.htaccess')) {
        @\file_put_contents($dir . '/.htaccess', HTACCESS_DENY_ALL);
    }
    if (!\file_exists($dir . '/index.html')) {
        @\file_put_contents($dir . '/index.html', '');
    }
    return true;
}

/**
 * Handle CLI commands (e.g., --reset-password).
 */
function handle_cli(array $argv): void {
    if (\in_array('--reset-password', $argv, true)) {
        echo "MintyMetrics Password Reset\n";
        // Never create a new database here: that would "succeed" against an empty file
        if (!db_marker_exists()) {
            echo "Error: no MintyMetrics database found for " . (\realpath(__FILE__) ?: __FILE__) . ".\n";
            echo "Complete setup in the browser first.\n";
            return;
        }
        $dbPath = get_db_path();
        if (!\is_file($dbPath)) {
            echo "Error: database file not found: {$dbPath}\n";
            return;
        }
        if (!\is_writable($dbPath)) {
            echo "Error: database is not writable by this user: {$dbPath}\n";
            echo "Run this command as the web server user.\n";
            return;
        }
        echo "Database: {$dbPath}\n";
        echo "Enter new password (min 8 characters): ";
        $password = read_secret_line();
        if (\strlen($password) < 8) {
            echo "Error: Password must be at least 8 characters.\n";
            return;
        }
        db_init();
        auth_set_password($password);
        auth_clear_login_limits();
        echo "Password updated successfully. All existing sessions have been signed out.\n";
        return;
    }

    if (\in_array('--version', $argv, true)) {
        echo "MintyMetrics v" . VERSION . "\n";
        return;
    }

    echo "MintyMetrics v" . VERSION . "\n\n";
    echo "Usage:\n";
    echo "  php " . ($argv[0] ?? 'analytics.php') . " --reset-password   Reset admin password\n";
    echo "  php " . ($argv[0] ?? 'analytics.php') . " --version          Show version\n";
}

/**
 * Read a line from STDIN without echoing it when running in a POSIX terminal.
 */
function read_secret_line(): string {
    $hide = DIRECTORY_SEPARATOR === '/' && \function_exists('shell_exec')
        && \function_exists('stream_isatty') && @\stream_isatty(STDIN);
    if ($hide) {
        @\shell_exec('stty -echo');
    }
    $line = \fgets(STDIN);
    if ($hide) {
        @\shell_exec('stty echo');
        echo "\n";
    }
    return \trim((string) $line);
}

/**
 * Serve a static asset (lazy-loaded resources like the world map).
 */
function serve_asset(string $name): void {
    switch ($name) {
        case 'worldmap':
            \header('Content-Type: image/svg+xml');
            \header('Cache-Control: public, max-age=86400');
            \header('X-Content-Type-Options: nosniff');
            echo '/* {{SVG_MAP}} */';
            return;
        default:
            \http_response_code(404);
            return;
    }
}
