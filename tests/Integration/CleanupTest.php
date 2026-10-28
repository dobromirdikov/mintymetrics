<?php

namespace MintyMetrics\Tests\Integration;

use MintyMetrics\Tests\IntegrationTestCase;

class CleanupTest extends IntegrationTestCase
{
    // ─── Day Summarization ──────────────────────────────────────────────

    public function testSummarizeDayCreatesRecord(): void
    {
        $date = '2025-06-15';
        $dayStart = strtotime($date . ' 00:00:00');

        $hash1 = hash('sha256', 'v1');
        $hash2 = hash('sha256', 'v2');

        $this->insertHit([
            'site' => 'test.com', 'visitor_hash' => $hash1,
            'page_path' => '/', 'browser' => 'Chrome', 'device_type' => 'desktop',
            'os' => 'Windows', 'country_code' => 'US', 'screen_res' => '1920x1080',
            'language' => 'en-US', 'time_on_page' => 30,
            'created_at' => $dayStart + 100,
        ]);
        $this->insertHit([
            'site' => 'test.com', 'visitor_hash' => $hash1,
            'page_path' => '/about', 'browser' => 'Chrome', 'device_type' => 'desktop',
            'os' => 'Windows', 'country_code' => 'US', 'screen_res' => '1920x1080',
            'language' => 'en-US', 'time_on_page' => 15,
            'created_at' => $dayStart + 200,
        ]);
        $this->insertHit([
            'site' => 'test.com', 'visitor_hash' => $hash2,
            'page_path' => '/', 'browser' => 'Firefox', 'device_type' => 'mobile',
            'os' => 'Android', 'country_code' => 'DE', 'screen_res' => '390x844',
            'language' => 'de-DE', 'time_on_page' => 45,
            'created_at' => $dayStart + 300,
        ]);

        \MintyMetrics\summarize_day($date, 'test.com');

        $summary = self::$testDb->querySingle(
            "SELECT * FROM daily_summaries WHERE date = '{$date}' AND site = 'test.com'",
            true
        );

        $this->assertNotFalse($summary);
        $this->assertSame(3, (int) $summary['pageviews']);
        $this->assertSame(2, (int) $summary['unique_visitors']);
    }

    public function testSummarizeDayBounceRate(): void
    {
        $date = '2025-07-01';
        $dayStart = strtotime($date . ' 00:00:00');

        $hash1 = hash('sha256', 'bounce_v1');
        $hash2 = hash('sha256', 'bounce_v2');
        $hash3 = hash('sha256', 'bounce_v3');

        // Visitor 1: multi-page (not a single-page visit)
        $this->insertHit(['site' => 'test.com', 'visitor_hash' => $hash1, 'page_path' => '/', 'created_at' => $dayStart + 100]);
        $this->insertHit(['site' => 'test.com', 'visitor_hash' => $hash1, 'page_path' => '/about', 'created_at' => $dayStart + 200]);

        // Visitor 2: single-page visit
        $this->insertHit(['site' => 'test.com', 'visitor_hash' => $hash2, 'page_path' => '/', 'created_at' => $dayStart + 300]);

        // Visitor 3: single-page visit
        $this->insertHit(['site' => 'test.com', 'visitor_hash' => $hash3, 'page_path' => '/blog', 'created_at' => $dayStart + 400]);

        \MintyMetrics\summarize_day($date, 'test.com');

        $summary = self::$testDb->querySingle(
            "SELECT bounce_rate FROM daily_summaries WHERE date = '{$date}' AND site = 'test.com'",
            true
        );

        // 2 out of 3 visitors had single-page visits = 0.6667
        $this->assertEqualsWithDelta(0.6667, (float) $summary['bounce_rate'], 0.001);
    }

