<?php

namespace MintyMetrics\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class UserAgentTest extends TestCase
{
    // ─── Browser Detection ──────────────────────────────────────────────

    #[DataProvider('browserProvider')]
    public function testDetectBrowser(string $ua, string $expected): void
    {
        $result = \MintyMetrics\parse_ua($ua);
        $this->assertSame($expected, $result['browser']);
    }

    public static function browserProvider(): array
    {
        return [
            'Chrome on Windows' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Chrome',
            ],
            'Firefox on Linux' => [
                'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0',
                'Firefox',
            ],
            'Safari on macOS' => [
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15',
                'Safari',
            ],
            'Edge on Windows' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0',
                'Edge',
            ],
            'Opera' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 OPR/106.0.0.0',
                'Opera',
            ],
            'Samsung Internet' => [
                'Mozilla/5.0 (Linux; Android 13; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/23.0 Chrome/115.0.0.0 Mobile Safari/537.36',
                'Samsung Internet',
            ],
            'Vivaldi' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Vivaldi/6.5.3206.50',
                'Vivaldi',
            ],
            'Yandex Browser' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 YaBrowser/24.1.0 Safari/537.36',
                'Yandex',
            ],
            'Chrome on iOS (CriOS)' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/120.0.6099.119 Mobile/15E148 Safari/604.1',
                'Chrome',
            ],
            'Firefox on iOS (FxiOS)' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/121.0 Mobile/15E148 Safari/605.1.15',
                'Firefox',
            ],
            'IE 11' => [
                'Mozilla/5.0 (Windows NT 6.3; Trident/7.0; rv:11.0) like Gecko',
                'Internet Explorer',
            ],
            'UC Browser' => [
                'Mozilla/5.0 (Linux; U; Android 13) AppleWebKit/537.36 (KHTML, like Gecko) UCBrowser/15.0.0.0 Mobile Safari/537.36',
                'UC Browser',
            ],
            'Unknown browser' => [
                'SomeRandomAgent/1.0',
                'Other',
            ],
            'Empty UA' => [
                '',
                'Other',
            ],
        ];
    }

    // ─── Browser Version Detection ──────────────────────────────────────

    #[DataProvider('browserVersionProvider')]
    public function testDetectBrowserVersion(string $ua, string $expectedVersion): void
    {
        $result = \MintyMetrics\parse_ua($ua);
        $this->assertSame($expectedVersion, $result['browser_ver']);
    }

    public static function browserVersionProvider(): array
    {
        return [
            'Chrome 120' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                '120.0',
            ],
            'Firefox 121' => [
                'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0',
                '121.0',
            ],
            'Safari 17.2' => [
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15',
                '17.2',
            ],
            'Edge 120' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.2210.91',
                '120.0',
            ],
            'No version' => [
                'SomeRandomAgent/1.0',
                '',
            ],
        ];
    }

    // ─── OS Detection ───────────────────────────────────────────────────

    #[DataProvider('osProvider')]
    public function testDetectOS(string $ua, string $expectedOS): void
    {
        $result = \MintyMetrics\parse_ua($ua);
        $this->assertSame($expectedOS, $result['os']);
    }

    public static function osProvider(): array
    {
        return [
            'Windows' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
                'Windows',
            ],
            'macOS' => [
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Version/17.2 Safari/605.1.15',
                'macOS',
            ],
            'Linux' => [
                'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0',
                'Linux',
            ],
            'iOS (iPhone)' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1',
                'iOS',
            ],
            'iOS (iPad)' => [
                'Mozilla/5.0 (iPad; CPU OS 17_2 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1',
                'iOS',
            ],
            'Android' => [
                'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/120.0.0.0 Mobile Safari/537.36',
                'Android',
            ],
            'Chrome OS' => [
                'Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
                'Chrome OS',
            ],
            'Unknown OS' => [
                'SomeRandomAgent/1.0',
                'Other',
            ],
        ];
    }

    // ─── OS Version Detection ───────────────────────────────────────────

    #[DataProvider('osVersionProvider')]
    public function testDetectOSVersion(string $ua, string $expectedVersion): void
    {
        $result = \MintyMetrics\parse_ua($ua);
        $this->assertSame($expectedVersion, $result['os_ver']);
    }

    public static function osVersionProvider(): array
    {
        return [
            'Windows NT 10.0' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0',
                '10.0',
            ],
            'macOS 10.15.7 (underscores)' => [
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
                '10.15.7',
            ],
            'iOS 17.2 (underscores)' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) Safari/604.1',
                '17.2',
            ],
            'Android 14' => [
                'Mozilla/5.0 (Linux; Android 14; Pixel 8) Chrome/120.0.0.0',
                '14',
            ],
            'Chrome OS 14541.0' => [
                'Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) Chrome/120.0.0.0',
                '14541.0',
            ],
            'Linux (no version)' => [
                'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Firefox/121.0',
                '',
            ],
        ];
    }

    // ─── Device Type Detection ──────────────────────────────────────────

    #[DataProvider('deviceProvider')]
    public function testDetectDevice(string $ua, string $expectedDevice): void
    {
        $result = \MintyMetrics\parse_ua($ua);
        $this->assertSame($expectedDevice, $result['device']);
    }

    public static function deviceProvider(): array
    {
        return [
            'Desktop (Windows Chrome)' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
                'desktop',
            ],
            'Desktop (macOS Safari)' => [
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Version/17.2 Safari/605.1.15',
                'desktop',
            ],
            'Mobile (iPhone)' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1',
                'mobile',
            ],
            'Mobile (Android phone)' => [
                'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/120.0.0.0 Mobile Safari/537.36',
                'mobile',
            ],
            'Tablet (iPad)' => [
                'Mozilla/5.0 (iPad; CPU OS 17_2 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1',
                'tablet',
            ],
            'Tablet (Android tablet, no Mobile keyword)' => [
                'Mozilla/5.0 (Linux; Android 13; SM-X710) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
                'tablet',
            ],
            'Desktop (Linux Firefox)' => [
                'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0',
                'desktop',
            ],
        ];
    }

    // ─── Full Parse Result Structure ────────────────────────────────────

    public function testParseUaReturnsAllKeys(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36';
        $result = \MintyMetrics\parse_ua($ua);

        $this->assertArrayHasKey('device', $result);
        $this->assertArrayHasKey('browser', $result);
        $this->assertArrayHasKey('browser_ver', $result);
        $this->assertArrayHasKey('os', $result);
        $this->assertArrayHasKey('os_ver', $result);
        $this->assertCount(5, $result);
    }
}
