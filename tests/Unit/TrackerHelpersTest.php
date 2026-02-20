<?php

namespace MintyMetrics\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class TrackerHelpersTest extends TestCase
{
    // ─── Bot Detection ──────────────────────────────────────────────────

    #[DataProvider('botUserAgentProvider')]
    public function testIsBotDetectsKnownBots(string $ua): void
    {
        $this->assertTrue(\MintyMetrics\is_bot($ua), "Expected bot: {$ua}");
    }

    public static function botUserAgentProvider(): array
    {
        return [
            'Googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'],
            'Bingbot' => ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)'],
            'YandexBot' => ['Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)'],
            'Baiduspider' => ['Mozilla/5.0 (compatible; Baiduspider/2.0; +http://www.baidu.com/search/spider.html)'],
            'DuckDuckBot' => ['DuckDuckBot/1.0; (+http://duckduckgo.com/duckduckbot.html)'],
            'Semrush' => ['Mozilla/5.0 (compatible; SemrushBot/7~bl; +http://www.semrush.com/bot.html)'],
            'Ahrefs' => ['Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)'],
            'GPTBot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.0; +https://openai.com/gptbot)'],
            'ClaudeBot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)'],
            'curl' => ['curl/7.68.0'],
            'wget' => ['Wget/1.21'],
            'python-requests' => ['python-requests/2.28.1'],
            'HeadlessChrome' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 HeadlessChrome/120.0.0.0 Safari/537.36'],
            'Lighthouse' => ['Mozilla/5.0 (Linux; Android 11; Lighthouse) Chrome/120.0.0.0 Mobile Safari/537.36'],
            'UptimeRobot' => ['Mozilla/5.0 (compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)'],
            'Go HTTP client' => ['Go-http-client/1.1'],
            'Java client' => ['Java/11.0.2'],
            'Archive.org' => ['Mozilla/5.0 (compatible; archive.org_bot +http://www.archive.org/details/archive.org_bot)'],
            'Empty UA' => [''],
        ];
    }

    #[DataProvider('humanUserAgentProvider')]
    public function testIsBotAllowsRealBrowsers(string $ua): void
    {
        $this->assertFalse(\MintyMetrics\is_bot($ua), "Expected human: {$ua}");
    }

    public static function humanUserAgentProvider(): array
    {
        return [
            'Chrome on Windows' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            ],
            'Firefox on Linux' => [
                'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0',
            ],
            'Safari on macOS' => [
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15',
            ],
            'Mobile Safari on iPhone' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1',
            ],
            'Edge on Windows' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0',
            ],
        ];
    }

    // ─── Visitor Hash Generation ────────────────────────────────────────

    public function testGenerateVisitorHashIsConsistent(): void
    {
        $ip = '192.168.1.1';
        $ua = 'Mozilla/5.0 Chrome/120.0';
        $salt = 'test_salt_123';

        $hash1 = \MintyMetrics\generate_visitor_hash($ip, $ua, $salt);
        $hash2 = \MintyMetrics\generate_visitor_hash($ip, $ua, $salt);

        $this->assertSame($hash1, $hash2);
    }

    public function testGenerateVisitorHashIsValidSha256(): void
    {
        $hash = \MintyMetrics\generate_visitor_hash('127.0.0.1', 'TestUA', 'salt');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }

    public function testGenerateVisitorHashDiffersWithDifferentInputs(): void
    {
        $salt = 'same_salt';
        $hash1 = \MintyMetrics\generate_visitor_hash('1.1.1.1', 'Chrome', $salt);
        $hash2 = \MintyMetrics\generate_visitor_hash('2.2.2.2', 'Chrome', $salt);
        $hash3 = \MintyMetrics\generate_visitor_hash('1.1.1.1', 'Firefox', $salt);
        $hash4 = \MintyMetrics\generate_visitor_hash('1.1.1.1', 'Chrome', 'different_salt');

        $this->assertNotSame($hash1, $hash2, 'Different IPs should produce different hashes');
        $this->assertNotSame($hash1, $hash3, 'Different UAs should produce different hashes');
        $this->assertNotSame($hash1, $hash4, 'Different salts should produce different hashes');
    }

    // ─── Referrer Domain Parsing ────────────────────────────────────────

    #[DataProvider('referrerDomainProvider')]
    public function testParseReferrerDomain(string $referrer, ?string $expected): void
    {
        $this->assertSame($expected, \MintyMetrics\parse_referrer_domain($referrer));
    }

    public static function referrerDomainProvider(): array
    {
        return [
            'Standard URL' => ['https://example.com/page', 'example.com'],
            'With www prefix' => ['https://www.example.com/page', 'example.com'],
            'With subdomain' => ['https://blog.example.com/post', 'blog.example.com'],
            'With port' => ['https://example.com:8080/page', 'example.com'],
            'HTTP URL' => ['http://example.com/', 'example.com'],
            'With query string' => ['https://google.com/search?q=test', 'google.com'],
            'Empty string' => ['', null],
            'Invalid URL' => ['not-a-url', null],
            'Just domain' => ['example.com', null], // No scheme = parse_url can't extract host
            'With uppercase' => ['https://WWW.Example.COM/page', 'example.com'],
        ];
    }

    // ─── UTM Parsing ────────────────────────────────────────────────────

    public function testParseUtmExtractsAllFields(): void
    {
        $params = [
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'summer_sale',
            'utm_term' => 'analytics',
            'utm_content' => 'banner_ad',
        ];

        $result = \MintyMetrics\parse_utm($params);

        $this->assertSame('google', $result['source']);
        $this->assertSame('cpc', $result['medium']);
        $this->assertSame('summer_sale', $result['campaign']);
        $this->assertSame('analytics', $result['term']);
        $this->assertSame('banner_ad', $result['content']);
    }

    public function testParseUtmReturnsNullForMissingFields(): void
    {
        $result = \MintyMetrics\parse_utm([]);

        $this->assertNull($result['source']);
        $this->assertNull($result['medium']);
        $this->assertNull($result['campaign']);
        $this->assertNull($result['term']);
        $this->assertNull($result['content']);
    }

    public function testParseUtmPartialFields(): void
    {
        $result = \MintyMetrics\parse_utm(['utm_source' => 'twitter']);

        $this->assertSame('twitter', $result['source']);
        $this->assertNull($result['medium']);
        $this->assertNull($result['campaign']);
    }

    public function testParseUtmTruncatesLongValues(): void
    {
        $longValue = str_repeat('x', 500);
        $result = \MintyMetrics\parse_utm(['utm_source' => $longValue]);

        $this->assertSame(\MintyMetrics\MAX_UTM_FIELD, mb_strlen($result['source']));
    }

    // ─── Site Sanitization ──────────────────────────────────────────────

    #[DataProvider('sanitizeSiteProvider')]
    public function testSanitizeSite(string $input, string $expected): void
    {
        $this->assertSame($expected, \MintyMetrics\sanitize_site($input));
    }

    public static function sanitizeSiteProvider(): array
    {
        return [
            'Normal domain' => ['example.com', 'example.com'],
            'Uppercase' => ['Example.COM', 'example.com'],
            'With spaces' => ['  example.com  ', 'example.com'],
            'With subdomain' => ['blog.example.com', 'blog.example.com'],
            'Special characters removed' => ['example<script>.com', 'examplescript.com'],
            'Only allowed chars' => ['my-site.example.com', 'my-site.example.com'],
            'With port notation stripped' => ['example.com:8080', 'example.com8080'],
        ];
    }

    // ─── Domain Validation ──────────────────────────────────────────────

    public function testValidateDomainAllowsAllWhenNoAllowlist(): void
    {
        // When no domains configured, all should pass
        $this->assertTrue(\MintyMetrics\validate_domain('anything.com'));
    }
}
