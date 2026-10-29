<?php
namespace MintyMetrics;

// Known bot patterns (compiled regex). "bot" only counts as a whole token or a word
// ending (Googlebot, AhrefsBot); the lookbehind keeps CUBOT phones from matching.
const BOT_REGEX = '/(?<!cu)bot(?![a-z])|crawl|spider|slurp|yandex(?:bot|images)|baiduspider|sogou|exabot|facebot|facebookexternalhit|ia_archiver|semrush|ahrefs|mj12|petalbot|bytespider|gptbot|claudebot|ccbot|chatgpt|perplexity|archive\.org|wget|curl|python-requests|python-urllib|go-http-client|java\/|okhttp|headlesschrome|phantomjs|lighthouse|pagespeed|google-inspectiontool|mediapartners-google|adsbot|uptimerobot|pingdom|statuspage|embedly/i';

/** Per-IP request cap per minute for each endpoint kind (flood protection). */
const RATE_LIMIT_PER_MINUTE = 300;

/** Query parameters that only identify a click or campaign and split page stats. */
const TRACKING_QUERY_PARAMS = ['gclid', 'gbraid', 'wbraid', 'dclid', 'gclsrc', 'fbclid', 'msclkid', 'yclid',
    'twclid', 'ttclid', 'li_fat_id', 'igshid', 'mc_cid', 'mc_eid', '_ga', '_gl', '_hsenc', '_hsmi',
    'mkt_tok', 'oly_anon_id', 'oly_enc_id', 'vero_id', 'srsltid', 'epik', 'ref_src'];

/**
 * Query parameter names that commonly carry secrets or personal data; never stored.
 * Matched against each part of a name split on [ ] _ - . (so user[email], reset_token
 * and x-api-key all match), plus a few words that are sensitive anywhere in a name.
 */
const SENSITIVE_QUERY_WORDS = ['token', 'auth', 'authorization', 'key', 'apikey', 'secret', 'password', 'passwd',
    'pwd', 'pass', 'email', 'mail', 'code', 'state', 'session', 'sessionid', 'sid', 'sig', 'signature', 'otp',
    'jwt', 'reset', 'ticket', 'nonce', 'credential', 'credentials', 'phone', 'ssn', 'hash'];
const SENSITIVE_QUERY_SUBSTRING_REGEX = '/token|passw|secret|e-?mail|session|signature|api_?key|credential/';

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
        if (is_bot($ua) || dnt_requested()) {
            return;
        }

        // Validate the site before writing anything (rejected requests cost no writes)
        $site = sanitize_site($_GET['site'] ?? ($_SERVER['HTTP_HOST'] ?? ''));
        if ($site === '' || !validate_domain($site)) {
            return;
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $visitorHash = generate_visitor_hash($ip, $ua, get_salt(), $site);

        // Parse tracking data
        $pagePath = clean_page_path($_GET['page'] ?? $_SERVER['REQUEST_URI'] ?? '/');
        $referrer = truncate($_GET['ref'] ?? $_SERVER['HTTP_REFERER'] ?? '', MAX_REFERRER);
        $referrerDomain = parse_referrer_domain($referrer);
        if ($referrerDomain !== null && is_own_site($referrerDomain, $site)) {
            // Navigation within the site is not a referral
            $referrer = '';
            $referrerDomain = null;
        }
        $utm = parse_utm($_GET);
        $screenRes = truncate($_GET['res'] ?? '', MAX_SCREEN_RES);
        $language = truncate($_GET['lang'] ?? '', MAX_LANGUAGE);
        $pageviewId = pageview_id_param($_GET['pv'] ?? '');
        $uaData = parse_ua($ua);

        // Geo lookup (before discarding IP)
        $countryCode = null;
        if (get_config('enable_geo', '1') === '1' && geo_available()) {
            $countryCode = geo_lookup($ip);
        }

        // Rate limit and insert in one write transaction
        $db = db();
        $db->exec('BEGIN IMMEDIATE');
        try {
            // Drops only exact repeats (same visitor, same page, within a second), so
            // people sharing an IP (NAT, offices) are never dropped for each other. With a
            // pageview ID, two tabs opened on the same page at once both count.
            if (check_rate_limit('hit|' . $visitorHash . '|' . $pagePath . '|' . ($pageviewId ?? ''), $ip, 'hit')) {
                $db->exec('COMMIT');
                return;
            }

            $stmt = $db->prepare('
                INSERT INTO hits (site, visitor_hash, page_path, referrer_url, referrer_domain,
                    utm_source, utm_medium, utm_campaign, utm_term, utm_content,
                    country_code, device_type, browser, browser_ver, os, os_ver,
                    screen_res, language, pageview_id)
                VALUES (:site, :hash, :page, :ref_url, :ref_domain,
                    :utm_s, :utm_m, :utm_c, :utm_t, :utm_ct,
                    :country, :device, :browser, :browser_ver, :os, :os_ver,
                    :screen, :lang, :pv)
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
            $stmt->bindValue(':pv', $pageviewId, $pageviewId !== null ? SQLITE3_TEXT : SQLITE3_NULL);
            $stmt->execute();

            $db->exec('COMMIT');
        } catch (\Throwable $e) {
            db_rollback($db);
            throw $e;
        }
    } catch (\Throwable $e) {
        // Silently fail — tracking errors must never break anything
        log_error('track_hit: ' . $e->getMessage());
    }
}

