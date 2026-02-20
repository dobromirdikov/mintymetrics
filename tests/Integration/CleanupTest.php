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
        $this->assertSame('google', $utm[0]['source']);
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
}
