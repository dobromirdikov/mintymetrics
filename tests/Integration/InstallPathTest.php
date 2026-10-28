<?php

namespace MintyMetrics\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Database location, marker, setup token and file-protection helpers, run against
 * a throwaway directory tree: <tmp>/parent/www/analytics.php.
 */
class InstallPathTest extends TestCase
{
    private string $root;
    private string $www;
    private array $serverBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        $this->root = sys_get_temp_dir() . '/mm_paths_' . uniqid();
        $this->www = $this->root . '/parent/www';
        mkdir($this->www, 0777, true);
        $GLOBALS['_mm_app_dir'] = realpath($this->www);
        $this->www = $GLOBALS['_mm_app_dir'];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_mm_app_dir']);
        $_SERVER = $this->serverBackup;
        $this->rmrf($this->root);
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    private function parent(): string
    {
        return dirname($this->www);
    }

    public function testNewInstallUsesParentOnlyWhenOutsideDocroot(): void
    {
        $_SERVER['DOCUMENT_ROOT'] = $this->www;
        $path = \MintyMetrics\resolve_db_path();
        $this->assertSame($this->parent(), dirname($path));
        $this->assertSame($path, \MintyMetrics\read_db_marker()[1], 'The chosen path is pinned in the marker');
    }

    public function testNewInstallStaysNextToScriptWhenParentIsInDocroot(): void
    {
        // e.g. analytics.php in /var/www/html/stats with DOCUMENT_ROOT=/var/www/html
        $_SERVER['DOCUMENT_ROOT'] = $this->parent();
        $path = \MintyMetrics\resolve_db_path();
        $this->assertSame($this->www, dirname($path));
    }

    public function testLegacyMarkerPinsExistingScriptDirDatabase(): void
    {
        // Pre-1.2 install whose DB went next to the script; parent has since become writable
        $_SERVER['DOCUMENT_ROOT'] = $this->www;
        file_put_contents($this->www . '/.mm_db_marker.php', "<?php __HALT_COMPILER();\nabcdef0123456789");
        file_put_contents($this->www . '/mintymetrics-abcdef0123456789.sqlite', 'existing');

        $path = \MintyMetrics\resolve_db_path();

        $this->assertSame($this->www . '/mintymetrics-abcdef0123456789.sqlite', $path);
        $this->assertFileDoesNotExist($this->parent() . '/mintymetrics-abcdef0123456789.sqlite', 'Must not start a new empty database');
        $this->assertSame($path, \MintyMetrics\read_db_marker()[1]);
    }

    public function testLegacyMarkerPinsExistingParentDatabase(): void
    {
        file_put_contents($this->www . '/.mm_db_marker.php', "<?php __HALT_COMPILER();\nabcdef0123456789");
        file_put_contents($this->parent() . '/mintymetrics-abcdef0123456789.sqlite', 'existing');

        $this->assertSame($this->parent() . '/mintymetrics-abcdef0123456789.sqlite', \MintyMetrics\resolve_db_path());
    }

    public function testPinnedPathIsReusedEvenIfAnotherCopyAppears(): void
    {
        $pinned = $this->parent() . '/mintymetrics-abcdef0123456789.sqlite';
        \MintyMetrics\write_db_marker('abcdef0123456789', $pinned);
        file_put_contents($this->www . '/mintymetrics-abcdef0123456789.sqlite', 'other');

        $this->assertSame($pinned, \MintyMetrics\resolve_db_path());
    }

    public function testMalformedMarkerFailsClosed(): void
    {
        file_put_contents($this->www . '/.mm_db_marker.php', "<?php __HALT_COMPILER();\nnot-a-suffix!!");
        $this->expectException(\RuntimeException::class);
        \MintyMetrics\resolve_db_path();
    }

    public function testTruncatedMarkerWithOnlyPrefixFailsClosed(): void
    {
        file_put_contents($this->www . '/.mm_db_marker.php', "<?php __HALT_COMPILER();\n");
        $this->expectException(\RuntimeException::class);
        \MintyMetrics\resolve_db_path();
    }

