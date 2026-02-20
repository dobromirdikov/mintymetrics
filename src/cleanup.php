<?php
namespace MintyMetrics;

/**
 * Run cleanup tasks. Called piggyback on dashboard loads, max once per day.
 * Summarizes old data, prunes expired raw hits, manages DB size.
 */
function cleanup_run(): void {
    try {
        $lastCleanup = get_config('last_cleanup', '');
        $today = \date('Y-m-d');

        if ($lastCleanup === $today) {
            return; // Already ran today
        }

        $db = db();
        $retentionDays = (int) get_config('data_retention_days', DATA_RETENTION_DAYS);
        $cutoffTs = \strtotime("-{$retentionDays} days");
        $cutoffDate = \date('Y-m-d', $cutoffTs);

        // Find dates with raw data that should be summarized
        $stmt = $db->prepare("
            SELECT DISTINCT date(created_at, 'unixepoch') as day, site
            FROM hits
            WHERE created_at < :cutoff
            ORDER BY day
        ");
        $stmt->bindValue(':cutoff', $cutoffTs, SQLITE3_INTEGER);
        $result = $stmt->execute();

        $toSummarize = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $toSummarize[] = ['date' => $row['day'], 'site' => $row['site']];
        }

        // Summarize each day/site combination
        foreach ($toSummarize as $item) {
            summarize_day($item['date'], $item['site']);
        }

        // Delete old raw hits
        if (!empty($toSummarize)) {
            $stmt = $db->prepare('DELETE FROM hits WHERE created_at < :cutoff');
            $stmt->bindValue(':cutoff', $cutoffTs, SQLITE3_INTEGER);
            $stmt->execute();
        }

        // Prune rate limit table
        $stmt = $db->prepare('DELETE FROM rate_limit WHERE last_hit_at < :cutoff');
        $stmt->bindValue(':cutoff', \microtime(true) - 60, SQLITE3_FLOAT);
        $stmt->execute();

        // Prune old logs
        $stmt = $db->prepare('DELETE FROM mm_logs WHERE id NOT IN (SELECT id FROM mm_logs ORDER BY id DESC LIMIT :lim)');
        $stmt->bindValue(':lim', LOG_MAX_ENTRIES, SQLITE3_INTEGER);
        $stmt->execute();

        // Check DB size limits
        $dbSize = db_size_mb();
        $maxSize = (float) get_config('max_db_size_mb', MAX_DB_SIZE_MB);

        if ($dbSize > $maxSize) {
            // Aggressive pruning: delete raw data older than 30 days
            $aggressiveCutoff = \strtotime('-30 days');
            $stmt = $db->prepare('DELETE FROM hits WHERE created_at < :cutoff');
            $stmt->bindValue(':cutoff', $aggressiveCutoff, SQLITE3_INTEGER);
            $stmt->execute();

            // Reclaim space without exclusive lock (unlike VACUUM)
            $db->exec('PRAGMA incremental_vacuum(100)');
        }

        set_config('last_cleanup', $today);

    } catch (\Exception $e) {
        log_error('cleanup_run: ' . $e->getMessage());
    }
}

/**
 * Summarize one day of raw hits into a daily_summaries row.
 */
