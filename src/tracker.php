<?php
namespace MintyMetrics;

// Known bot patterns (compiled regex)
const BOT_REGEX = '/bot|crawl|spider|slurp|bingbot|googlebot|yandexbot|baiduspider|duckduckbot|sogou|exabot|facebot|ia_archiver|semrush|ahrefs|mj12bot|dotbot|petalbot|bytespider|gptbot|claudebot|ccbot|chatgpt|archive\.org|wget|curl|python-requests|go-http-client|java\/|headlesschrome|phantomjs|lighthouse|pagespeed|uptimerobot|pingdom|statuspage/i';

/**
 * Record a pageview hit.
 * Handles both hub mode (?hit&...) and processes the tracking request.
 */
function track_hit(): void {
    try {
        // JS verification check — reject requests without the _v parameter
        if (empty($_GET['_v'])) {
            return;
        }

        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Bot filtering
        if (is_bot($ua)) {
            return;
        }

        // DNT / GPC respect
        if (get_config('respect_dnt', '1') === '1') {
            if (($_SERVER['HTTP_DNT'] ?? '') === '1' || ($_SERVER['HTTP_SEC_GPC'] ?? '') === '1') {
                return;
            }
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $normalizedIp = normalize_ip($ip);
        $ipHash = \hash('sha256', $normalizedIp);

        // Rate limiting
        if (check_rate_limit($ipHash)) {
            return;
        }

        // Domain validation
        $site = sanitize_site($_GET['site'] ?? ($_SERVER['HTTP_HOST'] ?? ''));
        if (!validate_domain($site)) {
            return;
        }

        // Generate visitor hash (daily-rotating, privacy-preserving)
        $salt = get_salt();
        $visitorHash = generate_visitor_hash($ip, $ua, $salt);

        // Parse tracking data
        $pagePath = truncate($_GET['page'] ?? $_SERVER['REQUEST_URI'] ?? '/', MAX_PAGE_PATH);
        $referrer = truncate($_GET['ref'] ?? $_SERVER['HTTP_REFERER'] ?? '', MAX_REFERRER);
        $referrerDomain = parse_referrer_domain($referrer);
        $utm = parse_utm($_GET);
        $screenRes = truncate($_GET['res'] ?? '', MAX_SCREEN_RES);
        $language = truncate($_GET['lang'] ?? '', MAX_LANGUAGE);

        // Parse User-Agent
        $uaData = parse_ua($ua);

        // Geo lookup (before discarding IP)
        $countryCode = null;
        if (get_config('enable_geo', '1') === '1' && geo_available()) {
            $countryCode = geo_lookup($ip);
        }

        // Write to database
        $db = db();
        $stmt = $db->prepare('
            INSERT INTO hits (site, visitor_hash, page_path, referrer_url, referrer_domain,
                utm_source, utm_medium, utm_campaign, utm_term, utm_content,
                country_code, device_type, browser, browser_ver, os, os_ver,
                screen_res, language)
            VALUES (:site, :hash, :page, :ref_url, :ref_domain,
                :utm_s, :utm_m, :utm_c, :utm_t, :utm_ct,
                :country, :device, :browser, :browser_ver, :os, :os_ver,
                :screen, :lang)
        ');

        $stmt->bindValue(':site', $site, SQLITE3_TEXT);
        $stmt->bindValue(':hash', $visitorHash, SQLITE3_TEXT);
        $stmt->bindValue(':page', $pagePath, SQLITE3_TEXT);
        $stmt->bindValue(':ref_url', $referrer ?: null, $referrer ? SQLITE3_TEXT : SQLITE3_NULL);
        $stmt->bindValue(':ref_domain', $referrerDomain, $referrerDomain ? SQLITE3_TEXT : SQLITE3_NULL);
        $stmt->bindValue(':utm_s', $utm['source'], $utm['source'] ? SQLITE3_TEXT : SQLITE3_NULL);
        $stmt->bindValue(':utm_m', $utm['medium'], $utm['medium'] ? SQLITE3_TEXT : SQLITE3_NULL);
        $stmt->bindValue(':utm_c', $utm['campaign'], $utm['campaign'] ? SQLITE3_TEXT : SQLITE3_NULL);
        $stmt->bindValue(':utm_t', $utm['term'], $utm['term'] ? SQLITE3_TEXT : SQLITE3_NULL);
        $stmt->bindValue(':utm_ct', $utm['content'], $utm['content'] ? SQLITE3_TEXT : SQLITE3_NULL);
        $stmt->bindValue(':country', $countryCode, $countryCode ? SQLITE3_TEXT : SQLITE3_NULL);
        $stmt->bindValue(':device', $uaData['device'], SQLITE3_TEXT);
        $stmt->bindValue(':browser', $uaData['browser'], SQLITE3_TEXT);
        $stmt->bindValue(':browser_ver', $uaData['browser_ver'], $uaData['browser_ver'] ? SQLITE3_TEXT : SQLITE3_NULL);
        $stmt->bindValue(':os', $uaData['os'], SQLITE3_TEXT);
        $stmt->bindValue(':os_ver', $uaData['os_ver'], $uaData['os_ver'] ? SQLITE3_TEXT : SQLITE3_NULL);
        $stmt->bindValue(':screen', $screenRes ?: null, $screenRes ? SQLITE3_TEXT : SQLITE3_NULL);
        $stmt->bindValue(':lang', $language ?: null, $language ? SQLITE3_TEXT : SQLITE3_NULL);

        $stmt->execute();

    } catch (\Exception $e) {
        // Silently fail — tracking errors must never break anything
        log_error('track_hit: ' . $e->getMessage());
    }
}

/**
 * Handle time-on-page beacon (POST).
 */
function track_beacon(): void {
    try {
        // CORS headers and preflight must be handled before method check
        set_cors_headers();

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            \http_response_code(204);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            \http_response_code(405);
            return;
        }

        if (empty($_POST['_v'])) {
            \http_response_code(204);
            return;
        }

        $site = sanitize_site($_POST['site'] ?? '');
        $page = truncate($_POST['page'] ?? '', MAX_PAGE_PATH);
        $time = (int) ($_POST['time'] ?? 0);

        if ($time < 1 || $time > 3600) {
            \http_response_code(204);
            return;
        }

        if (!validate_domain($site)) {
            \http_response_code(204);
            return;
        }

        // Generate same visitor hash to match the original hit
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $hash = generate_visitor_hash($ip, $ua, get_salt());

        $db = db();
        $stmt = $db->prepare('
            UPDATE hits SET time_on_page = :time, last_active_at = :now
            WHERE id = (
                SELECT id FROM hits
                WHERE visitor_hash = :hash AND site = :site AND page_path = :page
                  AND created_at >= :today_start
                ORDER BY created_at DESC LIMIT 1
            )
        ');
        $stmt->bindValue(':time', $time, SQLITE3_INTEGER);
        $stmt->bindValue(':now', \time(), SQLITE3_INTEGER);
        $stmt->bindValue(':hash', $hash, SQLITE3_TEXT);
        $stmt->bindValue(':site', $site, SQLITE3_TEXT);
        $stmt->bindValue(':page', $page, SQLITE3_TEXT);
        // Today's start as Unix timestamp
        $todayStart = \strtotime(\date('Y-m-d'));
        $stmt->bindValue(':today_start', $todayStart, SQLITE3_INTEGER);
        $stmt->execute();

    } catch (\Exception $e) {
        log_error('track_beacon: ' . $e->getMessage());
    }

    \http_response_code(204);
}

/**
 * Serve the tracking JS snippet for hub mode.
 */
function serve_js(string $site): void {
    $site = sanitize_site($site);
    if (!validate_domain($site)) {
        \http_response_code(403);
        return;
    }

    $endpoint = script_url();

    // The tracker JS template is inlined at build time
    $js = get_tracker_js($endpoint, $site);

    \header('Content-Type: application/javascript; charset=utf-8');
    \header('Cache-Control: public, max-age=86400');
    echo $js;
}

/**
 * Get the tracker JS with endpoint and site injected.
 */
function get_tracker_js(string $endpoint, string $site): string {
    $js = <<<'TRACKER_JS'
/* {{TRACKER_JS}} */
TRACKER_JS;

    $js = \str_replace('{{ENDPOINT}}', $endpoint, $js);
    $js = \str_replace('{{SITE}}', \addslashes($site), $js);
    $js = \str_replace('{{RESPECT_DNT}}', get_config('respect_dnt', '1'), $js);
    return $js;
}

/**
 * Get inline tracker JS for drop-in mode's head() function.
 */
function get_tracker_js_inline(string $endpoint, string $site): string {
    return get_tracker_js($endpoint, $site);
}

/**
 * Output function for drop-in mode. Users call this in their <head> section.
 * Outputs a <script> tag that handles client-side tracking.
 */
function head(string $nonce = ''): void {
    $site = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $endpoint = script_url();
    $js = get_tracker_js_inline($endpoint, $site);
    $attr = $nonce ? ' nonce="' . $nonce . '"' : '';
    echo "<script{$attr}>" . $js . '</script>';
}

/**
 * Serve a 1x1 transparent GIF (tracking pixel response).
 */
function serve_pixel(): void {
    set_cors_headers();
    \header('Content-Type: image/gif');
    \header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    \header('Pragma: no-cache');
    // 1x1 transparent GIF (43 bytes)
    echo \base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
}

/**
 * Set CORS headers for cross-origin tracking requests (hub mode).
 */
function set_cors_headers(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = get_allowed_domains();

    if (empty($allowed)) {
        // No allow-list configured (single-site mode) — allow all origins
        \header('Access-Control-Allow-Origin: *');
    } elseif ($origin) {
        // Check origin against allowed domains
        $originHost = \parse_url($origin, PHP_URL_HOST) ?? '';
        foreach ($allowed as $domain) {
            if ($originHost === $domain || \str_ends_with($originHost, '.' . $domain)) {
                \header('Access-Control-Allow-Origin: ' . $origin);
                \header('Vary: Origin');
                break;
            }
        }
        // If no match found, no CORS header is sent — browser blocks the request
    }

    \header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    \header('Access-Control-Allow-Headers: Content-Type');
    \header('Access-Control-Max-Age: 86400');
}

/**
 * Check if a User-Agent string matches known bot patterns.
 */
function is_bot(string $ua): bool {
    if (empty($ua)) {
        return true; // No UA = likely a bot
    }
    return (bool) \preg_match(BOT_REGEX, $ua);
}

/**
 * Check if the request should be rate-limited.
 * Returns true if the request should be DROPPED.
 */
function check_rate_limit(string $ipHash): bool {
    try {
        $db = db();
        $now = \microtime(true);

        $stmt = $db->prepare('SELECT last_hit_at FROM rate_limit WHERE ip_hash = :hash');
        $stmt->bindValue(':hash', $ipHash, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);

        if ($row && ($now - $row['last_hit_at']) < RATE_LIMIT_SECONDS) {
            return true; // Rate limited
        }

        // Upsert
        $stmt = $db->prepare('INSERT OR REPLACE INTO rate_limit (ip_hash, last_hit_at) VALUES (:hash, :now)');
        $stmt->bindValue(':hash', $ipHash, SQLITE3_TEXT);
        $stmt->bindValue(':now', $now, SQLITE3_FLOAT);
        $stmt->execute();

        // Periodic cleanup (every ~100 requests)
        if (\random_int(1, 100) === 1) {
            $clean = $db->prepare('DELETE FROM rate_limit WHERE last_hit_at < :cutoff');
            $clean->bindValue(':cutoff', $now - 60, SQLITE3_FLOAT);
            $clean->execute();
        }

        return false;
    } catch (\Exception $e) {
        return false; // On error, allow the request
    }
}

/**
 * Generate a visitor hash from IP + User-Agent + daily salt.
 */
function generate_visitor_hash(string $ip, string $ua, string $salt): string {
    return \hash('sha256', $ip . '|' . $ua . '|' . $salt);
}

/**
 * Validate that a site domain is in the allowed list.
 */
function validate_domain(string $site): bool {
    $allowed = get_allowed_domains();
    if (empty($allowed)) {
        return true; // No whitelist = allow all (single-site mode)
    }

    foreach ($allowed as $domain) {
        if ($site === $domain || \str_ends_with($site, '.' . $domain)) {
            return true;
        }
    }
    return false;
}

/**
 * Sanitize a site domain string.
 */
function sanitize_site(string $site): string {
    $site = \strtolower(\trim($site));
    $site = \preg_replace('/[^a-z0-9.\-]/', '', $site);
    return truncate($site, MAX_SITE_DOMAIN);
}

/**
 * Extract the domain from a referrer URL.
 */
function parse_referrer_domain(string $referrer): ?string {
    if (empty($referrer)) {
        return null;
    }
    $host = \parse_url($referrer, PHP_URL_HOST);
    if (!$host) {
        return null;
    }
    $host = \strtolower($host);
    // Remove www. prefix for cleaner grouping
    if (\str_starts_with($host, 'www.')) {
        $host = \substr($host, 4);
    }
    return $host;
}

/**
 * Parse UTM parameters from request, with length limits.
 *
 * @return array{source: ?string, medium: ?string, campaign: ?string, term: ?string, content: ?string}
 */
function parse_utm(array $params): array {
    return [
        'source'   => isset($params['utm_source']) ? truncate($params['utm_source'], MAX_UTM_FIELD) : null,
        'medium'   => isset($params['utm_medium']) ? truncate($params['utm_medium'], MAX_UTM_FIELD) : null,
        'campaign' => isset($params['utm_campaign']) ? truncate($params['utm_campaign'], MAX_UTM_FIELD) : null,
        'term'     => isset($params['utm_term']) ? truncate($params['utm_term'], MAX_UTM_FIELD) : null,
        'content'  => isset($params['utm_content']) ? truncate($params['utm_content'], MAX_UTM_FIELD) : null,
    ];
}
