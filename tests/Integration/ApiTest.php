<?php

namespace MintyMetrics\Tests\Integration;

use MintyMetrics\Tests\IntegrationTestCase;

class ApiTest extends IntegrationTestCase
{
    private int $todayTs;
    private int $yesterdayTs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->todayTs = strtotime(date('Y-m-d') . ' 12:00:00');
        $this->yesterdayTs = $this->todayTs - 86400;

        // Seed test data
        $this->seedHits();

        // Set data retention far in the past so all queries use raw hits
        $this->setConfig('data_retention_days', '365');
    }

    private function seedHits(): void
    {
        $hash1 = hash('sha256', 'visitor_1');
        $hash2 = hash('sha256', 'visitor_2');
        $hash3 = hash('sha256', 'visitor_3');

        // Visitor 1: 3 pages today
        $this->insertHit([
            'visitor_hash' => $hash1, 'site' => 'test.com',
            'page_path' => '/', 'browser' => 'Chrome', 'device_type' => 'desktop',
            'os' => 'Windows', 'country_code' => 'US', 'screen_res' => '1920x1080',
            'language' => 'en-US', 'referrer_domain' => 'google.com',
            'utm_source' => 'google', 'utm_medium' => 'organic',
            'time_on_page' => 30, 'created_at' => $this->todayTs,
        ]);
        $this->insertHit([
            'visitor_hash' => $hash1, 'site' => 'test.com',
            'page_path' => '/about', 'browser' => 'Chrome', 'device_type' => 'desktop',
            'os' => 'Windows', 'country_code' => 'US', 'screen_res' => '1920x1080',
            'language' => 'en-US', 'time_on_page' => 15,
            'created_at' => $this->todayTs + 60,
        ]);
        $this->insertHit([
            'visitor_hash' => $hash1, 'site' => 'test.com',
            'page_path' => '/contact', 'browser' => 'Chrome', 'device_type' => 'desktop',
            'os' => 'Windows', 'country_code' => 'US', 'screen_res' => '1920x1080',
            'language' => 'en-US', 'time_on_page' => 10,
            'created_at' => $this->todayTs + 120,
        ]);

        // Visitor 2: 1 page today (single-page visit)
        $this->insertHit([
            'visitor_hash' => $hash2, 'site' => 'test.com',
            'page_path' => '/', 'browser' => 'Firefox', 'device_type' => 'mobile',
            'os' => 'Android', 'country_code' => 'DE', 'screen_res' => '390x844',
            'language' => 'de-DE', 'referrer_domain' => 'twitter.com',
            'utm_source' => 'twitter', 'utm_medium' => 'social',
            'time_on_page' => 5, 'created_at' => $this->todayTs + 200,
        ]);

        // Visitor 3: yesterday, 1 page
        $this->insertHit([
            'visitor_hash' => $hash3, 'site' => 'test.com',
            'page_path' => '/blog', 'browser' => 'Safari', 'device_type' => 'tablet',
            'os' => 'iOS', 'country_code' => 'GB', 'screen_res' => '1024x768',
            'language' => 'en-GB',
            'time_on_page' => 60, 'created_at' => $this->yesterdayTs,
        ]);
    }

    // ─── Summary API ────────────────────────────────────────────────────

    public function testApiSummaryPageviews(): void
    {
        $fromTs = $this->yesterdayTs - 1;
        $toTs = $this->todayTs + 3600;

        $result = \MintyMetrics\api_summary('test.com', $fromTs, $toTs);

        $this->assertSame(5, $result['pageviews']);
        $this->assertSame(3, $result['uniques']);
    }

    public function testApiSummaryTodayOnly(): void
    {
        $fromTs = strtotime(date('Y-m-d') . ' 00:00:00');
        $toTs = strtotime(date('Y-m-d') . ' 23:59:59');

        $result = \MintyMetrics\api_summary('test.com', $fromTs, $toTs);

        $this->assertSame(4, $result['pageviews']);
        $this->assertSame(2, $result['uniques']);
    }

    public function testApiSummaryIncludesAvgTime(): void
    {
        $fromTs = $this->yesterdayTs - 1;
        $toTs = $this->todayTs + 3600;

        $result = \MintyMetrics\api_summary('test.com', $fromTs, $toTs);

        $this->assertNotNull($result['avg_time']);
        // Average of 30, 15, 10, 5, 60 = 24
        $this->assertEquals(24, $result['avg_time']);
    }

    public function testApiSummaryEmptySite(): void
    {
        $result = \MintyMetrics\api_summary('nonexistent.com', time() - 86400, time());

        $this->assertSame(0, $result['pageviews']);
        $this->assertSame(0, $result['uniques']);
    }

    // ─── Chart API ──────────────────────────────────────────────────────

    public function testApiChartReturnsDayByDayData(): void
    {
        $from = date('Y-m-d', $this->yesterdayTs);
        $to = date('Y-m-d', $this->todayTs);
        $fromTs = strtotime($from . ' 00:00:00');
        $toTs = strtotime($to . ' 23:59:59');

        $result = \MintyMetrics\api_chart('test.com', $from, $to, $fromTs, $toTs);

        $this->assertArrayHasKey('days', $result);
        $this->assertCount(2, $result['days']); // 2 days

        // Yesterday: 1 pageview, 1 unique
        $this->assertSame(1, $result['days'][0]['pageviews']);
        $this->assertSame(1, $result['days'][0]['uniques']);

        // Today: 4 pageviews, 2 uniques
        $this->assertSame(4, $result['days'][1]['pageviews']);
        $this->assertSame(2, $result['days'][1]['uniques']);
    }

    public function testApiChartFillsZeroDays(): void
    {
        $from = date('Y-m-d', $this->todayTs - 86400 * 3);
        $to = date('Y-m-d', $this->todayTs);
        $fromTs = strtotime($from . ' 00:00:00');
        $toTs = strtotime($to . ' 23:59:59');

        $result = \MintyMetrics\api_chart('test.com', $from, $to, $fromTs, $toTs);

        // Should have 4 days even if some have zero data
        $this->assertCount(4, $result['days']);
    }

    // ─── Pages API ──────────────────────────────────────────────────────

    public function testApiPagesRankedByViews(): void
    {
        $fromTs = $this->yesterdayTs - 1;
        $toTs = $this->todayTs + 3600;

        $result = \MintyMetrics\api_pages('test.com', $fromTs, $toTs, 50, 0);

        $this->assertArrayHasKey('rows', $result);
        // Seed data has 4 distinct pages: /, /about, /contact, /blog
        $this->assertCount(4, $result['rows']);

        // "/" should be first (2 hits from visitors 1 and 2)
        $this->assertSame('/', $result['rows'][0]['page_path']);
        $this->assertSame(2, $result['rows'][0]['views']);
    }

    public function testApiPagesWithLimit(): void
    {
        $fromTs = $this->yesterdayTs - 1;
        $toTs = $this->todayTs + 3600;

        $result = \MintyMetrics\api_pages('test.com', $fromTs, $toTs, 2, 0);
        $this->assertLessThanOrEqual(2, count($result['rows']));
    }

    // ─── Referrers API ──────────────────────────────────────────────────

    public function testApiReferrers(): void
    {
        $fromTs = $this->yesterdayTs - 1;
        $toTs = $this->todayTs + 3600;

        $result = \MintyMetrics\api_referrers('test.com', $fromTs, $toTs, 50, 0);

        $this->assertArrayHasKey('rows', $result);
        $domains = array_column($result['rows'], 'domain');
        $this->assertContains('google.com', $domains);
        $this->assertContains('twitter.com', $domains);
    }

    // ─── UTM API ────────────────────────────────────────────────────────

    public function testApiUtmBySource(): void
    {
        $fromTs = $this->yesterdayTs - 1;
        $toTs = $this->todayTs + 3600;

        $result = \MintyMetrics\api_utm('test.com', $fromTs, $toTs, 'source', 50, 0);

        $this->assertArrayHasKey('rows', $result);
        $this->assertSame('source', $result['group']);

        $sources = array_column($result['rows'], 'name');
        $this->assertContains('google', $sources);
        $this->assertContains('twitter', $sources);
    }

    public function testApiUtmByMedium(): void
    {
        $fromTs = $this->yesterdayTs - 1;
        $toTs = $this->todayTs + 3600;

        $result = \MintyMetrics\api_utm('test.com', $fromTs, $toTs, 'medium', 50, 0);
        $this->assertSame('medium', $result['group']);

        $mediums = array_column($result['rows'], 'name');
        $this->assertContains('organic', $mediums);
        $this->assertContains('social', $mediums);
    }

    // ─── Devices API ────────────────────────────────────────────────────

    public function testApiDevicesByType(): void
    {
        $fromTs = $this->yesterdayTs - 1;
        $toTs = $this->todayTs + 3600;

        $result = \MintyMetrics\api_devices('test.com', $fromTs, $toTs, 'type');

        $this->assertArrayHasKey('rows', $result);
        $names = array_column($result['rows'], 'name');
        $this->assertContains('desktop', $names);
        $this->assertContains('mobile', $names);
        $this->assertContains('tablet', $names);
    }

    public function testApiDevicesByBrowser(): void
    {
        $fromTs = $this->yesterdayTs - 1;
        $toTs = $this->todayTs + 3600;

        $result = \MintyMetrics\api_devices('test.com', $fromTs, $toTs, 'browser');
        $names = array_column($result['rows'], 'name');
        $this->assertContains('Chrome', $names);
        $this->assertContains('Firefox', $names);
        $this->assertContains('Safari', $names);
    }

    public function testApiDevicesByOs(): void
    {
        $fromTs = $this->yesterdayTs - 1;
        $toTs = $this->todayTs + 3600;

        $result = \MintyMetrics\api_devices('test.com', $fromTs, $toTs, 'os');
        $names = array_column($result['rows'], 'name');
        $this->assertContains('Windows', $names);
        $this->assertContains('Android', $names);
        $this->assertContains('iOS', $names);
    }

    // ─── Countries API ──────────────────────────────────────────────────

    public function testApiCountries(): void
    {
        $fromTs = $this->yesterdayTs - 1;
        $toTs = $this->todayTs + 3600;

        $result = \MintyMetrics\api_countries('test.com', $fromTs, $toTs, 50, 0);

        $this->assertArrayHasKey('rows', $result);
        $codes = array_column($result['rows'], 'code');
        $this->assertContains('US', $codes);
        $this->assertContains('DE', $codes);
        $this->assertContains('GB', $codes);
    }

    // ─── Screens API ────────────────────────────────────────────────────

    public function testApiScreens(): void
    {
        $fromTs = $this->yesterdayTs - 1;
        $toTs = $this->todayTs + 3600;

        $result = \MintyMetrics\api_screens('test.com', $fromTs, $toTs, 50, 0);

        $this->assertArrayHasKey('rows', $result);
        $resolutions = array_column($result['rows'], 'resolution');
        $this->assertContains('1920x1080', $resolutions);
    }

    // ─── Languages API ──────────────────────────────────────────────────

    public function testApiLanguages(): void
    {
        $fromTs = $this->yesterdayTs - 1;
        $toTs = $this->todayTs + 3600;

        $result = \MintyMetrics\api_languages('test.com', $fromTs, $toTs, 50, 0);

        $this->assertArrayHasKey('rows', $result);
        $langs = array_column($result['rows'], 'lang');
        $this->assertContains('en-US', $langs);
        $this->assertContains('de-DE', $langs);
    }

    // ─── Live Visitors API ──────────────────────────────────────────────

    public function testApiLiveCountsRecentVisitors(): void
    {
        // Use a unique site to isolate from seed data
        $this->insertHit([
            'visitor_hash' => hash('sha256', 'live_visitor'),
            'site' => 'live-recent.com',
            'created_at' => time() - 60, // 1 minute ago
        ]);

        $result = \MintyMetrics\api_live('live-recent.com');

        $this->assertArrayHasKey('count', $result);
        $this->assertSame(1, $result['count']);
    }

    public function testApiLiveExcludesOldHits(): void
    {
        // Only hits from 1 hour ago (outside the 5-minute window)
        $result = \MintyMetrics\api_live('old-site.com');
        $this->assertSame(0, $result['count']);
    }

    public function testApiLiveCountsVisitorsWithLastActiveAt(): void
    {
        // Hit created 10 minutes ago (outside default 5-min window)
        // but last_active_at is 1 minute ago (inside window)
        $this->insertHit([
            'visitor_hash' => hash('sha256', 'active_visitor'),
            'site' => 'live-test.com',
            'created_at' => time() - 600,
            'last_active_at' => time() - 60,
        ]);

        $result = \MintyMetrics\api_live('live-test.com');
        $this->assertSame(1, $result['count']);
    }

    public function testApiLiveIgnoresOldLastActiveAt(): void
    {
        // Both created_at and last_active_at are older than 5 minutes
        $this->insertHit([
            'visitor_hash' => hash('sha256', 'stale_visitor'),
            'site' => 'stale-test.com',
            'created_at' => time() - 600,
            'last_active_at' => time() - 400,
        ]);

        $result = \MintyMetrics\api_live('stale-test.com');
        $this->assertSame(0, $result['count']);
    }

    public function testApiLiveCountsDistinctHashes(): void
    {
        $now = time();
        $hash1 = hash('sha256', 'distinct_visitor_1');
        $hash2 = hash('sha256', 'distinct_visitor_2');

        // 3 hits from 2 distinct visitors, all within live window
        $this->insertHit([
            'visitor_hash' => $hash1, 'site' => 'distinct-test.com',
            'page_path' => '/page1', 'created_at' => $now - 60,
        ]);
        $this->insertHit([
            'visitor_hash' => $hash1, 'site' => 'distinct-test.com',
            'page_path' => '/page2', 'created_at' => $now - 30,
        ]);
        $this->insertHit([
            'visitor_hash' => $hash2, 'site' => 'distinct-test.com',
            'page_path' => '/page1', 'created_at' => $now - 45,
        ]);

        $result = \MintyMetrics\api_live('distinct-test.com');
        $this->assertSame(2, $result['count']);
    }

    public function testApiLiveRespectsConfigurableWindow(): void
    {
        // Insert a hit 3 minutes ago
        $this->insertHit([
            'visitor_hash' => hash('sha256', 'configurable_visitor'),
            'site' => 'config-test.com',
            'created_at' => time() - 180,
        ]);

        // Set window to 2 minutes — hit at 3 min ago should NOT be counted
        \MintyMetrics\set_config('live_visitor_minutes', '2');
        $result = \MintyMetrics\api_live('config-test.com');
        $this->assertSame(0, $result['count']);

        // Set window to 10 minutes — hit at 3 min ago should be counted
        \MintyMetrics\set_config('live_visitor_minutes', '10');
        $result = \MintyMetrics\api_live('config-test.com');
        $this->assertSame(1, $result['count']);
    }

    public function testApiLiveDefaultWindowIsFiveMinutes(): void
    {
        // Hit 4 minutes ago (inside default 5-min window)
        $this->insertHit([
            'visitor_hash' => hash('sha256', 'default_window_in'),
            'site' => 'default-window.com',
            'created_at' => time() - 240,
        ]);
        // Hit 6 minutes ago (outside default 5-min window)
        $this->insertHit([
            'visitor_hash' => hash('sha256', 'default_window_out'),
            'site' => 'default-window.com',
            'created_at' => time() - 360,
        ]);

        // No config override — should use default 5 minutes
        $result = \MintyMetrics\api_live('default-window.com');
        $this->assertSame(1, $result['count']);
    }

    public function testApiLiveFiltersBySite(): void
    {
        $now = time();

        $this->insertHit([
            'visitor_hash' => hash('sha256', 'site_a_visitor'),
            'site' => 'site-a-live.com',
            'created_at' => $now - 60,
        ]);
        $this->insertHit([
            'visitor_hash' => hash('sha256', 'site_b_visitor'),
            'site' => 'site-b-live.com',
            'created_at' => $now - 60,
        ]);

        $resultA = \MintyMetrics\api_live('site-a-live.com');
        $resultB = \MintyMetrics\api_live('site-b-live.com');

        $this->assertSame(1, $resultA['count']);
        $this->assertSame(1, $resultB['count']);
    }

    public function testApiLiveBoundaryExactlyAtThreshold(): void
    {
        // Hit created at exactly the threshold boundary (5 minutes ago)
        // The query is created_at >= :since, so exactly-at-threshold should be included
        $this->insertHit([
            'visitor_hash' => hash('sha256', 'boundary_visitor'),
            'site' => 'boundary-test.com',
            'created_at' => time() - (5 * 60), // Exactly 5 minutes ago
        ]);

        $result = \MintyMetrics\api_live('boundary-test.com');
        $this->assertSame(1, $result['count'], 'Hit at exact threshold boundary should be included (>=)');
    }

    public function testApiLiveBoundaryOneSecondPastThreshold(): void
    {
        // Hit created 1 second past the threshold (5 min + 1 sec ago)
        $this->insertHit([
            'visitor_hash' => hash('sha256', 'past_boundary_visitor'),
            'site' => 'past-boundary.com',
            'created_at' => time() - (5 * 60) - 1,
        ]);

        $result = \MintyMetrics\api_live('past-boundary.com');
        $this->assertSame(0, $result['count'], 'Hit 1 second past threshold should be excluded');
    }

    public function testApiLiveLastActiveAtBoundaryExact(): void
    {
        // created_at is old, last_active_at is exactly at threshold
        $this->insertHit([
            'visitor_hash' => hash('sha256', 'active_boundary_visitor'),
            'site' => 'active-boundary.com',
            'created_at' => time() - 600,
            'last_active_at' => time() - (5 * 60), // Exactly 5 minutes ago
        ]);

        $result = \MintyMetrics\api_live('active-boundary.com');
        $this->assertSame(1, $result['count'], 'last_active_at at exact threshold should be included (>=)');
    }

    public function testApiLiveMultiSiteWithLastActiveAt(): void
    {
        $now = time();

        // Site A: old created_at, recent last_active_at
        $this->insertHit([
            'visitor_hash' => hash('sha256', 'multi_site_a'),
            'site' => 'multi-a.com',
            'created_at' => $now - 600,
            'last_active_at' => $now - 30,
        ]);

        // Site B: old everything
        $this->insertHit([
            'visitor_hash' => hash('sha256', 'multi_site_b'),
            'site' => 'multi-b.com',
            'created_at' => $now - 600,
            'last_active_at' => $now - 400,
        ]);

        $resultA = \MintyMetrics\api_live('multi-a.com');
        $resultB = \MintyMetrics\api_live('multi-b.com');

        $this->assertSame(1, $resultA['count'], 'Site A visitor with recent last_active_at should count');
        $this->assertSame(0, $resultB['count'], 'Site B visitor with old last_active_at should not count');
    }

    // ─── Sites API ──────────────────────────────────────────────────────

    public function testApiSitesListsTrackedDomains(): void
    {
        $result = \MintyMetrics\api_sites();

        $this->assertArrayHasKey('sites', $result);
        $this->assertContains('test.com', $result['sites']);
    }
}
