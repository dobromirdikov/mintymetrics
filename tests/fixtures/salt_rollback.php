<?php
/**
 * Subprocess fixture: get_salt() caches per process, so its failure path is tested
 * in a fresh PHP process. Prints "persisted" if a write made after a failed salt
 * rotation survives closing the connection.
 */
require __DIR__ . '/../bootstrap.php';

$path = $argv[1];
$db = new SQLite3($path);
MintyMetrics\db_configure($db, true);
MintyMetrics\db_init_with($db);
$GLOBALS['_mm_test_db'] = $db;

// Make the salt rotation fail after BEGIN IMMEDIATE
$db->exec("CREATE TRIGGER fail_salt BEFORE INSERT ON settings WHEN NEW.key = 'daily_salt'
    BEGIN SELECT RAISE(ABORT, 'disk full'); END");

$salt = MintyMetrics\get_salt();
if (!preg_match('/^[a-f0-9]{64}$/', $salt)) {
    echo 'bad salt';
    exit(1);
}

$db->exec("INSERT INTO hits (site, visitor_hash, page_path, created_at) VALUES ('a.com', 'h', '/', 1)");
$db->close();

$check = new SQLite3($path);
echo (int) $check->querySingle('SELECT COUNT(*) FROM hits') === 1 ? 'persisted' : 'lost';
