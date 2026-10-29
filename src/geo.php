<?php
namespace MintyMetrics;

/** Largest geo download/upload accepted (the DB1 IPv6 LITE BIN is ~30 MB unzipped). */
const GEO_MAX_BYTES = 100 * 1048576;

/**
 * Minimal IP2Location BIN reader for DB1 (country-only), IPv4 and IPv6.
 * Reads the binary database format without requiring the full IP2Location library.
 */
class GeoReader {
    private $handle;
    private int $ipCount;
    private int $baseAddr;
    private int $recordSize;
    private int $ipv6Count;
    private int $ipv6BaseAddr;
    private int $ipv6RecordSize;

    public function __construct(string $path) {
        $this->handle = @\fopen($path, 'rb');
        if (!$this->handle) {
            throw new \RuntimeException("Cannot open geo database: {$path}");
        }

        // Header (IP2Location BIN format): byte 0 DB type, byte 1 column count,
        // bytes 2-4 date, 5-8 IPv4 count, 9-12 IPv4 base, 13-16 IPv6 count, 17-20 IPv6 base.
        // BIN offsets are 1-based; subtract 1 for 0-based fseek.
        $header = (string) \fread($this->handle, 21);
        if (\strlen($header) < 21) {
            throw new \RuntimeException('Geo database is too small to be an IP2Location BIN file');
        }
        $dbType = \ord($header[0]);
        $columns = \ord($header[1]);
        $this->ipCount = \unpack('V', \substr($header, 5, 4))[1];
        $this->baseAddr = \unpack('V', \substr($header, 9, 4))[1] - 1;
        $this->recordSize = $columns * 4; // ip_from + one 4-byte pointer per data column
        $this->ipv6Count = \unpack('V', \substr($header, 13, 4))[1];
        $this->ipv6BaseAddr = \unpack('V', \substr($header, 17, 4))[1] - 1;
        $this->ipv6RecordSize = 16 + ($columns - 1) * 4; // 128-bit ip_from + pointers

        // Reject anything that is not a plausible BIN file (e.g. a ZIP archive or an
        // HTML error page saved under the .BIN name) instead of returning garbage
        $size = (int) \fstat($this->handle)['size'];
        if ($dbType < 1 || $dbType > 25 || $columns < 2 || $columns > 30 || $this->ipCount < 1
            || $this->baseAddr < 0 || $this->baseAddr + $this->ipCount * $this->recordSize > $size) {
            throw new \RuntimeException('Not a valid IP2Location BIN file');
        }
        if ($this->ipv6Count > 0
            && ($this->ipv6BaseAddr < 0 || $this->ipv6BaseAddr + $this->ipv6Count * $this->ipv6RecordSize > $size)) {
            $this->ipv6Count = 0; // Ignore a broken IPv6 section rather than misreading it
        }
    }

    public function __destruct() {
        if ($this->handle) {
            \fclose($this->handle);
        }
    }

    /**
     * Whether this file contains IPv6 data (IP2LOCATION-LITE-DB1.IPV6.BIN).
     */
    public function hasIpv6(): bool {
        return $this->ipv6Count > 0;
    }

    /**
     * Look up a country code for an IPv4 or IPv6 address.
     * Returns 2-letter ISO 3166-1 alpha-2 code, or null if not found.
     */
    public function lookup(string $ip): ?string {
        $packed = @\inet_pton($ip);
        if ($packed === false) {
            return null;
        }
        // IPv4-mapped IPv6 (::ffff:a.b.c.d) is an IPv4 address
        if (\strlen($packed) === 16 && \str_starts_with($packed, \str_repeat("\0", 10) . "\xff\xff")) {
            $packed = \substr($packed, 12);
        }
        if (\strlen($packed) === 4) {
            return $this->search($packed, $this->baseAddr, $this->ipCount, $this->recordSize, 4);
        }
        if ($this->ipv6Count === 0) {
            return null;
        }
        return $this->search($packed, $this->ipv6BaseAddr, $this->ipv6Count, $this->ipv6RecordSize, 16);
    }

    /**
     * Binary search over records whose ip_from is stored little-endian. Addresses are
     * compared as big-endian byte strings, which works for both 32- and 128-bit values.
     */
    private function search(string $target, int $base, int $count, int $rs, int $width): ?string {
        $low = 0;
        $high = $count;

        while ($low <= $high) {
            $mid = \intdiv($low + $high, 2);
            $offset = $base + ($mid * $rs);

            \fseek($this->handle, $offset);
            $data = (string) \fread($this->handle, $rs + $width); // this record + next ip_from
            if (\strlen($data) < $rs) {
                break;
            }

            $ipFrom = \strrev(\substr($data, 0, $width));
            $ipTo = \strlen($data) >= $rs + $width ? \strrev(\substr($data, $rs, $width)) : \str_repeat("\xff", $width);

            if (\strcmp($target, $ipFrom) < 0) {
                $high = $mid - 1;
            } elseif (\strcmp($target, $ipTo) >= 0) {
                $low = $mid + 1;
            } else {
                // Pointer targets the string structure: [1 byte length][2 bytes code]
                $countryOffset = \unpack('V', \substr($data, $width, 4))[1];
                \fseek($this->handle, $countryOffset + 1); // +1 to skip length byte
                $countryCode = \fread($this->handle, 2);
                if ($countryCode && \strlen($countryCode) === 2 && $countryCode !== '-' && $countryCode !== '--'
                    && \ctype_alpha($countryCode)) {
                    return \strtoupper($countryCode);
                }
                return null;
            }
        }

        return null;
    }
}

