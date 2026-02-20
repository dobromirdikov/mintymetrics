<?php
namespace MintyMetrics;

/**
 * Render the settings page.
 * In fragment mode ($_GET['modal']), outputs only the inner content for modal injection.
 * In full-page mode, wraps content with HTML boilerplate (no-JS fallback).
 */
function render_settings(string $message = '', string $messageType = 'success'): void {
    auth_require();

    $isFragment = isset($_GET['modal']);

    $dashTitle = get_config('dashboard_title', DASHBOARD_TITLE);
    $domains = \implode("\n", get_allowed_domains());
    $retentionDays = get_config('data_retention_days', DATA_RETENTION_DAYS);
    $maxDbSize = get_config('max_db_size_mb', MAX_DB_SIZE_MB);
    $respectDnt = get_config('respect_dnt', '1');
    $enableGeo = get_config('enable_geo', '1');
    $geoInstalled = geo_available();
    $scriptUrl = e(script_url());

    $msgHtml = '';
    if ($message) {
        $cls = $messageType === 'error' ? 'mm-alert--error' : 'mm-alert--success';
        $msgHtml = '<div class="mm-alert ' . $cls . '">' . e($message) . '</div>';
    }

    if (!$isFragment) {
        $nonce = csp_nonce();
        set_csp_headers();
        echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . e($dashTitle) . ' &mdash; Settings</title>
    <link rel="icon" type="image/svg+xml" href="' . FAVICON_SVG . '">
    <style nonce="' . $nonce . '">
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #F4F7F6; color: #1A2B25; line-height: 1.6; padding: 24px; }
        .mm-settings { max-width: 1200px; margin: 0 auto; background: #fff; border-radius: 12px;
            padding: 32px; box-shadow: 0 4px 12px rgba(26,43,37,0.1); }
        .mm-settings-layout { display: flex; gap: 32px; }
        .mm-settings-main-wrap { flex: 1; min-width: 0; }
        .mm-settings-main { display: grid; grid-template-columns: repeat(3, 1fr); gap: 32px; }
        .mm-settings-form { display: contents; }
        .mm-settings-aside { flex: 0 0 240px; border-left: 2px solid #E8EFEB; padding-left: 32px; display: flex; flex-direction: column; }
        .mm-settings-aside form { flex: 1; display: flex; flex-direction: column; }
        .mm-settings-aside .mm-save-btn { margin-top: auto; }
        @media (max-width: 1024px) {
            .mm-settings-layout { flex-direction: column; }
            .mm-settings-main { grid-template-columns: 1fr 1fr; }
            .mm-settings-aside { flex: none; border-left: none; padding-left: 0; border-top: 2px solid #E8EFEB; padding-top: 32px; }
        }
        @media (max-width: 767px) { .mm-settings-main { grid-template-columns: 1fr; } }
        h1 { font-size: 1.5rem; color: #2AB090; margin-bottom: 8px; }
        h2 { font-size: 1.125rem; margin-bottom: 8px; color: #1A2B25; }
        .mm-back { display: inline-block; margin-bottom: 16px; color: #2AB090; text-decoration: none; }
        .mm-back:hover { text-decoration: underline; }
        label { display: block; font-weight: 600; margin-bottom: 6px; margin-top: 16px; font-size: 0.875rem; }
        input[type="text"], input[type="number"], input[type="password"], textarea, select {
            width: 100%; padding: 10px 12px; border: 1px solid #D8E2DE; border-radius: 8px;
            font-size: 1rem; font-family: inherit; background: #F4F7F6; }
        input:focus, textarea:focus, select:focus { outline: none; border-color: #2AB090; box-shadow: 0 0 0 3px rgba(42,176,144,0.15); }
        textarea { resize: vertical; min-height: 80px; }
        .hint { color: #8FA39A; font-size: 0.8125rem; margin-top: 4px; }
        .mm-check-row { display: flex; align-items: center; gap: 8px; margin-top: 16px; }
        .mm-check-row input[type="checkbox"] { width: 18px; height: 18px; accent-color: #2AB090; }
        .mm-check-row label { margin: 0; font-weight: normal; }
        .mm-save-btn, .mm-upload-btn {
            display: block; width: 100%; padding: 12px; margin-top: 24px; background: #2AB090;
            color: #fff; border: none; border-radius: 8px; font-size: 1rem; font-weight: 600;
            cursor: pointer; transition: background 0.15s; }
        .mm-save-btn:hover, .mm-upload-btn:hover { background: #239B7D; }
        .mm-upload-btn { display: inline-block; width: auto; padding: 8px 16px; font-size: 0.875rem; }
        .mm-alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 0.875rem; }
        .mm-alert--success { background: #E8F8F3; color: #1A8A6A; }
        .mm-alert--error { background: #FDEAEA; color: #D94F4F; }
        .mm-section { padding-bottom: 16px; margin-bottom: 16px; border-bottom: 1px solid #E8EFEB; }
        .mm-section:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
        .mm-geo-status { padding: 8px 12px; border-radius: 8px; font-size: 0.875rem; margin-top: 8px; }
        .mm-geo-ok { background: #E8F8F3; color: #1A8A6A; }
        .mm-geo-missing { background: #FDF3E0; color: #8B6B1F; }
        .mm-file-upload { margin-top: 12px; }
        .mm-file-upload input[type="file"] { font-size: 0.875rem; }
        .mm-geo-option { font-size: 0.875rem; margin-bottom: 16px; padding-bottom: 16px; border-bottom: 1px solid #E8EFEB; }
        .mm-geo-option:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
        .mm-token-row { display: flex; gap: 8px; align-items: center; margin-top: 8px; }
        .mm-token-row input[type="text"] { flex: 1; }
    </style>
</head>
<body>
    <div class="mm-settings">
        <a href="' . $scriptUrl . '" class="mm-back">&larr; Back to Dashboard</a>
        <h1>Settings</h1>';
    } else {
        echo '<div class="mm-settings">';
    }

    // ─── Settings content (shared by fragment and full-page modes) ───

    // Hidden form element — inputs reference it via form="mmSettingsForm"
    echo $msgHtml . '
        <form id="mmSettingsForm" method="POST" action="' . $scriptUrl . '?settings" hidden>' . csrf_field() . '</form>
        <div class="mm-settings-layout">
            <div class="mm-settings-main-wrap">
            <div class="mm-settings-main">
                <div>
                    <div class="mm-section">
                        <h2>General</h2>
                        <label for="dashboard_title">Dashboard Title</label>
                        <input form="mmSettingsForm" type="text" id="dashboard_title" name="dashboard_title" value="' . e($dashTitle) . '" maxlength="50">
                        <div class="hint">Displayed in the header and page titles. Default: MintyMetrics</div>
                    </div>

                    <div class="mm-section">
                        <h2>Tracking</h2>
                        <label for="domains">Allowed Domains</label>
                        <textarea form="mmSettingsForm" id="domains" name="domains" placeholder="example.com">' . e($domains) . '</textarea>
                        <div class="hint">One domain per line. Hub mode tracks multiple sites from one dashboard.</div>
                        <div class="mm-check-row">
                            <input form="mmSettingsForm" type="checkbox" id="respect_dnt" name="respect_dnt" value="1"' . ($respectDnt === '1' ? ' checked' : '') . '>
                            <label for="respect_dnt">Respect Do Not Track / Global Privacy Control</label>
                        </div>
                    </div>
                </div>

                <div>
                    <div class="mm-section">
                        <h2>Geolocation</h2>
                        <div class="mm-check-row">
                            <input form="mmSettingsForm" type="checkbox" id="enable_geo" name="enable_geo" value="1"' . ($enableGeo === '1' ? ' checked' : '') . '>
                            <label for="enable_geo">Enable country detection</label>
                        </div>';

    if ($geoInstalled) {
        echo '<div class="mm-geo-status mm-geo-ok">IP2Location database is installed.</div>';
    } else {
        echo '<div class="mm-geo-status mm-geo-missing">IP2Location database not found.</div>';
    }

    echo '          </div>

                    <div class="mm-section">
                        <h2>Install Geo Database</h2>
                        <div class="hint" style="margin-bottom:12px">Country detection requires the free <a href="https://lite.ip2location.com/database/db1-ip-country" target="_blank" rel="noopener">IP2Location LITE DB1</a> database.</div>

                        <div class="mm-geo-option">
                            <strong>Option 1:</strong> Auto-download with token
                            <div class="hint">Get a free download token at <a href="https://lite.ip2location.com/database/db1-ip-country" target="_blank" rel="noopener">lite.ip2location.com</a> (requires signup).</div>
                            <form method="POST" action="' . $scriptUrl . '?geo-download">
                                ' . csrf_field() . '
                                <div class="mm-token-row">
                                    <input type="text" name="token" placeholder="Download token" autocomplete="off">
                                    <button type="submit" class="mm-upload-btn">Download</button>
                                </div>
                            </form>
                        </div>

                        <div class="mm-geo-option">
                            <strong>Option 2:</strong> Upload BIN file
                            <div class="hint">Download the ZIP from <a href="https://lite.ip2location.com/database/db1-ip-country" target="_blank" rel="noopener">IP2Location</a>, extract the .BIN file, and upload it here.</div>
                            <form method="POST" action="' . $scriptUrl . '?geo-upload" enctype="multipart/form-data">
                                ' . csrf_field() . '
                                <div class="mm-file-upload">
                                    <input type="file" name="geofile" accept=".bin,.BIN">
                                    <button type="submit" class="mm-upload-btn">Upload</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div>
                    <div class="mm-section">
                        <h2>Data Retention</h2>
                        <label for="retention_days">Raw data retention (days)</label>
                        <input form="mmSettingsForm" type="number" id="retention_days" name="retention_days" value="' . e((string) $retentionDays) . '" min="7" max="365">
                        <div class="hint">Raw hit data older than this is summarized into daily aggregates and deleted.</div>
                        <label for="max_db_size">Max database size (MB)</label>
                        <input form="mmSettingsForm" type="number" id="max_db_size" name="max_db_size" value="' . e((string) $maxDbSize) . '" min="10" max="10000">
                        <label for="live_visitor_minutes">Live visitor window (minutes)</label>
                        <input form="mmSettingsForm" type="number" id="live_visitor_minutes" name="live_visitor_minutes" value="' . e((string) get_config('live_visitor_minutes', (string) LIVE_VISITOR_MINUTES)) . '" min="1" max="30">
                        <div class="hint">Time window for counting active visitors on the dashboard. Default: 5 minutes.</div>
                    </div>
                </div>
            </div>
            <button form="mmSettingsForm" type="submit" class="mm-save-btn">Save Settings</button>
            </div>

            <div class="mm-settings-aside">
                <h2>Change Password</h2>
                <form method="POST" action="' . $scriptUrl . '?settings">
                    ' . csrf_field() . '
                    <input type="hidden" name="action" value="change_password">
                    <label for="current_password">Current Password</label>
                    <input type="password" id="current_password" name="current_password" autocomplete="current-password">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password" minlength="8" autocomplete="new-password">
                    <label for="new_password_confirm">Confirm New Password</label>
                    <input type="password" id="new_password_confirm" name="new_password_confirm" minlength="8" autocomplete="new-password">
                    <button type="submit" class="mm-save-btn">Update Password</button>
                </form>
            </div>
        </div>
    </div>';

    if (!$isFragment) {
        echo '
</body>
</html>';
    }
}

/**
 * Handle settings form POST.
 * Returns JSON for AJAX requests, re-renders page for standard form submissions.
 */
function handle_settings(): void {
    auth_require();

    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
              \strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    $action = $_POST['action'] ?? '';
    $message = '';
    $messageType = 'success';

    if ($action === 'change_password') {
        $currentPw = $_POST['current_password'] ?? '';
        $newPw = $_POST['new_password'] ?? '';
        $confirmPw = $_POST['new_password_confirm'] ?? '';

        if (!auth_verify_password($currentPw)) {
            $message = 'Current password is incorrect.';
            $messageType = 'error';
        } elseif (\strlen($newPw) < 8) {
            $message = 'New password must be at least 8 characters.';
            $messageType = 'error';
        } elseif ($newPw !== $confirmPw) {
            $message = 'New passwords do not match.';
            $messageType = 'error';
        } else {
            auth_set_password($newPw);
            $message = 'Password updated successfully.';
        }
    } else {
        // General settings
        $title = \trim($_POST['dashboard_title'] ?? DASHBOARD_TITLE);
        if ($title === '') {
            $title = DASHBOARD_TITLE;
        }
        set_config('dashboard_title', truncate($title, 50));

        $domains = \array_filter(\array_map('trim', \explode("\n", $_POST['domains'] ?? '')));
        set_config('allowed_domains', \json_encode(\array_values($domains)));

        set_config('respect_dnt', isset($_POST['respect_dnt']) ? '1' : '0');
        set_config('enable_geo', isset($_POST['enable_geo']) ? '1' : '0');

        $retentionDays = \max(7, \min(365, (int) ($_POST['retention_days'] ?? DATA_RETENTION_DAYS)));
        set_config('data_retention_days', (string) $retentionDays);

        $maxDbSize = \max(10, \min(10000, (int) ($_POST['max_db_size'] ?? MAX_DB_SIZE_MB)));
        set_config('max_db_size_mb', (string) $maxDbSize);

        $liveMinutes = \max(1, \min(30, (int) ($_POST['live_visitor_minutes'] ?? LIVE_VISITOR_MINUTES)));
        set_config('live_visitor_minutes', (string) $liveMinutes);

        $message = 'Settings saved successfully.';
    }

    if ($isAjax) {
        \header('Content-Type: application/json');
        echo \json_encode(['success' => $messageType === 'success', 'message' => $message]);
        return;
    }

    render_settings($message, $messageType);
}