    public function testSummarizeDayAvgTimeOnPage(): void
    {
        $date = '2025-07-02';
        $dayStart = strtotime($date . ' 00:00:00');

        $this->insertHit(['site' => 'test.com', 'time_on_page' => 20, 'created_at' => $dayStart + 100]);
        $this->insertHit(['site' => 'test.com', 'time_on_page' => 40, 'created_at' => $dayStart + 200]);
        $this->insertHit(['site' => 'test.com', 'time_on_page' => null, 'created_at' => $dayStart + 300]);

        \MintyMetrics\summarize_day($date, 'test.com');

        $summary = self::$testDb->querySingle(
            "SELECT avg_time_on_page FROM daily_summaries WHERE date = '{$date}' AND site = 'test.com'",
            true
        );

        // Average of 20 and 40 = 30 (null excluded)
        $this->assertEqualsWithDelta(30.0, (float) $summary['avg_time_on_page'], 0.1);
    }

    public function testSummarizeDayJsonColumns(): void
    {
        $date = '2025-07-03';
        $dayStart = strtotime($date . ' 00:00:00');

        $hash1 = hash('sha256', 'json_v1');

        $this->insertHit([
            'site' => 'test.com', 'visitor_hash' => $hash1,
            'page_path' => '/', 'browser' => 'Chrome', 'device_type' => 'desktop',
            'os' => 'Windows', 'country_code' => 'US', 'screen_res' => '1920x1080',
            'language' => 'en-US',
            'utm_source' => 'google', 'utm_medium' => 'organic',
            'created_at' => $dayStart + 100,
        ]);

        \MintyMetrics\summarize_day($date, 'test.com');

        $summary = self::$testDb->querySingle(
            "SELECT * FROM daily_summaries WHERE date = '{$date}' AND site = 'test.com'",
            true
        );

        // Verify JSON columns decode properly
        $topPages = json_decode($summary['top_pages'], true);
        $this->assertIsArray($topPages);
        $this->assertArrayHasKey('/', $topPages);

        $devices = json_decode($summary['devices'], true);
        $this->assertIsArray($devices);
        $this->assertArrayHasKey('desktop', $devices);

        $browsers = json_decode($summary['browsers'], true);
        $this->assertIsArray($browsers);
        $this->assertArrayHasKey('Chrome', $browsers);

        $countries = json_decode($summary['top_countries'], true);
        $this->assertIsArray($countries);
        $this->assertArrayHasKey('US', $countries);

        $utm = json_decode($summary['utm_summary'], true);
        $this->assertIsArray($utm);
        $this->assertArrayHasKey('google', $utm['source']);
    }

    public function testSummarizeDayIsIdempotent(): void
    {
        $date = '2025-07-04';
        $dayStart = strtotime($date . ' 00:00:00');

        $this->insertHit(['site' => 'test.com', 'created_at' => $dayStart + 100]);

        \MintyMetrics\summarize_day($date, 'test.com');
        \MintyMetrics\summarize_day($date, 'test.com'); // Second call

        $count = self::$testDb->querySingle(
            "SELECT COUNT(*) FROM daily_summaries WHERE date = '{$date}' AND site = 'test.com'"
        );
        $this->assertSame(1, $count);
    }

    public function testSummarizeDaySkipsEmptyDay(): void
    {
        $date = '2025-07-05';

        \MintyMetrics\summarize_day($date, 'test.com');

        $count = self::$testDb->querySingle(
            "SELECT COUNT(*) FROM daily_summaries WHERE date = '{$date}'"
        );
        $this->assertSame(0, $count, 'No summary should be created for empty days');
    }

    // ─── Cleanup Run ────────────────────────────────────────────────────

    public function testCleanupRunOncePerDay(): void
    {
        // Set retention to 1 day
        $this->setConfig('data_retention_days', '1');

        // Insert an old hit
        $oldTs = strtotime('-5 days');
        $this->insertHit(['created_at' => $oldTs, 'site' => 'test.com']);

        // First run should process
        \MintyMetrics\cleanup_run();

        $lastCleanup = $this->getConfig('last_cleanup');
        $this->assertSame(date('Y-m-d'), $lastCleanup);

        // Second run same day should skip (verify by checking the config is unchanged)
        \MintyMetrics\cleanup_run();
        $this->assertSame(date('Y-m-d'), $this->getConfig('last_cleanup'));
    }

