<?php
namespace MintyMetrics;

/**
 * Handle API requests. Dispatches to the appropriate handler based on the action parameter.
 */
function handle_api(): void {
    auth_require();

    \header('Content-Type: application/json; charset=utf-8');
    \header('Cache-Control: no-store');

    $action = $_GET['action'] ?? '';
    $rawSite = $_GET['site'] ?? '';
    // Support comma-separated sites: sanitize each individually
    if (\str_contains($rawSite, ',')) {
        $parts = \array_filter(\array_map(function($s) {
            return sanitize_site(\trim($s));
        }, \explode(',', $rawSite)));
        $site = \implode(',', \array_unique($parts));
    } else {
        $site = sanitize_site($rawSite);
    }
    $from   = $_GET['from'] ?? \date('Y-m-d', \strtotime('-7 days'));
    $to     = $_GET['to'] ?? \date('Y-m-d');
    $limit  = \min((int) ($_GET['limit'] ?? 50), 500);
    $offset = \max((int) ($_GET['offset'] ?? 0), 0);

    // Validate date format
    if (!\preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !\preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        echo \json_encode(['error' => 'Invalid date format']);
        return;
    }

    // Convert dates to Unix timestamps for queries
    $fromTs = \strtotime($from . ' 00:00:00');
    $toTs   = \strtotime($to . ' 23:59:59');

    try {
        $data = match ($action) {
            'summary'    => api_summary($site, $fromTs, $toTs),
            'chart'      => api_chart($site, $from, $to, $fromTs, $toTs),
            'pages'      => api_pages($site, $fromTs, $toTs, $limit, $offset),
            'referrers'  => api_referrers($site, $fromTs, $toTs, $limit, $offset),
            'utm'        => api_utm($site, $fromTs, $toTs, $_GET['group'] ?? 'source', $limit, $offset),
            'devices'    => api_devices($site, $fromTs, $toTs, $_GET['group'] ?? 'type'),
            'countries'  => api_countries($site, $fromTs, $toTs, $limit, $offset),
            'screens'    => api_screens($site, $fromTs, $toTs, $limit, $offset),
            'languages'  => api_languages($site, $fromTs, $toTs, $limit, $offset),
            'live'       => api_live($site),
            'sites'      => api_sites(),
            default      => ['error' => 'Unknown action'],
        };
        echo \json_encode($data);
    } catch (\Exception $e) {
        log_error('api: ' . $e->getMessage());
        echo \json_encode(['error' => 'Internal error']);
    }
}

/**
 * Summary: total pageviews, unique visitors, single-page visit rate, avg time on page.
 */
