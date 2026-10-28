<?php

namespace MintyMetrics\Tests\Integration;

use MintyMetrics\Tests\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Regression tests for combining daily summaries with raw hits, local-day
 * bucketing, and API input validation.
 */
class ApiRangeTest extends IntegrationTestCase
{
    private string $prevTz;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prevTz = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->prevTz);
        parent::tearDown();
    }

    private function insertSummary(string $date, string $site, int $pv, int $uv, array $extra = []): void
    {
        $row = array_merge([
            'bounced' => 0, 'time_sum' => 0, 'time_count' => 0,
            'top_pages' => '{}', 'top_referrers' => '{}', 'utm_summary' => '[]',
        ], $extra);
        $stmt = self::$testDb->prepare('INSERT INTO daily_summaries
            (date, site, pageviews, unique_visitors, visitor_days, bounced, time_sum, time_count, top_pages, top_referrers, utm_summary)
            VALUES (:d, :s, :pv, :uv, :uv, :b, :ts, :tc, :tp, :tr, :utm)');
        $stmt->bindValue(':d', $date);
        $stmt->bindValue(':s', $site);
        $stmt->bindValue(':pv', $pv);
        $stmt->bindValue(':uv', $uv);
        $stmt->bindValue(':b', $row['bounced']);
        $stmt->bindValue(':ts', $row['time_sum']);
        $stmt->bindValue(':tc', $row['time_count']);
        $stmt->bindValue(':tp', $row['top_pages']);
        $stmt->bindValue(':tr', $row['top_referrers']);
        $stmt->bindValue(':utm', $row['utm_summary']);
        $stmt->execute();
    }

    private function range(string $from, string $to): array
    {
        $r = \MintyMetrics\parse_date_range($from, $to);
        return [$r['fromTs'], $r['toTs']];
    }

    private function daysAgo(int $n): string
    {
        return date('Y-m-d', strtotime("today -{$n} days"));
    }

    public function testOldRangeOnlyCountsItsOwnSummaries(): void
    {
        for ($i = 100; $i <= 200; $i++) {
            $this->insertSummary($this->daysAgo($i), 'a.com', 10, 5);
        }
        [$from, $to] = $this->range($this->daysAgo(200), $this->daysAgo(195));

        $summary = \MintyMetrics\api_summary('', $from, $to);

        $this->assertSame(60, $summary['pageviews']);
        $this->assertSame(30, $summary['uniques']);
    }

    public function testSummaryWinsOverLeftoverRawHitsOnSameDay(): void
    {
        // Legacy installs can have a summarized day whose later raw hits were never deleted
        $day = $this->daysAgo(20);
        $this->insertSummary($day, 'a.com', 3, 3);
        $this->insertHit(['site' => 'a.com', 'created_at' => strtotime("{$day} 18:00:00")]);
        $this->insertHit(['site' => 'a.com', 'created_at' => strtotime("{$day} 19:00:00")]);
        $this->insertHit(['site' => 'a.com', 'created_at' => strtotime('today 10:00:00')]);
        [$from, $to] = $this->range($day, date('Y-m-d'));

        $summary = \MintyMetrics\api_summary('', $from, $to);
        $chart = \MintyMetrics\api_chart('', $day, date('Y-m-d'), $from, $to);

        $this->assertSame(4, $summary['pageviews']);
        $this->assertSame(3, $chart['days'][0]['pageviews']);
        $this->assertSame(4, array_sum(array_column($chart['days'], 'pageviews')), 'Chart and summary card must agree');
    }

    public function testUnsummarizedSiteOnSummarizedDayIsCountedWithoutCleanup(): void
    {
        // Legacy state before cleanup has run: A has a summary for the day, B only raw hits
        $day = $this->daysAgo(5);
        $this->insertSummary($day, 'a.com', 3, 3, ['top_pages' => json_encode(['/a' => [3, 3]])]);
        $this->insertHit(['site' => 'a.com', 'page_path' => '/a', 'created_at' => strtotime("{$day} 22:00:00")]);
        $this->insertHit(['site' => 'b.com', 'page_path' => '/b', 'created_at' => strtotime("{$day} 12:00:00")]);
        [$from, $to] = $this->range($day, $day);

        $summary = \MintyMetrics\api_summary('', $from, $to);
        $chart = \MintyMetrics\api_chart('', $day, $day, $from, $to);
        $pages = array_column(\MintyMetrics\api_pages('', $from, $to, 10, 0)['rows'], 'views', 'page_path');

        $this->assertSame(4, $summary['pageviews'], "A's summary plus B's raw hit; A's leftover raw hit is not double counted");
        $this->assertSame(4, $chart['days'][0]['pageviews']);
        $this->assertSame(['/a' => 3, '/b' => 1], $pages);
        $this->assertSame(1, \MintyMetrics\api_summary('b.com', $from, $to)['pageviews']);
    }

    public function testLongRangeWithLeftoverRawHitStaysQueryable(): void
    {
        // 1,100 summarized days plus one leftover raw hit on the oldest day
        self::$testDb->exec('BEGIN');
        for ($i = 1; $i <= 1100; $i++) {
            $this->insertSummary($this->daysAgo($i), 'a.com', 1, 1, ['top_pages' => json_encode(['/' => [1, 1]])]);
        }
        self::$testDb->exec('COMMIT');
        $oldest = $this->daysAgo(1100);
        $this->insertHit(['site' => 'b.com', 'page_path' => '/b', 'created_at' => strtotime("{$oldest} 12:00:00")]);
        [$from, $to] = $this->range($oldest, date('Y-m-d'));

        $this->assertSame(1101, \MintyMetrics\api_summary('', $from, $to)['pageviews']);
        $chart = \MintyMetrics\api_chart('', $oldest, date('Y-m-d'), $from, $to);
        $this->assertSame(2, $chart['days'][0]['pageviews']);
        $pages = array_column(\MintyMetrics\api_pages('', $from, $to, 10, 0)['rows'], 'views', 'page_path');
        $this->assertSame(['/' => 1100, '/b' => 1], $pages);
    }

    public function testExclusionDoesNotSlowDownCurrentHits(): void
    {
        self::$testDb->exec('BEGIN');
        for ($i = 1; $i <= 1100; $i++) {
            $this->insertSummary($this->daysAgo($i), 'a.com', 1, 1);
        }
        $stmt = self::$testDb->prepare("INSERT INTO hits (site, visitor_hash, page_path, created_at) VALUES ('a.com', :h, '/', :t)");
        $todayStart = strtotime('today');
        for ($i = 0; $i < 30000; $i++) {
            $stmt->bindValue(':h', 'v' . ($i % 500));
            $stmt->bindValue(':t', $todayStart + ($i % 3600));
            $stmt->execute();
            $stmt->reset();
        }
        self::$testDb->exec('COMMIT');
        $oldest = $this->daysAgo(1100);
        $this->insertHit(['site' => 'b.com', 'created_at' => strtotime("{$oldest} 12:00:00")]);
        [$from, $to] = $this->range($oldest, date('Y-m-d'));

        $start = microtime(true);
        $summary = \MintyMetrics\api_summary('', $from, $to);
        $elapsed = microtime(true) - $start;

        $this->assertSame(1100 + 1 + 30000, $summary['pageviews']);
        $this->assertLessThan(3.0, $elapsed, 'Hits after the summarized range must skip the exclusion subquery');
    }

    public function testRequestSeesOneSnapshotWhileCleanupRuns(): void
    {
        $day = $this->daysAgo(3);
        $this->insertHit(['site' => 'a.com', 'created_at' => strtotime("{$day} 12:00:00")]);
        [$from, $to] = $this->range($day, $day);

        $other = new \SQLite3(self::$testDbPath);
        \MintyMetrics\db_configure($other);
        try {
            [$before, $after] = \MintyMetrics\with_read_snapshot(function () use ($other, $day, $from, $to) {
                $before = \MintyMetrics\api_summary('', $from, $to);
                // Another request summarizes and deletes the day mid-response
                $other->exec("INSERT INTO daily_summaries (date, site, pageviews, unique_visitors, visitor_days) VALUES ('{$day}', 'a.com', 1, 1, 1)");
                $other->exec('DELETE FROM hits');
                return [$before, \MintyMetrics\api_summary('', $from, $to)];
            });
        } finally {
            $other->close();
        }

        $this->assertSame(1, $before['pageviews']);
        $this->assertSame(1, $after['pageviews']);
        $this->assertSame(1, \MintyMetrics\api_summary('', $from, $to)['pageviews'], 'After the snapshot, the summary is used');
    }

    public function testSummariesStayVisibleAfterRaisingRetention(): void
    {
        $this->setConfig('data_retention_days', '365');
        $day = $this->daysAgo(100);
        $this->insertSummary($day, 'a.com', 2, 2);
        [$from, $to] = $this->range($day, $day);

        $this->assertSame(2, \MintyMetrics\api_summary('', $from, $to)['pageviews']);
    }

    public function testBounceAndTimeAreWeightedAcrossSummariesAndRaw(): void
    {
        $day = $this->daysAgo(40);
        // 10 visitors, 9 bounced, 100s over 10 timed views
        $this->insertSummary($day, 'a.com', 10, 10, ['bounced' => 9, 'time_sum' => 100, 'time_count' => 10]);
        // Raw today: 1 visitor, 2 pages, 300s over 1 timed view
        $this->insertHit(['visitor_hash' => 'v', 'time_on_page' => 300, 'created_at' => strtotime('today 00:05')]);
        $this->insertHit(['visitor_hash' => 'v', 'created_at' => strtotime('today 00:06')]);
        [$from, $to] = $this->range($day, date('Y-m-d'));

        $s = \MintyMetrics\api_summary('', $from, $to);

        $this->assertEqualsWithDelta(9 / 11, $s['bounce_rate'], 0.0001);
        $this->assertEquals(round(400 / 11), $s['avg_time']);
    }

    public function testChartUsesLocalDays(): void
    {
        date_default_timezone_set('America/New_York');
        $yesterday = date('Y-m-d', strtotime('yesterday'));
        // 23:30 local is already the next day in UTC
        $this->insertHit(['created_at' => strtotime("{$yesterday} 23:30:00")]);
        $this->insertHit(['created_at' => strtotime("{$yesterday} 01:00:00")]);
        [$from, $to] = $this->range($yesterday, date('Y-m-d'));

        $chart = \MintyMetrics\api_chart('', $yesterday, date('Y-m-d'), $from, $to);

        $this->assertSame($yesterday, $chart['days'][0]['date']);
        $this->assertSame(2, $chart['days'][0]['pageviews']);
        $this->assertSame(0, $chart['days'][1]['pageviews']);
    }

    public function testChartSumsSummariesAcrossSites(): void
    {
        $day = $this->daysAgo(50);
        $this->insertSummary($day, 'a.com', 5, 2);
        $this->insertSummary($day, 'b.com', 7, 3);
        [$from, $to] = $this->range($day, $day);

        $chart = \MintyMetrics\api_chart('', $day, $day, $from, $to);

        $this->assertSame(12, $chart['days'][0]['pageviews']);
        $this->assertSame(5, $chart['days'][0]['uniques']);
    }

    public function testBreakdownsMergeSummariesAndRaw(): void
    {
        $day = $this->daysAgo(40);
        $this->insertSummary($day, 'a.com', 5, 3, [
            'top_pages' => json_encode(['/old' => [4, 2], '/' => [1, 1]]),
            'utm_summary' => json_encode([['source' => 'news', 'medium' => null, 'campaign' => null, 'count' => 2, 'views' => 3]]),
        ]);
        $this->insertHit(['site' => 'a.com', 'page_path' => '/', 'utm_source' => 'news', 'created_at' => strtotime('today 00:05')]);
        [$from, $to] = $this->range($day, date('Y-m-d'));

        $pages = \MintyMetrics\api_pages('', $from, $to, 10, 0)['rows'];
        $utm = \MintyMetrics\api_utm('', $from, $to, 'source', 10, 0)['rows'];

        $this->assertSame(['page_path' => '/old', 'views' => 4, 'uniques' => 2], $pages[0]);
        $this->assertSame(['page_path' => '/', 'views' => 2, 'uniques' => 2], $pages[1]);
        $this->assertSame(['name' => 'news', 'visitors' => 3, 'views' => 4], $utm[0]);
    }

    public function testLegacyIntegerSummaryBreakdownsStillReadable(): void
    {
        $day = $this->daysAgo(40);
        $this->insertSummary($day, 'a.com', 5, 3, ['top_pages' => json_encode(['/legacy' => 3])]);
        [$from, $to] = $this->range($day, $day);

        $pages = \MintyMetrics\api_pages('', $from, $to, 10, 0)['rows'];

        $this->assertSame([['page_path' => '/legacy', 'views' => 3, 'uniques' => 3]], $pages);
    }

    public function testReferrersExcludeOwnSite(): void
    {
        $this->insertHit(['site' => 'blog.com', 'referrer_domain' => 'blog.com']);
        $this->insertHit(['site' => 'blog.com', 'referrer_domain' => 'www.blog.com']);
        $this->insertHit(['site' => 'blog.com', 'referrer_domain' => 'google.com']);
        $this->insertHit(['site' => 'shop.com', 'referrer_domain' => 'blog.com']);
        [$from, $to] = $this->range(date('Y-m-d'), date('Y-m-d'));

        $domains = array_column(\MintyMetrics\api_referrers('', $from, $to, 10, 0)['rows'], 'domain');
        sort($domains);

        $this->assertSame(['blog.com', 'google.com'], $domains, 'Cross-site referrals stay, self-referrals go');
        $this->assertCount(1, \MintyMetrics\api_referrers('blog.com', $from, $to, 10, 0)['rows']);
    }

    public function testEventValuesCollapseNullAndEmpty(): void
    {
        $this->insertEvent(['name' => 'click', 'value' => null]);
        $this->insertEvent(['name' => 'click', 'value' => '']);
        $this->insertEvent(['name' => 'click', 'value' => '007']);
        [$from, $to] = $this->range(date('Y-m-d'), date('Y-m-d'));

        $rows = \MintyMetrics\api_event_values('', $from, $to, 'click', 10, 0)['rows'];

        $this->assertCount(2, $rows);
        $this->assertSame(['value' => '(no value)', 'count' => 2, 'uniques' => 2], $rows[0]);
        $this->assertSame('007', $rows[1]['value'], 'Numeric-looking labels must stay strings');
    }

    public function testUtmLabelsStayStrings(): void
    {
        $this->insertHit(['utm_campaign' => '2024']);
        [$from, $to] = $this->range(date('Y-m-d'), date('Y-m-d'));

        $rows = \MintyMetrics\api_utm('', $from, $to, 'campaign', 10, 0)['rows'];

        $this->assertSame('2024', $rows[0]['name']);
    }

    public function testSitesIncludeSummaryOnlySites(): void
    {
        $this->insertSummary('2025-01-01', 'old-site.com', 1, 1);
        $this->assertContains('old-site.com', \MintyMetrics\api_sites()['sites']);
    }

    public function testLiveQueryUsesAnIndex(): void
    {
        foreach (['', " AND site = 'a.com'"] as $siteFilter) {
            $sql = \MintyMetrics\live_count_sql('created_at >= 1 AND (created_at >= 2 OR last_active_at >= 2)' . $siteFilter);
            $plan = '';
            $r = self::$testDb->query('EXPLAIN QUERY PLAN ' . $sql);
            while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
                $plan .= $row['detail'] . "\n";
            }
            // SQLite < 3.36 words plans as "SEARCH TABLE hits ..."
            $this->assertMatchesRegularExpression('/SEARCH (TABLE )?hits USING (COVERING )?INDEX/', $plan);
            $this->assertDoesNotMatchRegularExpression('/SCAN (TABLE )?hits/', $plan);
        }
    }

    public static function badRangeProvider(): array
    {
        return [
            'impossible date' => ['2026-99-99', '2026-01-01'],
            'reversed' => ['2026-02-01', '2026-01-01'],
            'too long' => ['0001-01-01', '9999-12-31'],
            'wrong format' => ['01/01/2026', '2026-01-02'],
        ];
    }

    #[DataProvider('badRangeProvider')]
    public function testDateRangeValidation(string $from, string $to): void
    {
        $this->assertArrayHasKey('error', \MintyMetrics\parse_date_range($from, $to));
    }

    public function testDateRangeCoversWholeLocalDays(): void
    {
        date_default_timezone_set('Europe/Sofia');
        $r = \MintyMetrics\parse_date_range('2026-03-29', '2026-03-29');
        $this->assertSame(23 * 3600 - 1, $r['toTs'] - $r['fromTs'], 'DST day is 23 hours');
    }
}
