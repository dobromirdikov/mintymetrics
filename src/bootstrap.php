<?php
/**
 * MintyMetrics — Single-file, cookie-free, privacy-first web analytics.
 * Version: {{VERSION}}
 * License: MIT
 * https://github.com/dobromirdikov/mintymetrics
 */

namespace MintyMetrics;

// {{INCLUDE:config}}
// {{INCLUDE:database}}
// {{INCLUDE:auth}}
// {{INCLUDE:useragent}}
// {{INCLUDE:geo}}
// {{INCLUDE:tracker}}
// {{INCLUDE:cleanup}}
// {{INCLUDE:export}}
// {{INCLUDE:api}}
// {{INCLUDE:setup}}
// {{INCLUDE:settings}}
// {{INCLUDE:dashboard}}

const VERSION = '{{VERSION}}';

// ─── CLI Mode ───────────────────────────────────────────────────────────────
if (\php_sapi_name() === 'cli') {
    handle_cli($argv ?? []);
    return;
}

// ─── Drop-in Mode Detection ────────────────────────────────────────────────
// If this file is included by another PHP script, operate in tracking-helper mode only.
if (\realpath(__FILE__) !== \realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    // Drop-in mode: provide head() function for the host page to call.
    // Tracking happens client-side via the JS snippet output by head().
    return;
}

// ─── Direct Access: Route by Query Parameters ──────────────────────────────
// Priority order: tracking endpoints first (no session needed), then dashboard routes.

// Tracking endpoints — no session, no auth, minimal overhead
// Skip if setup hasn't been completed (avoids creating DB on fresh installs)
if (isset($_GET['hit'])) {
    if (!db_marker_exists()) { serve_pixel(); return; }
    track_hit();
    serve_pixel();
    return;
}

if (isset($_GET['beacon'])) {
    if (!db_marker_exists()) { \http_response_code(204); return; }
    track_beacon();
    return;
}

if (isset($_GET['js']) && isset($_GET['site'])) {
    if (!db_marker_exists()) { \header('Content-Type: application/javascript'); return; }
    serve_js($_GET['site']);
    return;
}

// Static asset endpoint (lazy-loaded resources)
if (isset($_GET['asset'])) {
    serve_asset($_GET['asset']);
    return;
}

// ─── Dashboard Routes (session required) ────────────────────────────────────
session_init();

// First-run setup check
if (!setup_complete()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['setup'])) {
        csrf_verify();
        handle_setup();
        return;
    }
    render_setup();
    return;
}

