<?php
namespace MintyMetrics;

/** Longest date range the API and export accept (about 5 years). */
const MAX_RANGE_DAYS = 1830;

/** Cap on raw rows merged with summaries for a breakdown spanning both. */
const BREAKDOWN_MERGE_LIMIT = 10000;

/**
 * Handle API requests. Dispatches to the appropriate handler based on the action parameter.
 */
function handle_api(): void {
    auth_require();

    \header('Content-Type: application/json; charset=utf-8');
    \header('Cache-Control: no-store');

    $action = $_GET['action'] ?? '';
    $site = parse_site_param($_GET['site'] ?? '');
    $limit  = \max(1, \min((int) ($_GET['limit'] ?? 50), 500));
    $offset = \max((int) ($_GET['offset'] ?? 0), 0);

    $range = parse_date_range($_GET['from'] ?? null, $_GET['to'] ?? null);
    if (isset($range['error'])) {
        \http_response_code(400);
        echo \json_encode(['error' => $range['error']]);
        return;
    }
    [$from, $to, $fromTs, $toTs] = [$range['from'], $range['to'], $range['fromTs'], $range['toTs']];

    try {
        // One read snapshot for the whole request: a concurrent cleanup must not move
        // the summary/raw boundary between the queries that make up one response.
        $data = with_read_snapshot(fn() => match ($action) {
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
            'events'     => api_events($site, $fromTs, $toTs, $limit, $offset),
            'event_values' => api_event_values($site, $fromTs, $toTs, $_GET['name'] ?? '', $limit, $offset),
            default      => null,
        });
        if ($data === null) {
            \http_response_code(400);
            $data = ['error' => 'Unknown action'];
        } elseif (isset($data['error'])) {
            \http_response_code(400);
        }
        echo \json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (\Throwable $e) {
        log_error('api: ' . $e->getMessage());
        \http_response_code(500);
        echo \json_encode(['error' => 'Internal error']);
    }
}

/**
 * Run read-only queries inside one transaction so they all see the same WAL snapshot.
 */
function with_read_snapshot(callable $fn): mixed {
    $db = db();
    $db->exec('BEGIN');
    try {
        $result = $fn();
    } finally {
        db_rollback($db); // read-only: nothing to commit
    }
    return $result;
}

/**
 * Parse a site filter: a single domain or a comma-separated list.
 */
function parse_site_param(string $rawSite): string {
    if (\str_contains($rawSite, ',')) {
        $parts = \array_filter(\array_map(function ($s) {
            return sanitize_site(\trim($s));
        }, \explode(',', $rawSite)));
        return \implode(',', \array_unique($parts));
    }
    return sanitize_site($rawSite);
}

/**
 * Validate a from/to date pair. Defaults to the last 7 days.
 *
 * @return array{from:string,to:string,fromTs:int,toTs:int}|array{error:string}
 */
function parse_date_range(?string $from, ?string $to): array {
    $from = ($from === null || $from === '') ? \date('Y-m-d', \strtotime('-7 days')) : $from;
    $to = ($to === null || $to === '') ? \date('Y-m-d') : $to;

    if (!is_valid_date($from) || !is_valid_date($to)) {
        return ['error' => 'Invalid date format'];
    }
    if ($from > $to) {
        return ['error' => 'Start date is after end date'];
    }
    $days = (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days;
    if ($days > MAX_RANGE_DAYS) {
        return ['error' => 'Date range is too long'];
    }

    return [
        'from'   => $from,
        'to'     => $to,
        'fromTs' => day_start($from),
        'toTs'   => day_start(next_date($to)) - 1,
    ];
}

// ─── Raw vs. Summarized Data ────────────────────────────────────────────────
// Cleanup summarizes whole local days oldest-first, so summaries always cover a
// prefix of history. Days up to the latest summary come from daily_summaries;
// everything after comes from raw hits. If a legacy day has both, the summary wins.

/**
 * Latest summarized day, or null if nothing has been summarized yet.
 */
function latest_summary_date(): ?string {
    $max = db()->querySingle('SELECT MAX(date) FROM daily_summaries');
    return \is_string($max) && $max !== '' ? $max : null;
}

/**
 * Split a timestamp range into its summarized and raw parts.
 *
 * @return array{summary: ?array{0:string,1:string}, raw: ?array{0:int,1:int}}
 */
function range_parts(int $fromTs, int $toTs): array {
    $from = \date('Y-m-d', $fromTs);
    $to = \date('Y-m-d', $toTs);
    $latest = latest_summary_date();

    $summary = null;
    $rawFrom = $fromTs;
    $exclude = '';
    $excludeAliased = '';
    if ($latest !== null && $from <= $latest) {
        $summary = [$from, \min($to, $latest)];
        $boundary = day_start(next_date($latest));
        $rawFrom = \max($fromTs, $boundary);

        // Raw hits can remain on summarized days (older installs, or a cleanup that has
        // not finished yet). Count them too, except for site/days that already have a
        // summary — the summary is complete for those, so it wins.
        $minTs = db()->querySingle('SELECT MIN(created_at) FROM hits');
        if ($minTs !== null && (int) $minTs < $boundary && (int) $minTs <= $toTs) {
            $rawFrom = \max($fromTs, day_start(\date('Y-m-d', (int) $minTs)));
            $mixedFrom = \date('Y-m-d', $rawFrom);
            $mixedTo = \min($to, $latest);
            $exclude = summarized_exclusion_sql($mixedFrom, $mixedTo, '');
            $excludeAliased = summarized_exclusion_sql($mixedFrom, $mixedTo, 'h.');
        }
    }

    return [
        'summary' => $summary,
        'raw'     => $rawFrom <= $toTs ? [$rawFrom, $toTs] : null,
        'exclude' => $exclude,
        'exclude_h' => $excludeAliased,
    ];
}

/**
 * SQL predicate that drops raw hits belonging to a (local day, site) that already has
 * a summary. Values are server-generated timestamps and escaped site names.
 */
function summarized_exclusion_sql(string $fromDate, string $toDate, string $prefix): string {
    if ($fromDate > $toDate) {
        return '';
    }
    $stmt = db()->prepare('SELECT date, site FROM daily_summaries WHERE date BETWEEN :from AND :to ORDER BY date');
    $stmt->bindValue(':from', $fromDate, SQLITE3_TEXT);
    $stmt->bindValue(':to', $toDate, SQLITE3_TEXT);
    $result = $stmt->execute();
    $rows = [];
    $bounds = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $day = $row['date'];
        $bounds[$day] ??= [day_start($day), day_start(next_date($day)) - 1];
        $rows[] = '(' . $bounds[$day][0] . ',' . $bounds[$day][1] . ",'" . \SQLite3::escapeString((string) $row['site']) . "')";
    }
    if (!$rows) {
        return '';
    }

    // One NOT EXISTS over a VALUES list (columns column1..3) rather than an OR per day:
    // long ranges would otherwise exceed SQLite's expression depth limit. Hits after the
    // last summarized day short-circuit before the (correlated) subquery runs.
    $end = day_start(next_date($toDate));
    return " AND ({$prefix}created_at >= {$end} OR NOT EXISTS (SELECT 1 FROM (VALUES " . \implode(',', $rows) . ") sd"
        . " WHERE {$prefix}created_at BETWEEN sd.column1 AND sd.column2 AND {$prefix}site = sd.column3))";
}

/**
 * Summary: total pageviews, unique visitors, single-page visit rate, avg time on page.
 *
 * The daily salt makes visitor hashes per-day, so unique visitors are visitor-days
 * and can be added across days without double counting.
 */
function api_summary(string $site, int $fromTs, int $toTs): array {
    $db = db();
    $parts = range_parts($fromTs, $toTs);
    $pageviews = 0;
    $uniques = 0;
    $bounced = 0;
    $timeSum = 0.0;
    $timeCount = 0;

    if ($parts['summary'] !== null) {
        $where = 'date BETWEEN :from AND :to';
        $params = [':from' => $parts['summary'][0], ':to' => $parts['summary'][1]];
        apply_site_filter($where, $params, $site);
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(pageviews), 0) as pv, COALESCE(SUM(unique_visitors), 0) as uv,
                   COALESCE(SUM(bounced), 0) as bounced,
                   COALESCE(SUM(time_sum), 0) as tsum, COALESCE(SUM(time_count), 0) as tcount
            FROM daily_summaries WHERE {$where}
        ");
        bind_params($stmt, $params);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        $pageviews += (int) $row['pv'];
        $uniques += (int) $row['uv'];
        $bounced += (int) $row['bounced'];
        $timeSum += (float) $row['tsum'];
        $timeCount += (int) $row['tcount'];
    }

    if ($parts['raw'] !== null) {
        $where = 'created_at BETWEEN :from AND :to' . $parts['exclude'];
        $params = [':from' => $parts['raw'][0], ':to' => $parts['raw'][1]];
        apply_site_filter($where, $params, $site);

        $stmt = $db->prepare("
            SELECT COUNT(*) as pv, COUNT(DISTINCT visitor_hash) as uv,
                   COALESCE(SUM(time_on_page), 0) as tsum, COUNT(time_on_page) as tcount
            FROM hits WHERE {$where}
        ");
        bind_params($stmt, $params);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        $pageviews += (int) $row['pv'];
        $uniques += (int) $row['uv'];
        $timeSum += (float) $row['tsum'];
        $timeCount += (int) $row['tcount'];

        // Single-page visits: visitor hashes (one per visitor per day) with exactly one hit
        $stmt = $db->prepare("
            SELECT COUNT(*) as bounced FROM (
                SELECT visitor_hash FROM hits WHERE {$where}
                GROUP BY visitor_hash HAVING COUNT(*) = 1
            )
        ");
        bind_params($stmt, $params);
        $bounced += (int) $stmt->execute()->fetchArray(SQLITE3_ASSOC)['bounced'];
    }

    return [
        'pageviews'    => $pageviews,
        'uniques'      => $uniques,
        'bounce_rate'  => $uniques > 0 ? \round($bounced / $uniques, 4) : 0,
        'avg_time'     => $timeCount > 0 ? \round($timeSum / $timeCount) : null,
        'approximate'  => false,
    ];
}

/**
 * Chart: daily pageviews and unique visitors for the date range, in local days.
 */
function api_chart(string $site, string $from, string $to, int $fromTs, int $toTs): array {
    $db = db();
    $data = [];
    $parts = range_parts($fromTs, $toTs);

    if ($parts['summary'] !== null) {
        $where = 'date BETWEEN :from AND :to';
        $params = [':from' => $parts['summary'][0], ':to' => $parts['summary'][1]];
        apply_site_filter($where, $params, $site);
        $stmt = $db->prepare("
            SELECT date, SUM(pageviews) as pageviews, SUM(unique_visitors) as uniques
            FROM daily_summaries WHERE {$where} GROUP BY date
        ");
        bind_params($stmt, $params);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $data[$row['date']] = ['pageviews' => (int) $row['pageviews'], 'uniques' => (int) $row['uniques']];
        }
    }

    if ($parts['raw'] !== null) {
        // Local-day buckets computed in PHP (DST-aware), inlined as integer literals.
        // SQLite's date() only knows UTC, which would shift days for any other timezone.
        $values = [];
        $day = \date('Y-m-d', $parts['raw'][0]);
        $lastDay = \date('Y-m-d', $parts['raw'][1]);
        while ($day <= $lastDay) {
            $start = \max(day_start($day), $parts['raw'][0]);
            $end = \min(day_start(next_date($day)) - 1, $parts['raw'][1]);
            $values[] = "('{$day}', {$start}, {$end})";
            $day = next_date($day);
        }

        $where = 'h.created_at BETWEEN d.s AND d.e' . $parts['exclude_h'];
        $params = [];
        apply_site_filter($where, $params, $site, 'h.site');
        $stmt = $db->prepare("
            WITH d(day, s, e) AS (VALUES " . \implode(',', $values) . ")
            SELECT d.day as day, COUNT(*) as pageviews, COUNT(DISTINCT h.visitor_hash) as uniques
            FROM d JOIN hits h ON {$where}
            GROUP BY d.day
        ");
        bind_params($stmt, $params);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            // Add rather than overwrite: a day can have summaries for some sites and raw hits for others
            $data[$row['day']]['pageviews'] = ($data[$row['day']]['pageviews'] ?? 0) + (int) $row['pageviews'];
            $data[$row['day']]['uniques'] = ($data[$row['day']]['uniques'] ?? 0) + (int) $row['uniques'];
        }
    }

    // Fill in all dates in the range (including zeros)
    $days = [];
    for ($day = $from; $day <= $to; $day = next_date($day)) {
        $days[] = [
            'date'      => $day,
            'pageviews' => $data[$day]['pageviews'] ?? 0,
            'uniques'   => $data[$day]['uniques'] ?? 0,
        ];
    }

    return ['days' => $days];
}

/**
 * Generic breakdown of one hits column, merged with daily summaries when the
 * range reaches into summarized days. Returns rows of [val, views, visitors].
 *
 * @param string      $column         hits column (from a fixed allow-list in callers)
 * @param string|null $summaryColumn  daily_summaries JSON column holding the same breakdown
 * @param string      $order          'views' or 'visitors'
 * @param bool        $excludeOwnSite drop rows equal to the hit's own site (self-referrals)
 */
function breakdown(string $site, int $fromTs, int $toTs, string $column, ?string $summaryColumn,
                   string $order, int $limit, int $offset, bool $excludeOwnSite = false): array {
    $db = db();
    $parts = range_parts($fromTs, $toTs);
    $merging = $parts['summary'] !== null && $summaryColumn !== null;
    $orderSql = $order === 'views' ? 'views DESC, visitors DESC' : 'visitors DESC, views DESC';
    $ownSiteSql = $excludeOwnSite
        ? " AND {$column} != site AND {$column} != ('www.' || site) AND ('www.' || {$column}) != site"
        : '';

    $rows = [];
    if ($parts['raw'] !== null) {
        $where = "created_at BETWEEN :from AND :to AND {$column} IS NOT NULL{$ownSiteSql}" . $parts['exclude'];
        $params = [':from' => $parts['raw'][0], ':to' => $parts['raw'][1]];
        apply_site_filter($where, $params, $site);
        $stmt = $db->prepare("
            SELECT {$column} as val, COUNT(*) as views, COUNT(DISTINCT visitor_hash) as visitors
            FROM hits WHERE {$where}
            GROUP BY {$column}
            ORDER BY {$orderSql}, val
            LIMIT :limit OFFSET :offset
        ");
        bind_params($stmt, $params);
        $stmt->bindValue(':limit', $merging ? BREAKDOWN_MERGE_LIMIT : $limit, SQLITE3_INTEGER);
        $stmt->bindValue(':offset', $merging ? 0 : $offset, SQLITE3_INTEGER);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = ['val' => (string) $row['val'], 'views' => (int) $row['views'], 'visitors' => (int) $row['visitors']];
        }
    }

    if (!$merging) {
        return $rows;
    }

    // Merge per-day summary breakdowns (each holds that day's top entries)
    $merged = [];
    foreach ($rows as $row) {
        $merged[$row['val']] = $row;
    }
    $where = "date BETWEEN :from AND :to AND {$summaryColumn} IS NOT NULL";
    $params = [':from' => $parts['summary'][0], ':to' => $parts['summary'][1]];
    apply_site_filter($where, $params, $site);
    $stmt = $db->prepare("SELECT site, {$summaryColumn} as data FROM daily_summaries WHERE {$where}");
    bind_params($stmt, $params);
    $result = $stmt->execute();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $entries = \json_decode((string) $row['data'], true);
        if (!\is_array($entries)) {
            continue;
        }
        foreach ($entries as $val => $counts) {
            $val = (string) $val;
            if ($excludeOwnSite && is_own_site($val, (string) $row['site'])) {
                continue;
            }
            // v4 summaries store [views, visitors]; older ones store visitors only
            [$views, $visitors] = \is_array($counts)
                ? [(int) ($counts[0] ?? 0), (int) ($counts[1] ?? 0)]
                : [(int) $counts, (int) $counts];
            $merged[$val] ??= ['val' => $val, 'views' => 0, 'visitors' => 0];
            $merged[$val]['views'] += $views;
            $merged[$val]['visitors'] += $visitors;
        }
    }

    return sort_and_slice(\array_values($merged), $order, $limit, $offset);
}

/**
 * Whether a referrer domain is the tracked site itself (with or without www.).
 */
function is_own_site(string $domain, string $site): bool {
    return $domain === $site || $domain === 'www.' . $site || 'www.' . $domain === $site;
}

/**
 * Sort merged breakdown rows by the given metric and apply pagination.
 */
function sort_and_slice(array $rows, string $order, int $limit, int $offset): array {
    $secondary = $order === 'views' ? 'visitors' : 'views';
    \usort($rows, function ($a, $b) use ($order, $secondary) {
        return [$b[$order], $b[$secondary], $a['val']] <=> [$a[$order], $a[$secondary], $b['val']];
    });
    return \array_slice($rows, $offset, $limit);
}

/**
 * Rename breakdown rows to an endpoint's output keys.
 */
function map_rows(array $rows, string $valKey, array $fields): array {
    return \array_map(function ($row) use ($valKey, $fields) {
        $out = [$valKey => $row['val']];
        foreach ($fields as $outKey => $srcKey) {
            $out[$outKey] = $row[$srcKey];
        }
        return $out;
    }, $rows);
}

/**
 * Top pages with pageview and unique visitor counts.
 */
function api_pages(string $site, int $fromTs, int $toTs, int $limit, int $offset): array {
    $rows = breakdown($site, $fromTs, $toTs, 'page_path', 'top_pages', 'views', $limit, $offset);
    return ['rows' => map_rows($rows, 'page_path', ['views' => 'views', 'uniques' => 'visitors'])];
}

/**
 * Referrers grouped by domain. Navigation within the same site is not a referral.
 */
function api_referrers(string $site, int $fromTs, int $toTs, int $limit, int $offset): array {
    $rows = breakdown($site, $fromTs, $toTs, 'referrer_domain', 'top_referrers', 'visitors', $limit, $offset, true);
    return ['rows' => map_rows($rows, 'domain', ['visitors' => 'visitors', 'views' => 'views'])];
}

/**
 * UTM breakdown by source, medium, or campaign.
 */
function api_utm(string $site, int $fromTs, int $toTs, string $groupBy, int $limit, int $offset): array {
    [$column, $key] = match ($groupBy) {
        'medium'   => ['utm_medium', 'medium'],
        'campaign' => ['utm_campaign', 'campaign'],
        default    => ['utm_source', 'source'],
    };

    $parts = range_parts($fromTs, $toTs);
    if ($parts['summary'] === null) {
        $rows = breakdown($site, $fromTs, $toTs, $column, null, 'visitors', $limit, $offset);
    } else {
        // v4 summaries keep a {value: [views, visitors]} map per field. Legacy (pre-v4)
        // summaries are a list of source/medium/campaign combos, regrouped approximately.
        $merged = [];
        foreach (breakdown($site, $fromTs, $toTs, $column, null, 'visitors', BREAKDOWN_MERGE_LIMIT, 0) as $row) {
            $merged[$row['val']] = $row;
        }
        $where = 'date BETWEEN :from AND :to AND utm_summary IS NOT NULL';
        $params = [':from' => $parts['summary'][0], ':to' => $parts['summary'][1]];
        apply_site_filter($where, $params, $site);
        $stmt = db()->prepare("SELECT utm_summary FROM daily_summaries WHERE {$where}");
        bind_params($stmt, $params);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $combos = \json_decode((string) $row['utm_summary'], true);
            if (\is_array($combos) && \array_key_exists($key, $combos) && !\array_is_list($combos)) {
                foreach (\is_array($combos[$key]) ? $combos[$key] : [] as $val => $counts) {
                    $val = (string) $val;
                    $merged[$val] ??= ['val' => $val, 'views' => 0, 'visitors' => 0];
                    $merged[$val]['views'] += (int) ($counts[0] ?? 0);
                    $merged[$val]['visitors'] += (int) ($counts[1] ?? 0);
                }
                continue;
            }
            foreach (\is_array($combos) ? $combos : [] as $combo) {
                $val = $combo[$key] ?? null;
                if ($val === null || $val === '') {
                    continue;
                }
                $val = (string) $val;
                $merged[$val] ??= ['val' => $val, 'views' => 0, 'visitors' => 0];
                $merged[$val]['visitors'] += (int) ($combo['count'] ?? 0);
                $merged[$val]['views'] += (int) ($combo['views'] ?? $combo['count'] ?? 0);
            }
        }
        $rows = sort_and_slice(\array_values($merged), 'visitors', $limit, $offset);
    }

    return ['rows' => map_rows($rows, 'name', ['visitors' => 'visitors', 'views' => 'views']), 'group' => $key];
}

