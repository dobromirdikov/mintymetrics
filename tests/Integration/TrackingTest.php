<?php

namespace MintyMetrics\Tests\Integration;

use MintyMetrics\Tests\IntegrationTestCase;

class TrackingTest extends IntegrationTestCase
{
    // ─── Hit Insertion Integrity ────────────────────────────────────────

    public function testHitStoredWithAllFields(): void
    {
        $now = time();
        $this->insertHit([
            'site' => 'example.com',
            'page_path' => '/blog/post-1',
            'referrer_url' => 'https://google.com/search?q=test',
            'referrer_domain' => 'google.com',
            'utm_source' => 'google',
            'utm_medium' => 'organic',
            'utm_campaign' => 'summer',
            'utm_term' => 'analytics',
            'utm_content' => 'link1',
            'country_code' => 'US',
            'device_type' => 'desktop',
            'browser' => 'Chrome',
            'browser_ver' => '120.0',
            'os' => 'Windows',
            'os_ver' => '10.0',
            'screen_res' => '1920x1080',
            'language' => 'en-US',
            'time_on_page' => 45,
            'created_at' => $now,
        ]);

        $row = self::$testDb->querySingle('SELECT * FROM hits LIMIT 1', true);

        $this->assertSame('example.com', $row['site']);
        $this->assertSame('/blog/post-1', $row['page_path']);
        $this->assertSame('https://google.com/search?q=test', $row['referrer_url']);
        $this->assertSame('google.com', $row['referrer_domain']);
        $this->assertSame('google', $row['utm_source']);
        $this->assertSame('organic', $row['utm_medium']);
        $this->assertSame('summer', $row['utm_campaign']);
        $this->assertSame('analytics', $row['utm_term']);
        $this->assertSame('link1', $row['utm_content']);
        $this->assertSame('US', $row['country_code']);
        $this->assertSame('desktop', $row['device_type']);
        $this->assertSame('Chrome', $row['browser']);
        $this->assertSame('120.0', $row['browser_ver']);
        $this->assertSame('Windows', $row['os']);
        $this->assertSame('10.0', $row['os_ver']);
        $this->assertSame('1920x1080', $row['screen_res']);
        $this->assertSame('en-US', $row['language']);
        $this->assertSame(45, (int) $row['time_on_page']);
        $this->assertSame($now, (int) $row['created_at']);
    }

    public function testHitStoredWithNullOptionalFields(): void
    {
        $this->insertHit([
            'referrer_url' => null,
            'referrer_domain' => null,
            'utm_source' => null,
            'country_code' => null,
            'time_on_page' => null,
        ]);

        $row = self::$testDb->querySingle('SELECT * FROM hits LIMIT 1', true);
        $this->assertNull($row['referrer_url']);
        $this->assertNull($row['referrer_domain']);
        $this->assertNull($row['utm_source']);
        $this->assertNull($row['country_code']);
        $this->assertNull($row['time_on_page']);
    }

    // ─── Multiple Sites ─────────────────────────────────────────────────

    public function testHitsFromMultipleSites(): void
    {
        $this->insertHit(['site' => 'site-a.com']);
        $this->insertHit(['site' => 'site-a.com']);
        $this->insertHit(['site' => 'site-b.com']);

        $this->assertSame(3, $this->countRows('hits'));
        $this->assertSame(2, $this->countRows('hits', "site = 'site-a.com'"));
        $this->assertSame(1, $this->countRows('hits', "site = 'site-b.com'"));
    }

    // ─── Unique Visitor Counting ────────────────────────────────────────

    public function testUniqueVisitorsByHash(): void
    {
        $hash1 = hash('sha256', 'visitor_1');
        $hash2 = hash('sha256', 'visitor_2');

        // Same visitor, multiple pages
        $this->insertHit(['visitor_hash' => $hash1, 'page_path' => '/page1']);
        $this->insertHit(['visitor_hash' => $hash1, 'page_path' => '/page2']);
        // Different visitor
        $this->insertHit(['visitor_hash' => $hash2, 'page_path' => '/page1']);

        $uniqueCount = (int) self::$testDb->querySingle('SELECT COUNT(DISTINCT visitor_hash) FROM hits');
        $this->assertSame(2, $uniqueCount);
    }

    // ─── Time-on-Page ───────────────────────────────────────────────────