    public function testReadOnlyLegacyMarkerWithTwoCopiesFailsClosed(): void
    {
        $marker = $this->www . '/.mm_db_marker.php';
        file_put_contents($marker, "<?php __HALT_COMPILER();\nabcdef0123456789");
        file_put_contents($this->parent() . '/mintymetrics-abcdef0123456789.sqlite', 'copy one');
        file_put_contents($this->www . '/mintymetrics-abcdef0123456789.sqlite', 'copy two!');
        chmod($marker, 0444);
        if (is_writable($marker)) {
            chmod($marker, 0644);
            $this->markTestSkipped('Cannot make the marker read-only on this filesystem/user');
        }
        try {
            $this->expectException(\RuntimeException::class);
            \MintyMetrics\resolve_db_path();
        } finally {
            chmod($marker, 0644);
        }
    }

    public function testEmptyMarkerIsTreatedAsNotCreatedYet(): void
    {
        touch($this->www . '/.mm_db_marker.php');
        $path = \MintyMetrics\resolve_db_path();
        $this->assertSame($path, \MintyMetrics\read_db_marker()[1]);
    }

    public function testReadOnlyLegacyMarkerStillUsesExistingDatabase(): void
    {
        $marker = $this->www . '/.mm_db_marker.php';
        file_put_contents($marker, "<?php __HALT_COMPILER();\nabcdef0123456789");
        file_put_contents($this->parent() . '/mintymetrics-abcdef0123456789.sqlite', 'existing');
        chmod($marker, 0444);
        if (is_writable($marker)) {
            chmod($marker, 0644);
            $this->markTestSkipped('Cannot make the marker read-only on this filesystem/user');
        }
        $prevLog = ini_set('error_log', $this->root . '/php-error.log');
        try {
            $this->assertSame($this->parent() . '/mintymetrics-abcdef0123456789.sqlite', \MintyMetrics\resolve_db_path());
        } finally {
            ini_set('error_log', $prevLog === false ? '' : $prevLog);
            chmod($marker, 0644);
        }
    }

    public function testConcurrentFirstRequestsAgreeOnOneDatabase(): void
    {
        $_SERVER['DOCUMENT_ROOT'] = $this->www;
        $script = __DIR__ . '/../fixtures/resolve_path.php';
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($this->www);
        $procs = [];
        for ($i = 0; $i < 6; $i++) {
            $procs[] = popen($cmd, 'r');
        }
        $paths = array_map(fn($p) => trim(stream_get_contents($p)) . (pclose($p) ? '' : ''), $procs);
        $this->assertCount(1, array_unique($paths), implode("\n", $paths));
        $this->assertSame($paths[0], \MintyMetrics\read_db_marker()[1]);
    }

    public function testNoFilesBeforeSetup(): void
    {
        $this->assertFalse(\MintyMetrics\db_marker_exists());
        $this->assertSame([], \MintyMetrics\get_allowed_domains());
        $this->assertSame(['.', '..'], scandir($this->www));
    }

    public function testSetupTokenIsStableAndProtected(): void
    {
        $token = \MintyMetrics\setup_token();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{24}$/', $token);
        $this->assertSame($token, \MintyMetrics\setup_token());
        $this->assertStringStartsWith('<?php __HALT_COMPILER();', file_get_contents(\MintyMetrics\setup_token_path()));
    }

    public function testHtaccessRulesCoverWalFilesAndUpgradeOldRules(): void
    {
        file_put_contents($this->www . '/.htaccess', "<Files \"mintymetrics-abc.sqlite\">\n    Require all denied\n</Files>\n");
        $this->assertSame('partial', \MintyMetrics\check_htaccess()['status'], 'Old rules did not cover -wal/-shm');

        $this->assertTrue(\MintyMetrics\fix_htaccess());
        $this->assertSame('ok', \MintyMetrics\check_htaccess()['status']);

        $rules = file_get_contents($this->www . '/.htaccess');
        preg_match('/<FilesMatch "([^"]+)">/', $rules, $m);
        $re = '/' . $m[1] . '/';
        foreach (['mintymetrics-abcdef0123456789.sqlite', 'mintymetrics-abcdef0123456789.sqlite-wal',
                  'mintymetrics-abcdef0123456789.sqlite-shm', '.mm_db_marker.php', '.mm_setup_token.php'] as $file) {
            $this->assertMatchesRegularExpression($re, $file);
        }
        $this->assertDoesNotMatchRegularExpression($re, 'analytics.php');
    }

    public function testPrivateDirGetsDenyRules(): void
    {
        $dir = $this->parent() . '/mm_sessions';
        $this->assertTrue(\MintyMetrics\ensure_private_dir($dir));
        $this->assertStringContainsString('Require all denied', file_get_contents($dir . '/.htaccess'));
        $this->assertFileExists($dir . '/index.html');
    }
}