    public function testCleanupPrunesOldHits(): void
    {
        $this->setConfig('data_retention_days', '1');

        // Old hit (5 days ago)
        $this->insertHit(['created_at' => strtotime('-5 days'), 'site' => 'test.com']);
        // Recent hit (now)
        $this->insertHit(['created_at' => time(), 'site' => 'test.com']);

        $this->assertSame(2, $this->countRows('hits'));

        \MintyMetrics\cleanup_run();

        // Old hit should be deleted, recent should remain
        $this->assertSame(1, $this->countRows('hits'));
    }

    public function testCleanupSummarizesBeforeDeleting(): void
    {
        $this->setConfig('data_retention_days', '1');

        $oldDate = date('Y-m-d', strtotime('-5 days'));
        $oldTs = strtotime($oldDate . ' 12:00:00');

        $this->insertHit(['created_at' => $oldTs, 'site' => 'test.com', 'page_path' => '/old-page']);

        \MintyMetrics\cleanup_run();

        // Summary should exist for that old date
        $summary = self::$testDb->querySingle(
            "SELECT pageviews FROM daily_summaries WHERE date = '{$oldDate}' AND site = 'test.com'",
            true
        );
        $this->assertNotFalse($summary);
        $this->assertSame(1, (int) $summary['pageviews']);
    }

    // ─── Logging ────────────────────────────────────────────────────────

    public function testLogErrorWritesToDb(): void
    {
        \MintyMetrics\log_error('Test error message');

        $row = self::$testDb->querySingle('SELECT * FROM mm_logs LIMIT 1', true);
        $this->assertNotFalse($row);
        $this->assertSame('error', $row['level']);
        $this->assertSame('Test error message', $row['message']);
    }

    public function testLogErrorWithCustomLevel(): void
    {
        \MintyMetrics\log_error('Warning message', 'warning');

        $row = self::$testDb->querySingle('SELECT * FROM mm_logs LIMIT 1', true);
        $this->assertSame('warning', $row['level']);
    }

    // ─── Events Pruning ─────────────────────────────────────────────────

    public function testCleanupPrunesOldEvents(): void
    {
        $retentionDays = (int) \MintyMetrics\get_config('data_retention_days', \MintyMetrics\DATA_RETENTION_DAYS);
        $cutoffTs = strtotime("-{$retentionDays} days");

        $oldTs = $cutoffTs - (3 * 86400);
        $recentTs = time() - 3600;

        $this->insertEvent(['name' => 'old_event', 'created_at' => $oldTs]);
        $this->insertEvent(['name' => 'recent_event', 'created_at' => $recentTs]);

        // cleanup_run() needs at least one expired hit to drive its loop, but the
        // event prune step runs unconditionally. Add one expired hit so summarize_day
        // is exercised but not strictly required for the events assertion.
        $this->insertHit(['page_path' => '/old', 'created_at' => $oldTs]);

        \MintyMetrics\cleanup_run();

        $remaining = self::$testDb->querySingle('SELECT COUNT(*) FROM events');
        $this->assertSame(1, (int) $remaining);

        $row = self::$testDb->querySingle('SELECT name FROM events LIMIT 1', true);
        $this->assertSame('recent_event', $row['name']);
    }

    // ─── Timezone & Boundary Regressions ────────────────────────────────

    /**
     * Run a callback with PHP's default timezone temporarily changed.
     */
    private function inTimezone(string $tz, callable $fn): void
    {
        $prev = date_default_timezone_get();
        date_default_timezone_set($tz);
        try {
            $fn();
        } finally {
            date_default_timezone_set($prev);
        }
    }

