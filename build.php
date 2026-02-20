<?php
/**
 * MintyMetrics Build Script
 * Compiles /src into a single distributable analytics.php file.
 *
 * Usage:
 *   php build.php                    # Build with version from VERSION file
 *   php build.php --version 1.2.0    # Override version
 */

$srcDir = __DIR__ . '/src';
$outFile = __DIR__ . '/analytics.php';

// Parse CLI arguments
$version = null;
for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--version' && isset($argv[$i + 1])) {
        $version = $argv[++$i];
    }
}

if ($version === null) {
    $versionFile = __DIR__ . '/VERSION';
    if (file_exists($versionFile)) {
        $version = trim(file_get_contents($versionFile));
    } else {
        $version = '0.0.0-dev';
    }
}

echo "Building MintyMetrics v{$version}...\n";

// Module inclusion order (dependencies flow top-down)
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

// Read bootstrap as the base
$bootstrapFile = $srcDir . '/bootstrap.php';
if (!file_exists($bootstrapFile)) {
    fwrite(STDERR, "ERROR: {$bootstrapFile} not found\n");
    exit(1);
}
$output = file_get_contents($bootstrapFile);

// Track which markers were replaced
$replacedModules = [];

// Inline each module at its marker
foreach ($modules as $mod) {
    $marker = "// {{INCLUDE:{$mod}}}";
    $modFile = $srcDir . '/' . $mod . '.php';

    if (!file_exists($modFile)) {
        fwrite(STDERR, "ERROR: {$modFile} not found\n");
        exit(1);
    }

    if (strpos($output, $marker) === false) {
        fwrite(STDERR, "ERROR: Marker '{$marker}' not found in bootstrap.php\n");
        exit(1);
    }

    $content = file_get_contents($modFile);
    // Strip opening <?php tag
    $content = preg_replace('/^<\?php\s*/s', '', $content);

    $output = str_replace($marker, $content, $output);
    $replacedModules[] = $mod;
    echo "  + {$mod}.php\n";
}

// Inline assets
$assets = [
    '/* {{CSS}} */'        => $srcDir . '/assets/style.css',
    '/* {{JS}} */'         => $srcDir . '/assets/dashboard.js',
    '/* {{TRACKER_JS}} */' => $srcDir . '/assets/tracker.js',
    '/* {{SVG_MAP}} */'    => $srcDir . '/assets/worldmap.svg',
];

foreach ($assets as $marker => $assetFile) {
    if (!file_exists($assetFile)) {
        fwrite(STDERR, "ERROR: {$assetFile} not found\n");
        exit(1);
    }
    if (strpos($output, $marker) === false) {
        fwrite(STDERR, "ERROR: Asset marker '{$marker}' not found\n");
        exit(1);
    }
    $content = file_get_contents($assetFile);
    $output = str_replace($marker, $content, $output);
    echo "  + " . basename($assetFile) . "\n";
}

// Replace version placeholder
$output = str_replace('{{VERSION}}', $version, $output);

// Validate no unreplaced markers remain
if (preg_match('/\{\{(?:INCLUDE:|VERSION)[^}]*\}\}/', $output, $matches)) {
    fwrite(STDERR, "ERROR: Unreplaced marker found: {$matches[0]}\n");
    exit(1);
}

// Write output
file_put_contents($outFile, $output);

// Report
$size = filesize($outFile);
$lines = count(file($outFile));
$sizeKB = round($size / 1024, 1);
echo "\nBuilt: analytics.php ({$sizeKB} KB, {$lines} lines)\n";

// Syntax check
echo "Running syntax check...\n";
$syntaxOutput = [];
$syntaxReturn = 0;
exec('php -l ' . escapeshellarg($outFile) . ' 2>&1', $syntaxOutput, $syntaxReturn);
if ($syntaxReturn !== 0) {
    fwrite(STDERR, "ERROR: Syntax check failed:\n" . implode("\n", $syntaxOutput) . "\n");
    exit(1);
}
echo "Syntax check passed.\n";

// Verify key function definitions exist
$expectedFunctions = ['db', 'track_hit', 'auth_check', 'render_dashboard', 'export_csv'];
$missingFunctions = [];
foreach ($expectedFunctions as $fn) {
    if (strpos($output, "function {$fn}(") === false) {
        $missingFunctions[] = $fn;
    }
}
if (!empty($missingFunctions)) {
    fwrite(STDERR, "WARNING: Missing function definitions: " . implode(', ', $missingFunctions) . "\n");
}

echo "Build complete.\n";
