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
    'http',
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

// Replace version placeholder (before inlining assets, so asset contents are left untouched)
if (!preg_match('/^[0-9A-Za-z.+\-]+$/', $version)) {
    fwrite(STDERR, "ERROR: Invalid version string: {$version}\n");
    exit(1);
}
$output = str_replace('{{VERSION}}', $version, $output);

// Inline assets. Each one lands inside a PHP string: nowdoc bodies for CSS/JS, a
// single-quoted echo for the SVG. Content that would terminate that string early
// must fail the build instead of producing a broken analytics.php.
$assets = [
    '/* {{CSS}} */'        => [$srcDir . '/assets/style.css', 'nowdoc', 'CSSBLOCK'],
    '/* {{JS}} */'         => [$srcDir . '/assets/dashboard.js', 'nowdoc', 'JSBLOCK'],
    '/* {{TRACKER_JS}} */' => [$srcDir . '/assets/tracker.js', 'nowdoc', 'TRACKER_JS'],
    '/* {{SVG_MAP}} */'    => [$srcDir . '/assets/worldmap.svg', 'single-quoted', null],
];

foreach ($assets as $marker => [$assetFile, $context, $terminator]) {
    if (!file_exists($assetFile)) {
        fwrite(STDERR, "ERROR: {$assetFile} not found\n");
        exit(1);
    }
    if (substr_count($output, $marker) !== 1) {
        fwrite(STDERR, "ERROR: Asset marker '{$marker}' must appear exactly once\n");
        exit(1);
    }
    $content = file_get_contents($assetFile);
    if ($context === 'nowdoc' && preg_match('/^\s*' . preg_quote($terminator, '/') . '\b/m', $content)) {
        fwrite(STDERR, "ERROR: " . basename($assetFile) . " contains a line starting with {$terminator}, which would end its nowdoc\n");
        exit(1);
    }
    if ($context === 'single-quoted' && preg_match("/['\\\\]/", $content)) {
        fwrite(STDERR, "ERROR: " . basename($assetFile) . " contains ' or \\, which would break its single-quoted string\n");
        exit(1);
    }
    $output = str_replace($marker, $content, $output);
    echo "  + " . basename($assetFile) . "\n";
}

// Validate no unreplaced markers remain
if (preg_match('/\{\{(?:INCLUDE:|VERSION)[^}]*\}\}/', $output, $matches)) {
    fwrite(STDERR, "ERROR: Unreplaced marker found: {$matches[0]}\n");
    exit(1);
}

// Write to a temporary file and only replace analytics.php once every check passes,
// so a failed build never leaves a broken analytics.php behind.
$tmpFile = $outFile . '.tmp';
file_put_contents($tmpFile, $output);

$fail = function (string $message) use ($tmpFile): void {
    @unlink($tmpFile);
    fwrite(STDERR, "ERROR: {$message}\n");
    exit(1);
};

// Syntax check with the same PHP binary that runs this build
echo "Running syntax check...\n";
$syntaxOutput = [];
$syntaxReturn = 0;
exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmpFile) . ' 2>&1', $syntaxOutput, $syntaxReturn);
if ($syntaxReturn !== 0) {
    $fail("Syntax check failed:\n" . implode("\n", $syntaxOutput));
}
echo "Syntax check passed.\n";

// Verify key function definitions exist
$expectedFunctions = ['db', 'track_hit', 'track_event', 'auth_check', 'render_dashboard', 'export_csv', 'handle_api', 'cleanup_run', 'head'];
$missingFunctions = [];
foreach ($expectedFunctions as $fn) {
    if (strpos($output, "function {$fn}(") === false) {
        $missingFunctions[] = $fn;
    }
}
if (!empty($missingFunctions)) {
    $fail('Missing function definitions: ' . implode(', ', $missingFunctions));
}

if (!rename($tmpFile, $outFile)) {
    $fail("Could not write {$outFile}");
}

// Report
$size = filesize($outFile);
$lines = count(file($outFile));
$sizeKB = round($size / 1024, 1);
echo "\nBuilt: analytics.php ({$sizeKB} KB, {$lines} lines)\n";
echo "Build complete.\n";