/**
 * Handle time-on-page beacon (POST). The tracker sends the cumulative visible time
 * for the page each time it is hidden; the largest value wins.
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

        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (is_bot($ua) || dnt_requested()) {
            \http_response_code(204);
            return;
        }

        $site = sanitize_site($_POST['site'] ?? '');
        $page = clean_page_path($_POST['page'] ?? '');
        $time = (int) ($_POST['time'] ?? 0);
        $pageviewId = pageview_id_param($_POST['pv'] ?? '');

        if ($time < 1 || $site === '' || !validate_domain($site)) {
            \http_response_code(204);
            return;
        }
        // Long reads are capped rather than discarded
        $time = \min($time, 3600);

        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $now = \time();
        $db = db();

        // Today's salt first; yesterday's for a page opened before midnight
        foreach ([get_salt(), get_prev_salt()] as $salt) {
            if ($salt === null) {
                continue;
            }
            $hash = generate_visitor_hash($ip, $ua, $salt, $site);
            if (check_rate_limit('bcn|' . $hash . '|' . ($pageviewId ?? $page), $ip, 'bcn')) {
                break;
            }
            // With a pageview ID (current tracker) the beacon updates exactly its own
            // pageview, so two tabs on the same page never overwrite each other. Older
            // cached trackers fall back to the latest matching pageview.
            $match = $pageviewId !== null
                ? 'pageview_id = :pv AND visitor_hash = :hash AND site = :site'
                : 'visitor_hash = :hash AND site = :site AND page_path = :page AND pageview_id IS NULL';
            $stmt = $db->prepare("
                UPDATE hits SET time_on_page = MAX(COALESCE(time_on_page, 0), :time), last_active_at = :now
                WHERE id = (
                    SELECT id FROM hits
                    WHERE {$match} AND created_at >= :since
                    ORDER BY created_at DESC LIMIT 1
                )
            ");
            if ($pageviewId !== null) {
                $stmt->bindValue(':pv', $pageviewId, SQLITE3_TEXT);
            } else {
                $stmt->bindValue(':page', $page, SQLITE3_TEXT);
            }
            $stmt->bindValue(':time', $time, SQLITE3_INTEGER);
            $stmt->bindValue(':now', $now, SQLITE3_INTEGER);
            $stmt->bindValue(':hash', $hash, SQLITE3_TEXT);
            $stmt->bindValue(':site', $site, SQLITE3_TEXT);
            $stmt->bindValue(':since', $now - 2 * 86400, SQLITE3_INTEGER);
            $stmt->execute();
            if ($db->changes() > 0) {
                break;
            }
        }
    } catch (\Throwable $e) {
        log_error('track_beacon: ' . $e->getMessage());
    }

    \http_response_code(204);
}

/**
 * Record a custom event. Mirrors track_hit() validation gates so events get the
 * same bot/DNT/rate-limit/domain protection. Accepts GET (image pixel) and POST
 * (navigator.sendBeacon, which survives page unloads) with the data in the query.
 */
