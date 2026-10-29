<?php
namespace MintyMetrics;

/** Max seconds a single cleanup run may spend; the rest continues on the next dashboard load. */
const CLEANUP_TIME_BUDGET = 8.0;

/** Raw-data window kept when the database exceeds its size limit. */
const AGGRESSIVE_RETENTION_DAYS = 30;

/**
 * Run cleanup tasks. Called piggyback on dashboard loads, max once per day.
 * Summarizes old data, prunes expired raw hits, manages DB size.
 */
function cleanup_run(): void {
    try {
        $today = \date('Y-m-d');
        if (get_config('last_cleanup', '') === $today) {
            return; // Already ran today
        }

        $db = db();
        if (!cleanup_claim($db, $today)) {
            return; // Another request is running (or just ran) today's cleanup
        }

        $deadline = \microtime(true) + CLEANUP_TIME_BUDGET;
        $retentionDays = (int) get_config('data_retention_days', DATA_RETENTION_DAYS);
        $cutoffTs = retention_cutoff_ts($retentionDays);

        // The API reads every day up to the latest summary from summaries. Leftover raw
        // hits on those days (older installs, or a site missing from a legacy summary)
        // are summarized and folded in, so the summarized range stays complete.
        $latest = latest_summary_date();
        $coverageTs = $latest !== null ? day_start(next_date($latest)) : 0;

        $complete = summarize_and_prune($db, \max($cutoffTs, $coverageTs), $deadline);
        $complete = prune_events($db, $cutoffTs, $deadline) && $complete;

        // Prune rate limit table
        $stmt = $db->prepare('DELETE FROM rate_limit WHERE last_hit_at < :cutoff');
        $stmt->bindValue(':cutoff', \microtime(true) - 120, SQLITE3_FLOAT);
        $stmt->execute();

        // Prune expired login throttling rows
        $stmt = $db->prepare('DELETE FROM login_attempts WHERE window_start < :old AND locked_until < :now');
        $stmt->bindValue(':old', \time() - LOGIN_LOCKOUT_MINUTES * 60, SQLITE3_INTEGER);
        $stmt->bindValue(':now', \time(), SQLITE3_INTEGER);
        $stmt->execute();

        // Prune old logs
        $stmt = $db->prepare('DELETE FROM mm_logs WHERE id NOT IN (SELECT id FROM mm_logs ORDER BY id DESC LIMIT :lim)');
        $stmt->bindValue(':lim', LOG_MAX_ENTRIES, SQLITE3_INTEGER);
        $stmt->execute();

        // Over the size limit: keep a shorter raw window, but summarize before deleting
        $maxSize = (float) get_config('max_db_size_mb', MAX_DB_SIZE_MB);
        if ($complete && db_size_mb() > $maxSize) {
            $aggressiveCutoff = \max($cutoffTs, retention_cutoff_ts(AGGRESSIVE_RETENTION_DAYS));
            $complete = summarize_and_prune($db, $aggressiveCutoff, $deadline);
            $complete = prune_events($db, $aggressiveCutoff, $deadline) && $complete;
            if ($complete) {
                reclaim_space($db, true);
            }
        } else {
            reclaim_space($db, false);
        }

        if (!$complete) {
            // Out of time: release the claim so the next dashboard load continues
            set_config('last_cleanup', '');
        }
    } catch (\Throwable $e) {
        log_error('cleanup_run: ' . $e->getMessage());
        try {
            set_config('last_cleanup', '');
        } catch (\Throwable $ignored) {
            // Nothing more we can do
        }
    }
}

/**
 * Atomically mark today's cleanup as started. Returns false if it was already claimed.
 */
function cleanup_claim(\SQLite3 $db, string $today): bool {
    $db->exec("INSERT OR IGNORE INTO settings (key, value) VALUES ('last_cleanup', '')");
    $stmt = $db->prepare("UPDATE settings SET value = :today WHERE key = 'last_cleanup' AND value != :today");
    $stmt->bindValue(':today', $today, SQLITE3_TEXT);
    $stmt->execute();
    $claimed = $db->changes() === 1;
    if ($claimed) {
        $cache = &_cfg_cache();
        $cache['last_cleanup'] = $today;
    }
    return $claimed;
}

/**
 * Summarize every local day before $cutoffTs into daily_summaries, then delete its raw hits.
 * Each day is handled in its own transaction, so a crash or error never deletes
 * hits that were not summarized. Returns false if the time budget ran out.
 */
