<?php
namespace MintyMetrics;

// ─── Setup Token ────────────────────────────────────────────────────────────
// A freshly uploaded analytics.php must not be claimable by whoever finds it first.
// Setup requires a token written to a file next to analytics.php, which only someone
// with access to the server's files can read.

function setup_token_path(): string {
    return app_dir() . '/.mm_setup_token.php';
}

/**
 * Read the setup token, creating it on first use. Returns null if it cannot be written.
 */
function setup_token(): ?string {
    $path = setup_token_path();
    if (\is_file($path)) {
        $raw = (string) \file_get_contents($path);
        if (\preg_match('/\b([a-f0-9]{24})\b/', $raw, $m)) {
            return $m[1];
        }
    }
    $token = \bin2hex(\random_bytes(12));
    $content = "<?php __HALT_COMPILER();\n# MintyMetrics setup token. Paste the code below into the setup form.\n# This file is deleted automatically once setup is complete.\n{$token}\n";
    if (@\file_put_contents($path, $content, LOCK_EX) === false) {
        return null;
    }
    return $token;
}

/**
 * Parse a list of domains entered one per line or comma-separated.
 */
function parse_domain_list(string $input): array {
    return normalize_domains(\preg_split('/[\s,]+/', $input, -1, PREG_SPLIT_NO_EMPTY));
}

/**
 * Handle the POST submission of the setup form.
 */
