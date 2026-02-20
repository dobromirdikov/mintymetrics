<?php
namespace MintyMetrics;

/**
 * Stream a CSV export of analytics data.
 */
const EXPORT_MAX_ROWS = 100000;

function export_csv(string $type, string $site, string $from, string $to): void {
    auth_require();

    if (!\preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !\preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        \http_response_code(400);
        echo 'Invalid date format.';
        return;
    }

    $fromTs = \strtotime($from . ' 00:00:00');
    $toTs = \strtotime($to . ' 23:59:59');

    if (!$fromTs || !$toTs) {
        \http_response_code(400);
        echo 'Invalid date range.';
        return;
    }

    // Sanitize type for filename (allow-list)
    $safeType = match ($type) {
        'pageviews', 'pages', 'referrers', 'utm', 'countries' => $type,
        default => 'export',
    };
    $filename = "mintymetrics-{$safeType}-{$from}-to-{$to}.csv";

    \header('Content-Type: text/csv; charset=utf-8');
    \header('Content-Disposition: attachment; filename="' . $filename . '"');
    \header('Cache-Control: no-store');

    $output = \fopen('php://output', 'w');
    $db = db();

    $where = 'created_at BETWEEN :from AND :to';
    $params = [':from' => $fromTs, ':to' => $toTs];
    apply_site_filter($where, $params, $site);

    switch ($type) {
        case 'pageviews':
            \fputcsv($output, ['Date', 'Page', 'Visitor Hash', 'Referrer', 'Country', 'Device', 'Browser', 'OS', 'Screen', 'Language', 'Time on Page']);
            $stmt = $db->prepare("
                SELECT datetime(created_at, 'unixepoch') as date, page_path, visitor_hash, referrer_domain,
                    country_code, device_type, browser, os, screen_res, language, time_on_page
                FROM hits WHERE {$where}
                ORDER BY created_at DESC
                LIMIT " . EXPORT_MAX_ROWS . "
            ");
            bind_params($stmt, $params);
            $result = $stmt->execute();
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                \fputcsv($output, \array_values($row));
            }
            break;

        case 'pages':
            \fputcsv($output, ['Page', 'Pageviews', 'Unique Visitors']);
            $stmt = $db->prepare("
                SELECT page_path, COUNT(*) as views, COUNT(DISTINCT visitor_hash) as uniques
                FROM hits WHERE {$where}
                GROUP BY page_path
                ORDER BY views DESC
            ");
            bind_params($stmt, $params);
            $result = $stmt->execute();
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                \fputcsv($output, \array_values($row));
            }
            break;

        case 'referrers':
            \fputcsv($output, ['Referrer Domain', 'Visitors', 'Pageviews']);
            $stmt = $db->prepare("
                SELECT referrer_domain, COUNT(DISTINCT visitor_hash) as visitors, COUNT(*) as views
                FROM hits WHERE {$where} AND referrer_domain IS NOT NULL
                GROUP BY referrer_domain
                ORDER BY visitors DESC
            ");
            bind_params($stmt, $params);
            $result = $stmt->execute();
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                \fputcsv($output, \array_values($row));
            }
            break;

        case 'utm':
            \fputcsv($output, ['Source', 'Medium', 'Campaign', 'Visitors']);
            $stmt = $db->prepare("
                SELECT utm_source, utm_medium, utm_campaign, COUNT(DISTINCT visitor_hash) as visitors
                FROM hits WHERE {$where} AND (utm_source IS NOT NULL OR utm_medium IS NOT NULL)
                GROUP BY utm_source, utm_medium, utm_campaign
                ORDER BY visitors DESC
            ");
            bind_params($stmt, $params);
            $result = $stmt->execute();
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                \fputcsv($output, \array_values($row));
            }
            break;

        case 'countries':
            \fputcsv($output, ['Country Code', 'Visitors']);
            $stmt = $db->prepare("
                SELECT country_code, COUNT(DISTINCT visitor_hash) as visitors
                FROM hits WHERE {$where} AND country_code IS NOT NULL
                GROUP BY country_code
                ORDER BY visitors DESC
            ");
            bind_params($stmt, $params);
            $result = $stmt->execute();
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                \fputcsv($output, \array_values($row));
            }
            break;

        default:
            \fputcsv($output, ['Error']);
            \fputcsv($output, ['Unknown export type: ' . $type]);
    }

    \fclose($output);
}
