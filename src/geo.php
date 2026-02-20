<?php
namespace MintyMetrics;

/**
 * Minimal IP2Location BIN reader for DB1 (country-only, IPv4).
 * Reads the binary database format without requiring the full IP2Location library.
 */
class GeoReader {
    private $handle;
    private int $ipCount;
    private int $baseAddr;
    private int $recordSize;

    public function __construct(string $path) {
        $this->handle = \fopen($path, 'rb');
        if (!$this->handle) {
            throw new \RuntimeException("Cannot open geo database: {$path}");
        }

        // Read header (IP2Location BIN format)
        // Byte 0: DB type, Byte 1: column count, Bytes 2-4: year/month/day
        // Bytes 5-8: IPv4 count, Bytes 9-12: IPv4 base address
        // Note: BIN offsets are 1-based; subtract 1 for 0-based fseek
        $header = \fread($this->handle, 13);
        $columns = \unpack('C', $header[1])[1];
        $this->ipCount = \unpack('V', \substr($header, 5, 4))[1];
        $this->baseAddr = \unpack('V', \substr($header, 9, 4))[1] - 1;
        $this->recordSize = $columns * 4; // 4 bytes per column (ip_from + data pointers)
    }

    public function __destruct() {
        if ($this->handle) {
            \fclose($this->handle);
        }
    }

