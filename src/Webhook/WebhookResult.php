<?php

declare(strict_types=1);

namespace Remita\Commerce7\Webhook;

/**
 * Immutable value object representing the result of processing a single webhook.
 *
 * The status field follows the Remita adapter conventions:
 *   'ok'      — payment recorded successfully in Commerce7
 *   'pending' — payment not yet complete; Remita should retry
 *   'failed'  — payment definitively failed; recorded as idempotent
 *   'error'   — processing error (bad signature, missing data, upstream failure)
 */
final class WebhookResult
{
    private string $status;
    private string $message;
    private int    $httpCode;
    /** @var array<string, mixed> */
    private array  $data;

    /**
     * @param array<string, mixed> $data
     */
    private function __construct(
        string $status,
        string $message,
        int    $httpCode,
        array  $data = []
    ) {
        $this->status   = $status;
        $this->message  = $message;
        $this->httpCode = $httpCode;
        $this->data     = $data;
    }

    // -------------------------------------------------------------------------
    // Named constructors
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     */
    public static function ok(string $message, array $data = []): self
    {
        return new self('ok', $message, 200, $data);
    }

    public static function pending(string $message): self
    {
        return new self('pending', $message, 200);
    }

    public static function failed(string $message): self
    {
        return new self('failed', $message, 200);
    }

    public static function error(string $message, int $httpCode = 200): self
    {
        return new self('error', $message, $httpCode);
    }

    /**
     * Reconstruct a WebhookResult from a plain array (e.g. from test stubs).
     *
     * @param  array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            status:   (string) ($raw['status']   ?? 'error'),
            message:  (string) ($raw['message']  ?? ''),
            httpCode: (int)    ($raw['httpCode'] ?? 200),
            data:     (array)  ($raw['data']     ?? [])
        );
    }

    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getHttpCode(): int
    {
        return $this->httpCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    public function isSuccess(): bool
    {
        return $this->status === 'ok';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isError(): bool
    {
        return $this->status === 'error';
    }

    // -------------------------------------------------------------------------
    // Serialisation
    // -------------------------------------------------------------------------

    /**
     * Convert to the array format expected by webhook.php entry point.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'status'   => $this->status,
            'message'  => $this->message,
            'httpCode' => $this->httpCode,
        ];

        if (!empty($this->data)) {
            $out['data'] = $this->data;
        }

        return $out;
    }
}