// Login/logout
if (isset($_GET['login']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $password = $_POST['password'] ?? '';
    if (auth_login($password)) {
        \session_regenerate_id(true);
        // Extend session to 30 days if "remember me" is checked
        if (!empty($_POST['remember_me'])) {
            $lifetime = 30 * 24 * 60 * 60;
            $_SESSION['_mm_remember'] = true;
            $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            \setcookie(\session_name(), \session_id(), [
                'expires'  => \time() + $lifetime,
                'path'     => '/',
                'secure'   => $secure,
                'httponly'  => true,
                'samesite' => 'Strict',
            ]);
        }
        \header('Location: ' . script_url());
        return;
    }
    render_login('Invalid password or account locked.');
    return;
}

if (isset($_GET['logout']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    auth_logout();
    \header('Location: ' . script_url() . '?login');
    return;
}

// All remaining routes require authentication
if (!auth_check()) {
    render_login();
    return;
}

// Authenticated routes
if (isset($_GET['api'])) {
    handle_api();
    return;
}

if (isset($_GET['export'])) {
    $type = $_GET['type'] ?? 'pageviews';
    $rawSite = $_GET['site'] ?? '';
    if (\str_contains($rawSite, ',')) {
        $parts = \array_filter(\array_map(function($s) { return sanitize_site(\trim($s)); }, \explode(',', $rawSite)));
        $site = \implode(',', \array_unique($parts));
    } else {
        $site = sanitize_site($rawSite);
    }
    $from = $_GET['from'] ?? \date('Y-m-d', \strtotime('-7 days'));
    $to = $_GET['to'] ?? \date('Y-m-d');
    export_csv($type, $site, $from, $to);
    return;
}

if (isset($_GET['geo-download']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $result = geo_download();
    \header('Content-Type: application/json');
    echo \json_encode($result);
    return;
}

if (isset($_GET['geo-upload']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $result = geo_upload();
    \header('Content-Type: application/json');
    echo \json_encode($result);
    return;
}

if (isset($_GET['htaccess-fix']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $ok = fix_htaccess();
    \header('Content-Type: application/json');
    echo \json_encode(['success' => $ok, 'message' => $ok ? 'Database protection added.' : 'Failed to update .htaccess.']);
    return;
}

if (isset($_GET['settings'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();
        handle_settings();
        return;
    }
    // Fragment mode: return inner HTML for modal injection
    if (isset($_GET['modal'])) {
        render_settings();
        return;
    }
    // Non-AJAX GET: render dashboard, JS auto-opens settings modal
    cleanup_run();
    render_dashboard();
    return;
}

if (isset($_GET['compliance'])) {
    render_compliance();
    return;
}

if (isset($_GET['help'])) {
    if (isset($_GET['modal'])) {
        render_help();
        return;
    }
    cleanup_run();
    render_dashboard();
    return;
}

if (isset($_GET['health'])) {
    // Fragment mode: return inner HTML for modal injection
    if (isset($_GET['modal'])) {
        render_health();
        return;
    }
    // Non-AJAX GET: render dashboard, JS auto-opens health modal
    cleanup_run();
    render_dashboard();
    return;
}

// Default: dashboard
cleanup_run();
render_dashboard();


// ─── Helpers ────────────────────────────────────────────────────────────────

/**
 * Get the URL to this script (for self-referencing links and redirects).
 */
function script_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // Sanitize host header: strip anything that isn't a valid hostname character.
    // This prevents host header injection even when allowed_domains is empty.
    $host = \preg_replace('/[^a-zA-Z0-9.\-:\[\]]/', '', $host);

    // Validate host against allowed domains to prevent host header injection.
    // Falls back to SERVER_NAME (set by server config, not client-controlled).
    $allowed = get_allowed_domains();
    if (!empty($allowed)) {
        $hostOnly = \strtolower(\explode(':', $host)[0]); // strip port
        $valid = false;
        foreach ($allowed as $domain) {
            if ($hostOnly === $domain || \str_ends_with($hostOnly, '.' . $domain)) {
                $valid = true;
                break;
            }
        }
        if (!$valid) {
            $host = $_SERVER['SERVER_NAME'] ?? $allowed[0];
        }
    }

    // In drop-in mode, __FILE__ is the analytics script while SCRIPT_NAME
    // points to the including page. Derive the URL path from DOCUMENT_ROOT.
    $filePath = \str_replace('\\', '/', \realpath(__FILE__));
    $docRoot = \str_replace('\\', '/', \realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '');

    if ($docRoot !== '' && \str_starts_with($filePath, $docRoot)) {
        $path = \substr($filePath, \strlen($docRoot));
    } else {
        $path = $_SERVER['SCRIPT_NAME'] ?? '/analytics.php';
    }

    return "{$scheme}://{$host}{$path}";
}

/**
 * Initialize session with hardened settings. Called only for dashboard routes.
 */
function session_init(): void {
    if (\session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    // Set GC lifetime to 30 days so "remember me" sessions survive server-side
    \ini_set('session.gc_maxlifetime', 30 * 24 * 60 * 60);

    // Use a dedicated session directory so system-wide GC crons
    // and other PHP apps cannot prune our long-lived sessions.
    $sessionDir = \dirname(get_db_path()) . '/mm_sessions';
    if (!\is_dir($sessionDir)) {
        @\mkdir($sessionDir, 0700, true);
    }
    if (\is_dir($sessionDir) && \is_writable($sessionDir)) {
        \ini_set('session.save_path', $sessionDir);
    }

    \session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly'  => true,
        'samesite' => 'Strict',
    ]);

    \session_name('mm_session');
    \session_start();

    // "Remember me" sessions: regenerate daily, then refresh the 30-day cookie
    if (!empty($_SESSION['_mm_remember'])) {
        $lifetime = 30 * 24 * 60 * 60;

        // Regenerate session ID daily (do this BEFORE setcookie so we use the current ID)
        if (!isset($_SESSION['_mm_created'])) {
            $_SESSION['_mm_created'] = \time();
        } elseif (\time() - $_SESSION['_mm_created'] > 86400) {
            \session_regenerate_id(true);
            $_SESSION['_mm_created'] = \time();
        }

        // Refresh the 30-day cookie with the (possibly new) session ID
        \setcookie(\session_name(), \session_id(), [
            'expires'  => \time() + $lifetime,
            'path'     => '/',
            'secure'   => $secure,
            'httponly'  => true,
            'samesite' => 'Strict',
        ]);
        return;
    }

    // Default sessions: regenerate every 30 minutes
    if (!isset($_SESSION['_mm_created'])) {
        $_SESSION['_mm_created'] = \time();
    } elseif (\time() - $_SESSION['_mm_created'] > 1800) {
        \session_regenerate_id(true);
        $_SESSION['_mm_created'] = \time();
    }
}

/**
 * Handle CLI commands (e.g., --reset-password).
 */
function handle_cli(array $argv): void {
    if (\in_array('--reset-password', $argv, true)) {
        echo "MintyMetrics Password Reset\n";
        echo "Enter new password (min 8 characters): ";
        $password = \trim(\fgets(STDIN));
        if (\strlen($password) < 8) {
            echo "Error: Password must be at least 8 characters.\n";
            return;
        }
        db_init();
        auth_set_password($password);
        echo "Password updated successfully.\n";
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
 * Serve a static asset (lazy-loaded resources like the world map).
 */
function serve_asset(string $name): void {
    switch ($name) {
        case 'worldmap':
            \header('Content-Type: image/svg+xml');
            \header('Cache-Control: public, max-age=86400');
            echo '/* {{SVG_MAP}} */';
            return;
        default:
            \http_response_code(404);
            return;
    }
}