    public function testTimeOnPageUpdate(): void
    {
        $hash = hash('sha256', 'beacon_visitor');
        $todayStart = strtotime(date('Y-m-d'));

        // Insert hit without time_on_page
        $this->insertHit([
            'visitor_hash' => $hash,
            'site' => 'test.com',
            'page_path' => '/home',
            'time_on_page' => null,
            'created_at' => $todayStart + 100,
        ]);

        // Simulate beacon update
        $stmt = self::$testDb->prepare('
            UPDATE hits SET time_on_page = :time
            WHERE id = (
                SELECT id FROM hits
                WHERE visitor_hash = :hash AND site = :site AND page_path = :page
                  AND created_at >= :today_start
                ORDER BY created_at DESC LIMIT 1
            )
        ');
        $stmt->bindValue(':time', 30, SQLITE3_INTEGER);
        $stmt->bindValue(':hash', $hash, SQLITE3_TEXT);
        $stmt->bindValue(':site', 'test.com', SQLITE3_TEXT);
        $stmt->bindValue(':page', '/home', SQLITE3_TEXT);
        $stmt->bindValue(':today_start', $todayStart, SQLITE3_INTEGER);
        $stmt->execute();

        $row = self::$testDb->querySingle('SELECT time_on_page FROM hits LIMIT 1', true);
        $this->assertSame(30, (int) $row['time_on_page']);
    }

    // ─── Beacon & last_active_at ────────────────────────────────────────

    public function testBeaconUpdatesLastActiveAt(): void
    {
        $hash = hash('sha256', 'beacon_active_visitor');
        $todayStart = strtotime(date('Y-m-d'));

        // Insert hit without last_active_at
        $this->insertHit([
            'visitor_hash' => $hash,
            'site' => 'test.com',
            'page_path' => '/beacon-test',
            'time_on_page' => null,
            'last_active_at' => null,
            'created_at' => $todayStart + 100,
        ]);

        // Simulate the beacon UPDATE (same query as track_beacon)
        $now = time();
        $stmt = self::$testDb->prepare('
            UPDATE hits SET time_on_page = :time, last_active_at = :now
            WHERE id = (
                SELECT id FROM hits
                WHERE visitor_hash = :hash AND site = :site AND page_path = :page
                  AND created_at >= :today_start
                ORDER BY created_at DESC LIMIT 1
            )
        ');
        $stmt->bindValue(':time', 25, SQLITE3_INTEGER);
        $stmt->bindValue(':now', $now, SQLITE3_INTEGER);
        $stmt->bindValue(':hash', $hash, SQLITE3_TEXT);
        $stmt->bindValue(':site', 'test.com', SQLITE3_TEXT);
        $stmt->bindValue(':page', '/beacon-test', SQLITE3_TEXT);
        $stmt->bindValue(':today_start', $todayStart, SQLITE3_INTEGER);
        $stmt->execute();

        $row = self::$testDb->querySingle('SELECT time_on_page, last_active_at FROM hits LIMIT 1', true);
        $this->assertSame(25, (int) $row['time_on_page']);
        $this->assertSame($now, (int) $row['last_active_at']);
    }

    public function testLastActiveAtDefaultsToNull(): void
    {
        $this->insertHit([
            'page_path' => '/null-active-test',
        ]);

        $row = self::$testDb->querySingle('SELECT last_active_at FROM hits LIMIT 1', true);
        $this->assertNull($row['last_active_at']);
    }

    // ─── Date Range Filtering ───────────────────────────────────────────

    public function testHitsFilteredByDateRange(): void
    {
        $now = time();
        $yesterday = $now - 86400;
        $twoDaysAgo = $now - 172800;

        $this->insertHit(['created_at' => $twoDaysAgo, 'page_path' => '/old']);
        $this->insertHit(['created_at' => $yesterday, 'page_path' => '/yesterday']);
        $this->insertHit(['created_at' => $now, 'page_path' => '/today']);

        // Query only recent hits
        $stmt = self::$testDb->prepare('SELECT COUNT(*) FROM hits WHERE created_at >= :since');
        $stmt->bindValue(':since', $yesterday, SQLITE3_INTEGER);
        $count = (int) $stmt->execute()->fetchArray()[0];

        $this->assertSame(2, $count);
    }

    // ─── Rate Limiting Table ────────────────────────────────────────────

    public function testRateLimitInsert(): void
    {
        $ipHash = hash('sha256', '192.168.1.1');
        $now = microtime(true);

        $stmt = self::$testDb->prepare('INSERT OR REPLACE INTO rate_limit (ip_hash, last_hit_at) VALUES (:hash, :now)');
        $stmt->bindValue(':hash', $ipHash, SQLITE3_TEXT);
        $stmt->bindValue(':now', $now, SQLITE3_FLOAT);
        $stmt->execute();

        $this->assertSame(1, $this->countRows('rate_limit'));

        // Upsert same IP
        $stmt = self::$testDb->prepare('INSERT OR REPLACE INTO rate_limit (ip_hash, last_hit_at) VALUES (:hash, :now)');
        $stmt->bindValue(':hash', $ipHash, SQLITE3_TEXT);
        $stmt->bindValue(':now', $now + 1, SQLITE3_FLOAT);
        $stmt->execute();

        $this->assertSame(1, $this->countRows('rate_limit'), 'Upsert should not create duplicates');
    }

    // ─── Device/Browser Grouping ────────────────────────────────────────

    public function testDeviceTypeGrouping(): void
    {
        $this->insertHit(['device_type' => 'desktop']);
        $this->insertHit(['device_type' => 'desktop']);
        $this->insertHit(['device_type' => 'mobile']);
        $this->insertHit(['device_type' => 'tablet']);

        $result = self::$testDb->query('SELECT device_type, COUNT(*) as cnt FROM hits GROUP BY device_type ORDER BY cnt DESC');
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[$row['device_type']] = (int) $row['cnt'];
        }

        $this->assertSame(2, $rows['desktop']);
        $this->assertSame(1, $rows['mobile']);
        $this->assertSame(1, $rows['tablet']);
    }

    // ─── Events: Schema Integrity ───────────────────────────────────────

    public function testEventStoredWithAllFields(): void
    {
        $now = time();
        $this->insertEvent([
            'site' => 'app.example.com',
            'name' => 'export',
            'value' => 'stl-binary',
            'props' => '{"scope":"scene"}',
            'page_path' => '/editor',
            'country_code' => 'DE',
            'device_type' => 'desktop',
            'browser' => 'Firefox',
            'os' => 'Linux',
            'created_at' => $now,
        ]);

        $row = self::$testDb->querySingle('SELECT * FROM events LIMIT 1', true);

        $this->assertSame('app.example.com', $row['site']);
        $this->assertSame('export', $row['name']);
        $this->assertSame('stl-binary', $row['value']);
        $this->assertSame('{"scope":"scene"}', $row['props']);
        $this->assertSame('/editor', $row['page_path']);
        $this->assertSame('DE', $row['country_code']);
        $this->assertSame('desktop', $row['device_type']);
        $this->assertSame('Firefox', $row['browser']);
        $this->assertSame('Linux', $row['os']);
        $this->assertSame($now, (int) $row['created_at']);
    }

    public function testEventStoredWithNullOptionalFields(): void
    {
        $this->insertEvent(['name' => 'open_project']);

        $row = self::$testDb->querySingle('SELECT * FROM events LIMIT 1', true);
        $this->assertSame('open_project', $row['name']);
        $this->assertNull($row['value']);
        $this->assertNull($row['props']);
        $this->assertNull($row['page_path']);
        $this->assertNull($row['country_code']);
    }

    // ─── Events: Validation ─────────────────────────────────────────────

    public function testValidateEventNameAcceptsValid(): void
    {
        $this->assertTrue(\MintyMetrics\validate_event_name('save'));
        $this->assertTrue(\MintyMetrics\validate_event_name('primitive_create'));
        $this->assertTrue(\MintyMetrics\validate_event_name('a'));
        $this->assertTrue(\MintyMetrics\validate_event_name('A_1'));
    }

    public function testValidateEventNameRejectsInvalid(): void
    {
        $this->assertFalse(\MintyMetrics\validate_event_name(''));
        $this->assertFalse(\MintyMetrics\validate_event_name('with space'));
        $this->assertFalse(\MintyMetrics\validate_event_name('with-dash'));
        $this->assertFalse(\MintyMetrics\validate_event_name('with.dot'));
        $this->assertFalse(\MintyMetrics\validate_event_name('inj"ect'));
        $this->assertFalse(\MintyMetrics\validate_event_name(str_repeat('a', 65)));
    }

    public function testValidateEventPropsAcceptsObject(): void
    {
        $out = \MintyMetrics\validate_event_props('{"scope":"scene","count":3}');
        $this->assertNotNull($out);
        $decoded = json_decode($out, true);
        $this->assertSame('scene', $decoded['scope']);
        $this->assertSame(3, $decoded['count']);
    }

    public function testValidateEventPropsRejectsArray(): void
    {
        // arrays decode to indexed arrays in PHP — we only accept objects/assoc
        $this->assertNotNull(\MintyMetrics\validate_event_props('{"a":1}'));
        // Numeric or scalar JSON should be rejected
        $this->assertNull(\MintyMetrics\validate_event_props('"just a string"'));
        $this->assertNull(\MintyMetrics\validate_event_props('42'));
    }

    public function testValidateEventPropsRejectsMalformed(): void
    {
        $this->assertNull(\MintyMetrics\validate_event_props('not json'));
        $this->assertNull(\MintyMetrics\validate_event_props('{unterminated'));
        $this->assertNull(\MintyMetrics\validate_event_props(''));
    }

    public function testValidateEventPropsRejectsOversize(): void
    {
        $big = '{"k":"' . str_repeat('x', 1100) . '"}';
        $this->assertNull(\MintyMetrics\validate_event_props($big));
    }

    // ─── Events: Rate Limit Bucket Isolation ────────────────────────────

    public function testRateLimitBucketsAreIndependent(): void
    {
        $ipHash = hash('sha256', '203.0.113.7');

        // First call in the default bucket — should be allowed
        $this->assertFalse(\MintyMetrics\check_rate_limit($ipHash));
        // Immediate second call in default bucket — throttled
        $this->assertTrue(\MintyMetrics\check_rate_limit($ipHash));
        // But the 'evt:' bucket is fresh — should be allowed
        $this->assertFalse(\MintyMetrics\check_rate_limit($ipHash, 'evt:'));
        // Immediate second 'evt:' call — throttled
        $this->assertTrue(\MintyMetrics\check_rate_limit($ipHash, 'evt:'));
    }

    public function testRateLimitBucketsStoredAsDistinctRows(): void
    {
        $ipHash = hash('sha256', '203.0.113.8');
        \MintyMetrics\check_rate_limit($ipHash);
        \MintyMetrics\check_rate_limit($ipHash, 'evt:');
        // Two distinct rows: one with bare hash, one with 'evt:'+hash
        $this->assertSame(2, $this->countRows('rate_limit'));
    }

    // ─── Events: End-to-End via track_event() ───────────────────────────

    public function testTrackEventEndToEnd(): void
    {
        $_GET = [
            '_v' => '1',
            'site' => 'test.com',
            'name' => 'save',
            'value' => 'shortcut',
            'p' => '{"key":"ctrl-s"}',
            'page' => '/editor',
        ];
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';
        $_SERVER['REMOTE_ADDR'] = '198.51.100.42';

        \MintyMetrics\track_event();

        $row = self::$testDb->querySingle('SELECT * FROM events LIMIT 1', true);
        $this->assertNotFalse($row, 'event should have been inserted');
        $this->assertSame('test.com', $row['site']);
        $this->assertSame('save', $row['name']);
        $this->assertSame('shortcut', $row['value']);
        $this->assertSame('{"key":"ctrl-s"}', $row['props']);
        $this->assertSame('/editor', $row['page_path']);
        $this->assertNotEmpty($row['visitor_hash']);
    }

    public function testTrackEventRejectedWithoutJsVerification(): void
    {
        $_GET = ['site' => 'test.com', 'name' => 'save']; // missing _v
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
        $_SERVER['REMOTE_ADDR'] = '198.51.100.43';

        \MintyMetrics\track_event();

        $this->assertSame(0, $this->countRows('events'));
    }

    public function testTrackEventRejectedFromBot(): void
    {
        $_GET = ['_v' => '1', 'site' => 'test.com', 'name' => 'save'];
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
        $_SERVER['REMOTE_ADDR'] = '198.51.100.44';

        \MintyMetrics\track_event();

        $this->assertSame(0, $this->countRows('events'));
    }

    public function testTrackEventRejectedWithDnt(): void
    {
        $_GET = ['_v' => '1', 'site' => 'test.com', 'name' => 'save'];
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
        $_SERVER['REMOTE_ADDR'] = '198.51.100.45';
        $_SERVER['HTTP_DNT'] = '1';

        \MintyMetrics\track_event();

        unset($_SERVER['HTTP_DNT']);
        $this->assertSame(0, $this->countRows('events'));
    }

    public function testTrackEventRejectedWithInvalidName(): void
    {
        $_GET = ['_v' => '1', 'site' => 'test.com', 'name' => 'bad name with spaces'];
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
        $_SERVER['REMOTE_ADDR'] = '198.51.100.46';

        \MintyMetrics\track_event();

        $this->assertSame(0, $this->countRows('events'));
    }

    public function testTrackEventStoresNullPropsOnMalformedJson(): void
    {
        $_GET = [
            '_v' => '1',
            'site' => 'test.com',
            'name' => 'save',
            'p' => 'not-json',
        ];
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0';
        $_SERVER['REMOTE_ADDR'] = '198.51.100.47';

        \MintyMetrics\track_event();

        $row = self::$testDb->querySingle('SELECT props FROM events LIMIT 1', true);
        $this->assertNotFalse($row, 'event should still be recorded');
        $this->assertNull($row['props']);
    }
}
