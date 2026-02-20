<?php
/**
 * PHPUnit bootstrap — loads all MintyMetrics source modules in dependency order.
 *
 * This simulates the build process by requiring each source file,
 * making all MintyMetrics\* functions available for testing.
 */

// Enable test mode — gates test-only code paths in production source
define('MM_TESTING', true);

// Set up minimal server environment for functions that read $_SERVER
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/fake_analytics.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';

// Load source modules in dependency order (same as build.php)
$srcDir = dirname(__DIR__) . '/src';

$modules = [
    'config',
    'database',
    'auth',
    'useragent',
    'geo',
    'tracker',
    'cleanup',
    'export',
    'api',
    'setup',
    'settings',
    'dashboard',
];

foreach ($modules as $mod) {
    require_once $srcDir . '/' . $mod . '.php';
}

// Define the VERSION constant that's normally injected by the build
if (!defined('MintyMetrics\VERSION')) {
    define('MintyMetrics\VERSION', '1.0.0-test');
}