function summarize_and_prune(\SQLite3 $db, int $cutoffTs, float $deadline): bool {
    while (true) {
        $minTs = $db->querySingle('SELECT MIN(created_at) FROM hits');
        if ($minTs === null || (int) $minTs >= $cutoffTs) {
            return true;
        }
        if (\microtime(true) > $deadline) {
            return false;
        }

        $day = \date('Y-m-d', (int) $minTs);
        $dayStart = day_start($day);
        $dayEnd = day_start(next_date($day));

        $db->exec('BEGIN IMMEDIATE');
        try {
            $stmt = $db->prepare('SELECT DISTINCT site FROM hits WHERE created_at >= :start AND created_at < :end');
            $stmt->bindValue(':start', $dayStart, SQLITE3_INTEGER);
            $stmt->bindValue(':end', $dayEnd, SQLITE3_INTEGER);
            $result = $stmt->execute();
            $sites = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $sites[] = $row['site'];
            }
            foreach ($sites as $site) {
                summarize_day($day, $site);
            }

            // Everything before the end of this day is now summarized (or was already)
            $stmt = $db->prepare('DELETE FROM hits WHERE created_at < :end');
            $stmt->bindValue(':end', $dayEnd, SQLITE3_INTEGER);
            $stmt->execute();

            $db->exec('COMMIT');
        } catch (\Throwable $e) {
            db_rollback($db);
            throw $e;
        }
    }
}

/**
 * Delete raw events older than the cutoff in small batches (events are not summarized).
 * Returns false if the time budget ran out.
 */
function prune_events(\SQLite3 $db, int $cutoffTs, float $deadline): bool {
    if (!table_exists($db, 'events')) {
        return true;
    }
    $stmt = $db->prepare('DELETE FROM events WHERE id IN (SELECT id FROM events WHERE created_at < :cutoff LIMIT 5000)');
    do {
        if (\microtime(true) > $deadline) {
            return false;
        }
        $stmt->reset();
        $stmt->bindValue(':cutoff', $cutoffTs, SQLITE3_INTEGER);
        $stmt->execute();
    } while ($db->changes() > 0);
    return true;
}

/**
 * Return freed pages to the filesystem. Databases created before auto_vacuum was
 * applied correctly need one full VACUUM to switch modes (only done when over the limit).
 */
function reclaim_space(\SQLite3 $db, bool $overLimit): void {
    $mode = (int) $db->querySingle('PRAGMA auto_vacuum');
    if ($mode === 2) {
        $db->exec('PRAGMA incremental_vacuum');
    } elseif ($overLimit) {
        $db->exec('PRAGMA auto_vacuum = INCREMENTAL');
        $db->exec('VACUUM');
    }
}