/**
 * Device, browser, or OS distribution.
 */
function api_devices(string $site, int $fromTs, int $toTs, string $groupBy): array {
    [$column, $summaryColumn, $groupBy] = match ($groupBy) {
        'browser' => ['browser', 'browsers', 'browser'],
        'os'      => ['os', 'operating_systems', 'os'],
        default   => ['device_type', 'devices', 'type'],
    };
    $rows = breakdown($site, $fromTs, $toTs, $column, $summaryColumn, 'visitors', 20, 0);
    return ['rows' => map_rows($rows, 'name', ['visitors' => 'visitors']), 'group' => $groupBy];
}

/**
 * Country distribution.
 */
function api_countries(string $site, int $fromTs, int $toTs, int $limit, int $offset): array {
    $rows = breakdown($site, $fromTs, $toTs, 'country_code', 'top_countries', 'visitors', $limit, $offset);
    return ['rows' => map_rows($rows, 'code', ['visitors' => 'visitors'])];
}

/**
 * Screen resolutions.
 */
function api_screens(string $site, int $fromTs, int $toTs, int $limit, int $offset): array {
    $rows = breakdown($site, $fromTs, $toTs, 'screen_res', 'screens', 'visitors', $limit, $offset);
    return ['rows' => map_rows($rows, 'resolution', ['visitors' => 'visitors'])];
}