    /**
     * Look up a country code for an IPv4 address.
     * Returns 2-letter ISO 3166-1 alpha-2 code, or null if not found.
     */
    public function lookup(string $ip): ?string {
        $ipNum = \ip2long($ip);
        if ($ipNum === false) {
            return null; // Invalid IP or IPv6
        }
        $ipNum = (float) \sprintf('%u', $ipNum); // Unsigned

        // Binary search
        $low = 0;
        $high = $this->ipCount;
        $rs = $this->recordSize;

        while ($low <= $high) {
            $mid = (int) (($low + $high) / 2);
            $offset = $this->baseAddr + ($mid * $rs);

            \fseek($this->handle, $offset);
            $data = \fread($this->handle, $rs);
            if (\strlen($data) < $rs) {
                break;
            }

            $ipFrom = (float) \sprintf('%u', \unpack('V', \substr($data, 0, 4))[1]);

            // Read next record's IP to get IP range end
            \fseek($this->handle, $offset + $rs);
            $nextData = \fread($this->handle, 4);
            $ipTo = \strlen($nextData) >= 4
                ? (float) \sprintf('%u', \unpack('V', $nextData)[1])
                : 4294967295.0;

            if ($ipNum < $ipFrom) {
                $high = $mid - 1;
            } elseif ($ipNum >= $ipTo) {
                $low = $mid + 1;
            } else {
                // Found — read country code from the record
                // Pointer targets the string structure: [1 byte length][2 bytes code]
                $countryOffset = \unpack('V', \substr($data, 4, 4))[1];
                \fseek($this->handle, $countryOffset + 1); // +1 to skip length byte
                $countryCode = \fread($this->handle, 2);
                if ($countryCode && \strlen($countryCode) === 2 && $countryCode !== '-' && $countryCode !== '--') {
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
    if (\str_starts_with($ip, '::ffff:')) {
        $ip = \substr($ip, 7);
    }

    // IPv6 is not supported by DB1 LITE
    if (\str_contains($ip, ':')) {
        return null;
    }

    if ($reader === null) {
        $path = geo_db_path();
        if (!$path || !\file_exists($path)) {
            return null;
        }
        try {
            $reader = new GeoReader($path);
        } catch (\Exception $e) {
            log_error('geo_lookup: ' . $e->getMessage());
            return null;
        }
    }

    try {
        return $reader->lookup($ip);
    } catch (\Exception $e) {
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
 * Get the path to the IP2Location BIN file.
 */
function geo_db_path(): ?string {
    $dir = \dirname(\realpath($_SERVER['SCRIPT_FILENAME'] ?? __FILE__));
    $candidates = [
        $dir . '/IP2LOCATION-LITE-DB1.BIN',
        $dir . '/IP2LOCATION-LITE-DB1.IPV6.BIN',
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
 * Handle geo database file upload.
 */
function geo_upload(): array {
    if (empty($_FILES['geofile'])) {
        return ['success' => false, 'message' => 'No file uploaded.'];
    }

    $file = $_FILES['geofile'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Upload error: ' . $file['error']];
    }

    // Reject files over 50 MB
    if ($file['size'] > 50 * 1048576) {
        return ['success' => false, 'message' => 'File too large. IP2Location LITE DB1 should be under 5 MB.'];
    }

    // Validate: check it looks like a BIN file (first byte should be a small number = DB type)
    $firstByte = \ord(\file_get_contents($file['tmp_name'], false, null, 0, 1));
    if ($firstByte < 1 || $firstByte > 25) {
        return ['success' => false, 'message' => 'Invalid file format. Please upload an IP2Location BIN file.'];
    }

    $destPath = geo_db_path();
    $tempPath = $destPath . '.tmp';

    if (!\move_uploaded_file($file['tmp_name'], $tempPath)) {
        return ['success' => false, 'message' => 'Failed to save file. Check directory permissions.'];
    }

    // Verify the file works
    try {
        $reader = new GeoReader($tempPath);
        $test = $reader->lookup('8.8.8.8');
        unset($reader);
    } catch (\Exception $e) {
        @\unlink($tempPath);
        log_error('geo_upload validation: ' . $e->getMessage());
        return ['success' => false, 'message' => 'File validation failed. Ensure this is a valid IP2Location BIN file.'];
    }

    // Rename to final path
    if (\file_exists($destPath)) {
        @\unlink($destPath);
    }
    \rename($tempPath, $destPath);

    return ['success' => true, 'message' => 'Geolocation database installed successfully.'];
}

/**
 * Download the IP2Location LITE database (requires token).
 */
function geo_download(): array {
    $token = $_POST['token'] ?? '';
    if (empty($token)) {
        return ['success' => false, 'message' => 'Download token is required. Get one at https://lite.ip2location.com'];
    }

    $url = 'https://www.ip2location.com/download/?token=' . \urlencode($token) . '&file=DB1LITEBIN';
    $destPath = geo_db_path();
    $tempPath = $destPath . '.tmp';

    // Try curl first, then file_get_contents
    $content = null;
    $maxSize = 50 * 1048576; // 50 MB safety limit
    if (\function_exists('curl_init')) {
        $ch = \curl_init($url);
        \curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_MAXFILESIZE    => $maxSize,
        ]);
        $content = \curl_exec($ch);
        $httpCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        \curl_close($ch);

        if ($httpCode !== 200 || $content === false) {
            $content = null;
        }
    }

    if ($content === null && \ini_get('allow_url_fopen')) {
        $ctx = \stream_context_create(['http' => ['timeout' => 120]]);
        $content = @\file_get_contents($url, false, $ctx);
        if ($content !== false && \strlen($content) > $maxSize) {
            return ['success' => false, 'message' => 'Downloaded file exceeds size limit.'];
        }
    }

    if (!$content || \strlen($content) < 1000) {
        return ['success' => false, 'message' => 'Download failed. Please download manually from https://lite.ip2location.com and upload the BIN file.'];
    }

    // Write to temp file
    if (@\file_put_contents($tempPath, $content) === false) {
        return ['success' => false, 'message' => 'Failed to save file. Check directory permissions.'];
    }

    // Verify
    try {
        $reader = new GeoReader($tempPath);
        unset($reader);
    } catch (\Exception $e) {
        @\unlink($tempPath);
        return ['success' => false, 'message' => 'Downloaded file is not a valid IP2Location BIN database.'];
    }

    if (\file_exists($destPath)) {
        @\unlink($destPath);
    }
    \rename($tempPath, $destPath);

    return ['success' => true, 'message' => 'Geolocation database downloaded and installed successfully.'];
}
