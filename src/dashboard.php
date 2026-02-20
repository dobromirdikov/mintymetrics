<?php
namespace MintyMetrics;

/**
 * Render the main analytics dashboard.
 */
function render_dashboard(): void {
    $nonce = csp_nonce();
    set_csp_headers();

    $dntEnabled = get_config('respect_dnt', '1') === '1';
    $geoAvail = geo_available();
    $dashTitle = get_config('dashboard_title', DASHBOARD_TITLE);

    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . e($dashTitle) . ' &mdash; Dashboard</title>
    <link rel="icon" type="image/svg+xml" href="' . FAVICON_SVG . '">
    <style nonce="' . $nonce . '">';
    // CSS is inlined by build script — must be outside PHP string to avoid quote conflicts
    echo <<<'CSSBLOCK'
/* {{CSS}} */
CSSBLOCK;
    echo '</style>
</head>
<body>';

    // DNT notice
    if ($dntEnabled) {
        echo '<div class="mm-dnt-notice">DNT/GPC is respected. Some visitors may not be tracked.</div>';
    }

    echo '<header class="mm-header">
    <div class="mm-logo">
        ' . logo_svg(28) . '
        <span>' . e($dashTitle) . '</span>
    </div>
    <nav class="mm-nav">
        <div class="mm-site-selector" id="siteSelector" aria-label="Select site">
            <button class="mm-site-selector__trigger" id="siteSelectorTrigger" type="button" aria-haspopup="listbox" aria-expanded="false">
                <span class="mm-site-selector__label" id="siteSelectorLabel">All Sites</span>
                <svg class="mm-site-selector__arrow" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="3,4.5 6,7.5 9,4.5"/></svg>
            </button>
            <div class="mm-site-selector__menu" id="siteSelectorMenu" role="listbox" hidden></div>
        </div>
        <a href="?health" class="mm-nav-link" data-modal="health" title="Health">
            <svg viewBox="0 0 20 20" fill="currentColor"><path d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v3.586L7.707 11.293a1 1 0 001.414 1.414l2-2A1 1 0 0011.5 10V7z"/></svg>
        </a>
        <a href="?settings" class="mm-nav-link" data-modal="settings" title="Settings">
            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd"/></svg>
        </a>
        <a href="?help" class="mm-nav-link" data-modal="help" title="Tracking Code">
            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-3a1 1 0 00-.867.5 1 1 0 11-1.731-1A3 3 0 0113 8a3.001 3.001 0 01-2 2.83V11a1 1 0 11-2 0v-1a1 1 0 011-1 1 1 0 100-2zm0 8a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>
        </a>
        <form method="POST" action="' . e(script_url()) . '?logout" class="mm-nav-form">
            ' . csrf_field() . '
            <button type="submit" class="mm-nav-link mm-nav-logout" title="Logout">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 3a1 1 0 011-1h12a1 1 0 011 1v4a1 1 0 01-2 0V4H5v12h10v-3a1 1 0 112 0v4a1 1 0 01-1 1H4a1 1 0 01-1-1V3z" clip-rule="evenodd"/><path d="M13.293 9.293a1 1 0 011.414 0l2 2a1 1 0 010 1.414l-2 2a1 1 0 01-1.414-1.414L14.586 12H7a1 1 0 110-2h7.586l-1.293-1.293a1 1 0 010-1.414z"/></svg>
            </button>
        </form>
    </nav>
</header>

<main class="mm-main">
    <div class="mm-date-bar">
        <div class="mm-date-presets">
            <button data-range="today">Today</button>
            <button data-range="yesterday">Yesterday</button>
            <button data-range="7d" class="active">7 days</button>
            <button data-range="30d">30 days</button>
            <button data-range="90d">90 days</button>
            <button data-range="custom">Custom</button>
        </div>
        <div class="mm-date-custom" id="customDatePicker" hidden>
            <input type="date" id="dateFrom" aria-label="From date">
            <span>to</span>
            <input type="date" id="dateTo" aria-label="To date">
            <button id="applyDateRange" class="mm-btn mm-btn--primary">Apply</button>
        </div>
    </div>

    <div class="mm-summary-bar">
        <div class="mm-card mm-card--stat">
            <span class="mm-card__label">Pageviews</span>
            <span class="mm-card__value" id="valPageviews">&mdash;</span>
        </div>
        <div class="mm-card mm-card--stat">
            <span class="mm-card__label">Unique Visitors</span>
            <span class="mm-card__value" id="valUniques">&mdash;</span>
        </div>
        <div class="mm-card mm-card--stat">
            <span class="mm-card__label">Single-Page Visits</span>
            <span class="mm-card__value" id="valBounce">&mdash;</span>
        </div>
        <div class="mm-card mm-card--stat">
            <span class="mm-card__label">Avg. Time on Page</span>
            <span class="mm-card__value" id="valTime">&mdash;</span>
        </div>
        <div class="mm-card mm-card--stat mm-card--live">
            <span class="mm-card__label">Active Now</span>
            <span class="mm-card__value" id="valLive">&mdash;</span>
            <span class="mm-pulse"></span>
        </div>
    </div>

    <div class="mm-card mm-card--chart">
        <div class="mm-card__header"><h2>Visitors &amp; Pageviews</h2></div>
        <div class="mm-chart-container" id="mainChart">
            <div class="mm-loading"><div class="mm-spinner"></div></div>
        </div>
    </div>

    <div class="mm-grid">
        <div class="mm-col">
            <div class="mm-card">
                <div class="mm-card__header">
                    <h2>Top Pages</h2>
                    <button class="mm-export-btn" data-export="pages" title="Export CSV"></button>
                </div>
                <table class="mm-table" id="tablePages">
                    <thead><tr><th>Page</th><th>Views</th><th>Uniques</th></tr></thead>
                    <tbody><tr><td colspan="3"><div class="mm-loading"><div class="mm-spinner"></div></div></td></tr></tbody>
                </table>
            </div>

            <div class="mm-card">
                <div class="mm-card__header">
                    <h2>Referrers</h2>
                    <button class="mm-export-btn" data-export="referrers" title="Export CSV"></button>
                </div>
                <table class="mm-table" id="tableReferrers">
                    <thead><tr><th>Source</th><th>Visitors</th></tr></thead>
                    <tbody><tr><td colspan="2"><div class="mm-loading"><div class="mm-spinner"></div></div></td></tr></tbody>
                </table>
            </div>

            <div class="mm-card">
                <div class="mm-card__header">
                    <h2>UTM Campaigns</h2>
                    <div class="mm-tab-bar">
                        <button class="mm-tab active" data-utm="source">Source</button>
                        <button class="mm-tab" data-utm="medium">Medium</button>
                        <button class="mm-tab" data-utm="campaign">Campaign</button>
                    </div>
                </div>
                <table class="mm-table" id="tableUTM">
                    <thead><tr><th>Name</th><th>Visitors</th></tr></thead>
                    <tbody><tr><td colspan="2"><div class="mm-loading"><div class="mm-spinner"></div></div></td></tr></tbody>
                </table>
            </div>
        </div>

        <div class="mm-col">
            <div class="mm-card">
                <div class="mm-card__header"><h2>Countries</h2></div>';

    if ($geoAvail) {
        echo '<div class="mm-map-container" id="worldMap"></div>';
    } else {
        echo '<div class="mm-map-container" id="worldMap">
                    <div class="mm-empty">
                        <div class="mm-empty__text">Enable country tracking in <a href="?settings" data-modal="settings">settings</a></div>
                    </div>
                </div>';
    }

    echo '      <table class="mm-table" id="tableCountries">
                    <thead><tr><th>Country</th><th>Visitors</th></tr></thead>
                    <tbody><tr><td colspan="2"><div class="mm-loading"><div class="mm-spinner"></div></div></td></tr></tbody>
                </table>
            </div>

            <div class="mm-card">
                <div class="mm-card__header">
                    <h2>Devices</h2>
                    <div class="mm-tab-bar">
                        <button class="mm-tab active" data-device="type">Type</button>
                        <button class="mm-tab" data-device="browser">Browser</button>
                        <button class="mm-tab" data-device="os">OS</button>
                    </div>
                </div>
                <div class="mm-bar-chart" id="chartDevices">
                    <div class="mm-loading"><div class="mm-spinner"></div></div>
                </div>
            </div>

            <div class="mm-card">
                <div class="mm-card__header"><h2>Screen Resolutions</h2></div>
                <table class="mm-table" id="tableScreens">
                    <thead><tr><th>Resolution</th><th>Visitors</th></tr></thead>
                    <tbody><tr><td colspan="2"><div class="mm-loading"><div class="mm-spinner"></div></div></td></tr></tbody>
                </table>
            </div>

            <div class="mm-card">
                <div class="mm-card__header"><h2>Languages</h2></div>
                <table class="mm-table" id="tableLangs">
                    <thead><tr><th>Language</th><th>Visitors</th></tr></thead>
                    <tbody><tr><td colspan="2"><div class="mm-loading"><div class="mm-spinner"></div></div></td></tr></tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<footer class="mm-footer">
    <span>MintyMetrics v' . VERSION . '</span>
    <span>&middot;</span>
    <a href="?compliance">Privacy &amp; GDPR</a>
    <span>&middot;</span>
    <span>By <a href="https://mintyanalyst.com" target="_blank" rel="noopener">Minty Analyst</a></span>';

    if ($geoAvail) {
        echo '<span>&middot;</span><span>Country data by <a href="https://lite.ip2location.com" target="_blank" rel="noopener">IP2Location LITE</a></span>';
    }

    echo '</footer>
<div class="mm-modal-overlay" id="mmModal" hidden>
    <div class="mm-modal">
        <div class="mm-modal-header">
            <h2 id="mmModalTitle"></h2>
            <button class="mm-modal-close" type="button">&times;</button>
        </div>
        <div class="mm-modal-body" id="mmModalBody"></div>
    </div>
</div>
<script nonce="' . $nonce . '">';
    // JS is inlined by build script — must be outside PHP string to avoid quote conflicts
    echo <<<'JSBLOCK'
/* {{JS}} */
JSBLOCK;
    echo '</script>
</body>
</html>';
}