function api_summary(string $site, int $fromTs, int $toTs): array {
    $db = db();

    // Check if we need to combine raw + summary data
    $retentionCutoff = \strtotime('-' . get_config('data_retention_days', DATA_RETENTION_DAYS) . ' days');
    $useRaw = $fromTs >= $retentionCutoff;

    if ($useRaw) {
        // All data is in raw hits table
        $where = 'created_at BETWEEN :from AND :to';
        $params = [':from' => $fromTs, ':to' => $toTs];
        apply_site_filter($where, $params, $site);

        // Pageviews
        $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM hits WHERE {$where}");
        bind_params($stmt, $params);
        $pageviews = (int) $stmt->execute()->fetchArray(SQLITE3_ASSOC)['cnt'];

        // Unique visitors
        $stmt = $db->prepare("SELECT COUNT(DISTINCT visitor_hash) as cnt FROM hits WHERE {$where}");
        bind_params($stmt, $params);
        $uniques = (int) $stmt->execute()->fetchArray(SQLITE3_ASSOC)['cnt'];

        // Single-page visit rate (deferred bounce calculation)
        $stmt = $db->prepare("
            SELECT COUNT(*) as bounced FROM (
                SELECT visitor_hash, COUNT(*) as hits
                FROM hits WHERE {$where}
                GROUP BY visitor_hash, date(created_at, 'unixepoch')
                HAVING hits = 1
            )
        ");
        bind_params($stmt, $params);
        $bounced = (int) $stmt->execute()->fetchArray(SQLITE3_ASSOC)['bounced'];

        // Total visitors by day (for bounce rate denominator)
        $stmt = $db->prepare("
            SELECT COUNT(*) as total FROM (
                SELECT DISTINCT visitor_hash, date(created_at, 'unixepoch') as d
                FROM hits WHERE {$where}
            )
        ");
        bind_params($stmt, $params);
        $totalVisitorDays = (int) $stmt->execute()->fetchArray(SQLITE3_ASSOC)['total'];
        $bounceRate = $totalVisitorDays > 0 ? \round($bounced / $totalVisitorDays, 4) : 0;

        // Average time on page
        $stmt = $db->prepare("SELECT AVG(time_on_page) as avg_time FROM hits WHERE {$where} AND time_on_page IS NOT NULL");
        bind_params($stmt, $params);
        $avgTime = $stmt->execute()->fetchArray(SQLITE3_ASSOC)['avg_time'];
        $avgTime = $avgTime !== null ? \round((float) $avgTime) : null;

        return [
            'pageviews'    => $pageviews,
            'uniques'      => $uniques,
            'bounce_rate'  => $bounceRate,
            'avg_time'     => $avgTime,
            'approximate'  => false,
        ];
    } else {
        // Use daily summaries for older data, raw for recent
        $summaryData = get_summary_data($site, $fromTs, $toTs, $retentionCutoff);

        return [
            'pageviews'    => $summaryData['pageviews'],
            'uniques'      => $summaryData['unique_visitors'],
            'bounce_rate'  => $summaryData['bounce_rate'],
            'avg_time'     => $summaryData['avg_time_on_page'],
            'approximate'  => $summaryData['approximate'] ?? false,
        ];
    }
}

/**
 * Chart: daily pageviews and unique visitors for the date range.
 */
function api_chart(string $site, string $from, string $to, int $fromTs, int $toTs): array {
    $db = db();
    $days = [];

    $where = 'created_at BETWEEN :from AND :to';
    $params = [':from' => $fromTs, ':to' => $toTs];
    apply_site_filter($where, $params, $site);

    // Get raw data grouped by day
    $stmt = $db->prepare("
        SELECT date(created_at, 'unixepoch') as day,
               COUNT(*) as pageviews,
               COUNT(DISTINCT visitor_hash) as uniques
        FROM hits
        WHERE {$where}
        GROUP BY day
        ORDER BY day
    ");
    bind_params($stmt, $params);
    $result = $stmt->execute();

    $rawData = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rawData[$row['day']] = [
            'pageviews' => (int) $row['pageviews'],
            'uniques'   => (int) $row['uniques'],
        ];
    }

    // Also check daily_summaries for dates outside retention
    $retentionCutoff = \strtotime('-' . get_config('data_retention_days', DATA_RETENTION_DAYS) . ' days');
    $summaryFrom = \date('Y-m-d', $fromTs);
    $summaryTo = \date('Y-m-d', $retentionCutoff);

    if ($fromTs < $retentionCutoff) {
        $swhere = 'date BETWEEN :from AND :to';
        $sparams = [':from' => $summaryFrom, ':to' => $summaryTo];
        apply_site_filter($swhere, $sparams, $site);
        $stmt = $db->prepare("SELECT date, pageviews, unique_visitors FROM daily_summaries WHERE {$swhere}");
        bind_params($stmt, $sparams);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if (!isset($rawData[$row['date']])) {
                $rawData[$row['date']] = [
                    'pageviews' => (int) $row['pageviews'],
                    'uniques'   => (int) $row['unique_visitors'],
                ];
            }
        }
    }

    // Fill in all dates in the range (including zeros)
    $current = new \DateTime($from);
    $end = new \DateTime($to);
    $end->modify('+1 day');

    while ($current < $end) {
        $day = $current->format('Y-m-d');
        $days[] = [
            'date'      => $day,
            'pageviews' => $rawData[$day]['pageviews'] ?? 0,
            'uniques'   => $rawData[$day]['uniques'] ?? 0,
        ];
        $current->modify('+1 day');
    }

    return ['days' => $days];
}

/**
 * Top pages with pageview and unique visitor counts.
 */
function api_pages(string $site, int $fromTs, int $toTs, int $limit, int $offset): array {
    $db = db();
    $where = 'created_at BETWEEN :from AND :to';
    $params = [':from' => $fromTs, ':to' => $toTs];
    apply_site_filter($where, $params, $site);

    $stmt = $db->prepare("
        SELECT page_path,
               COUNT(*) as views,
               COUNT(DISTINCT visitor_hash) as uniques
        FROM hits
        WHERE {$where}
        GROUP BY page_path
        ORDER BY views DESC
        LIMIT :limit OFFSET :offset
    ");
    bind_params($stmt, $params);
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);

    return ['rows' => fetch_all($stmt)];
}

/**
 * Referrers grouped by domain.
 */
function api_referrers(string $site, int $fromTs, int $toTs, int $limit, int $offset): array {
    $db = db();
    $where = 'created_at BETWEEN :from AND :to AND referrer_domain IS NOT NULL';
    $params = [':from' => $fromTs, ':to' => $toTs];
    apply_site_filter($where, $params, $site);

    $stmt = $db->prepare("
        SELECT referrer_domain as domain,
               COUNT(DISTINCT visitor_hash) as visitors,
               COUNT(*) as views
        FROM hits
        WHERE {$where}
        GROUP BY referrer_domain
        ORDER BY visitors DESC
        LIMIT :limit OFFSET :offset
    ");
    bind_params($stmt, $params);
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);

    return ['rows' => fetch_all($stmt)];
}

/**
 * UTM breakdown by source, medium, or campaign.
 */
function api_utm(string $site, int $fromTs, int $toTs, string $groupBy, int $limit, int $offset): array {
    $column = match ($groupBy) {
        'medium'   => 'utm_medium',
        'campaign' => 'utm_campaign',
        default    => 'utm_source',
    };

    $db = db();
    $where = "created_at BETWEEN :from AND :to AND {$column} IS NOT NULL";
    $params = [':from' => $fromTs, ':to' => $toTs];
    apply_site_filter($where, $params, $site);

    $stmt = $db->prepare("
        SELECT {$column} as name,
               COUNT(DISTINCT visitor_hash) as visitors,
               COUNT(*) as views
        FROM hits
        WHERE {$where}
        GROUP BY {$column}
        ORDER BY visitors DESC
        LIMIT :limit OFFSET :offset
    ");
    bind_params($stmt, $params);
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);

    return ['rows' => fetch_all($stmt), 'group' => $groupBy];
}

/**
 * Device, browser, or OS distribution.
 */
function api_devices(string $site, int $fromTs, int $toTs, string $groupBy): array {
    $column = match ($groupBy) {
        'browser' => 'browser',
        'os'      => 'os',
        default   => 'device_type',
    };

    $db = db();
    $where = "created_at BETWEEN :from AND :to AND {$column} IS NOT NULL";
    $params = [':from' => $fromTs, ':to' => $toTs];
    apply_site_filter($where, $params, $site);

    $stmt = $db->prepare("
        SELECT {$column} as name,
               COUNT(DISTINCT visitor_hash) as visitors
        FROM hits
        WHERE {$where}
        GROUP BY {$column}
        ORDER BY visitors DESC
        LIMIT 20
    ");
    bind_params($stmt, $params);

    return ['rows' => fetch_all($stmt), 'group' => $groupBy];
}

/**
 * Country distribution.
 */
function api_countries(string $site, int $fromTs, int $toTs, int $limit, int $offset): array {
    $db = db();
    $where = 'created_at BETWEEN :from AND :to AND country_code IS NOT NULL';
    $params = [':from' => $fromTs, ':to' => $toTs];
    apply_site_filter($where, $params, $site);

    $stmt = $db->prepare("
        SELECT country_code as code,
               COUNT(DISTINCT visitor_hash) as visitors
        FROM hits
        WHERE {$where}
        GROUP BY country_code
        ORDER BY visitors DESC
        LIMIT :limit OFFSET :offset
    ");
    bind_params($stmt, $params);
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);

    return ['rows' => fetch_all($stmt)];
}

/**
 * Screen resolutions.
 */
function api_screens(string $site, int $fromTs, int $toTs, int $limit, int $offset): array {
    $db = db();
    $where = 'created_at BETWEEN :from AND :to AND screen_res IS NOT NULL';
    $params = [':from' => $fromTs, ':to' => $toTs];
    apply_site_filter($where, $params, $site);

    $stmt = $db->prepare("
        SELECT screen_res as resolution,
               COUNT(DISTINCT visitor_hash) as visitors
        FROM hits
        WHERE {$where}
        GROUP BY screen_res
        ORDER BY visitors DESC
        LIMIT :limit OFFSET :offset
    ");
    bind_params($stmt, $params);
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);

    return ['rows' => fetch_all($stmt)];
}

/**
 * Language distribution.
 */
function api_languages(string $site, int $fromTs, int $toTs, int $limit, int $offset): array {
    $db = db();
    $where = 'created_at BETWEEN :from AND :to AND language IS NOT NULL';
    $params = [':from' => $fromTs, ':to' => $toTs];
    apply_site_filter($where, $params, $site);

    $stmt = $db->prepare("
        SELECT language as lang,
               COUNT(DISTINCT visitor_hash) as visitors
        FROM hits
        WHERE {$where}
        GROUP BY language
        ORDER BY visitors DESC
        LIMIT :limit OFFSET :offset
    ");
    bind_params($stmt, $params);
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);

    return ['rows' => fetch_all($stmt)];
}

/**
 * Live visitors: count of unique hashes in the last N minutes.
 */
function api_live(string $site): array {
    $db = db();
    $minutes = (int) get_config('live_visitor_minutes', (string) LIVE_VISITOR_MINUTES);
    $since = \time() - ($minutes * 60);

    $where = '(created_at >= :since OR last_active_at >= :since)';
    $params = [':since' => $since];
    apply_site_filter($where, $params, $site);

    $stmt = $db->prepare("SELECT COUNT(DISTINCT visitor_hash) as cnt FROM hits WHERE {$where}");
    bind_params($stmt, $params);
    $count = (int) $stmt->execute()->fetchArray(SQLITE3_ASSOC)['cnt'];

    return ['count' => $count];
}

/**
 * Get list of tracked sites.
 */
function api_sites(): array {
    $db = db();
    $stmt = $db->prepare('SELECT DISTINCT site FROM hits WHERE site != "" ORDER BY site');
    $result = $stmt->execute();
    $sites = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $sites[] = $row['site'];
    }
    // Also include configured domains not yet tracked
    $allowed = get_allowed_domains();
    foreach ($allowed as $domain) {
        if (!\in_array($domain, $sites, true)) {
            $sites[] = $domain;
        }
    }
    \sort($sites, SORT_STRING | SORT_FLAG_CASE);
    return ['sites' => $sites];
}

// ─── Query Helpers ──────────────────────────────────────────────────────────

/**
 * Bind named parameters to a prepared statement.
 */
function bind_params(\SQLite3Stmt $stmt, array $params): void {
    foreach ($params as $key => $value) {
        if (\is_int($value)) {
            $stmt->bindValue($key, $value, SQLITE3_INTEGER);
        } elseif (\is_float($value)) {
            $stmt->bindValue($key, $value, SQLITE3_FLOAT);
        } elseif ($value === null) {
            $stmt->bindValue($key, null, SQLITE3_NULL);
        } else {
            $stmt->bindValue($key, $value, SQLITE3_TEXT);
        }
    }
}

/**
 * Apply site filter to a WHERE clause. Handles single site, comma-separated multi-site, or all sites (empty).
 */
function apply_site_filter(string &$where, array &$params, string $site): void {
    if (!$site) {
        return;
    }
    if (\str_contains($site, ',')) {
        $sites = \explode(',', $site);
        $placeholders = [];
        foreach ($sites as $i => $s) {
            $key = ':site' . $i;
            $placeholders[] = $key;
            $params[$key] = $s;
        }
        $where .= ' AND site IN (' . \implode(',', $placeholders) . ')';
    } else {
        $where .= ' AND site = :site';
        $params[':site'] = $site;
    }
}

/**
 * Fetch all rows from a prepared statement result.
 */
function fetch_all(\SQLite3Stmt $stmt): array {
    $result = $stmt->execute();
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        // Cast numeric strings to int
        foreach ($row as $k => $v) {
            if (\is_numeric($v) && \strpos($v, '.') === false) {
                $row[$k] = (int) $v;
            }
        }
        $rows[] = $row;
    }
    return $rows;
}