/**
 * Language distribution.
 */
function api_languages(string $site, int $fromTs, int $toTs, int $limit, int $offset): array {
    $rows = breakdown($site, $fromTs, $toTs, 'language', 'languages', 'visitors', $limit, $offset);
    return ['rows' => map_rows($rows, 'lang', ['visitors' => 'visitors'])];
}

/**
 * Live visitors: count of unique hashes in the last N minutes.
 */
function api_live(string $site): array {
    $db = db();
    $minutes = (int) get_config('live_visitor_minutes', (string) LIVE_VISITOR_MINUTES);
    $since = \time() - ($minutes * 60);

    // The created_at floor lets SQLite use the index instead of scanning every hit.
    // Beacons only refresh hits created the same day, so a 1-day floor loses nothing.
    $where = 'created_at >= :floor AND (created_at >= :since OR last_active_at >= :since)';
    $params = [':since' => $since, ':floor' => $since - 86400];
    apply_site_filter($where, $params, $site);

    $stmt = $db->prepare(live_count_sql($where));
    bind_params($stmt, $params);
    $count = (int) $stmt->execute()->fetchArray(SQLITE3_ASSOC)['cnt'];

    return ['count' => $count];
}

/**
 * Live visitor count query. The unary + keeps SQLite from walking idx_hits_visitor
 * (a full scan) for the DISTINCT and makes it use the created_at range instead.
 */
