<?php
/**
 * Subprocess fixture: real file-backed sessions. A session ID that existed before
 * login (e.g. planted by an attacker) must never become signed in.
 * Prints JSON describing the old and new session files.
 */
require __DIR__ . '/../bootstrap.php';

[$dbPath, $sessDir] = [$argv[1], $argv[2]];
$db = new SQLite3($dbPath);
MintyMetrics\db_configure($db, true);
MintyMetrics\db_init_with($db);
$GLOBALS['_mm_test_db'] = $db;
MintyMetrics\auth_set_password('CorrectHorse1');

ini_set('session.save_path', $sessDir);
ini_set('session.use_cookies', '0');
ini_set('session.use_strict_mode', '1');
session_start();
$planted = session_id();
$_SESSION['_mm_csrf'] = 'x';

$ok = MintyMetrics\auth_login('CorrectHorse1');
$newId = session_id();
session_write_close();

echo json_encode([
    'ok' => $ok,
    'rotated' => $newId !== $planted,
    'old_exists' => is_file("{$sessDir}/sess_{$planted}"),
    'old_signed_in' => is_file("{$sessDir}/sess_{$planted}") && str_contains(file_get_contents("{$sessDir}/sess_{$planted}"), '_mm_authenticated'),
    'new_signed_in' => str_contains((string) @file_get_contents("{$sessDir}/sess_{$newId}"), '_mm_authenticated'),
]);
