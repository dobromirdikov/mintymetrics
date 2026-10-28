<?php
namespace MintyMetrics;

/**
 * Stream a CSV export of analytics data.
 */
const EXPORT_MAX_ROWS = 100000;

function export_csv(string $type, string $site, ?string $from, ?string $to): void {
    auth_require();

    $range = parse_date_range($from, $to);
    if (isset($range['error'])) {
        \http_response_code(400);
        \header('Content-Type: text/plain; charset=utf-8');
        echo $range['error'] . '.';
        return;
    }
    [$from, $to, $fromTs, $toTs] = [$range['from'], $range['to'], $range['fromTs'], $range['toTs']];

    // Sanitize type for filename (allow-list)
    $safeType = match ($type) {
        'pageviews', 'pages', 'referrers', 'utm', 'countries', 'events', 'summaries' => $type,
        default => 'export',
    };
    $filename = "mintymetrics-{$safeType}-{$from}-to-{$to}.csv";

    \header('Content-Type: text/csv; charset=utf-8');
    \header('Content-Disposition: attachment; filename="' . $filename . '"');
    \header('Cache-Control: no-store');
    \header('X-Content-Type-Options: nosniff');

    $output = \fopen('php://output', 'w');
    with_read_snapshot(fn() => export_rows($output, $type, $site, $from, $to, $fromTs, $toTs));
    \fclose($output);
}

/**
 * Write the CSV for one export type to a stream.
 */
function export_rows($output, string $type, string $site, string $from, string $to, int $fromTs, int $toTs): void {
    $db = db();

    $where = 'created_at BETWEEN :from AND :to';
    $params = [':from' => $fromTs, ':to' => $toTs];
    apply_site_filter($where, $params, $site);

    switch ($type) {
        case 'pageviews':
            csv_row($output, ['Date', 'Page', 'Visitor Hash', 'Referrer', 'Country', 'Device', 'Browser', 'OS', 'Screen', 'Language', 'Time on Page']);
            $stmt = $db->prepare("
                SELECT created_at, page_path, visitor_hash, referrer_domain,
                    country_code, device_type, browser, os, screen_res, language, time_on_page
                FROM hits WHERE {$where}
                ORDER BY created_at DESC
                LIMIT " . EXPORT_MAX_ROWS . "
            ");
            csv_stream($output, $stmt, $params, true);
            break;

        case 'pages':
            csv_row($output, ['Page', 'Pageviews', 'Unique Visitors']);
            $stmt = $db->prepare("
                SELECT page_path, COUNT(*) as views, COUNT(DISTINCT visitor_hash) as uniques
                FROM hits WHERE {$where}
                GROUP BY page_path
                ORDER BY views DESC
            ");
            csv_stream($output, $stmt, $params);
            break;

        case 'referrers':
            csv_row($output, ['Referrer Domain', 'Visitors', 'Pageviews']);
            $stmt = $db->prepare("
                SELECT referrer_domain, COUNT(DISTINCT visitor_hash) as visitors, COUNT(*) as views
                FROM hits WHERE {$where} AND referrer_domain IS NOT NULL
                    AND referrer_domain != site AND referrer_domain != ('www.' || site) AND ('www.' || referrer_domain) != site
                GROUP BY referrer_domain
                ORDER BY visitors DESC
            ");
            csv_stream($output, $stmt, $params);
            break;

        case 'utm':
            csv_row($output, ['Source', 'Medium', 'Campaign', 'Visitors']);
            $stmt = $db->prepare("
                SELECT utm_source, utm_medium, utm_campaign, COUNT(DISTINCT visitor_hash) as visitors
                FROM hits WHERE {$where}
                    AND (utm_source IS NOT NULL OR utm_medium IS NOT NULL OR utm_campaign IS NOT NULL)
                GROUP BY utm_source, utm_medium, utm_campaign
                ORDER BY visitors DESC
            ");
            csv_stream($output, $stmt, $params);
            break;

        case 'countries':
            csv_row($output, ['Country Code', 'Visitors']);
            $stmt = $db->prepare("
                SELECT country_code, COUNT(DISTINCT visitor_hash) as visitors
                FROM hits WHERE {$where} AND country_code IS NOT NULL
                GROUP BY country_code
                ORDER BY visitors DESC
            ");
            csv_stream($output, $stmt, $params);
            break;

        case 'events':
            csv_row($output, ['Date', 'Name', 'Value', 'Props', 'Visitor Hash', 'Page', 'Country', 'Device', 'Browser', 'OS']);
            if (!table_exists($db, 'events')) {
                break;
            }
            $stmt = $db->prepare("
                SELECT created_at, name, value, props, visitor_hash,
                    page_path, country_code, device_type, browser, os
                FROM events WHERE {$where}
                ORDER BY created_at DESC
                LIMIT " . EXPORT_MAX_ROWS . "
            ");
            csv_stream($output, $stmt, $params, true);
            break;

        case 'summaries':
            // Daily totals kept after raw hits expire
            csv_row($output, ['Date', 'Site', 'Pageviews', 'Unique Visitors', 'Single-Page Visit Rate', 'Avg Time on Page']);
            $swhere = 'date BETWEEN :from AND :to';
            $sparams = [':from' => $from, ':to' => $to];
            apply_site_filter($swhere, $sparams, $site);
            $stmt = $db->prepare("
                SELECT date, site, pageviews, unique_visitors, bounce_rate, avg_time_on_page
                FROM daily_summaries WHERE {$swhere}
                ORDER BY date DESC, site
            ");
            csv_stream($output, $stmt, $sparams);
            break;

        default:
            csv_row($output, ['Error']);
            csv_row($output, ['Unknown export type: ' . $type]);
    }
}

/**
 * Execute a statement and write each row. With $firstIsTimestamp, the first
 * column is a Unix timestamp rendered in the configured local timezone.
 */
function csv_stream($output, \SQLite3Stmt $stmt, array $params, bool $firstIsTimestamp = false): void {
    bind_params($stmt, $params);
    $result = $stmt->execute();
    while ($row = $result->fetchArray(SQLITE3_NUM)) {
        if ($firstIsTimestamp) {
            $row[0] = \date('Y-m-d H:i:s', (int) $row[0]);
        }
        csv_row($output, $row);
    }
}

/**
 * Write one CSV row with spreadsheet formula injection neutralized.
 */
function csv_row($output, array $fields): void {
    \fputcsv($output, \array_map('MintyMetrics\csv_safe', $fields), ',', '"', '');
}

/**
 * Prefix cells that a spreadsheet would treat as a formula. Page paths, UTM tags,
 * event values and props all come from anonymous visitors.
 */
function csv_safe(mixed $value): mixed {
    if (!\is_string($value) || $value === '') {
        return $value;
    }
    if (\str_contains("=+-@\t\r", $value[0])) {
        return "'" . $value;
    }
    return $value;
}