// ─── Geo Functions ──────────────────────────────────────────────────────────

/**
 * Look up a country code for an IP address.
 */
function geo_lookup(string $ip): ?string {
    static $reader = null;

    // Handle IPv4-mapped IPv6 (::ffff:1.2.3.4) — common on dual-stack hosts
    if (\str_starts_with(\strtolower($ip), '::ffff:') && \str_contains($ip, '.')) {
        $ip = \substr($ip, 7);
    }

    if ($reader === null) {
        $path = geo_db_path();
        if (!$path || !\file_exists($path)) {
            return null;
        }
        try {
            $reader = new GeoReader($path);
        } catch (\Throwable $e) {
            log_error('geo_lookup: ' . $e->getMessage());
            $reader = false;
        }
    }
    if ($reader === false) {
        return null;
    }

    try {
        return $reader->lookup($ip);
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Check if the IP2Location database file is available.
 */
function geo_available(): bool {
    if (get_config('enable_geo', '1') !== '1') {
        return false;
    }
    $path = geo_db_path();
    return $path !== null && \file_exists($path);
}

/**
 * Get the path to the IP2Location BIN file. The IPv6 edition (which also covers
 * IPv4) is preferred when both are present.
 */
function geo_db_path(): ?string {
    $dir = app_dir();
    $candidates = [
        $dir . '/IP2LOCATION-LITE-DB1.IPV6.BIN',
        $dir . '/IP2LOCATION-LITE-DB1.BIN',
        $dir . '/ip2location.bin',
    ];
    foreach ($candidates as $path) {
        if (\file_exists($path)) {
            return $path;
        }
    }
    return $dir . '/IP2LOCATION-LITE-DB1.BIN'; // Default expected path
}

/**
 * Validate a downloaded/uploaded file (BIN, or a ZIP containing one) and install it.
 * Returns an error message, or null on success.
 */
function geo_install_file(string $file): ?string {
    $dir = app_dir();
    $bin = $dir . '/.geo-install-' . \bin2hex(\random_bytes(4)) . '.tmp';

    try {
        // ip2location.com serves ZIP archives; extract the BIN inside
        $magic = (string) @\file_get_contents($file, false, null, 0, 4);
        if ($magic === "PK\x03\x04") {
            $error = geo_extract_bin($file, $bin);
            if ($error !== null) {
                return $error;
            }
        } elseif (!@\copy($file, $bin)) {
            return 'Failed to save file. Check directory permissions.';
        }

        // Must parse, and must actually resolve a well-known address
        try {
            $reader = new GeoReader($bin);
            $test = $reader->lookup('8.8.8.8');
            $ipv6 = $reader->hasIpv6();
            unset($reader);
        } catch (\Throwable $e) {
            log_error('geo validation: ' . $e->getMessage());
            return 'This is not a valid IP2Location LITE DB1 BIN file.';
        }
        if ($test === null) {
            return 'The file could not resolve a test address. Use the IP2Location LITE DB1 (country) database.';
        }

        $dest = $dir . ($ipv6 ? '/IP2LOCATION-LITE-DB1.IPV6.BIN' : '/IP2LOCATION-LITE-DB1.BIN');
        if (!@\rename($bin, $dest)) {
            return 'Failed to save file. Check directory permissions.';
        }

        // The file just validated must be the one geo_db_path() picks: remove other editions
        foreach (['/IP2LOCATION-LITE-DB1.IPV6.BIN', '/IP2LOCATION-LITE-DB1.BIN', '/ip2location.bin'] as $name) {
            $other = $dir . $name;
            if ($other !== $dest && \file_exists($other)) {
                @\unlink($other);
            }
        }
        if (geo_db_path() !== $dest) {
            return 'The new database was saved, but an older one could not be removed. Delete the other IP2Location .BIN files next to analytics.php.';
        }
        return null;
    } finally {
        @\unlink($bin);
    }
}

/**
 * Extract the (largest) .BIN entry of a ZIP archive to $dest without loading it into memory.
 */
function geo_extract_bin(string $zipPath, string $dest): ?string {
    if (!\class_exists('ZipArchive')) {
        return 'This server cannot open ZIP files. Extract the .BIN file and upload it instead.';
    }
    $zip = new \ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return 'The downloaded archive could not be opened.';
    }
    try {
        $best = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat && \preg_match('/\.bin$/i', $stat['name']) && ($best === null || $stat['size'] > $best['size'])) {
                $best = $stat;
            }
        }
        if ($best === null) {
            return 'The archive does not contain a .BIN file.';
        }
        if ($best['size'] > GEO_MAX_BYTES) {
            return 'The database in the archive is too large.';
        }
        $in = $zip->getStream($best['name']);
        $out = @\fopen($dest, 'wb');
        if (!$in || !$out) {
            return 'Failed to extract the database. Check directory permissions.';
        }
        $copied = \stream_copy_to_stream($in, $out, GEO_MAX_BYTES + 1);
        \fclose($in);
        \fclose($out);
        if ($copied === false || $copied > GEO_MAX_BYTES) {
            return 'Failed to extract the database.';
        }
        return null;
    } finally {
        $zip->close();
    }
}