function summarize_day(string $date, string $site): void {
    $db = db();

    // Check if summary already exists
    $stmt = $db->prepare('SELECT id FROM daily_summaries WHERE date = :date AND site = :site');
    $stmt->bindValue(':date', $date, SQLITE3_TEXT);
    $stmt->bindValue(':site', $site, SQLITE3_TEXT);
    $existing = $stmt->execute()->fetchArray();
    if ($existing) {
        return; // Already summarized
    }

    $dayStart = \strtotime($date . ' 00:00:00');
    $dayEnd = \strtotime($date . ' 23:59:59');

    // Base query filter
    $where = 'created_at BETWEEN :start AND :end AND site = :site';
    $params = [':start' => $dayStart, ':end' => $dayEnd, ':site' => $site];

    // Pageviews
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM hits WHERE {$where}");
    bind_params($stmt, $params);
    $pageviews = (int) $stmt->execute()->fetchArray(SQLITE3_ASSOC)['cnt'];

    if ($pageviews === 0) {
        return; // No data to summarize
    }

    // Unique visitors
    $stmt = $db->prepare("SELECT COUNT(DISTINCT visitor_hash) as cnt FROM hits WHERE {$where}");
    bind_params($stmt, $params);
    $uniques = (int) $stmt->execute()->fetchArray(SQLITE3_ASSOC)['cnt'];

    // Bounce rate (single-page visits)
    $stmt = $db->prepare("
        SELECT COUNT(*) as bounced FROM (
            SELECT visitor_hash FROM hits WHERE {$where}
            GROUP BY visitor_hash HAVING COUNT(*) = 1
        )
    ");
    bind_params($stmt, $params);
    $bounced = (int) $stmt->execute()->fetchArray(SQLITE3_ASSOC)['bounced'];
    $bounceRate = $uniques > 0 ? \round($bounced / $uniques, 4) : null;

    // Average time on page
    $stmt = $db->prepare("SELECT AVG(time_on_page) as avg_t FROM hits WHERE {$where} AND time_on_page IS NOT NULL");
    bind_params($stmt, $params);
    $avgTime = $stmt->execute()->fetchArray(SQLITE3_ASSOC)['avg_t'];
    $avgTime = $avgTime !== null ? \round((float) $avgTime, 1) : null;

    // Top pages (top 50)
    $topPages = summarize_column($db, 'page_path', 50, $where, $params);

    // Top referrers (top 50)
    $topReferrers = summarize_column($db, 'referrer_domain', 50, $where . ' AND referrer_domain IS NOT NULL', $params);

    // Top countries
    $topCountries = summarize_column($db, 'country_code', 50, $where . ' AND country_code IS NOT NULL', $params);

    // Devices
    $devices = summarize_column($db, 'device_type', 10, $where . ' AND device_type IS NOT NULL', $params);

    // Browsers
    $browsers = summarize_column($db, 'browser', 20, $where . ' AND browser IS NOT NULL', $params);

    // Operating systems
    $oses = summarize_column($db, 'os', 20, $where . ' AND os IS NOT NULL', $params);

    // UTM summary
    $utmSummary = summarize_utm($db, $where, $params);

    // Screens
    $screens = summarize_column($db, 'screen_res', 20, $where . ' AND screen_res IS NOT NULL', $params);

    // Languages
    $languages = summarize_column($db, 'language', 20, $where . ' AND language IS NOT NULL', $params);

    // Insert summary
    $stmt = $db->prepare('
        INSERT INTO daily_summaries (date, site, pageviews, unique_visitors, bounce_rate, avg_time_on_page,
            top_pages, top_referrers, top_countries, devices, browsers, operating_systems,
            utm_summary, screens, languages)
        VALUES (:date, :site, :pv, :uv, :br, :atp,
            :pages, :refs, :countries, :devices, :browsers, :oses,
            :utm, :screens, :langs)
    ');
    $stmt->bindValue(':date', $date, SQLITE3_TEXT);
    $stmt->bindValue(':site', $site, SQLITE3_TEXT);
    $stmt->bindValue(':pv', $pageviews, SQLITE3_INTEGER);
    $stmt->bindValue(':uv', $uniques, SQLITE3_INTEGER);
    $stmt->bindValue(':br', $bounceRate, $bounceRate !== null ? SQLITE3_FLOAT : SQLITE3_NULL);
    $stmt->bindValue(':atp', $avgTime, $avgTime !== null ? SQLITE3_FLOAT : SQLITE3_NULL);
    $stmt->bindValue(':pages', \json_encode($topPages), SQLITE3_TEXT);
    $stmt->bindValue(':refs', \json_encode($topReferrers), SQLITE3_TEXT);
    $stmt->bindValue(':countries', \json_encode($topCountries), SQLITE3_TEXT);
    $stmt->bindValue(':devices', \json_encode($devices), SQLITE3_TEXT);
    $stmt->bindValue(':browsers', \json_encode($browsers), SQLITE3_TEXT);
    $stmt->bindValue(':oses', \json_encode($oses), SQLITE3_TEXT);
    $stmt->bindValue(':utm', \json_encode($utmSummary), SQLITE3_TEXT);
    $stmt->bindValue(':screens', \json_encode($screens), SQLITE3_TEXT);
    $stmt->bindValue(':langs', \json_encode($languages), SQLITE3_TEXT);
    $stmt->execute();
}

/**
 * Summarize a column into a {value: count} map.
 */
function summarize_column(\SQLite3 $db, string $column, int $limit, string $where, array $params): array {
    // Validate column name against allow-list to prevent SQL injection
    static $allowed = ['page_path', 'referrer_domain', 'country_code', 'device_type',
        'browser', 'os', 'screen_res', 'language'];
    if (!\in_array($column, $allowed, true)) {
        return [];
    }

    $stmt = $db->prepare("
        SELECT {$column} as val, COUNT(DISTINCT visitor_hash) as cnt
        FROM hits WHERE {$where}
        GROUP BY {$column}
        ORDER BY cnt DESC
        LIMIT {$limit}
    ");
    bind_params($stmt, $params);
    $result = $stmt->execute();

    $data = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if ($row['val'] !== null) {
            $data[$row['val']] = (int) $row['cnt'];
        }
    }
    return $data;
}

/**
 * Summarize UTM data into an array of source/medium/campaign combos.
 */
function summarize_utm(\SQLite3 $db, string $where, array $params): array {
    $stmt = $db->prepare("
        SELECT utm_source, utm_medium, utm_campaign, COUNT(DISTINCT visitor_hash) as cnt
        FROM hits
        WHERE {$where} AND (utm_source IS NOT NULL OR utm_medium IS NOT NULL OR utm_campaign IS NOT NULL)
        GROUP BY utm_source, utm_medium, utm_campaign
        ORDER BY cnt DESC
        LIMIT 50
    ");
    bind_params($stmt, $params);
    $result = $stmt->execute();

    $data = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $data[] = [
            'source'   => $row['utm_source'],
            'medium'   => $row['utm_medium'],
            'campaign' => $row['utm_campaign'],
            'count'    => (int) $row['cnt'],
        ];
    }
    return $data;
}
