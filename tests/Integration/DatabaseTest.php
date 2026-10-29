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
        $this->assertContains('events', $tables);
    }

    public function testSchemaVersionIsSet(): void
    {
        $version = self::$testDb->querySingle("SELECT value FROM settings WHERE key = 'schema_version'");
        $this->assertSame((string) \MintyMetrics\SCHEMA_VERSION, $version);
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
        $this->assertContains('idx_events_site_created', $indexes);
        $this->assertContains('idx_events_site_name', $indexes);
        $this->assertContains('idx_events_visitor', $indexes);
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

        // v1→v2 migration cascades through to v3 in a single db_init_with call
        $version = $v1Db->querySingle("SELECT value FROM settings WHERE key = 'schema_version'");
        $this->assertSame((string) \MintyMetrics\SCHEMA_VERSION, $version);

        // The cascading v2→v3 should also have created the events table
        $hasEvents = (int) $v1Db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='events'");
        $this->assertSame(1, $hasEvents);

        $v1Db->close();
        @unlink($v1Path);
        @unlink($v1Path . '-wal');
        @unlink($v1Path . '-shm');
    }

    public function testSchemaMigrationV2ToV3CreatesEventsTable(): void
    {
        // Build a v2 DB by running db_init_with then rolling schema_version back.
        $v2Path = sys_get_temp_dir() . '/mintymetrics_v2_' . uniqid() . '.sqlite';
        $v2Db = new \SQLite3($v2Path);
        $v2Db->exec('PRAGMA journal_mode = WAL');

        \MintyMetrics\db_init_with($v2Db);

        // Drop the events table and reset schema_version to 2 to simulate a v2 install
        $v2Db->exec('DROP TABLE IF EXISTS events');
        $v2Db->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('schema_version', '2')");

        $tables = [];
        $r = $v2Db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='events'");
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
            $tables[] = $row['name'];
        }
        $this->assertNotContains('events', $tables, 'pre-migration: events table should not exist');

        // Run migration
        \MintyMetrics\db_init_with($v2Db);

        // Verify events table now exists with all expected columns
        $cols = [];
        $r = $v2Db->query("PRAGMA table_info(events)");
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
            $cols[] = $row['name'];
        }
        foreach (['id', 'site', 'visitor_hash', 'name', 'value', 'props', 'page_path',
                  'country_code', 'device_type', 'browser', 'os', 'created_at'] as $expected) {
            $this->assertContains($expected, $cols, "events table missing column: {$expected}");
        }

        // Verify indexes
        $indexes = [];
        $r = $v2Db->query("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='events'");
        while ($row = $r->fetchArray(SQLITE3_ASSOC)) {
            $indexes[] = $row['name'];
        }
        $this->assertContains('idx_events_site_created', $indexes);
        $this->assertContains('idx_events_site_name', $indexes);
        $this->assertContains('idx_events_visitor', $indexes);

        // Verify schema version bumped
        $version = $v2Db->querySingle("SELECT value FROM settings WHERE key = 'schema_version'");
        $this->assertSame((string) \MintyMetrics\SCHEMA_VERSION, $version);

        $v2Db->close();
        @unlink($v2Path);
        @unlink($v2Path . '-wal');
        @unlink($v2Path . '-shm');
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

    // ─── Connection Settings & v4 Migration ─────────────────────────────

    private function withTempDb(callable $fn): void
    {
        $path = sys_get_temp_dir() . '/mintymetrics_tmp_' . uniqid() . '.sqlite';
        $db = new \SQLite3($path);
        try {
            $fn($db);
        } finally {
            $db->close();
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function testConfigureEnablesExceptionsAndWal(): void
    {
        $this->withTempDb(function (\SQLite3 $db) {
            \MintyMetrics\db_configure($db, true);
            $this->assertSame('wal', strtolower($db->querySingle('PRAGMA journal_mode')));
            $this->assertSame(5000, (int) $db->querySingle('PRAGMA busy_timeout'));

            $this->expectException(\Exception::class);
            $db->exec('SELECT * FROM no_such_table');
        });
    }

    public function testNewDatabaseGetsIncrementalAutoVacuum(): void
    {
        $this->withTempDb(function (\SQLite3 $db) {
            \MintyMetrics\db_configure($db, true);
            \MintyMetrics\db_init_with($db);
            // 2 = INCREMENTAL; only takes effect if set before WAL and table creation
            $this->assertSame(2, (int) $db->querySingle('PRAGMA auto_vacuum'));
        });
    }

    public function testMigrationV3ToV4BackfillsSummaryCounters(): void
    {
        $this->withTempDb(function (\SQLite3 $db) {
            \MintyMetrics\db_configure($db, true);
            \MintyMetrics\db_init_with($db);

            // Rebuild daily_summaries in its v3 shape and roll the version back
            $db->exec('DROP TABLE daily_summaries');
            $db->exec("CREATE TABLE daily_summaries (
                id INTEGER PRIMARY KEY AUTOINCREMENT, date TEXT NOT NULL, site TEXT NOT NULL DEFAULT '',
                pageviews INTEGER NOT NULL DEFAULT 0, unique_visitors INTEGER NOT NULL DEFAULT 0,
                bounce_rate REAL DEFAULT NULL, avg_time_on_page REAL DEFAULT NULL,
                top_pages TEXT, top_referrers TEXT, top_countries TEXT, devices TEXT, browsers TEXT,
                operating_systems TEXT, utm_summary TEXT, screens TEXT, languages TEXT, UNIQUE(date, site))");
            $db->exec("INSERT INTO daily_summaries (date, site, pageviews, unique_visitors, bounce_rate, avg_time_on_page)
                VALUES ('2026-01-01', 'a.com', 10, 4, 0.5, 30), ('2026-01-02', 'a.com', 6, 3, NULL, NULL)");
            $db->exec("UPDATE settings SET value = '3' WHERE key = 'schema_version'");

            \MintyMetrics\db_init_with($db);

            $this->assertSame((string) \MintyMetrics\SCHEMA_VERSION, $db->querySingle("SELECT value FROM settings WHERE key = 'schema_version'"));
            $row = $db->querySingle("SELECT visitor_days, bounced, time_sum, time_count FROM daily_summaries WHERE date = '2026-01-01'", true);
            $this->assertSame(4, (int) $row['visitor_days']);
            $this->assertSame(2, (int) $row['bounced']);
            $this->assertEqualsWithDelta(300.0, (float) $row['time_sum'], 0.01);
            $this->assertSame(10, (int) $row['time_count']);
            $row = $db->querySingle("SELECT bounced, time_count FROM daily_summaries WHERE date = '2026-01-02'", true);
            $this->assertSame(0, (int) $row['bounced']);
            $this->assertSame(0, (int) $row['time_count']);
        });
    }

    public function testMigrationV4ToV5AddsLoginThrottling(): void
    {
        $this->withTempDb(function (\SQLite3 $db) {
            \MintyMetrics\db_configure($db, true);
            \MintyMetrics\db_init_with($db);
            // Shape of a database created by the v4 schema
            $db->exec('DROP TABLE login_attempts');
            $db->exec("UPDATE settings SET value = '4' WHERE key = 'schema_version'");

            \MintyMetrics\db_init_with($db);

            $this->assertSame((string) \MintyMetrics\SCHEMA_VERSION, $db->querySingle("SELECT value FROM settings WHERE key = 'schema_version'"));
            $this->assertSame(1, (int) $db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE name = 'login_attempts'"));
        });
    }

    public function testFailedMigrationThrowsAndKeepsVersion(): void
    {
        $this->withTempDb(function (\SQLite3 $db) {
            \MintyMetrics\db_configure($db, true);
            \MintyMetrics\db_init_with($db);
            $db->exec('DROP TABLE daily_summaries');
            $db->exec("UPDATE settings SET value = '3' WHERE key = 'schema_version'");

            $logFile = tempnam(sys_get_temp_dir(), 'mmlog');
            $prevLog = ini_set('error_log', $logFile);
            try {
                \MintyMetrics\db_init_with($db);
                $this->fail('Migration on a broken schema should throw');
            } catch (\Throwable $e) {
                $this->assertSame('3', $db->querySingle("SELECT value FROM settings WHERE key = 'schema_version'"));
                $this->assertStringContainsString('migration v3 to v4', file_get_contents($logFile));
            } finally {
                ini_set('error_log', $prevLog === false ? '' : $prevLog);
                @unlink($logFile);
            }
        });
    }

    public function testFailedSaltRotationDoesNotSwallowLaterWrites(): void
    {
        $path = sys_get_temp_dir() . '/mintymetrics_salt_' . uniqid() . '.sqlite';
        try {
            $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../fixtures/salt_rollback.php') . ' ' . escapeshellarg($path) . ' 2>&1');
            $this->assertSame('persisted', trim((string) $out));
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function testDbSizeExcludesFreePages(): void
    {
        $before = \MintyMetrics\db_size_mb();
        for ($i = 0; $i < 2000; $i++) {
            $this->insertHit(['page_path' => str_repeat('x', 400)]);
        }
        $grown = \MintyMetrics\db_size_mb();
        self::$testDb->exec('DELETE FROM hits');
        $this->assertGreaterThan($before, $grown);
        $this->assertLessThan($grown, \MintyMetrics\db_size_mb(), 'Deleted rows should not count towards the size limit');
    }
}
