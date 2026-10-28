<?php

namespace MintyMetrics\Tests\Integration;

use MintyMetrics\Tests\IntegrationTestCase;

class ExportTest extends IntegrationTestCase
{
    private function export(string $type, string $site = ''): array
    {
        $r = \MintyMetrics\parse_date_range(date('Y-m-d', strtotime('-1 day')), date('Y-m-d'));
        $stream = fopen('php://memory', 'w+');
        \MintyMetrics\export_rows($stream, $type, $site, $r['from'], $r['to'], $r['fromTs'], $r['toTs']);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);
        return $rows;
    }

    public function testCsvSafeNeutralizesFormulas(): void
    {
        foreach (['=HYPERLINK("x")', '+1+1', '-2+3', '@SUM(A1)', "\tcmd", "\rcmd"] as $cell) {
            $this->assertSame("'" . $cell, \MintyMetrics\csv_safe($cell));
        }
        $this->assertSame('/normal/path', \MintyMetrics\csv_safe('/normal/path'));
        $this->assertSame(42, \MintyMetrics\csv_safe(42));
        $this->assertNull(\MintyMetrics\csv_safe(null));
    }

    public function testPageviewsExportNeutralizesVisitorData(): void
    {
        $this->insertHit(['page_path' => '=HYPERLINK("http://evil","x")', 'created_at' => time() - 60]);

        $rows = $this->export('pageviews');

        $this->assertSame('Date', $rows[0][0]);
        $this->assertSame('\'=HYPERLINK("http://evil","x")', $rows[1][1]);
        $this->assertSame(date('Y-m-d H:i:s', time() - 60), $rows[1][0], 'Dates are in the configured local timezone');
    }

    public function testUtmExportIncludesCampaignOnlyRows(): void
    {
        $this->insertHit(['utm_campaign' => 'spring']);

        $rows = $this->export('utm');

        $this->assertCount(2, $rows);
        $this->assertSame('spring', $rows[1][2]);
    }

    public function testReferrerExportExcludesOwnSite(): void
    {
        $this->insertHit(['site' => 'blog.com', 'referrer_domain' => 'blog.com']);
        $this->insertHit(['site' => 'blog.com', 'referrer_domain' => 'news.com']);

        $rows = $this->export('referrers');

        $this->assertCount(2, $rows);
        $this->assertSame('news.com', $rows[1][0]);
    }

    public function testSummariesExport(): void
    {
        self::$testDb->exec("INSERT INTO daily_summaries (date, site, pageviews, unique_visitors, bounce_rate, avg_time_on_page)
            VALUES ('" . date('Y-m-d', strtotime('-1 day')) . "', 'a.com', 12, 5, 0.4, 33.5)");

        $rows = $this->export('summaries');

        $this->assertSame(['Date', 'Site', 'Pageviews', 'Unique Visitors', 'Single-Page Visit Rate', 'Avg Time on Page'], $rows[0]);
        $this->assertSame('12', $rows[1][2]);
    }
}
