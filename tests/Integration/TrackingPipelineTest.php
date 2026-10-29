<?php

namespace MintyMetrics\Tests\Integration;

use MintyMetrics\Tests\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Hit/beacon/event ingestion: rate limiting, page cleaning, self-referrals,
 * visitor hashing and beacon matching.
 */
class TrackingPipelineTest extends IntegrationTestCase
{
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setConfig('allowed_domains', '["blog.com"]');
        $_SERVER['HTTP_USER_AGENT'] = self::UA;
        $_SERVER['REMOTE_ADDR'] = '198.51.100.42';
        unset($_SERVER['HTTP_DNT'], $_SERVER['HTTP_SEC_GPC']);
    }

    private function hit(array $params): void
    {
        $_GET = array_merge(['_v' => '1', 'site' => 'blog.com', 'page' => '/'], $params);
        \MintyMetrics\track_hit();
    }

    private function beacon(string $page, int $time): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['_v' => '1', 'site' => 'blog.com', 'page' => $page, 'time' => (string) $time];
        @\MintyMetrics\track_beacon(); // suppress "headers already sent" from http_response_code in CLI
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    // ─── Rate limiting ──────────────────────────────────────────────────

    public function testExactRepeatIsDroppedButOtherPagesAreNot(): void
    {
        $this->hit(['page' => '/a']);
        $this->hit(['page' => '/a']); // same visitor, same page, same second
        $this->hit(['page' => '/b']); // a second tab or another page
        $this->assertSame(2, $this->countRows('hits'));
    }

    public function testVisitorsSharingAnIpAreNotDroppedForEachOther(): void
    {
        $this->hit(['page' => '/']);
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Version/17.0 Safari/605.1.15';
        $this->hit(['page' => '/']);
        $this->assertSame(2, $this->countRows('hits'));
    }

    public function testFloodCapPerIp(): void
    {
        for ($i = 0; $i < \MintyMetrics\RATE_LIMIT_PER_MINUTE + 5; $i++) {
            $this->hit(['page' => "/p{$i}"]);
        }
        $this->assertLessThanOrEqual(\MintyMetrics\RATE_LIMIT_PER_MINUTE, $this->countRows('hits'));
        $this->assertGreaterThan(\MintyMetrics\RATE_LIMIT_PER_MINUTE - 70, $this->countRows('hits'), 'Allows up to the cap (the minute may roll over mid-test)');
    }

    public function testFloodingDoesNotAllocateRowsPerRequest(): void
    {
        for ($i = 0; $i < \MintyMetrics\RATE_LIMIT_PER_MINUTE + 200; $i++) {
            $this->hit(['page' => "/unique-{$i}"]);
        }
        // One dedupe row per accepted request, plus the per-IP counter(s); rejected
        // requests add nothing
        $this->assertLessThanOrEqual($this->countRows('hits') + 2, $this->countRows('rate_limit'));
    }

    public function testRateLimitKeysAreNotReversibleIpHashes(): void
    {
        $this->hit([]);
        $keys = [];
        $r = self::$testDb->query('SELECT ip_hash FROM rate_limit');
        while ($row = $r->fetchArray(SQLITE3_NUM)) {
            $keys[] = $row[0];
        }
        $this->assertNotEmpty($keys);
        $this->assertNotContains(hash('sha256', '198.51.100.42'), $keys);
    }

    public function testRejectedSiteCostsNoWrites(): void
    {
        $this->hit(['site' => 'not-allowed.com']);
        $this->assertSame(0, $this->countRows('rate_limit'));
        $this->assertSame(0, $this->countRows('hits'));
    }

    public function testSubdomainsCanBeDisallowed(): void
    {
        $this->hit(['site' => 'random123.blog.com']);
        $this->assertSame(1, $this->countRows('hits'));
        $this->setConfig('allow_subdomains', '0');
        $this->hit(['site' => 'random456.blog.com', 'page' => '/x']);
        $this->assertSame(1, $this->countRows('hits'));
    }

    // ─── Stored data ────────────────────────────────────────────────────

    public function testSelfReferralIsNotStored(): void
    {
        $this->hit(['page' => '/a', 'ref' => 'https://www.blog.com/previous']);
        $this->hit(['page' => '/b', 'ref' => 'https://news.example.org/story']);
        $refs = self::$testDb->query('SELECT referrer_domain, referrer_url FROM hits ORDER BY id');
        $first = $refs->fetchArray(SQLITE3_ASSOC);
        $second = $refs->fetchArray(SQLITE3_ASSOC);
        $this->assertNull($first['referrer_domain']);
        $this->assertNull($first['referrer_url']);
        $this->assertSame('news.example.org', $second['referrer_domain']);
    }

    public static function pagePathProvider(): array
    {
        return [
            'plain' => ['/about', '/about'],
            'empty' => ['', '/'],
            'fragment dropped' => ['/docs#install', '/docs'],
            'utm and click ids dropped' => ['/p?utm_source=x&gclid=1&fbclid=2&id=7', '/p?id=7'],
            'secrets dropped' => ['/reset?token=abc&email=a%40b.c&lang=en', '/reset?lang=en'],
            'wordpress ids kept' => ['/?p=123', '/?p=123'],
            'all removed' => ['/x?utm_campaign=y', '/x'],
            'array token' => ['/x?token%5B%5D=secret&a=1', '/x?a=1'],
            'nested email' => ['/x?user%5Bemail%5D=a%40b.com&a=1', '/x?a=1'],
            'compound names' => ['/x?reset_token=s&x-api-key=k&auth_code=c&a=1', '/x?a=1'],
            'harmless names kept' => ['/x?page=2&sort=date', '/x?page=2&sort=date'],
        ];
    }

    #[DataProvider('pagePathProvider')]
    public function testCleanPagePath(string $in, string $out): void
    {
        $this->assertSame($out, \MintyMetrics\clean_page_path($in));
    }

    public function testVisitorHashIncludesSiteAndNormalizesIpv6(): void
    {
        $a = \MintyMetrics\generate_visitor_hash('2001:db8:1:2:aaaa::1', 'UA', 'salt', 'a.com');
        $b = \MintyMetrics\generate_visitor_hash('2001:db8:1:2:bbbb::9', 'UA', 'salt', 'a.com');
        $c = \MintyMetrics\generate_visitor_hash('2001:db8:1:2:aaaa::1', 'UA', 'salt', 'b.com');
        $this->assertSame($a, $b, 'Rotating IPv6 privacy addresses in one /64 are one visitor');
        $this->assertNotSame($a, $c, 'The same person on two hub sites cannot be linked');
        $this->assertSame(
            \MintyMetrics\generate_visitor_hash('::ffff:1.2.3.4', 'UA', 'salt', 'a.com'),
            \MintyMetrics\generate_visitor_hash('1.2.3.4', 'UA', 'salt', 'a.com')
        );
    }

    // ─── Beacons ────────────────────────────────────────────────────────

    public function testBeaconKeepsLargestTimeAndCapsLongVisits(): void
    {
        $this->hit(['page' => '/read']);
        $this->beacon('/read', 5);
        sleep(1); // past the 1s duplicate window
        $this->beacon('/read', 3); // an older/smaller report never lowers it
        $this->assertSame(5, (int) self::$testDb->querySingle('SELECT time_on_page FROM hits'));
        sleep(1);
        $this->beacon('/read', 9000);
        $this->assertSame(3600, (int) self::$testDb->querySingle('SELECT time_on_page FROM hits'));
    }

    public function testTwoTabsOnSamePageKeepTheirOwnTime(): void
    {
        // Opened within the same second: both count, because their pageview IDs differ
        $this->hit(['page' => '/read', 'pv' => 'aaaaaaaaaaaaaaaa']);
        $this->hit(['page' => '/read', 'pv' => 'bbbbbbbbbbbbbbbb']);
        $this->hit(['page' => '/read', 'pv' => 'bbbbbbbbbbbbbbbb']); // a repeat of the same pageview
        $this->assertSame(2, $this->countRows('hits'));

        $_POST = ['_v' => '1', 'site' => 'blog.com', 'page' => '/read', 'time' => '60', 'pv' => 'aaaaaaaaaaaaaaaa'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        @\MintyMetrics\track_beacon();
        $_POST['time'] = '5';
        $_POST['pv'] = 'bbbbbbbbbbbbbbbb';
        @\MintyMetrics\track_beacon();
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $times = [];
        $r = self::$testDb->query('SELECT pageview_id, time_on_page FROM hits');
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
            $times[$row['pageview_id']] = (int) $row['time_on_page'];
        }
        $this->assertSame(['aaaaaaaaaaaaaaaa' => 60, 'bbbbbbbbbbbbbbbb' => 5], $times);
    }

    public function testBeaconCannotTouchAnotherVisitorsPageview(): void
    {
        $this->hit(['page' => '/read', 'pv' => 'cccccccccccccccc']);
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/120.0';
        $this->beaconWithPv('/read', 99, 'cccccccccccccccc');
        $this->assertNull(self::$testDb->querySingle('SELECT time_on_page FROM hits'));
    }

    private function beaconWithPv(string $page, int $time, string $pv): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['_v' => '1', 'site' => 'blog.com', 'page' => $page, 'time' => (string) $time, 'pv' => $pv];
        @\MintyMetrics\track_beacon();
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    public function testExpandedIpv4MappedAddressesStayDistinct(): void
    {
        $this->assertSame('192.0.2.1', \MintyMetrics\normalize_ip('0:0:0:0:0:ffff:c000:201'));
        $this->assertSame('192.0.2.2', \MintyMetrics\normalize_ip('0:0:0:0:0:ffff:c000:202'));
        $this->assertSame('192.0.2.1', \MintyMetrics\normalize_ip('::ffff:192.0.2.1'));
        $this->assertSame('2001:db8:1:2::', \MintyMetrics\normalize_ip('2001:db8:1:2:aaaa::1'));
        $this->assertSame('203.0.113.9', \MintyMetrics\normalize_ip('203.0.113.9'));
    }

    public function testBeaconMatchesCleanedPagePath(): void
    {
        $this->hit(['page' => '/read?utm_source=x']);
        $this->beacon('/read?utm_source=x', 12);
        $this->assertSame(12, (int) self::$testDb->querySingle('SELECT time_on_page FROM hits'));
    }

    public function testBeaconAfterMidnightMatchesYesterdaysHit(): void
    {
        // A hit recorded with yesterday's salt...
        $oldSalt = str_repeat('a', 64);
        $hash = \MintyMetrics\generate_visitor_hash('198.51.100.42', self::UA, $oldSalt, 'blog.com');
        $this->insertHit(['site' => 'blog.com', 'visitor_hash' => $hash, 'page_path' => '/late', 'created_at' => time() - 600]);
        // ...and the salt has since rotated
        $this->setConfig('prev_salt', $oldSalt);
        $this->setConfig('prev_salt_date', date('Y-m-d', strtotime('yesterday')));

        $this->beacon('/late', 30);

        $this->assertSame(30, (int) self::$testDb->querySingle('SELECT time_on_page FROM hits'));
    }

    public function testBeaconFromBotIsIgnored(): void
    {
        $this->hit(['page' => '/']);
        $_SERVER['HTTP_USER_AGENT'] = 'Googlebot/2.1 (+http://www.google.com/bot.html)';
        $this->beacon('/', 30);
        $this->assertNull(self::$testDb->querySingle('SELECT time_on_page FROM hits'));
    }

    // ─── Events ─────────────────────────────────────────────────────────

    public function testEventPropsKeepUnicodeAndObjects(): void
    {
        $cyrillic = str_repeat('ж', 300); // 600 bytes raw, ~1.8 KB if \u-escaped
        $this->assertSame('{"t":"' . $cyrillic . '"}', \MintyMetrics\validate_event_props('{"t":"' . $cyrillic . '"}'));
        $this->assertSame('{}', \MintyMetrics\validate_event_props('{}'));
        $this->assertSame('{"0":"a"}', \MintyMetrics\validate_event_props('{"0":"a"}'));
        $this->assertNull(\MintyMetrics\validate_event_props('[1,2]'));
    }

    public function testDifferentEventsInSameSecondAreKept(): void
    {
        foreach (['open', 'save', 'save'] as $name) {
            $_GET = ['_v' => '1', 'site' => 'blog.com', 'name' => $name];
            \MintyMetrics\track_event();
        }
        $this->assertSame(2, $this->countRows('events'), 'Only the exact repeat is dropped');
    }
}
