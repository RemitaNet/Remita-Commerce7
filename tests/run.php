#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Test runner — no PHPUnit required.
 *
 * Usage:
 *   php tests/run.php
 *   php tests/run.php --filter AmountNormalizer
 *
 * Exit code: 0 on all pass, 1 on any failure.
 */

require_once __DIR__ . '/bootstrap.php';

// ---------------------------------------------------------------------------
// Discover test classes
// ---------------------------------------------------------------------------

$testFiles = glob(__DIR__ . '/*Test.php') ?: [];

$filter = null;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--filter=')) {
        $filter = substr($arg, 9);
    } elseif ($arg === '--filter' && isset($argv[array_search($arg, $argv, true) + 1])) {
        $filter = $argv[array_search($arg, $argv, true) + 1];
    }
}

// ---------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------

$totalPassed = 0;
$totalFailed = 0;
$failedSuites = [];

$width = 70;
echo str_repeat('─', $width) . PHP_EOL;
echo '  Commerce7 + Remita Adapter — Test Suite' . PHP_EOL;
echo str_repeat('─', $width) . PHP_EOL;

foreach ($testFiles as $file) {
    require_once $file;

    $className = 'Remita\\Commerce7\\Tests\\' . basename($file, '.php');

    if (!class_exists($className)) {
        continue;
    }

    if ($filter !== null && !str_contains($className, $filter)) {
        continue;
    }

    /** @var \Remita\Commerce7\Tests\TestCase $suite */
    $suite = new $className();

    $start = microtime(true);
    $ok    = $suite->execute();
    $ms    = round((microtime(true) - $start) * 1000, 1);

    $passed = count($suite->getPassed());
    $failed = count($suite->getFailed());

    $totalPassed += $passed;
    $totalFailed += $failed;

    $label  = basename($file, '.php');
    $symbol = $ok ? '✓' : '✗';
    $line   = sprintf('  %s  %-44s  %3d passed  %3d failed  %5.1fms', $symbol, $label, $passed, $failed, $ms);
    echo $line . PHP_EOL;

    if (!$ok) {
        $failedSuites[$label] = $suite->getFailed();
    }
}

echo str_repeat('─', $width) . PHP_EOL;
echo sprintf('  Total: %d passed, %d failed', $totalPassed, $totalFailed) . PHP_EOL;
echo str_repeat('─', $width) . PHP_EOL;

// ---------------------------------------------------------------------------
// Print failures
// ---------------------------------------------------------------------------

if (!empty($failedSuites)) {
    echo PHP_EOL . '  FAILURES:' . PHP_EOL . PHP_EOL;
    foreach ($failedSuites as $suite => $failures) {
        echo "  [{$suite}]" . PHP_EOL;
        foreach ($failures as $msg) {
            echo "    ✗  {$msg}" . PHP_EOL;
        }
        echo PHP_EOL;
    }
}

exit($totalFailed > 0 ? 1 : 0);