function handle_setup(): void {
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';
    $domains = $_POST['domains'] ?? '';
    $timezone = $_POST['timezone'] ?? '';
    $token = setup_token();

    // Validate
    $errors = [];
    if ($token === null) {
        $errors[] = 'The folder containing ' . \basename(__FILE__) . ' is not writable, so setup cannot continue.';
    } elseif (!\hash_equals($token, \strtolower(\trim($_POST['setup_token'] ?? '')))) {
        $errors[] = 'The setup token is incorrect.';
    }
    if (\strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($password !== $passwordConfirm) {
        $errors[] = 'Passwords do not match.';
    }
    $domainList = parse_domain_list($domains);
    if (empty($domainList)) {
        $domainList = [normalize_domain($_SERVER['HTTP_HOST'] ?? 'localhost') ?: 'localhost'];
    }

    if (!empty($errors)) {
        render_setup($errors, $domains);
        return;
    }

    // Initialize database (creates marker file + schema on first run)
    db_init();
    $db = db();

    // All-or-nothing, and never twice: a second (racing) submission must not
    // overwrite the password chosen by the first.
    $db->exec('BEGIN IMMEDIATE');
    try {
        $alreadyDone = $db->querySingle("SELECT value FROM settings WHERE key = 'setup_complete'") === '1'
            || $db->querySingle('SELECT COUNT(*) FROM auth') > 0;
        if ($alreadyDone) {
            $db->exec('ROLLBACK');
            redirect(script_path() . '?login');
            exit;
        }

        auth_set_password($password);
        set_config('allowed_domains', \json_encode(\array_values($domainList)));
        if (is_valid_timezone($timezone)) {
            set_config('timezone', $timezone);
        }
        set_config('setup_complete', '1');
        $db->exec('COMMIT');
    } catch (\Throwable $e) {
        db_rollback($db);
        throw $e;
    }

    @\unlink(setup_token_path());

    // Try to set up .htaccess protection
    fix_htaccess();

    // Redirect to login
    redirect(script_path());
    exit;
}

/**
 * Render the first-run setup page.
 */
function render_setup(array $errors = [], string $domains = ''): void {
    $nonce = csp_nonce();
    set_csp_headers();

    if (empty($domains)) {
        $domains = normalize_domain($_SERVER['HTTP_HOST'] ?? 'localhost') ?: 'localhost';
    }

    $token = setup_token();
    $tokenFile = \basename(setup_token_path());
    $tokenHtml = $token === null
        ? '<div class="mm-alert mm-alert--error">The folder containing ' . e(\basename(__FILE__))
            . ' is not writable. Make it writable by the web server, then reload this page.</div>'
        : '<label for="setup_token">Setup Token</label>
            <input type="text" id="setup_token" name="setup_token" required autocomplete="off" spellcheck="false" pattern="[a-fA-F0-9]{24}">
            <div class="mm-hint">Open <code>' . e($tokenFile) . '</code> in the same folder as ' . e(\basename(__FILE__))
            . ' (via FTP or your host&rsquo;s file manager) and copy the 24-character code from its last line. This proves you control the server.</div>';

    $errorsHtml = '';
    if (!empty($errors)) {
        $errorsHtml = '<div class="mm-alert mm-alert--error"><ul>';
        foreach ($errors as $err) {
            $errorsHtml .= '<li>' . e($err) . '</li>';
        }
        $errorsHtml .= '</ul></div>';
    }

    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MintyMetrics — Setup</title>
    <link rel="icon" type="image/svg+xml" href="' . FAVICON_SVG . '">
    <style nonce="' . $nonce . '">
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #F4F7F6; color: #1A2B25; min-height: 100vh;
            display: flex; align-items: center; justify-content: center; padding: 16px;
        }
        .mm-setup { background: #fff; border-radius: 12px; padding: 40px; max-width: 480px; width: 100%;
            box-shadow: 0 4px 12px rgba(26,43,37,0.1); }
        .mm-setup h1 { font-size: 1.5rem; margin-bottom: 8px; }
        .mm-setup .mm-subtitle { color: #5A6F66; margin-bottom: 24px; }
        .mm-setup label { display: block; font-weight: 600; margin-bottom: 6px; margin-top: 16px; font-size: 0.875rem; }
        .mm-setup input[type="password"], .mm-setup textarea {
            width: 100%; padding: 10px 12px; border: 1px solid #D8E2DE; border-radius: 8px;
            font-size: 1rem; font-family: inherit; background: #F4F7F6;
        }
        .mm-setup input:focus, .mm-setup textarea:focus { outline: none; border-color: #2AB090; box-shadow: 0 0 0 3px rgba(42,176,144,0.15); }
        .mm-setup textarea { resize: vertical; min-height: 80px; }
        .mm-setup .mm-hint { color: #8FA39A; font-size: 0.8125rem; margin-top: 4px; }
        .mm-setup button {
            display: block; width: 100%; padding: 12px; margin-top: 24px; background: #2AB090;
            color: #fff; border: none; border-radius: 8px; font-size: 1rem; font-weight: 600;
            cursor: pointer; transition: background 0.15s;
        }
        .mm-setup button:hover { background: #239B7D; }
        .mm-alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; }
        .mm-alert--error { background: #FDEAEA; color: #D94F4F; }
        .mm-alert ul { margin-left: 16px; }
        .mm-logo-text { color: #2AB090; font-weight: 700; }
        .mm-setup-logo { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; }
        .mm-setup-logo svg { flex-shrink: 0; }
        .mm-setup-logo-text { font-size: 1.5rem; font-weight: 700; color: #1A2B25; }
        .mm-setup-logo-text span { color: #2AB090; }
        .mm-site-link { display: block; text-align: center; margin-top: 20px; color: #8FA39A; font-size: 0.8125rem; text-decoration: none; }
        .mm-site-link:hover { color: #2AB090; }
    </style>
</head>
<body>
    <div class="mm-setup">
        <div class="mm-setup-logo">' . logo_svg(40) . '<div class="mm-setup-logo-text">Minty<span>Metrics</span></div></div>
        <h1>Setup</h1>
        <p class="mm-subtitle">Welcome! Let\'s get your analytics running.</p>
        ' . $errorsHtml . '
        <form method="POST" action="' . e(script_path()) . '?setup">
            ' . csrf_field() . '
            <input type="hidden" id="timezone" name="timezone" value="">
            ' . $tokenHtml . '
            <label for="password">Admin Password</label>
            <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password">
            <div class="mm-hint">Minimum 8 characters. Used to access the analytics dashboard.</div>

            <label for="password_confirm">Confirm Password</label>
            <input type="password" id="password_confirm" name="password_confirm" required minlength="8" autocomplete="new-password">

            <label for="domains">Allowed Domains</label>
            <textarea id="domains" name="domains" placeholder="example.com">' . e($domains) . '</textarea>
            <div class="mm-hint">One domain per line. For hub mode, list all sites you want to track.</div>

            <button type="submit">Complete Setup</button>
        </form>
        <a href="https://mintymetrics.com" class="mm-site-link" target="_blank" rel="noopener noreferrer">mintymetrics.com</a>
    </div>
    <script nonce="' . $nonce . '">
        try { document.getElementById("timezone").value = Intl.DateTimeFormat().resolvedOptions().timeZone || ""; } catch (e) {}
    </script>
</body>
</html>';
}

/**
 * Render the login page.
 */
function render_login(string $error = ''): void {
    $nonce = csp_nonce();
    set_csp_headers();

    $errorHtml = $error ? '<div class="mm-alert mm-alert--error">' . e($error) . '</div>' : '';

    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MintyMetrics — Login</title>
    <link rel="icon" type="image/svg+xml" href="' . FAVICON_SVG . '">
    <style nonce="' . $nonce . '">
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #F4F7F6; color: #1A2B25; min-height: 100vh;
            display: flex; align-items: center; justify-content: center; padding: 16px;
        }
        .mm-login { background: #fff; border-radius: 12px; padding: 40px; max-width: 400px; width: 100%;
            box-shadow: 0 4px 12px rgba(26,43,37,0.1); text-align: center; }
        .mm-login h1 { font-size: 1.5rem; margin-bottom: 24px; }
        .mm-login input[type="password"] {
            width: 100%; padding: 10px 12px; border: 1px solid #D8E2DE; border-radius: 8px;
            font-size: 1rem; font-family: inherit; background: #F4F7F6; margin-bottom: 16px;
        }
        .mm-login input:focus { outline: none; border-color: #2AB090; box-shadow: 0 0 0 3px rgba(42,176,144,0.15); }
        .mm-login button {
            display: block; width: 100%; padding: 12px; background: #2AB090;
            color: #fff; border: none; border-radius: 8px; font-size: 1rem; font-weight: 600;
            cursor: pointer; transition: background 0.15s;
        }
        .mm-login button:hover { background: #239B7D; }
        .mm-alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; text-align: left; }
        .mm-alert--error { background: #FDEAEA; color: #D94F4F; }
        .mm-logo-text { color: #2AB090; font-weight: 700; }
        .mm-login-logo { display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 24px; }
        .mm-login-logo svg { flex-shrink: 0; }
        .mm-login-logo-text { font-size: 1.375rem; font-weight: 700; color: #1A2B25; }
        .mm-login-logo-text span { color: #2AB090; }
        .mm-remember { display: flex; align-items: center; gap: 8px; margin-bottom: 16px; justify-content: flex-start; }
        .mm-remember input[type="checkbox"] { width: 16px; height: 16px; accent-color: #2AB090; }
        .mm-remember label { font-size: 0.875rem; color: #5A6F66; cursor: pointer; }
    </style>
</head>
<body>
    <div class="mm-login">
        <div class="mm-login-logo">' . logo_svg(36) . '<div class="mm-login-logo-text">Minty<span>Metrics</span></div></div>
        ' . $errorHtml . '
        <form method="POST" action="' . e(script_path()) . '?login">
            <input type="password" name="password" placeholder="Password" required autofocus autocomplete="current-password">
            <div class="mm-remember">
                <input type="checkbox" id="remember_me" name="remember_me" value="1" checked>
                <label for="remember_me">Stay signed in for 30 days</label>
            </div>
            ' . csrf_field() . '
            <button type="submit">Sign In</button>
        </form>
    </div>
</body>
</html>';
}

/**
 * HTML-escape helper.
 */
function e(string $str): string {
    return \htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** Marker line identifying the current version of MintyMetrics' .htaccess rules. */
const HTACCESS_MARK = '# MintyMetrics file protection v2';

/**
 * .htaccess rules protecting the database (including WAL/SHM/journal files),
 * the location marker and the setup token.
 */
function htaccess_rules(): string {
    $deny = \str_replace("\n", "\n    ", \rtrim(HTACCESS_DENY_ALL));
    return HTACCESS_MARK . "\n"
        . '<FilesMatch "^(mintymetrics-[0-9a-f]+\.sqlite(-wal|-shm|-journal)?|\.mm_db_marker(\.php)?|\.mm_setup_token\.php)$">' . "\n"
        . '    ' . $deny . "\n"
        . "</FilesMatch>\n";
}

/**
 * Check .htaccess protection status for the folder containing analytics.php.
 */
function check_htaccess(): array {
    $htaccessPath = app_dir() . '/.htaccess';
    $rules = htaccess_rules();

    if (!\file_exists($htaccessPath)) {
        return ['status' => 'missing', 'rules' => $rules];
    }

    // Older versions only protected the main .sqlite file, not its -wal/-shm files
    if (\str_contains((string) \file_get_contents($htaccessPath), HTACCESS_MARK)) {
        return ['status' => 'ok'];
    }

    return ['status' => 'partial', 'rules' => $rules];
}

/**
 * Create or append .htaccess rules to protect the database file.
 */
function fix_htaccess(): bool {
    $check = check_htaccess();
    if ($check['status'] === 'ok') {
        return true;
    }

    $htaccessPath = app_dir() . '/.htaccess';
    $rules = "\n" . $check['rules'];

    if ($check['status'] === 'missing') {
        return @\file_put_contents($htaccessPath, \ltrim($rules)) !== false;
    }

    // Append to existing
    return @\file_put_contents($htaccessPath, $rules, FILE_APPEND) !== false;
}