<?php
namespace MintyMetrics;

/**
 * Parse a User-Agent string into device type, browser, and OS components.
 *
 * @return array{device: string, browser: string, browser_ver: string, os: string, os_ver: string}
 */
function parse_ua(string $ua): array {
    return [
        'device'      => detect_device($ua),
        'browser'     => detect_browser($ua),
        'browser_ver' => detect_browser_version($ua),
        'os'          => detect_os($ua),
        'os_ver'      => detect_os_version($ua),
    ];
}

function detect_device(string $ua): string {
    // Tablets first (before mobile, since some tablets contain "Mobile")
    if (\preg_match('/iPad|Android(?!.*Mobile)|Tablet|PlayBook|Silk|Kindle/i', $ua)) {
        return 'tablet';
    }
    // Mobile
    if (\preg_match('/Mobile|iPhone|iPod|Android.*Mobile|Windows Phone|Opera Mini|Opera Mobi|BlackBerry|IEMobile|wpdesktop/i', $ua)) {
        return 'mobile';
    }
    return 'desktop';
}

function detect_browser(string $ua): string {
    // Order matters: check more specific patterns first
    if (\preg_match('/Edg(?:e|A|iOS)?\/[\d.]+/i', $ua)) return 'Edge';
    if (\preg_match('/OPR\/|Opera/i', $ua)) return 'Opera';
    if (\preg_match('/SamsungBrowser/i', $ua)) return 'Samsung Internet';
    if (\preg_match('/UCBrowser|UCWEB/i', $ua)) return 'UC Browser';
    if (\preg_match('/Brave/i', $ua)) return 'Brave';
    if (\preg_match('/Vivaldi/i', $ua)) return 'Vivaldi';
    if (\preg_match('/YaBrowser/i', $ua)) return 'Yandex';
    if (\preg_match('/Firefox|FxiOS/i', $ua)) return 'Firefox';
    if (\preg_match('/CriOS/i', $ua)) return 'Chrome'; // Chrome on iOS
    if (\preg_match('/Chrome\/[\d.]+/i', $ua) && !\preg_match('/Chromium/i', $ua)) return 'Chrome';
    if (\preg_match('/Chromium/i', $ua)) return 'Chromium';
    if (\preg_match('/Safari\/[\d.]+/i', $ua) && !\preg_match('/Chrome|Chromium/i', $ua)) return 'Safari';
    if (\preg_match('/MSIE|Trident/i', $ua)) return 'Internet Explorer';
    return 'Other';
}

function detect_browser_version(string $ua): string {
    $patterns = [
        '/Edg(?:e|A|iOS)?\/(\d+(?:\.\d+)?)/i',
        '/OPR\/(\d+(?:\.\d+)?)/i',
        '/SamsungBrowser\/(\d+(?:\.\d+)?)/i',
        '/UCBrowser\/(\d+(?:\.\d+)?)/i',
        '/Brave\/(\d+(?:\.\d+)?)/i',
        '/Vivaldi\/(\d+(?:\.\d+)?)/i',
        '/YaBrowser\/(\d+(?:\.\d+)?)/i',
        '/(?:Firefox|FxiOS)\/(\d+(?:\.\d+)?)/i',
        '/CriOS\/(\d+(?:\.\d+)?)/i',
        '/Chrome\/(\d+(?:\.\d+)?)/i',
        '/Version\/(\d+(?:\.\d+)?).*Safari/i',
        '/(?:MSIE |rv:)(\d+(?:\.\d+)?)/i',
    ];

    foreach ($patterns as $pattern) {
        if (\preg_match($pattern, $ua, $m)) {
            return $m[1];
        }
    }
    return '';
}

function detect_os(string $ua): string {
    if (\preg_match('/iPhone|iPad|iPod/i', $ua)) return 'iOS';
    if (\preg_match('/Android/i', $ua)) return 'Android';
    if (\preg_match('/Windows/i', $ua)) return 'Windows';
    if (\preg_match('/Macintosh|Mac OS X/i', $ua)) return 'macOS';
    if (\preg_match('/CrOS/i', $ua)) return 'Chrome OS';
    if (\preg_match('/Linux/i', $ua)) return 'Linux';
    if (\preg_match('/FreeBSD/i', $ua)) return 'FreeBSD';
    return 'Other';
}

function detect_os_version(string $ua): string {
    $patterns = [
        'iOS'       => '/OS (\d+[_\.]\d+(?:[_\.]\d+)?)/i',
        'Android'   => '/Android (\d+(?:\.\d+)?)/i',
        'Windows'   => '/Windows NT (\d+\.\d+)/i',
        'macOS'     => '/Mac OS X (\d+[_\.]\d+(?:[_\.]\d+)?)/i',
        'Chrome OS' => '/CrOS \S+ (\d+(?:\.\d+)?)/i',
    ];

    $os = detect_os($ua);
    if (isset($patterns[$os]) && \preg_match($patterns[$os], $ua, $m)) {
        return \str_replace('_', '.', $m[1]);
    }
    return '';
}