/**
 * Get combined summary data from both raw hits and daily_summaries tables.
 */
function get_summary_data(string $site, int $fromTs, int $toTs, int $retentionCutoff): array {
    $db = db();
    $totalPageviews = 0;
    $totalUniques = 0;
    $bounceRateSum = 0;
    $avgTimeSum = 0;
    $summaryCount = 0;

    // Get data from daily_summaries for dates before retention cutoff
    $summaryFrom = \date('Y-m-d', $fromTs);
    $summaryTo = \date('Y-m-d', $retentionCutoff);
    $swhere = 'date BETWEEN :from AND :to';
    $sparams = [':from' => $summaryFrom, ':to' => $summaryTo];
    apply_site_filter($swhere, $sparams, $site);

    $stmt = $db->prepare("SELECT pageviews, unique_visitors, bounce_rate, avg_time_on_page FROM daily_summaries WHERE {$swhere}");
    bind_params($stmt, $sparams);
    $result = $stmt->execute();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $totalPageviews += (int) $row['pageviews'];
        $totalUniques += (int) $row['unique_visitors'];
        if ($row['bounce_rate'] !== null) {
            $bounceRateSum += (float) $row['bounce_rate'];
            $summaryCount++;
        }
        if ($row['avg_time_on_page'] !== null) {
            $avgTimeSum += (float) $row['avg_time_on_page'];
        }
    }

    // Get data from raw hits for recent dates
    $where = 'created_at BETWEEN :from AND :to';
    $params = [':from' => $retentionCutoff, ':to' => $toTs];
    apply_site_filter($where, $params, $site);

    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM hits WHERE {$where}");
    bind_params($stmt, $params);
    $totalPageviews += (int) $stmt->execute()->fetchArray(SQLITE3_ASSOC)['cnt'];

    $stmt = $db->prepare("SELECT COUNT(DISTINCT visitor_hash) as cnt FROM hits WHERE {$where}");
    bind_params($stmt, $params);
    $totalUniques += (int) $stmt->execute()->fetchArray(SQLITE3_ASSOC)['cnt'];

    $stmt = $db->prepare("SELECT AVG(time_on_page) as avg_time FROM hits WHERE {$where} AND time_on_page IS NOT NULL");
    bind_params($stmt, $params);
    $recentAvgTime = $stmt->execute()->fetchArray(SQLITE3_ASSOC)['avg_time'];

    // Simple average of bounce rates (weighted would be more accurate but complex)
    $bounceRate = $summaryCount > 0 ? \round($bounceRateSum / $summaryCount, 4) : 0;
    $avgTime = ($recentAvgTime !== null || $avgTimeSum > 0)
        ? \round((($avgTimeSum + ($recentAvgTime ?? 0)) / \max($summaryCount + 1, 1)))
        : null;

    // Note: unique_visitors from summaries are per-day counts summed together,
    // so cross-day visitors are double-counted. Flag as approximate when summaries are involved.
    return [
        'pageviews'        => $totalPageviews,
        'unique_visitors'  => $totalUniques,
        'bounce_rate'      => $bounceRate,
        'avg_time_on_page' => $avgTime,
        'approximate'      => $summaryCount > 0,
    ];
}