function live_count_sql(string $where): string {
    return "SELECT COUNT(DISTINCT +visitor_hash) as cnt FROM hits WHERE {$where}";
}

/**
 * Get list of tracked sites (raw, summarized, and configured).
 */
function api_sites(): array {
    $db = db();
    $result = $db->query("
        SELECT site FROM hits WHERE site != '' GROUP BY site
        UNION
        SELECT site FROM daily_summaries WHERE site != ''
    ");
    $sites = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $sites[] = $row['site'];
    }
    // Also include configured domains not yet tracked
    foreach (get_allowed_domains() as $domain) {
        if (!\in_array($domain, $sites, true)) {
            $sites[] = $domain;
        }
    }
    \sort($sites, SORT_STRING | SORT_FLAG_CASE);
    return ['sites' => $sites];
}

/**
 * Top events by name. Returns total count and unique visitor count per event name.
 */
function api_events(string $site, int $fromTs, int $toTs, int $limit, int $offset): array {
    $db = db();

    if (!table_exists($db, 'events')) {
        return ['rows' => []];
    }

    $where = 'created_at BETWEEN :from AND :to';
    $params = [':from' => $fromTs, ':to' => $toTs];
    apply_site_filter($where, $params, $site);

    $stmt = $db->prepare("
        SELECT name,
               COUNT(*) as count,
               COUNT(DISTINCT visitor_hash) as uniques
        FROM events
        WHERE {$where}
        GROUP BY name
        ORDER BY count DESC, name
        LIMIT :limit OFFSET :offset
    ");
    bind_params($stmt, $params);
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);

    return ['rows' => fetch_all($stmt)];
}

