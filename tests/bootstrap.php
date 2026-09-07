<?php

declare(strict_types=1);

/**
 * Test bootstrap — loaded by run.php before any test file.
 *
 * Registers a PSR-4 autoloader for both the src/ namespace and the
 * tests/ namespace so tests can be run without Composer.
 */

$baseDir = dirname(__DIR__);

// ---------------------------------------------------------------------------
// Explicit requires for Support classes (supplemental to the autoloader below)
// ---------------------------------------------------------------------------

require_once $baseDir . '/src/Support/AmountNormalizer.php';
require_once $baseDir . '/src/Support/PaymentIdentifier.php';
require_once $baseDir . '/src/Support/PaymentStatusMapper.php';
require_once $baseDir . '/src/Support/OrderMapper.php';
require_once $baseDir . '/src/Support/IdempotencyStore.php';
require_once $baseDir . '/src/Support/LoggerService.php';

spl_autoload_register(function (string $class) use ($baseDir): void {
    // Map Remita\Commerce7\… → src/…
    $prefix = 'Remita\\Commerce7\\';
    if (str_starts_with($class, $prefix)) {
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file     = $baseDir . '/src/' . $relative . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }

    // Map tests/ flat namespace → tests/…
    $testPrefix = 'Remita\\Commerce7\\Tests\\';
    if (str_starts_with($class, $testPrefix)) {
        $relative = str_replace('\\', '/', substr($class, strlen($testPrefix)));
        $file     = $baseDir . '/tests/' . $relative . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});
