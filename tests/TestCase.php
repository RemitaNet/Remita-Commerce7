<?php

declare(strict_types=1);

namespace Remita\Commerce7\Tests;

/**
 * Minimal test case base class — no PHPUnit dependency.
 *
 * Provides assert helpers that throw \RuntimeException on failure so
 * run.php can catch and report them.
 */
abstract class TestCase
{
    /** @var array<string> */
    private array $passed = [];
    /** @var array<string> */
    private array $failed = [];

    abstract public function run(): void;

    // -------------------------------------------------------------------------
    // Runner interface
    // -------------------------------------------------------------------------

    public function execute(): bool
    {
        $this->run();
        return empty($this->failed);
    }

    public function getPassed(): array { return $this->passed; }
    public function getFailed(): array { return $this->failed; }

    // -------------------------------------------------------------------------
    // Assertion helpers
    // -------------------------------------------------------------------------

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected === $actual) {
            $this->pass($message ?: sprintf('assertSame(%s)', var_export($expected, true)));
            return;
        }
        $this->fail(sprintf(
            '%s — expected %s, got %s',
            $message ?: 'assertSame',
            var_export($expected, true),
            var_export($actual, true)
        ));
    }

    protected function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected == $actual) {
            $this->pass($message ?: 'assertEquals');
            return;
        }
        $this->fail(sprintf(
            '%s — expected %s, got %s',
            $message ?: 'assertEquals',
            var_export($expected, true),
            var_export($actual, true)
        ));
    }

    protected function assertTrue(mixed $value, string $message = ''): void
    {
        if ($value === true) {
            $this->pass($message ?: 'assertTrue');
            return;
        }
        $this->fail(sprintf('%s — got %s', $message ?: 'assertTrue', var_export($value, true)));
    }

    protected function assertFalse(mixed $value, string $message = ''): void
    {
        if ($value === false) {
            $this->pass($message ?: 'assertFalse');
            return;
        }
        $this->fail(sprintf('%s — got %s', $message ?: 'assertFalse', var_export($value, true)));
    }

    protected function assertStringStartsWith(string $prefix, string $actual, string $message = ''): void
    {
        if (str_starts_with($actual, $prefix)) {
            $this->pass($message ?: "assertStringStartsWith({$prefix})");
            return;
        }
        $this->fail(sprintf('%s — "%s" does not start with "%s"', $message ?: 'assertStringStartsWith', $actual, $prefix));
    }

    protected function assertStringContains(string $needle, string $haystack, string $message = ''): void
    {
        if (str_contains($haystack, $needle)) {
            $this->pass($message ?: "assertStringContains({$needle})");
            return;
        }
        $this->fail(sprintf('%s — "%s" not found in "%s"', $message ?: 'assertStringContains', $needle, $haystack));
    }

    protected function assertThrows(string $exceptionClass, callable $fn, string $message = ''): void
    {
        try {
            $fn();
            $this->fail(sprintf('%s — expected %s to be thrown, but nothing was', $message ?: 'assertThrows', $exceptionClass));
        } catch (\Throwable $e) {
            if ($e instanceof $exceptionClass) {
                $this->pass($message ?: "assertThrows({$exceptionClass})");
            } else {
                $this->fail(sprintf(
                    '%s — expected %s, got %s: %s',
                    $message ?: 'assertThrows',
                    $exceptionClass,
                    $e::class,
                    $e->getMessage()
                ));
            }
        }
    }

    protected function assertCount(int $expected, array $array, string $message = ''): void
    {
        $actual = count($array);
        if ($actual === $expected) {
            $this->pass($message ?: "assertCount({$expected})");
            return;
        }
        $this->fail(sprintf('%s — expected count %d, got %d', $message ?: 'assertCount', $expected, $actual));
    }

    protected function assertArrayHasKey(string|int $key, array $array, string $message = ''): void
    {
        if (array_key_exists($key, $array)) {
            $this->pass($message ?: "assertArrayHasKey({$key})");
            return;
        }
        $this->fail(sprintf('%s — key "%s" not found in array', $message ?: 'assertArrayHasKey', $key));
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private function pass(string $label): void
    {
        $this->passed[] = $label;
    }

    private function fail(string $label): void
    {
        $this->failed[] = $label;
    }
}