/**
 * Top values for a single event name. Used by the dashboard drilldown.
 * NULL/empty values are grouped under "(no value)".
 */
function api_event_values(string $site, int $fromTs, int $toTs, string $eventName, int $limit, int $offset): array {
    if (!\preg_match('/^[a-zA-Z0-9_]{1,64}$/', $eventName)) {
        return ['error' => 'Invalid event name'];
    }

    $db = db();
    if (!table_exists($db, 'events')) {
        return ['rows' => [], 'name' => $eventName];
    }

    $where = 'created_at BETWEEN :from AND :to AND name = :name';
    $params = [':from' => $fromTs, ':to' => $toTs, ':name' => $eventName];
    apply_site_filter($where, $params, $site);

    // Group by the labelled expression so NULL and '' collapse into one "(no value)" row
    $stmt = $db->prepare("
        SELECT COALESCE(NULLIF(value, ''), '(no value)') as label,
               COUNT(*) as count,
               COUNT(DISTINCT visitor_hash) as uniques
        FROM events
        WHERE {$where}
        GROUP BY label
        ORDER BY count DESC, label
        LIMIT :limit OFFSET :offset
    ");
    bind_params($stmt, $params);
    $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);

    $rows = \array_map(function ($row) {
        return ['value' => (string) $row['label'], 'count' => $row['count'], 'uniques' => $row['uniques']];
    }, fetch_all($stmt));

    return ['rows' => $rows, 'name' => $eventName];
}

// ─── Query Helpers ──────────────────────────────────────────────────────────

/**
 * Check if a table exists. Used to gate event queries on installs that have
 * not yet completed the v2→v3 migration.
 */
function table_exists(\SQLite3 $db, string $name): bool {
    $stmt = $db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:name LIMIT 1");
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    return (bool) $stmt->execute()->fetchArray();
}

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
function apply_site_filter(string &$where, array &$params, string $site, string $column = 'site'): void {
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
        $where .= " AND {$column} IN (" . \implode(',', $placeholders) . ')';
    } else {
        $where .= " AND {$column} = :site";
        $params[':site'] = $site;
    }
}

/**
 * Fetch all rows from a prepared statement result. Only count columns are cast to
 * integers; labels like a "2024" campaign or "007" event value stay strings.
 */
function fetch_all(\SQLite3Stmt $stmt): array {
    static $countColumns = ['views', 'uniques', 'visitors', 'count'];
    $result = $stmt->execute();
    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        foreach ($countColumns as $col) {
            if (isset($row[$col])) {
                $row[$col] = (int) $row[$col];
            }
        }
        $rows[] = $row;
    }
    return $rows;
}
