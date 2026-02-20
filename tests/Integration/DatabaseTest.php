<?php

namespace MintyMetrics\Tests\Integration;

use MintyMetrics\Tests\IntegrationTestCase;

class DatabaseTest extends IntegrationTestCase
{
    // ─── Schema Creation ────────────────────────────────────────────────

    public function testSchemaCreatesAllTables(): void
    {
        $tables = [];
        $result = self::$testDb->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $tables[] = $row['name'];
        }

        $this->assertContains('hits', $tables);
        $this->assertContains('daily_summaries', $tables);
        $this->assertContains('auth', $tables);
        $this->assertContains('settings', $tables);
        $this->assertContains('rate_limit', $tables);
        $this->assertContains('mm_logs', $tables);
    }

    public function testSchemaVersionIsSet(): void
    {
        $version = self::$testDb->querySingle("SELECT value FROM settings WHERE key = 'schema_version'");
        $this->assertSame('2', $version);
    }

    public function testSchemaCreatesIndexes(): void
    {
        $indexes = [];
        $result = self::$testDb->query("SELECT name FROM sqlite_master WHERE type='index' AND name LIKE 'idx_%' ORDER BY name");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $indexes[] = $row['name'];
        }

        $this->assertContains('idx_hits_site_created', $indexes);
        $this->assertContains('idx_hits_visitor', $indexes);
        $this->assertContains('idx_hits_page', $indexes);
        $this->assertContains('idx_hits_created', $indexes);
        $this->assertContains('idx_summaries_site_date', $indexes);
        $this->assertContains('idx_ratelimit_time', $indexes);
    }

    public function testSchemaIdempotent(): void
    {
        // Running init again should not throw or create duplicate tables
        \MintyMetrics\db_init_with(self::$testDb);

        $count = self::$testDb->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='hits'");
        $this->assertSame(1, $count);
    }

    // ─── Settings CRUD ──────────────────────────────────────────────────

    public function testSetAndGetConfig(): void
    {
        $this->setConfig('test_key', 'test_value');
        $this->assertSame('test_value', $this->getConfig('test_key'));
    }

    public function testSetConfigOverwritesExisting(): void
    {
        $this->setConfig('color', 'red');
        $this->setConfig('color', 'blue');
        $this->assertSame('blue', $this->getConfig('color'));
    }

    public function testGetConfigReturnsNullForMissing(): void
    {
        $this->assertNull($this->getConfig('nonexistent_key'));
    }

    // ─── Hits Table ─────────────────────────────────────────────────────

    public function testInsertHitWithDefaults(): void
    {
        $id = $this->insertHit();
        $this->assertGreaterThan(0, $id);
        $this->assertSame(1, $this->countRows('hits'));
    }

    public function testInsertHitWithCustomData(): void
    {
        $this->insertHit([
            'site' => 'mysite.com',
            'page_path' => '/about',
            'browser' => 'Firefox',
            'os' => 'Linux',
            'country_code' => 'US',
        ]);

        $row = self::$testDb->querySingle("SELECT * FROM hits WHERE site = 'mysite.com'", true);
        $this->assertSame('/about', $row['page_path']);
        $this->assertSame('Firefox', $row['browser']);
        $this->assertSame('Linux', $row['os']);
        $this->assertSame('US', $row['country_code']);
    }

    public function testInsertMultipleHits(): void
    {
        $this->insertHit(['page_path' => '/page1']);
        $this->insertHit(['page_path' => '/page2']);
        $this->insertHit(['page_path' => '/page3']);

        $this->assertSame(3, $this->countRows('hits'));
    }

    public function testHitDefaultTimestampIsSet(): void
    {
        $now = time();
        $this->insertHit(['created_at' => $now]);

        $row = self::$testDb->querySingle('SELECT created_at FROM hits LIMIT 1', true);
        $this->assertSame($now, (int) $row['created_at']);
    }

    // ─── last_active_at Column ─────────────────────────────────────────

    public function testHitsTableHasLastActiveAtColumn(): void
    {
        $result = self::$testDb->query("PRAGMA table_info(hits)");
        $columns = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $columns[] = $row['name'];
        }

        $this->assertContains('last_active_at', $columns);
    }

    public function testSchemaMigrationV1ToV2(): void
    {
        // Create a fresh DB with a v1 schema (no last_active_at)
        $v1Path = sys_get_temp_dir() . '/mintymetrics_v1_' . uniqid() . '.sqlite';
        $v1Db = new \SQLite3($v1Path);
        $v1Db->exec('PRAGMA journal_mode = WAL');

        // Create a minimal v1 schema without last_active_at
        $v1Db->exec("
            CREATE TABLE IF NOT EXISTS hits (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                site         TEXT NOT NULL DEFAULT '',
                visitor_hash TEXT NOT NULL,
                page_path    TEXT NOT NULL,
                referrer_url TEXT DEFAULT NULL,
                referrer_domain TEXT DEFAULT NULL,
                utm_source   TEXT DEFAULT NULL,
                utm_medium   TEXT DEFAULT NULL,
                utm_campaign TEXT DEFAULT NULL,
                utm_term     TEXT DEFAULT NULL,
                utm_content  TEXT DEFAULT NULL,
                country_code TEXT DEFAULT NULL,
                device_type  TEXT DEFAULT NULL,
                browser      TEXT DEFAULT NULL,
                browser_ver  TEXT DEFAULT NULL,
                os           TEXT DEFAULT NULL,
                os_ver       TEXT DEFAULT NULL,
                screen_res   TEXT DEFAULT NULL,
                language     TEXT DEFAULT NULL,
                time_on_page    INTEGER DEFAULT NULL,
                created_at      INTEGER NOT NULL DEFAULT (strftime('%s', 'now'))
            )
        ");
        $v1Db->exec("
            CREATE TABLE IF NOT EXISTS settings (
                key   TEXT PRIMARY KEY,
                value TEXT NOT NULL
            )
        ");
        $v1Db->exec("
            CREATE TABLE IF NOT EXISTS daily_summaries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                date TEXT NOT NULL, site TEXT NOT NULL DEFAULT '',
                pageviews INTEGER NOT NULL DEFAULT 0, unique_visitors INTEGER NOT NULL DEFAULT 0,
                bounce_rate REAL DEFAULT NULL, avg_time_on_page REAL DEFAULT NULL,
                top_pages TEXT DEFAULT NULL, top_referrers TEXT DEFAULT NULL,
                top_countries TEXT DEFAULT NULL, devices TEXT DEFAULT NULL,
                browsers TEXT DEFAULT NULL, operating_systems TEXT DEFAULT NULL,
                utm_summary TEXT DEFAULT NULL, screens TEXT DEFAULT NULL,
                languages TEXT DEFAULT NULL, UNIQUE(date, site)
            )
        ");
        $v1Db->exec("
            CREATE TABLE IF NOT EXISTS auth (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                password_hash TEXT NOT NULL,
                failed_attempts INTEGER NOT NULL DEFAULT 0,
                last_failed_at INTEGER DEFAULT NULL,
                lockout_until INTEGER DEFAULT NULL
            )
        ");
        $v1Db->exec("
            CREATE TABLE IF NOT EXISTS rate_limit (
                ip_hash TEXT PRIMARY KEY,
                last_hit_at REAL NOT NULL
            )
        ");
        $v1Db->exec("
            CREATE TABLE IF NOT EXISTS mm_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                level TEXT NOT NULL DEFAULT 'error',
                message TEXT NOT NULL,
                created_at INTEGER NOT NULL
            )
        ");
        $v1Db->exec("INSERT INTO settings (key, value) VALUES ('schema_version', '1')");

        // Verify v1 does NOT have last_active_at
        $cols = [];
        $r = $v1Db->query("PRAGMA table_info(hits)");
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
            $cols[] = $row['name'];
        }
        $this->assertNotContains('last_active_at', $cols, 'v1 schema should not have last_active_at');

        // Run migration
        \MintyMetrics\db_init_with($v1Db);

        // Verify last_active_at was added
        $cols = [];
        $r = $v1Db->query("PRAGMA table_info(hits)");
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
            $cols[] = $row['name'];
        }
        $this->assertContains('last_active_at', $cols, 'v2 schema should have last_active_at');

        // Verify schema version is now 2
        $version = $v1Db->querySingle("SELECT value FROM settings WHERE key = 'schema_version'");
        $this->assertSame('2', $version);

        $v1Db->close();
        @unlink($v1Path);
        @unlink($v1Path . '-wal');
        @unlink($v1Path . '-shm');
    }

    // ─── get_config / set_config Integration ─────────────────────────────

    public function testGetConfigReturnsDefaultWhenKeyMissing(): void
    {
        $result = \MintyMetrics\get_config('nonexistent_key_xyz', 'my_default');
        $this->assertSame('my_default', $result);
    }

    public function testGetConfigReturnsNullDefaultWhenKeyMissing(): void
    {
        $result = \MintyMetrics\get_config('another_missing_key');
        $this->assertNull($result);
    }

    public function testSetConfigAndGetConfigRoundTrip(): void
    {
        \MintyMetrics\set_config('test_round_trip', 'hello_world');
        $this->assertSame('hello_world', \MintyMetrics\get_config('test_round_trip'));
    }

    public function testSetConfigOverwritesViaGetConfig(): void
    {
        \MintyMetrics\set_config('overwrite_me', 'first');
        \MintyMetrics\set_config('overwrite_me', 'second');
        $this->assertSame('second', \MintyMetrics\get_config('overwrite_me'));
    }

    // ─── get_salt Integration ────────────────────────────────────────────

    public function testGetSaltReturnsNonEmptyString(): void
    {
        // get_salt() uses a static cache, so it returns the same value
        // across all tests in the process. We can only verify it returns
        // a non-empty string of the expected format (64 hex chars).
        $salt = \MintyMetrics\get_salt();

        $this->assertNotEmpty($salt);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $salt, 'Salt should be a 64-char hex string');
    }

    public function testGetSaltIsConsistentWithinProcess(): void
    {
        $salt1 = \MintyMetrics\get_salt();
        $salt2 = \MintyMetrics\get_salt();

        $this->assertSame($salt1, $salt2, 'get_salt should return the same salt within a single process');
    }

    // ─── Count Rows Helper ──────────────────────────────────────────────

    public function testCountRowsWithCondition(): void
    {
        $this->insertHit(['browser' => 'Chrome']);
        $this->insertHit(['browser' => 'Chrome']);
        $this->insertHit(['browser' => 'Firefox']);

        $this->assertSame(3, $this->countRows('hits'));
        $this->assertSame(2, $this->countRows('hits', "browser = 'Chrome'"));
        $this->assertSame(1, $this->countRows('hits', "browser = 'Firefox'"));
    }
}