/**
 * Handle geo database file upload (.BIN, or the .ZIP as downloaded from IP2Location).
 */
function geo_upload(): array {
    if (empty($_FILES['geofile']) || !\is_array($_FILES['geofile']) || \is_array($_FILES['geofile']['error'] ?? null)) {
        return ['success' => false, 'message' => 'No file uploaded.'];
    }

    $file = $_FILES['geofile'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Upload error: ' . $file['error']];
    }

    if ($file['size'] > GEO_MAX_BYTES) {
        return ['success' => false, 'message' => 'File too large. IP2Location LITE DB1 should be well under 100 MB.'];
    }
    if (!\is_uploaded_file($file['tmp_name'])) {
        return ['success' => false, 'message' => 'No file uploaded.'];
    }

    $error = geo_install_file($file['tmp_name']);
    if ($error !== null) {
        return ['success' => false, 'message' => $error];
    }
    return ['success' => true, 'message' => 'Geolocation database installed successfully.'];
}

/**
 * Download the IP2Location LITE database (requires token). Fetches the IPv6 edition,
 * which covers both IPv4 and IPv6 visitors.
 */
function geo_download(): array {
    $token = \trim($_POST['token'] ?? '');
    if ($token === '' || !\preg_match('/^[A-Za-z0-9]{8,128}$/', $token)) {
        return ['success' => false, 'message' => 'A valid download token is required. Get one at https://lite.ip2location.com'];
    }

    $url = 'https://www.ip2location.com/download/?token=' . \urlencode($token) . '&file=DB1LITEBINIPV6';
    $tempPath = app_dir() . '/.geo-download-' . \bin2hex(\random_bytes(4)) . '.tmp';

    try {
        // Stream straight to disk with a size cap (never buffer the whole file in memory)
        $error = geo_fetch($url, $tempPath);
        if ($error !== null) {
            return ['success' => false, 'message' => $error];
        }

        $error = geo_install_file($tempPath);
        if ($error !== null) {
            return ['success' => false, 'message' => $error . ' You can also download it manually from https://lite.ip2location.com and upload it.'];
        }
        return ['success' => true, 'message' => 'Geolocation database downloaded and installed successfully.'];
    } finally {
        @\unlink($tempPath);
    }
}

/**
 * Download a URL to a file, capped at GEO_MAX_BYTES. Returns an error message or null.
 */
function geo_fetch(string $url, string $dest): ?string {
    $out = @\fopen($dest, 'wb');
    if (!$out) {
        return 'Failed to save file. Check directory permissions.';
    }

    $ok = false;
    if (\function_exists('curl_init')) {
        // Count bytes as they are written: CURLOPT_MAXFILESIZE alone is only enforced
        // during transfer from libcurl 8.4, and never without a Content-Length.
        $written = 0;
        $ch = \curl_init($url);
        \curl_setopt_array($ch, [
            CURLOPT_WRITEFUNCTION  => function ($ch, string $chunk) use ($out, &$written): int {
                $written += \strlen($chunk);
                if ($written > GEO_MAX_BYTES) {
                    return 0; // abort the transfer
                }
                return (int) \fwrite($out, $chunk);
            },
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_MAXFILESIZE    => GEO_MAX_BYTES,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        ]);
        $ok = \curl_exec($ch) !== false && \curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        \curl_close($ch);
    } elseif (\ini_get('allow_url_fopen')) {
        $in = @\fopen($url, 'rb', false, \stream_context_create(['http' => ['timeout' => 180]]));
        if ($in) {
            $copied = \stream_copy_to_stream($in, $out, GEO_MAX_BYTES + 1);
            \fclose($in);
            $ok = $copied !== false && $copied <= GEO_MAX_BYTES;
        }
    }
    \fclose($out);

    if (!$ok || \filesize($dest) < 1000) {
        // Small responses are error messages such as "INVALID TOKEN" or a download quota notice
        $body = $ok ? \trim((string) \file_get_contents($dest, false, null, 0, 200)) : '';
        return 'Download failed' . ($body !== '' && !\preg_match('/[^\x20-\x7e]/', $body) ? ' (' . $body . ')' : '') . '.';
    }
    return null;
}