    public static function timezoneProvider(): array
    {
        return [
            'New York evening' => ['America/New_York', '20:00:00', '22:00:00'],
            'Sofia after midnight' => ['Europe/Sofia', '00:30:00', '01:30:00'],
            'UTC' => ['UTC', '10:00:00', '11:00:00'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('timezoneProvider')]
    public function testCleanupNeverDeletesUnsummarizedHitsInAnyTimezone(string $tz, string $t1, string $t2): void
    {
        $this->inTimezone($tz, function () use ($t1, $t2) {
            $this->setConfig('data_retention_days', '30');
            $day = date('Y-m-d', strtotime('today -40 days'));
            $this->insertHit(['created_at' => strtotime("{$day} {$t1}")]);
            $this->insertHit(['created_at' => strtotime("{$day} {$t2}")]);

            \MintyMetrics\cleanup_run();

            $this->assertSame(0, $this->countRows('hits'));
            $summary = self::$testDb->querySingle("SELECT date, pageviews FROM daily_summaries", true);
            $this->assertSame($day, $summary['date'], 'Summary must use the local day');
            $this->assertSame(2, (int) $summary['pageviews']);
        });
    }

    public function testCutoffIsMidnightSoBoundaryDayStaysRaw(): void
    {
        $this->inTimezone('Europe/Sofia', function () {
            $this->setConfig('data_retention_days', '10');
            $boundaryDay = date('Y-m-d', strtotime('today -10 days'));
            $this->insertHit(['created_at' => strtotime("{$boundaryDay} 00:10:00")]);
            $this->insertHit(['created_at' => strtotime("{$boundaryDay} 23:50:00")]);

            \MintyMetrics\cleanup_run();

            // The whole boundary day is kept raw; nothing is summarized or half-deleted
            $this->assertSame(2, $this->countRows('hits'));
            $this->assertSame(0, $this->countRows('daily_summaries'));
        });
    }

    public function testCleanupClaimPreventsSecondRunSameDay(): void
    {
        $db = self::$testDb;
        $today = date('Y-m-d');
        $this->assertTrue(\MintyMetrics\cleanup_claim($db, $today));
        $this->assertFalse(\MintyMetrics\cleanup_claim($db, $today));
        $this->assertSame($today, $this->getConfig('last_cleanup'));
    }

    public function testSizeLimitPruneSummarizesBeforeDeleting(): void
    {
        $this->setConfig('data_retention_days', '90');
        $this->setConfig('max_db_size_mb', '0');
        $day = date('Y-m-d', strtotime('today -45 days'));
        $this->insertHit(['created_at' => strtotime("{$day} 12:00:00")]);
        $this->insertHit(['created_at' => time() - 3600]);

        \MintyMetrics\cleanup_run();

        $this->assertSame(1, $this->countRows('hits'), 'Hits older than 30 days are pruned when over the size limit');
        $this->assertSame(1, (int) self::$testDb->querySingle(
            "SELECT pageviews FROM daily_summaries WHERE date = '{$day}'"
        ), 'Pruned days must be summarized first');
    }

    public function testFailedSummaryDoesNotDeleteHits(): void
    {
        $this->setConfig('data_retention_days', '30');
        $day = date('Y-m-d', strtotime('today -40 days'));
        $this->insertHit(['created_at' => strtotime("{$day} 12:00:00")]);

        // Make every summary insert fail
        self::$testDb->exec("CREATE TRIGGER fail_summary BEFORE INSERT ON daily_summaries
            BEGIN SELECT RAISE(ABORT, 'disk full'); END");

        \MintyMetrics\cleanup_run();

        $this->assertSame(1, $this->countRows('hits'), 'Hits must survive a failed summary');
        $this->assertSame('', $this->getConfig('last_cleanup'), 'Failed run must be retried on next load');
        $this->assertSame(1, $this->countRows('mm_logs', "message LIKE 'cleanup_run:%'"));
    }

    public function testTopPagesKeepMostViewedPage(): void
    {
        $day = '2025-06-15';
        $start = strtotime("{$day} 00:00:00");
        // 50 pages with 2 visitors each, and one page with 1 visitor but 100 views
        for ($p = 0; $p < 50; $p++) {
            $this->insertHit(['visitor_hash' => "a{$p}", 'page_path' => "/p{$p}", 'created_at' => $start + $p]);
            $this->insertHit(['visitor_hash' => "b{$p}", 'page_path' => "/p{$p}", 'created_at' => $start + $p]);
        }
        for ($i = 0; $i < 100; $i++) {
            $this->insertHit(['visitor_hash' => 'reader', 'page_path' => '/popular', 'created_at' => $start + 100 + $i]);
        }

        \MintyMetrics\summarize_day($day, 'test.com');

        $pages = json_decode(self::$testDb->querySingle("SELECT top_pages FROM daily_summaries WHERE date = '{$day}'"), true);
        $this->assertSame([100, 1], $pages['/popular']);
    }

    public function testUtmSummaryKeepsDistinctVisitorsPerField(): void
    {
        $day = '2025-06-15';
        $start = strtotime("{$day} 00:00:00");
        // One visitor, one source, two campaigns
        $this->insertHit(['visitor_hash' => 'v', 'utm_source' => 'news', 'utm_campaign' => 'a', 'created_at' => $start + 1]);
        $this->insertHit(['visitor_hash' => 'v', 'utm_source' => 'news', 'utm_campaign' => 'b', 'created_at' => $start + 2]);

        \MintyMetrics\summarize_day($day, 'test.com');
        $r = \MintyMetrics\parse_date_range($day, $day);
        $rows = \MintyMetrics\api_utm('', $r['fromTs'], $r['toTs'], 'source', 10, 0)['rows'];

        $this->assertSame([['name' => 'news', 'visitors' => 1, 'views' => 2]], $rows);
    }

    public function testCleanupFoldsLeftoverRawHitsIntoSummarizedDays(): void
    {
        // Legacy state: site A was summarized for a recent day, site B's hits that day were not
        $this->setConfig('data_retention_days', '90');
        $day = date('Y-m-d', strtotime('today -5 days'));
        self::$testDb->exec("INSERT INTO daily_summaries (date, site, pageviews, unique_visitors, visitor_days)
            VALUES ('{$day}', 'a.com', 3, 3, 3)");
        $this->insertHit(['site' => 'a.com', 'created_at' => strtotime("{$day} 22:00:00")]);
        $this->insertHit(['site' => 'b.com', 'created_at' => strtotime("{$day} 12:00:00")]);
        $this->insertHit(['site' => 'b.com', 'created_at' => strtotime('today 00:10:00')]);

        \MintyMetrics\cleanup_run();

        $this->assertSame(3, (int) self::$testDb->querySingle("SELECT pageviews FROM daily_summaries WHERE date = '{$day}' AND site = 'a.com'"));
        $this->assertSame(1, (int) self::$testDb->querySingle("SELECT pageviews FROM daily_summaries WHERE date = '{$day}' AND site = 'b.com'"));
        $this->assertSame(1, $this->countRows('hits'), 'Only the hit after the summarized range stays raw');

        $r = \MintyMetrics\parse_date_range($day, date('Y-m-d'));
        $this->assertSame(5, \MintyMetrics\api_summary('', $r['fromTs'], $r['toTs'])['pageviews']);
    }

    public function testSummaryStoresViewsAndVisitorsAndCounters(): void
    {
        $day = '2025-06-15';
        $start = strtotime("{$day} 00:00:00");
        $this->insertHit(['visitor_hash' => 'a', 'page_path' => '/', 'time_on_page' => 10, 'created_at' => $start + 10]);
        $this->insertHit(['visitor_hash' => 'a', 'page_path' => '/', 'time_on_page' => 20, 'created_at' => $start + 20]);
        $this->insertHit(['visitor_hash' => 'b', 'page_path' => '/x', 'created_at' => $start + 30]);

        \MintyMetrics\summarize_day($day, 'test.com');

        $row = self::$testDb->querySingle("SELECT * FROM daily_summaries WHERE date = '{$day}'", true);
        $this->assertSame(['/' => [2, 1], '/x' => [1, 1]], json_decode($row['top_pages'], true));
        $this->assertSame(2, (int) $row['visitor_days']);
        $this->assertSame(1, (int) $row['bounced']);
        $this->assertEqualsWithDelta(30.0, (float) $row['time_sum'], 0.01);
        $this->assertSame(2, (int) $row['time_count']);
    }
}