/**
 * Render the GDPR compliance information page.
 */
function render_compliance(): void {
    $nonce = csp_nonce();
    set_csp_headers();
    $dashTitle = get_config('dashboard_title', DASHBOARD_TITLE);

    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . e($dashTitle) . ' &mdash; Privacy &amp; GDPR</title>
    <link rel="icon" type="image/svg+xml" href="' . FAVICON_SVG . '">
    <style nonce="' . $nonce . '">
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #F4F7F6; color: #1A2B25; line-height: 1.6; padding: 24px; }
        .mm-compliance { max-width: 720px; margin: 0 auto; background: #fff; border-radius: 12px;
            padding: 40px; box-shadow: 0 4px 12px rgba(26,43,37,0.1); }
        h1 { font-size: 1.5rem; color: #2AB090; margin-bottom: 24px; }
        h2 { font-size: 1.125rem; margin-top: 24px; margin-bottom: 8px; }
        p, li { margin-bottom: 8px; color: #5A6F66; }
        ul { margin-left: 24px; }
        .mm-back { display: inline-block; margin-bottom: 16px; color: #2AB090; text-decoration: none; }
        .mm-back:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="mm-compliance">
        <a href="' . e(script_url()) . '" class="mm-back">&larr; Back to Dashboard</a>
        <h1>Privacy &amp; GDPR Compliance</h1>

        <h2>What data is collected</h2>
        <ul>
            <li>Page URL and referrer URL</li>
            <li>Device type, browser, and operating system (from User-Agent)</li>
            <li>Screen resolution and browser language</li>
            <li>Country (when geolocation is enabled, via IP lookup)</li>
            <li>UTM campaign parameters (from URL query strings)</li>
        </ul>

        <h2>What is NOT collected</h2>
        <ul>
            <li>No cookies are set on visitors &mdash; ever</li>
            <li>No IP addresses are stored &mdash; IPs are hashed immediately with a daily-rotating salt and then discarded</li>
            <li>No cross-day tracking &mdash; visitor hashes rotate daily by design</li>
            <li>No fingerprinting beyond the IP + User-Agent hash</li>
            <li>No personal data, no login tracking, no form data</li>
        </ul>

        <h2>Why no cookie consent is needed</h2>
        <p>MintyMetrics does not use cookies, local storage, or any persistent client-side storage.
        Under GDPR (Recital 30) and the ePrivacy Directive, cookie consent requirements apply to
        information stored on or accessed from a user\'s device. Since MintyMetrics stores nothing
        on the visitor\'s device, no consent banner is required.</p>

        <h2>DNT &amp; Global Privacy Control</h2>
        <p>When the Do Not Track (DNT) header or Global Privacy Control (GPC) signal is detected,
        no data is collected for that visitor. This is enabled by default and can be configured by the site owner.</p>

        <h2>Data retention</h2>
        <p>Raw pageview data is retained for ' . e((string) get_config('data_retention_days', DATA_RETENTION_DAYS)) . ' days,
        after which it is aggregated into anonymous daily summaries and the raw data is deleted.
        Daily summaries contain no individual visitor information.</p>

        <h2>Data storage</h2>
        <p>All data is stored in a local SQLite database on the same server that hosts this analytics tool.
        No data is sent to third parties, external servers, or cloud services.</p>
    </div>
</body>
</html>';
}

/**
 * Render the health/status panel.
 */
function render_health(): void {
    $isFragment = isset($_GET['modal']);

    $htaccess = check_htaccess();
    $geoAvail = geo_available();
    $dbSize = db_size_mb();
    $maxSize = (float) get_config('max_db_size_mb', MAX_DB_SIZE_MB);
    $retentionDays = (int) get_config('data_retention_days', DATA_RETENTION_DAYS);

    // Estimate days until max size
    $daysEstimate = null;
    if ($dbSize > 0) {
        $dailyGrowth = $dbSize / \max($retentionDays, 1);
        $remaining = $maxSize - $dbSize;
        if ($dailyGrowth > 0 && $remaining > 0) {
            $daysEstimate = (int) ($remaining / $dailyGrowth);
        }
    }

    if (!$isFragment) {
        $nonce = csp_nonce();
        set_csp_headers();
        $dashTitle = get_config('dashboard_title', DASHBOARD_TITLE);
        echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . e($dashTitle) . ' &mdash; Health</title>
    <link rel="icon" type="image/svg+xml" href="' . FAVICON_SVG . '">
    <style nonce="' . $nonce . '">
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #F4F7F6; color: #1A2B25; line-height: 1.6; padding: 24px; }
        .mm-health { max-width: 600px; margin: 0 auto; background: #fff; border-radius: 12px;
            padding: 32px; box-shadow: 0 4px 12px rgba(26,43,37,0.1); }
        .mm-back { display: inline-block; margin-bottom: 16px; color: #2AB090; text-decoration: none; }
        .mm-back:hover { text-decoration: underline; }
        .mm-health-item { display: flex; align-items: center; gap: 12px; padding: 12px 0; border-bottom: 1px solid #E8EFEB; }
        .mm-health-item:last-child { border-bottom: none; }
        .mm-health-icon { font-size: 1.25rem; }
        .mm-health-label { font-weight: 500; }
        .mm-health-detail { color: #5A6F66; font-size: 0.875rem; }
        .ok { color: #2AB090; } .warn { color: #E5A53D; } .err { color: #D94F4F; }
        .mm-htaccess-form { display: inline; }
        .mm-htaccess-btn { background:none;border:none;color:#2AB090;cursor:pointer;text-decoration:underline;font-size:inherit; }
    </style>
</head>
<body>
    <div class="mm-health">
        <a href="' . e(script_url()) . '" class="mm-back">&larr; Back to Dashboard</a>';
    } else {
        echo '<div class="mm-health">';
    }

    // Database status
    echo '<div class="mm-health-item"><span class="mm-health-icon ok">&#10003;</span><div><div class="mm-health-label">Database</div><div class="mm-health-detail">' . $dbSize . ' MB / ' . $maxSize . ' MB';
    if ($daysEstimate !== null) {
        echo ' &mdash; ~' . $daysEstimate . ' days until limit';
    }
    echo '</div></div></div>';

    // Password
    echo '<div class="mm-health-item"><span class="mm-health-icon ok">&#10003;</span><div><div class="mm-health-label">Password configured</div></div></div>';

    // DB location detection
    $dbPath = get_db_path();
    $scriptDir = \dirname(\realpath($_SERVER['SCRIPT_FILENAME'] ?? __FILE__));
    $dbRealPath = \realpath($dbPath) ?: $dbPath;
    $dbInWebRoot = \str_starts_with($dbRealPath, $scriptDir);

    // Server type detection and .htaccess
    $serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? '';
    $isApache = \stripos($serverSoftware, 'apache') !== false || \stripos($serverSoftware, 'litespeed') !== false;
    $isNginx = \stripos($serverSoftware, 'nginx') !== false;

    if (!$dbInWebRoot) {
        echo '<div class="mm-health-item"><span class="mm-health-icon ok">&#10003;</span><div><div class="mm-health-label">Database protection</div><div class="mm-health-detail">Database is stored outside the web root</div></div></div>';
    } elseif ($isApache) {
        if ($htaccess['status'] === 'ok') {
            echo '<div class="mm-health-item"><span class="mm-health-icon ok">&#10003;</span><div><div class="mm-health-label">Database protection</div><div class="mm-health-detail">Database is in web root but protected via .htaccess</div></div></div>';
        } elseif ($htaccess['status'] === 'partial') {
            echo '<div class="mm-health-item"><span class="mm-health-icon warn">&#9888;</span><div><div class="mm-health-label">Database protection</div><div class="mm-health-detail">Database is in web root and rules not found in .htaccess. <form method="POST" action="' . e(script_url()) . '?htaccess-fix" class="mm-htaccess-form">' . csrf_field() . '<button type="submit" class="mm-htaccess-btn">Add automatically</button></form></div></div></div>';
        } else {
            echo '<div class="mm-health-item"><span class="mm-health-icon warn">&#9888;</span><div><div class="mm-health-label">Database protection</div><div class="mm-health-detail">Database is in web root with no .htaccess file. <form method="POST" action="' . e(script_url()) . '?htaccess-fix" class="mm-htaccess-form">' . csrf_field() . '<button type="submit" class="mm-htaccess-btn">Create automatically</button></form></div></div></div>';
        }
    } elseif ($isNginx) {
        echo '<div class="mm-health-item"><span class="mm-health-icon warn">&#9888;</span><div><div class="mm-health-label">Database protection</div><div class="mm-health-detail">Database is in web root. Nginx detected &mdash; add this to your server config:<br><code>location ~* \.(sqlite|sqlite-wal|sqlite-shm|mm_db_marker\.php)$ { deny all; }</code></div></div></div>';
    } else {
        echo '<div class="mm-health-item"><span class="mm-health-icon warn">&#9888;</span><div><div class="mm-health-label">Database protection</div><div class="mm-health-detail">Database is in web root. Ensure your server blocks access to .sqlite and .mm_db_marker.php files.</div></div></div>';
    }

    // Geo
    if ($geoAvail) {
        $testIp = $_SERVER['REMOTE_ADDR'] ?? '';
        $testResult = geo_lookup($testIp);
        $detail = 'IP2Location database found. Your IP: ' . e($testIp) . ' &rarr; ' . ($testResult ? e($testResult) : '<em>no result</em>');
        echo '<div class="mm-health-item"><span class="mm-health-icon ok">&#10003;</span><div><div class="mm-health-label">Geolocation</div><div class="mm-health-detail">' . $detail . '</div></div></div>';
    } else {
        echo '<div class="mm-health-item"><span class="mm-health-icon warn">&#9888;</span><div><div class="mm-health-label">Geolocation</div><div class="mm-health-detail">Not installed. <a href="?settings" data-modal="settings">Enable in settings</a></div></div></div>';
    }

    echo '</div>';

    if (!$isFragment) {
        echo '
</body>
</html>';
    }
}

/**
 * Render the tracking code reference (help) modal content.
 */
function render_help(): void {
    auth_require();

    $filename = \basename(__FILE__);
    $scriptUrl = e(script_url());

    echo '<div class="mm-help">
    <p class="mm-help-intro">Add tracking to your websites. You can rename <code>' . e($filename) . '</code> to anything &mdash; all routing is self-referencing.</p>

    <div class="mm-help-section">
        <h3 class="mm-help-section-title">PHP Sites <span class="mm-help-badge">same server</span></h3>
        <p>Add these two lines to each page you want to track:</p>
        <div class="mm-help-code"><code>&lt;?php include \'' . e($filename) . '\'; ?&gt;</code></div>
        <div class="mm-help-code"><code>&lt;?php \\MintyMetrics\\head(); ?&gt;</code></div>
        <p class="mm-help-note">The <code>include</code> goes at the very top of the file, before any output. The <code>head()</code> call goes inside your <code>&lt;head&gt;</code> tag.</p>
    </div>

    <div class="mm-help-section">
        <h3 class="mm-help-section-title">Any Website <span class="mm-help-badge">hub mode</span></h3>
        <p>Track one or more external sites from this dashboard. Add a script tag to each site&rsquo;s <code>&lt;head&gt;</code>:</p>
        <div class="mm-help-code"><code>&lt;script defer src=&quot;' . $scriptUrl . '?js&amp;site=<em>yoursite.com</em>&quot;&gt;&lt;/script&gt;</code></div>
        <p class="mm-help-note">Replace <em>yoursite.com</em> with the site&rsquo;s domain. Each domain must be added in <a href="?settings" data-modal="settings">Settings</a> &rarr; Allowed Domains. All sites appear in one dashboard via the site switcher.</p>
    </div>

    <p class="mm-help-verify">Verify the URL above matches your public-facing address, especially behind reverse proxies.</p>

    <p class="mm-help-docs">Full documentation including server configuration, geolocation, and CLI commands is available in the <a href="https://github.com/dobromirdikov/mintymetrics#readme" target="_blank" rel="noopener">README</a>.</p>
</div>';
}
