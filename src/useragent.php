<?php
namespace MintyMetrics;

/**
 * Parse a User-Agent string into device type, browser, and OS components.
 *
 * @return array{device: string, browser: string, browser_ver: string, os: string, os_ver: string}
 */
function parse_ua(string $ua): array {
    $browser = detect_browser($ua);
    return [
        'device'      => detect_device($ua),
        'browser'     => $browser,
        'browser_ver' => detect_browser_version($ua, $browser),
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

/**
 * Browser detection rules, most specific first. Each entry is
 * [name, detection regex, version regex (capture group 1)].
 */
function browser_rules(): array {
    return [
        // In-app browsers report their host app, not the engine underneath
        ['Instagram', '/Instagram/i', '/Instagram (\d+(?:\.\d+)?)/i'],
        ['Facebook', '/FBAN|FBAV|FB_IAB/i', '/FBAV\/(\d+(?:\.\d+)?)/i'],
        ['Google App', '/\bGSA\//', '/GSA\/(\d+(?:\.\d+)?)/'],
        ['Edge', '/Edg(?:e|A|iOS)?\/[\d.]+/i', '/Edg(?:e|A|iOS)?\/(\d+(?:\.\d+)?)/i'],
        ['Opera', '/OPR\/|OPiOS|Opera/i', '/(?:OPR|OPiOS|Opera Mini|Opera Mobi|Version)\/(\d+(?:\.\d+)?)/i'],
        ['Samsung Internet', '/SamsungBrowser/i', '/SamsungBrowser\/(\d+(?:\.\d+)?)/i'],
        ['UC Browser', '/UCBrowser|UCWEB/i', '/UCBrowser\/(\d+(?:\.\d+)?)/i'],
        ['Brave', '/Brave/i', '/Brave\/(\d+(?:\.\d+)?)/i'],
        ['Vivaldi', '/Vivaldi/i', '/Vivaldi\/(\d+(?:\.\d+)?)/i'],
        ['Yandex', '/YaBrowser/i', '/YaBrowser\/(\d+(?:\.\d+)?)/i'],
        ['Firefox', '/Firefox|FxiOS/i', '/(?:Firefox|FxiOS)\/(\d+(?:\.\d+)?)/i'],
        ['Chrome', '/CriOS/i', '/CriOS\/(\d+(?:\.\d+)?)/i'],
        // Android System WebView marks itself with "; wv)"
        ['Android WebView', '/;\s*wv\)/i', '/Chrome\/(\d+(?:\.\d+)?)/i'],
        ['Chromium', '/Chromium/i', '/Chromium\/(\d+(?:\.\d+)?)/i'],
        ['Chrome', '/Chrome\/[\d.]+/i', '/Chrome\/(\d+(?:\.\d+)?)/i'],
        ['Safari', '/Version\/[\d.]+.*Safari\//i', '/Version\/(\d+(?:\.\d+)?)/i'],
        // iOS apps embedding WebKit (WKWebView) omit the Safari/ token
        ['iOS WebView', '/(?:iPhone|iPad|iPod).*AppleWebKit(?!.*Safari\/)/i', '/OS (\d+[_.]\d+)/i'],
        ['Safari', '/Safari\/[\d.]+/i', '/Version\/(\d+(?:\.\d+)?)/i'],
        ['Internet Explorer', '/MSIE|Trident/i', '/(?:MSIE |rv:)(\d+(?:\.\d+)?)/i'],
    ];
}

function detect_browser(string $ua): string {
    foreach (browser_rules() as [$name, $detect]) {
        if (\preg_match($detect, $ua)) {
            return $name;
        }
    }
    return 'Other';
}

/**
 * Version of the browser that detect_browser() found (never another token in the UA).
 */
function detect_browser_version(string $ua, ?string $browser = null): string {
    $browser ??= detect_browser($ua);
    foreach (browser_rules() as [$name, $detect, $version]) {
        if ($name === $browser && \preg_match($detect, $ua)) {
            return \preg_match($version, $ua, $m) ? \str_replace('_', '.', $m[1]) : '';
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
    if (!isset($patterns[$os]) || !\preg_match($patterns[$os], $ua, $m)) {
        return '';
    }
    $version = \str_replace('_', '.', $m[1]);

    // Browsers freeze these values: Windows 11 still says NT 10.0, and every macOS
    // since Catalina says 10.15.7. Label them as ranges instead of a false precise version.
    if ($os === 'Windows' && $version === '10.0') {
        return '10/11';
    }
    if ($os === 'macOS' && \str_starts_with($version, '10.15')) {
        return '10.15+';
    }
    return $version;
}
