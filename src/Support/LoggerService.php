<?php

declare(strict_types=1);

namespace Remita\Commerce7\Support;

/**
 * Simple file-based logger for the Commerce7 + Remita adapter.
 *
 * Log line format:
 *   [ISO-8601 datetime] [CHANNEL] [LEVEL] message {"key":"value",...}
 *
 * Each line is appended to the log file with LOCK_EX to prevent interleaving
 * under concurrent processes.  The log directory is created on first write.
 *
 * Supported levels (in ascending severity):
 *   debug → info → warning → error
 *
 * Lines below $minLevel are silently discarded.
 */
final class LoggerService
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    private string $logFile;
    private int    $minLevelInt;
    private string $channel;

    /**
     * @param string $logDir   Directory where the log file will be written.
     * @param string $minLevel Minimum severity to log ('debug'|'info'|'warning'|'error').
     * @param string $channel  Channel tag embedded in every line (e.g. 'webhook').
     */
    public function __construct(
        string $logDir,
        string $minLevel = 'info',
        string $channel  = 'commerce7'
    ) {
        $this->logFile     = rtrim($logDir, '/') . '/commerce7.log';
        $this->minLevelInt = self::LEVELS[strtolower($minLevel)] ?? self::LEVELS['info'];
        $this->channel     = strtoupper($channel);
    }

    // -------------------------------------------------------------------------
    // PSR-3-style log methods
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $context
     */
    public function debug(string $message, array $context = []): void
    {
        $this->write('debug', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $context
     */
    private function write(string $level, string $message, array $context): void
    {
        $levelInt = self::LEVELS[$level] ?? 0;
        if ($levelInt < $this->minLevelInt) {
            return;
        }

        $this->ensureLogDir();

        $timestamp   = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $contextJson = empty($context) ? '{}' : (json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        $line        = sprintf(
            '[%s] [%s] [%s] %s %s' . PHP_EOL,
            $timestamp,
            $this->channel,
            strtoupper($level),
            $message,
            $contextJson
        );

        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }

    private function ensureLogDir(): void
    {
        $dir = dirname($this->logFile);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            // If the directory still cannot be created, silently swallow — a logger
            // should never crash the application.
        }
    }
}
