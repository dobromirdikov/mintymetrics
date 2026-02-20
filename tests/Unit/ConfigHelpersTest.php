<?php

namespace MintyMetrics\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ConfigHelpersTest extends TestCase
{
    // ─── IP Normalization ───────────────────────────────────────────────

    #[DataProvider('ipNormalizationProvider')]
    public function testNormalizeIp(string $input, string $expected): void
    {
        $this->assertSame($expected, \MintyMetrics\normalize_ip($input));
    }

    public static function ipNormalizationProvider(): array
    {
        return [
            'IPv4 passthrough' => [
                '192.168.1.1',
                '192.168.1.1',
            ],
            'IPv4 loopback' => [
                '127.0.0.1',
                '127.0.0.1',
            ],
            'IPv4-mapped IPv6' => [
                '::ffff:192.168.1.1',
                '192.168.1.1',
            ],
            'IPv6 truncated to /64' => [
                '2001:0db8:85a3:0000:0000:8a2e:0370:7334',
                '2001:db8:85a3::',
            ],
            'IPv6 loopback' => [
                '::1',
                '::',
            ],
        ];
    }

    public function testNormalizeIpHandlesInvalidIPv6Gracefully(): void
    {
        // Invalid IPv6 that inet_pton can't parse should return as-is
        $invalid = 'not:a:valid:ipv6:::but:has:colons';
        $result = \MintyMetrics\normalize_ip($invalid);
        $this->assertSame($invalid, $result);
    }

    // ─── String Truncation ──────────────────────────────────────────────

    public function testTruncateShortString(): void
    {
        $this->assertSame('hello', \MintyMetrics\truncate('hello', 100));
    }

    public function testTruncateLongString(): void
    {
        $result = \MintyMetrics\truncate('hello world', 5);
        $this->assertSame('hello', $result);
    }

    public function testTruncateEmptyString(): void
    {
        $this->assertSame('', \MintyMetrics\truncate('', 10));
    }

    public function testTruncateMultibyteString(): void
    {
        $utf8 = 'Привет мир'; // Russian "Hello world"
        $result = \MintyMetrics\truncate($utf8, 6);
        $this->assertSame('Привет', $result);
        $this->assertSame(6, mb_strlen($result));
    }

    public function testTruncateExactLength(): void
    {
        $this->assertSame('hello', \MintyMetrics\truncate('hello', 5));
    }

    // ─── HTML Escape Helper ─────────────────────────────────────────────

    public function testEscapeHtmlSpecialChars(): void
    {
        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', \MintyMetrics\e('<script>alert(1)</script>'));
    }

    public function testEscapeQuotes(): void
    {
        $this->assertSame('a &quot;b&quot; c', \MintyMetrics\e('a "b" c'));
        $this->assertSame('a &apos;b&apos; c', \MintyMetrics\e("a 'b' c"));
    }

    public function testEscapeAmpersand(): void
    {
        $this->assertSame('foo &amp; bar', \MintyMetrics\e('foo & bar'));
    }

    public function testEscapeEmptyString(): void
    {
        $this->assertSame('', \MintyMetrics\e(''));
    }

    public function testEscapeSafeStringPassthrough(): void
    {
        $this->assertSame('Hello World', \MintyMetrics\e('Hello World'));
    }
}
