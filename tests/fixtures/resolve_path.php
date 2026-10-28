<?php
/**
 * Subprocess fixture: resolve the DB path for the app dir given in argv[1] and print it.
 * Several of these run at once to check that concurrent first requests agree.
 */
require __DIR__ . '/../bootstrap.php';

$GLOBALS['_mm_app_dir'] = $argv[1];
$_SERVER['DOCUMENT_ROOT'] = $argv[1];
echo MintyMetrics\resolve_db_path();