/**
 * Summarize one local day of raw hits into a daily_summaries row.
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

    $dayStart = day_start($date);
    $dayEnd = day_start(next_date($date)) - 1;

    // Base query filter
    $where = 'created_at BETWEEN :start AND :end AND site = :site';
    $params = [':start' => $dayStart, ':end' => $dayEnd, ':site' => $site];

    $stmt = $db->prepare("
        SELECT COUNT(*) as pageviews,
               COUNT(DISTINCT visitor_hash) as uniques,
               COALESCE(SUM(time_on_page), 0) as time_sum,
               COUNT(time_on_page) as time_count
        FROM hits WHERE {$where}
    ");
    bind_params($stmt, $params);
    $totals = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    $pageviews = (int) $totals['pageviews'];

    if ($pageviews === 0) {
        return; // No data to summarize
    }

    $uniques = (int) $totals['uniques'];
    $timeSum = (float) $totals['time_sum'];
    $timeCount = (int) $totals['time_count'];

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
    $avgTime = $timeCount > 0 ? \round($timeSum / $timeCount, 1) : null;

    // Pages are ranked by views in the dashboard, so keep each day's top pages by views
    $topPages = summarize_column($db, 'page_path', 50, $where, $params, 'views');
    $topReferrers = summarize_column($db, 'referrer_domain', 50, $where . ' AND referrer_domain IS NOT NULL', $params);
    $topCountries = summarize_column($db, 'country_code', 50, $where . ' AND country_code IS NOT NULL', $params);
    $devices = summarize_column($db, 'device_type', 10, $where . ' AND device_type IS NOT NULL', $params);
    $browsers = summarize_column($db, 'browser', 20, $where . ' AND browser IS NOT NULL', $params);
    $oses = summarize_column($db, 'os', 20, $where . ' AND os IS NOT NULL', $params);
    $utmSummary = summarize_utm($db, $where, $params);
    $screens = summarize_column($db, 'screen_res', 20, $where . ' AND screen_res IS NOT NULL', $params);
    $languages = summarize_column($db, 'language', 20, $where . ' AND language IS NOT NULL', $params);

    $stmt = $db->prepare('
        INSERT INTO daily_summaries (date, site, pageviews, unique_visitors, bounce_rate, avg_time_on_page,
            top_pages, top_referrers, top_countries, devices, browsers, operating_systems,
            utm_summary, screens, languages, visitor_days, bounced, time_sum, time_count)
        VALUES (:date, :site, :pv, :uv, :br, :atp,
            :pages, :refs, :countries, :devices, :browsers, :oses,
            :utm, :screens, :langs, :vd, :bounced, :tsum, :tcount)
    ');
    $stmt->bindValue(':date', $date, SQLITE3_TEXT);
    $stmt->bindValue(':site', $site, SQLITE3_TEXT);
    $stmt->bindValue(':pv', $pageviews, SQLITE3_INTEGER);
    $stmt->bindValue(':uv', $uniques, SQLITE3_INTEGER);
    $stmt->bindValue(':br', $bounceRate, $bounceRate !== null ? SQLITE3_FLOAT : SQLITE3_NULL);
    $stmt->bindValue(':atp', $avgTime, $avgTime !== null ? SQLITE3_FLOAT : SQLITE3_NULL);
    $stmt->bindValue(':pages', summary_json($topPages), SQLITE3_TEXT);
    $stmt->bindValue(':refs', summary_json($topReferrers), SQLITE3_TEXT);
    $stmt->bindValue(':countries', summary_json($topCountries), SQLITE3_TEXT);
    $stmt->bindValue(':devices', summary_json($devices), SQLITE3_TEXT);
    $stmt->bindValue(':browsers', summary_json($browsers), SQLITE3_TEXT);
    $stmt->bindValue(':oses', summary_json($oses), SQLITE3_TEXT);
    $stmt->bindValue(':utm', summary_json($utmSummary), SQLITE3_TEXT);
    $stmt->bindValue(':screens', summary_json($screens), SQLITE3_TEXT);
    $stmt->bindValue(':langs', summary_json($languages), SQLITE3_TEXT);
    $stmt->bindValue(':vd', $uniques, SQLITE3_INTEGER);
    $stmt->bindValue(':bounced', $bounced, SQLITE3_INTEGER);
    $stmt->bindValue(':tsum', $timeSum, SQLITE3_FLOAT);
    $stmt->bindValue(':tcount', $timeCount, SQLITE3_INTEGER);
    $stmt->execute();
}

/**
 * Encode a summary breakdown. Visitor-supplied strings may contain invalid UTF-8,
 * which must not make the whole summary fail to encode.
 */
function summary_json(array $data): string {
    return \json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
}

/**
 * Summarize a column into a {value: [views, visitors]} map.
 */
function summarize_column(\SQLite3 $db, string $column, int $limit, string $where, array $params, string $order = 'visitors'): array {
    // Validate column name against allow-list to prevent SQL injection
    static $allowed = ['page_path', 'referrer_domain', 'country_code', 'device_type',
        'browser', 'os', 'screen_res', 'language', 'utm_source', 'utm_medium', 'utm_campaign'];
    if (!\in_array($column, $allowed, true)) {
        return [];
    }
    $orderSql = $order === 'views' ? 'views DESC, visitors DESC' : 'visitors DESC, views DESC';

    $stmt = $db->prepare("
        SELECT {$column} as val, COUNT(*) as views, COUNT(DISTINCT visitor_hash) as visitors
        FROM hits WHERE {$where}
        GROUP BY {$column}
        ORDER BY {$orderSql}
        LIMIT {$limit}
    ");
    bind_params($stmt, $params);
    $result = $stmt->execute();

    $data = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if ($row['val'] !== null) {
            $data[(string) $row['val']] = [(int) $row['views'], (int) $row['visitors']];
        }
    }
    return $data;
}

/**
 * Summarize UTM data as an independent {value: [views, visitors]} map per field, so each
 * grouping keeps exact distinct visitor counts. (Pre-v4 summaries stored a list of
 * source/medium/campaign combos instead; readers accept both.)
 */
function summarize_utm(\SQLite3 $db, string $where, array $params): array {
    $data = [];
    foreach (['source' => 'utm_source', 'medium' => 'utm_medium', 'campaign' => 'utm_campaign'] as $key => $column) {
        $data[$key] = summarize_column($db, $column, 50, $where . " AND {$column} IS NOT NULL", $params);
    }
    return $data;
}
