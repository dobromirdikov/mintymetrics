<?php

namespace MintyMetrics\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Base test case for integration tests that need a fresh SQLite database.
 *
 * Creates an in-memory database before each test and tears it down after.
 * Overrides MintyMetrics\db() to return the test database instance.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected static \SQLite3 $testDb;
    protected static string $testDbPath;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a temp SQLite file for each test
        self::$testDbPath = sys_get_temp_dir() . '/mintymetrics_test_' . uniqid() . '.sqlite';
        self::$testDb = new \SQLite3(self::$testDbPath);
        self::$testDb->exec('PRAGMA journal_mode = WAL');
        self::$testDb->exec('PRAGMA busy_timeout = 5000');
        self::$testDb->exec('PRAGMA foreign_keys = ON');

        // Initialize schema
        \MintyMetrics\db_init_with(self::$testDb);

        // Inject test database into db() singleton via global override
        $GLOBALS['_mm_test_db'] = self::$testDb;

        // Reset session
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        // Close and clean up
        if (isset(self::$testDb)) {
            self::$testDb->close();
        }
        if (file_exists(self::$testDbPath)) {
            @unlink(self::$testDbPath);
            @unlink(self::$testDbPath . '-wal');
            @unlink(self::$testDbPath . '-shm');
        }

        // Remove test db injection
        unset($GLOBALS['_mm_test_db']);

        // Reset globals
        $_GET = [];
        $_POST = [];
        $_SESSION = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_USER_AGENT'] = '';

        parent::tearDown();
    }

    /**
     * Insert a test hit directly into the database.
     */
    protected function insertHit(array $data = []): int
    {
        $defaults = [
            'site' => 'test.com',
            'visitor_hash' => hash('sha256', 'test_visitor_' . uniqid()),
            'page_path' => '/',
            'referrer_url' => null,
            'referrer_domain' => null,
            'utm_source' => null,
            'utm_medium' => null,
            'utm_campaign' => null,
            'utm_term' => null,
            'utm_content' => null,
            'country_code' => null,
            'device_type' => 'desktop',
            'browser' => 'Chrome',
            'browser_ver' => '120.0',
            'os' => 'Windows',
            'os_ver' => '10.0',
            'screen_res' => '1920x1080',
            'language' => 'en-US',
            'time_on_page' => null,
            'last_active_at' => null,
            'created_at' => time(),
        ];

        $row = array_merge($defaults, $data);

        $stmt = self::$testDb->prepare('
            INSERT INTO hits (site, visitor_hash, page_path, referrer_url, referrer_domain,
                utm_source, utm_medium, utm_campaign, utm_term, utm_content,
                country_code, device_type, browser, browser_ver, os, os_ver,
                screen_res, language, time_on_page, last_active_at, created_at)
            VALUES (:site, :hash, :page, :ref_url, :ref_domain,
                :utm_s, :utm_m, :utm_c, :utm_t, :utm_ct,
                :country, :device, :browser, :browser_ver, :os, :os_ver,
                :screen, :lang, :time_on_page, :last_active_at, :created_at)
        ');

        $stmt->bindValue(':site', $row['site'], SQLITE3_TEXT);
        $stmt->bindValue(':hash', $row['visitor_hash'], SQLITE3_TEXT);
        $stmt->bindValue(':page', $row['page_path'], SQLITE3_TEXT);
        $stmt->bindValue(':ref_url', $row['referrer_url'], $row['referrer_url'] ? SQLITE3_TEXT : SQLITE3_NULL);
        $stmt->bindValue(':ref_domain', $row['referrer_domain'], $row['referrer_domain'] ? SQLITE3_TEXT : SQLITE3_NULL);
        $stmt->bindValue(':utm_s', $row['utm_source'], $row['utm_source'] ? SQLITE3_TEXT : SQLITE3_NULL);
        $stmt->bindValue(':utm_m', $row['utm_medium'], $row['utm_medium'] ? SQLITE3_TEXT : SQLITE3_NULL);
        $stmt->bindValue(':utm_c', $row['utm_campaign'], $row['utm_campaign'] ? SQLITE3_TEXT : SQLITE3_NULL);
        $stmt->bindValue(':utm_t', $row['utm_term'], $row['utm_term'] ? SQLITE3_TEXT : SQLITE3_NULL);
        $stmt->bindValue(':utm_ct', $row['utm_content'], $row['utm_content'] ? SQLITE3_TEXT : SQLITE3_NULL);
        $stmt->bindValue(':country', $row['country_code'], $row['country_code'] ? SQLITE3_TEXT : SQLITE3_NULL);
        $stmt->bindValue(':device', $row['device_type'], SQLITE3_TEXT);
        $stmt->bindValue(':browser', $row['browser'], SQLITE3_TEXT);
        $stmt->bindValue(':browser_ver', $row['browser_ver'], SQLITE3_TEXT);
        $stmt->bindValue(':os', $row['os'], SQLITE3_TEXT);
        $stmt->bindValue(':os_ver', $row['os_ver'], SQLITE3_TEXT);
        $stmt->bindValue(':screen', $row['screen_res'], SQLITE3_TEXT);
        $stmt->bindValue(':lang', $row['language'], SQLITE3_TEXT);
        $stmt->bindValue(':time_on_page', $row['time_on_page'], $row['time_on_page'] !== null ? SQLITE3_INTEGER : SQLITE3_NULL);
        $stmt->bindValue(':last_active_at', $row['last_active_at'], $row['last_active_at'] !== null ? SQLITE3_INTEGER : SQLITE3_NULL);
        $stmt->bindValue(':created_at', $row['created_at'], SQLITE3_INTEGER);
        $stmt->execute();

        return (int) self::$testDb->lastInsertRowID();
    }

    /**
     * Set a config value in the test database.
     */
    protected function setConfig(string $key, string $value): void
    {
        $stmt = self::$testDb->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (:key, :value)');
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $stmt->bindValue(':value', $value, SQLITE3_TEXT);
        $stmt->execute();
    }

    /**
     * Get a config value from the test database.
     */
    protected function getConfig(string $key): ?string
    {
        $stmt = self::$testDb->prepare('SELECT value FROM settings WHERE key = :key');
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return $result ? $result['value'] : null;
    }

    /**
     * Count rows in a table.
     */
    protected function countRows(string $table, string $where = '1=1'): int
    {
        return (int) self::$testDb->querySingle("SELECT COUNT(*) FROM {$table} WHERE {$where}");
    }
}
