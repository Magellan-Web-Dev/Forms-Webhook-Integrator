<?php
/**
 * Test runner for Forms Webhook Integrator.
 *
 * Each suite runs in its own PHP process, because some suites must run with
 * the Elementor / Elementor Pro Atomic classes present and others must run
 * with them genuinely absent — and PHP cannot unload a class once declared.
 *
 * Usage:  php tests/run-tests.php
 */

declare(strict_types=1);

$suites = glob(__DIR__ . '/suites/*.php') ?: [];
sort($suites);

if ($suites === []) {
    fwrite(STDERR, "No suites found in tests/suites/\n");
    exit(1);
}

$php      = PHP_BINARY;
$failures = [];

foreach ($suites as $suite) {
    $command = escapeshellarg($php) . ' ' . escapeshellarg($suite) . ' 2>&1';

    passthru($command, $exitCode);

    if ($exitCode !== 0) {
        $failures[] = basename($suite) . ' (exit ' . $exitCode . ')';
    }
}

echo "\n" . str_repeat('=', 62) . "\n";

if ($failures === []) {
    echo "ALL SUITES PASSED (" . count($suites) . " suites)\n";
    exit(0);
}

echo "FAILED SUITES:\n";
foreach ($failures as $failure) {
    echo '  - ' . $failure . "\n";
}

exit(1);