function track_event(): void {
    try {
        if (empty($_GET['_v'])) {
            return;
        }

        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (is_bot($ua) || dnt_requested()) {
            return;
        }

        $site = sanitize_site($_GET['site'] ?? ($_SERVER['HTTP_HOST'] ?? ''));
        if ($site === '' || !validate_domain($site)) {
            return;
        }

        $name = $_GET['name'] ?? '';
        if (!validate_event_name($name)) {
            return;
        }

        $value = isset($_GET['value']) && $_GET['value'] !== ''
            ? truncate((string) $_GET['value'], MAX_EVENT_VALUE)
            : null;

        $props = isset($_GET['p']) && $_GET['p'] !== ''
            ? validate_event_props((string) $_GET['p'])
            : null;

        $pagePath = isset($_GET['page']) && $_GET['page'] !== ''
            ? clean_page_path((string) $_GET['page'])
            : null;

        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $visitorHash = generate_visitor_hash($ip, $ua, get_salt(), $site);
        $uaData = parse_ua($ua);

        $countryCode = null;
        if (get_config('enable_geo', '1') === '1' && geo_available()) {
            $countryCode = geo_lookup($ip);
        }

        $db = db();
        $db->exec('BEGIN IMMEDIATE');
        try {
            // Separate bucket per event name/value: a burst of different events is fine,
            // only an exact repeat within a second is dropped
            $pageviewId = pageview_id_param($_GET['pv'] ?? '');
            if (check_rate_limit('evt|' . $visitorHash . '|' . ($pageviewId ?? '') . '|' . $name . '|' . $value, $ip, 'evt')) {
                $db->exec('COMMIT');
                return;
            }

            $stmt = $db->prepare('
                INSERT INTO events (site, visitor_hash, name, value, props, page_path,
                    country_code, device_type, browser, os)
                VALUES (:site, :hash, :name, :value, :props, :page,
                    :country, :device, :browser, :os)
            ');

            $stmt->bindValue(':site', $site, SQLITE3_TEXT);
            $stmt->bindValue(':hash', $visitorHash, SQLITE3_TEXT);
            $stmt->bindValue(':name', $name, SQLITE3_TEXT);
            $stmt->bindValue(':value', $value, $value !== null ? SQLITE3_TEXT : SQLITE3_NULL);
            $stmt->bindValue(':props', $props, $props !== null ? SQLITE3_TEXT : SQLITE3_NULL);
            $stmt->bindValue(':page', $pagePath, $pagePath !== null ? SQLITE3_TEXT : SQLITE3_NULL);
            $stmt->bindValue(':country', $countryCode, $countryCode ? SQLITE3_TEXT : SQLITE3_NULL);
            $stmt->bindValue(':device', $uaData['device'], SQLITE3_TEXT);
            $stmt->bindValue(':browser', $uaData['browser'], SQLITE3_TEXT);
            $stmt->bindValue(':os', $uaData['os'], SQLITE3_TEXT);
            $stmt->execute();

            $db->exec('COMMIT');
        } catch (\Throwable $e) {
            db_rollback($db);
            throw $e;
        }
    } catch (\Throwable $e) {
        log_error('track_event: ' . $e->getMessage());
    }
}

/**
 * Validate a tracker-generated pageview ID (random hex); null if absent or malformed.
 */
function pageview_id_param(string $value): ?string {
    return \preg_match('/^[a-f0-9]{16,32}$/', $value) ? $value : null;
}

/**
 * Whether the visitor sent Do Not Track / Global Privacy Control and the site respects it.
 */
function dnt_requested(): bool {
    if (get_config('respect_dnt', '1') !== '1') {
        return false;
    }
    return ($_SERVER['HTTP_DNT'] ?? '') === '1' || ($_SERVER['HTTP_SEC_GPC'] ?? '') === '1';
}

/**
 * Normalize a tracked page path: drop the #fragment, click/campaign IDs (UTM values
 * are stored separately) and parameters that commonly carry secrets or personal data
 * (?token=, ?email=). Other parameters (?p=123, ?q=search) are kept in order.
 */
function clean_page_path(string $page): string {
    $page = \explode('#', $page, 2)[0];
    $parts = \explode('?', $page, 2);
    $path = $parts[0] === '' ? '/' : $parts[0];
    if (!isset($parts[1]) || $parts[1] === '') {
        return truncate($path, MAX_PAGE_PATH);
    }

    $kept = [];
    foreach (\explode('&', $parts[1]) as $pair) {
        if ($pair === '') {
            continue;
        }
        $name = \strtolower(\urldecode(\explode('=', $pair, 2)[0]));
        if (\str_starts_with($name, 'utm_') || \in_array($name, TRACKING_QUERY_PARAMS, true)
            || is_sensitive_query_name($name)) {
            continue;
        }
        $kept[] = $pair;
    }

    return truncate($kept ? $path . '?' . \implode('&', $kept) : $path, MAX_PAGE_PATH);
}

/**
 * Whether a (decoded, lowercased) query parameter name looks like it carries a secret
 * or personal data. Covers array syntax (token[], user[email]) and compound names.
 */
function is_sensitive_query_name(string $name): bool {
    if (\preg_match(SENSITIVE_QUERY_SUBSTRING_REGEX, $name)) {
        return true;
    }
    foreach (\preg_split('/[\[\]_\-.]+/', $name, -1, PREG_SPLIT_NO_EMPTY) as $part) {
        if (\in_array($part, SENSITIVE_QUERY_WORDS, true)) {
            return true;
        }
    }
    return false;
}

/**
 * Validate an event name. Allowed: ASCII letters, digits, underscore; 1..MAX_EVENT_NAME chars.
 */
function validate_event_name(string $name): bool {
    if ($name === '' || \strlen($name) > MAX_EVENT_NAME) {
        return false;
    }
    return (bool) \preg_match('/^[a-zA-Z0-9_]+$/', $name);
}

/**
 * Validate an event props payload: a JSON object of at most MAX_EVENT_PROPS_JSON bytes.
 * Returns the re-encoded JSON (Unicode kept as-is, objects stay objects) or null.
 */
function validate_event_props(string $raw): ?string {
    if ($raw === '' || \strlen($raw) > MAX_EVENT_PROPS_JSON) {
        return null;
    }
    $decoded = \json_decode($raw);
    if (!($decoded instanceof \stdClass) || \json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }
    $reencoded = \json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($reencoded === false || \strlen($reencoded) > MAX_EVENT_PROPS_JSON) {
        return null;
    }
    return $reencoded;
}

/**
 * Serve the tracking JS snippet for hub mode.
 */
function serve_js(string $site): void {
    $site = sanitize_site($site);
    if ($site === '' || !validate_domain($site)) {
        \http_response_code(403);
        return;
    }

    $endpoint = script_url();

    // The tracker JS template is inlined at build time
    $js = get_tracker_js($endpoint, $site);

    \header('Content-Type: application/javascript; charset=utf-8');
    \header('Cache-Control: public, max-age=86400');
    \header('X-Content-Type-Options: nosniff');
    echo $js;
}

/**
 * Get the tracker JS with endpoint and site injected.
 */
function get_tracker_js(string $endpoint, string $site): string {
    $js = <<<'TRACKER_JS'
/* {{TRACKER_JS}} */
TRACKER_JS;

    $js = \str_replace('{{ENDPOINT}}', js_string($endpoint), $js);
    $js = \str_replace('{{SITE}}', js_string($site), $js);
    $js = \str_replace('{{RESPECT_DNT}}', get_config('respect_dnt', '1') === '1' ? '1' : '0', $js);
    return $js;
}

/**
 * Escape a value for use inside a single-quoted JS string literal that may sit in an
 * inline <script> (quotes, backslashes, < > & and line terminators are all escaped).
 */
function js_string(string $value): string {
    $json = \json_encode($value, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE);
    return \substr((string) $json, 1, -1);
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
    // Never let analytics break the host page
    try {
        // Before setup there is nothing to track into, and no files may be created
        if (!db_marker_exists()) {
            return;
        }
        $site = sanitize_site($_SERVER['HTTP_HOST'] ?? '') ?: 'localhost';
        $js = get_tracker_js_inline(script_url(), $site);
    } catch (\Throwable $e) {
        \error_log('MintyMetrics head(): ' . $e->getMessage());
        return;
    }
    $attr = $nonce !== '' ? ' nonce="' . e($nonce) . '"' : '';
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
        $originHost = normalize_domain($origin);
        if (domain_matches_allowed($originHost, $allowed)) {
            \header('Access-Control-Allow-Origin: ' . $origin);
            \header('Vary: Origin');
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
 * Rate limiting. Returns true if the request should be DROPPED.
 *
 * Two checks, both atomic single-statement upserts:
 *  - $key: drops an exact repeat (same $key) within RATE_LIMIT_SECONDS
 *  - per-IP flood cap: at most RATE_LIMIT_PER_MINUTE requests of $kind per minute
 * Keys are HMACs with the per-install secret, so stored rows cannot be reversed
 * to IP addresses with a lookup table.
 */
function check_rate_limit(string $key, string $ip, string $kind): bool {
    try {
        $db = db();
        $now = \microtime(true);
        $secret = install_secret();

        // Periodic cleanup (every ~100 requests, including rejected ones)
        if (\random_int(1, 100) === 1) {
            $clean = $db->prepare('DELETE FROM rate_limit WHERE last_hit_at < :cutoff');
            $clean->bindValue(':cutoff', $now - 120, SQLITE3_FLOAT);
            $clean->execute();
        }

        // Flood cap first: a flooding client must not be able to allocate a dedupe
        // row per request (one counter row per IP and kind, whatever it sends)
        $ipKey = \hash_hmac('sha256', 'ip|' . $kind . '|' . normalize_ip($ip), $secret);
        $window = (float) (\intdiv((int) $now, 60) * 60);
        $stmt = $db->prepare('
            INSERT INTO rate_limit (ip_hash, last_hit_at, hits) VALUES (:k, :w, 1)
            ON CONFLICT(ip_hash) DO UPDATE SET
                hits = CASE WHEN rate_limit.last_hit_at < :w THEN 1 ELSE rate_limit.hits + 1 END,
                last_hit_at = :w
        ');
        $stmt->bindValue(':k', $ipKey, SQLITE3_TEXT);
        $stmt->bindValue(':w', $window, SQLITE3_FLOAT);
        $stmt->execute();
        $stmt = $db->prepare('SELECT hits FROM rate_limit WHERE ip_hash = :k');
        $stmt->bindValue(':k', $ipKey, SQLITE3_TEXT);
        if ((int) $stmt->execute()->fetchArray(SQLITE3_NUM)[0] > RATE_LIMIT_PER_MINUTE) {
            return true;
        }

        $stmt = $db->prepare('
            INSERT INTO rate_limit (ip_hash, last_hit_at, hits) VALUES (:k, :now, 1)
            ON CONFLICT(ip_hash) DO UPDATE SET last_hit_at = excluded.last_hit_at, hits = rate_limit.hits + 1
            WHERE rate_limit.last_hit_at <= :cutoff
        ');
        $stmt->bindValue(':k', \hash_hmac('sha256', $key, $secret), SQLITE3_TEXT);
        $stmt->bindValue(':now', $now, SQLITE3_FLOAT);
        $stmt->bindValue(':cutoff', $now - RATE_LIMIT_SECONDS, SQLITE3_FLOAT);
        $stmt->execute();
        if ($db->changes() === 0) {
            return true; // Same request within the window
        }

        return false;
    } catch (\Throwable $e) {
        return false; // On error, allow the request
    }
}

/**
 * Generate a visitor hash from IP + User-Agent + site + daily salt.
 *
 * The IP is normalized (IPv6 to its /64, IPv4-mapped to IPv4) so rotating IPv6
 * privacy addresses do not split one visitor. The site is included so the same
 * person cannot be linked across sites tracked by one hub.
 */
function generate_visitor_hash(string $ip, string $ua, string $salt, string $site = ''): string {
    return \hash('sha256', normalize_ip($ip) . '|' . $ua . '|' . $site . '|' . $salt);
}

/**
 * Validate that a site domain is in the allowed list.
 */
function validate_domain(string $site): bool {
    $allowed = get_allowed_domains();
    if (empty($allowed)) {
        return true; // No whitelist = allow all (single-site mode)
    }

    if (get_config('allow_subdomains', '1') !== '1') {
        return \in_array(normalize_domain($site), $allowed, true);
    }
    return domain_matches_allowed($site, $allowed);
}

/**
 * Sanitize a site domain string.
 */
function sanitize_site(string $site): string {
    return normalize_domain($site);
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
