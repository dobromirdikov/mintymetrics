<?php
/**
 * MintyMetrics — Single-file, cookie-free, privacy-first web analytics.
 * Version: {{VERSION}}
 * License: MIT
 * https://github.com/dobromirdikov/mintymetrics
 */

namespace MintyMetrics;

// {{INCLUDE:config}}
// {{INCLUDE:http}}
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

// ─── Drop-in Mode Detection ────────────────────────────────────────────────
// If this file is included by another PHP script (web or CLI), only provide the
// head() helper. The host script's own CLI arguments must never reach handle_cli().
if (\realpath(__FILE__) !== \realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    // Drop-in mode: provide head() function for the host page to call.
    // Tracking happens client-side via the JS snippet output by head().
    return;
}

// ─── CLI Mode ───────────────────────────────────────────────────────────────
if (\php_sapi_name() === 'cli') {
    handle_cli($argv ?? []);
    return;
}

// ─── Direct Access: Route by Query Parameters ──────────────────────────────
// Priority order: tracking endpoints first (no session needed), then dashboard routes.

// Warnings must never leak into pixel, JSON, or CSV responses (or break headers).
\ini_set('display_errors', '0');
\ini_set('log_errors', '1');

// Every handler expects scalar strings; array params like ?page[]=x become ''.
$_GET = scalar_input($_GET);
$_POST = scalar_input($_POST);

// Tracking endpoints — no session, no auth, minimal overhead
// Skip if setup hasn't been completed (avoids creating DB on fresh installs)
if (isset($_GET['hit'])) {
    if (!db_marker_exists()) { serve_pixel(); return; }
    apply_timezone();
    track_hit();
    serve_pixel();
    return;
}

if (isset($_GET['beacon'])) {
    if (!db_marker_exists()) { \http_response_code(204); return; }
    apply_timezone();
    track_beacon();
    return;
}

if (isset($_GET['event'])) {
    if (!db_marker_exists()) { serve_pixel(); return; }
    apply_timezone();
    track_event();
    serve_pixel();
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

// First-run setup check. A configured install whose database is temporarily
// unreachable must fail closed, never fall back to the (open) setup page.
try {
    $isSetUp = setup_complete();
} catch (\Throwable $e) {
    \error_log('MintyMetrics: database unavailable: ' . $e->getMessage());
    \http_response_code(503);
    \header('Content-Type: text/plain; charset=utf-8');
    \header('Retry-After: 30');
    echo 'MintyMetrics is temporarily unavailable. Please try again shortly.';
    return;
}
if (!$isSetUp) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['setup'])) {
        csrf_verify();
        handle_setup();
        return;
    }
    render_setup();
    return;
}

apply_timezone();

// Login/logout
if (isset($_GET['login']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $password = $_POST['password'] ?? '';
    if (auth_login($password)) {
        // auth_login() already issued a fresh session ID before marking it signed in
        // Extend session to 30 days if "remember me" is checked
        if (!empty($_POST['remember_me'])) {
            $_SESSION['_mm_remember'] = true;
            set_remember_cookie();
        }
        redirect(script_path());
        return;
    }
    render_login('Invalid password, or too many attempts. Try again later.');
    return;
}

if (isset($_GET['logout']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    auth_logout();
    redirect(script_path() . '?login');
    return;
}

// All remaining routes require authentication
if (!auth_check()) {
    // Background requests (API, modal fragments) get a status the dashboard can act on
    if (isset($_GET['api']) || isset($_GET['modal'])) {
        auth_require();
    }
    render_login();
    return;
}

// Authenticated routes
if (isset($_GET['api'])) {
    handle_api();
    return;
}

if (isset($_GET['export'])) {
    export_csv($_GET['type'] ?? 'pageviews', parse_site_param($_GET['site'] ?? ''), $_GET['from'] ?? null, $_GET['to'] ?? null);
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
