<?php

declare(strict_types=1);

namespace Remita\Commerce7\Support;

/**
 * File-based idempotency store for payment processing.
 *
 * Each processed payment key is stored as a JSON file under
 * {dataDir}/idempotency/{sha256(key)}.json.  Files older than TTL_HOURS are
 * considered expired and may be purged.
 *
 * All writes use an atomic tmp→rename pattern to avoid partial reads.
 */
final class IdempotencyStore
{
    private const TTL_HOURS     = 24;
    private const SUBDIR        = 'idempotency';

    private string $idempotencyDir;

    public function __construct(string $dataDir)
    {
        $this->idempotencyDir = rtrim($dataDir, '/') . '/' . self::SUBDIR;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Check whether a key has already been processed (and is not expired).
     *
     * @param  string $key  Unique payment identifier.
     * @return bool
     */
    public function has(string $key): bool
    {
        $file = $this->filePath($key);

        if (!file_exists($file)) {
            return false;
        }

        // Check TTL.
        $mtime = filemtime($file);
        if ($mtime === false) {
            return false;
        }

        return (time() - $mtime) < (self::TTL_HOURS * 3600);
    }

    /**
     * Alias of has() — kept for backward-compatible call-sites.
     *
     * @param string $key
     */
    public function isProcessed(string $key): bool
    {
        return $this->has($key);
    }

    /**
     * Record that a payment key has been processed.
     *
     * @param  string               $key      Unique payment identifier.
     * @param  array<string, mixed> $context  Optional metadata to persist alongside the record.
     *
     * @throws \RuntimeException on write failure.
     */
    public function record(string $key, array $context = []): void
    {
        $this->ensureDir();

        $contents = json_encode([
            'key'         => $key,
            'processedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'context'     => $context,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

        $file = $this->filePath($key);
        $tmp  = $file . '.tmp.' . getmypid();

        if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
            throw new \RuntimeException(
                sprintf('IdempotencyStore: failed to write temp file "%s".', $tmp)
            );
        }

        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException(
                sprintf('IdempotencyStore: failed to rename "%s" → "%s".', $tmp, $file)
            );
        }
    }

    /**
     * Alias of record() — matches the interface referenced in WebhookProcessor.
     *
     * @param  string               $key
     * @param  array<string, mixed> $meta
     */
    public function markProcessed(string $key, array $meta = []): void
    {
        $this->record($key, $meta);
    }

    /**
     * Retrieve the stored context for a processed key, or null if not found.
     *
     * @param  string                    $key
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        $file = $this->filePath($key);

        if (!file_exists($file)) {
            return null;
        }

        $raw = file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : null;
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * Remove idempotency files older than the TTL.
     */
    public function purgeExpired(): void
    {
        if (!is_dir($this->idempotencyDir)) {
            return;
        }

        $cutoff = time() - (self::TTL_HOURS * 3600);

        foreach (glob($this->idempotencyDir . '/*.json') ?: [] as $file) {
            $mtime = filemtime($file);
            if ($mtime !== false && $mtime < $cutoff) {
                @unlink($file);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function filePath(string $key): string
    {
        return $this->idempotencyDir . '/' . hash('sha256', $key) . '.json';
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->idempotencyDir)
            && !mkdir($this->idempotencyDir, 0755, true)
            && !is_dir($this->idempotencyDir)
        ) {
            throw new \RuntimeException(
                sprintf('IdempotencyStore: cannot create directory "%s".', $this->idempotencyDir)
            );
        }
    }
}
