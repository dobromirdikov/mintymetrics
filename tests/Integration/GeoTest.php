<?php

namespace MintyMetrics\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * GeoReader against a tiny synthetic IP2Location DB1 BIN with IPv4 and IPv6 sections,
 * and installing a BIN that arrives inside a ZIP archive.
 */
class GeoTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mm_geo_' . uniqid();
        mkdir($this->dir);
        $GLOBALS['_mm_app_dir'] = $this->dir;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_mm_app_dir']);
        array_map('unlink', array_filter(glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [], 'is_file'));
        @rmdir($this->dir);
    }

    /**
     * Build a DB1 BIN: IPv4 0.0.0.0→"--", 8.0.0.0→$v4Country; IPv6 ::→"--", 2a00::→DE
     * (the IPv6 section is left out of the header when $ipv6 is false).
     */
    private function buildBin(bool $ipv6 = true, string $v4Country = 'US', string $name = 'test.bin'): string
    {
        $strings = "\x02--\x02" . $v4Country . "\x02DE";
        $headerSize = 64;
        $v4Base = $headerSize;
        $v4 = '';
        foreach ([['0.0.0.0', 0], ['8.0.0.0', 3], ['255.255.255.255', 0]] as [$ip, $ptr]) {
            $v4 .= strrev(inet_pton($ip)) . pack('V', 0); // pointer patched below
        }
        $v6Base = $v4Base + strlen($v4);
        $v6 = '';
        foreach ([['::', 0], ['2a00::', 6], ['ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff', 0]] as [$ip, $ptr]) {
            $v6 .= strrev(inet_pton($ip)) . pack('V', 0);
        }
        $strBase = $v6Base + strlen($v6);

        // Patch country pointers (0-based offset of each string's length byte)
        $v4 = substr_replace($v4, pack('V', $strBase), 4, 4);
        $v4 = substr_replace($v4, pack('V', $strBase + 3), 8 + 4, 4);
        $v6 = substr_replace($v6, pack('V', $strBase), 16, 4);
        $v6 = substr_replace($v6, pack('V', $strBase + 6), 20 + 16, 4);

        $header = chr(1) . chr(2) . chr(26) . chr(1) . chr(1)
            . pack('V', 2) . pack('V', $v4Base + 1)
            . pack('V', $ipv6 ? 2 : 0) . pack('V', $ipv6 ? $v6Base + 1 : 0);
        $header = str_pad($header, $headerSize, "\0");

        $path = $this->dir . '/' . $name;
        file_put_contents($path, $header . $v4 . $v6 . $strings . str_repeat("\0", 2000));
        return $path;
    }

    public function testLooksUpIpv4AndIpv6(): void
    {
        $reader = new \MintyMetrics\GeoReader($this->buildBin());

        $this->assertTrue($reader->hasIpv6());
        $this->assertSame('US', $reader->lookup('8.8.8.8'));
        $this->assertSame('US', $reader->lookup('::ffff:8.8.8.8'), 'IPv4-mapped IPv6 uses the IPv4 section');
        $this->assertNull($reader->lookup('1.2.3.4'), '"--" is not a country');
        $this->assertSame('DE', $reader->lookup('2a00:1450:4001:80b::200e'));
        $this->assertNull($reader->lookup('2001:db8::1'));
        $this->assertNull($reader->lookup('not an ip'));
    }

    public function testRejectsFilesThatAreNotBin(): void
    {
        $zipLike = $this->dir . '/fake.BIN';
        file_put_contents($zipLike, "PK\x03\x04" . str_repeat('x', 5000));
        $this->expectException(\RuntimeException::class);
        new \MintyMetrics\GeoReader($zipLike);
    }

    public function testInstallsBinFromZip(): void
    {
        if (!class_exists('ZipArchive')) {
            $this->markTestSkipped('zip extension not available');
        }
        $bin = $this->buildBin();
        $zipPath = $this->dir . '/download.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('README_LITE.TXT', 'readme');
        $zip->addFile($bin, 'IP2LOCATION-LITE-DB1.IPV6.BIN');
        $zip->close();

        $this->assertNull(\MintyMetrics\geo_install_file($zipPath));
        $this->assertFileExists($this->dir . '/IP2LOCATION-LITE-DB1.IPV6.BIN');
        $this->assertSame($this->dir . '/IP2LOCATION-LITE-DB1.IPV6.BIN', \MintyMetrics\geo_db_path());
        $this->assertEmpty(glob($this->dir . '/.geo-install-*'), 'Temporary files are removed');
    }

    public function testNewUploadReplacesOtherEdition(): void
    {
        $this->assertNull(\MintyMetrics\geo_install_file($this->buildBin(true, 'US', 'v6.bin')));
        $this->assertSame($this->dir . '/IP2LOCATION-LITE-DB1.IPV6.BIN', \MintyMetrics\geo_db_path());

        // An IPv4-only upload must become the active database, not hide behind the IPv6 file
        $this->assertNull(\MintyMetrics\geo_install_file($this->buildBin(false, 'GB', 'v4.bin')));
        $this->assertSame($this->dir . '/IP2LOCATION-LITE-DB1.BIN', \MintyMetrics\geo_db_path());
        $this->assertFileDoesNotExist($this->dir . '/IP2LOCATION-LITE-DB1.IPV6.BIN');
        $reader = new \MintyMetrics\GeoReader(\MintyMetrics\geo_db_path());
        $this->assertFalse($reader->hasIpv6());
        $this->assertSame('GB', $reader->lookup('8.8.8.8'));
        unset($reader);

        // And back again
        $this->assertNull(\MintyMetrics\geo_install_file($this->buildBin(true, 'US', 'v6b.bin')));
        $this->assertSame($this->dir . '/IP2LOCATION-LITE-DB1.IPV6.BIN', \MintyMetrics\geo_db_path());
        $this->assertFileDoesNotExist($this->dir . '/IP2LOCATION-LITE-DB1.BIN');
    }

    public function testInstallRejectsGarbage(): void
    {
        $junk = $this->dir . '/junk.bin';
        file_put_contents($junk, '<html>INVALID TOKEN</html>' . str_repeat(' ', 2000));
        $this->assertNotNull(\MintyMetrics\geo_install_file($junk));
        $this->assertFileDoesNotExist($this->dir . '/IP2LOCATION-LITE-DB1.BIN');
    }
}
