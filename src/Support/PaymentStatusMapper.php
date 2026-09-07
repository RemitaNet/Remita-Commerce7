<?php

declare(strict_types=1);

namespace Remita\Commerce7\Support;

/**
 * Maps Remita payment status codes to normalised status constants.
 *
 * Remita uses numeric string codes in its query-status response.  The adapter
 * needs to map these into one of four internal states so the rest of the code
 * can make branching decisions (record to C7, retry later, mark failed, etc.)
 * without being coupled to Remita's raw codes.
 *
 * Status code reference (Remita Payment Engine):
 *   00, 01, 025 → success / approved
 *   02          → pending (awaiting payment)
 *   021         → processing (payment in progress / redirect flow)
 *   07, 06x     → failed / declined
 *   (anything else) → unknown
 */
final class PaymentStatusMapper
{
    // -------------------------------------------------------------------------
    // Status constants
    // -------------------------------------------------------------------------

    public const STATUS_SUCCESS    = 'success';
    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_FAILED     = 'failed';
    public const STATUS_UNKNOWN    = 'unknown';

    // -------------------------------------------------------------------------
    // Code maps
    // -------------------------------------------------------------------------

    /** Codes that represent a completed, billable payment. */
    private const SUCCESS_CODES = ['00', '01', '025'];

    /** Codes that mean the customer has not yet paid — Remita should retry. */
    private const PENDING_CODES = ['02'];

    /** Codes that mean payment is in-flight (redirect flow, bank processing). */
    private const PROCESSING_CODES = ['021'];

    /** Codes that represent a definitive failure / decline. */
    private const FAILED_CODES = ['07', '068', '069', '062', '063'];

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Map a raw Remita status code to an internal status constant.
     *
     * @param  string $code  e.g. "00", "02", "07"
     * @return string        One of the STATUS_* constants.
     */
    public static function fromRemitaCode(string $code): string
    {
        if (in_array($code, self::SUCCESS_CODES, true)) {
            return self::STATUS_SUCCESS;
        }

        if (in_array($code, self::PENDING_CODES, true)) {
            return self::STATUS_PENDING;
        }

        if (in_array($code, self::PROCESSING_CODES, true)) {
            return self::STATUS_PROCESSING;
        }

        if (in_array($code, self::FAILED_CODES, true)) {
            return self::STATUS_FAILED;
        }

        return self::STATUS_UNKNOWN;
    }

    /**
     * Map a Remita query-result payload to an internal status.
     *
     * Reads the 'status' key (numeric code) and optionally the 'paymentState'
     * key (string label) for providers that return both.
     *
     * @param  array<string, mixed> $payload  Decoded Remita query response.
     * @return string                          One of the STATUS_* constants.
     */
    public static function fromQueryResult(array $payload): string
    {
        $code  = (string) ($payload['status'] ?? '');
        $state = strtoupper((string) ($payload['paymentState'] ?? ''));

        // Prefer the numeric code when present.
        if ($code !== '') {
            $mapped = self::fromRemitaCode($code);
            if ($mapped !== self::STATUS_UNKNOWN) {
                return $mapped;
            }
        }

        // Fall back to paymentState string.
        return match ($state) {
            'APPROVED', 'SUCCESS', 'COMPLETED' => self::STATUS_SUCCESS,
            'PENDING'                           => self::STATUS_PENDING,
            'PROCESSING'                        => self::STATUS_PROCESSING,
            'FAILED', 'DECLINED'                => self::STATUS_FAILED,
            default                             => self::STATUS_UNKNOWN,
        };
    }

    /**
     * Map a Remita webhook payload to an internal status.
     *
     * Reads data.status (string label) from the webhook envelope.
     *
     * @param  array<string, mixed> $payload  Decoded webhook JSON.
     * @return string                          One of the STATUS_* constants.
     */
    public static function fromWebhookPayload(array $payload): string
    {
        $status = strtolower((string) ($payload['data']['status'] ?? ''));

        if (in_array($status, ['success', 'approved', 'completed'], true)) {
            return self::STATUS_SUCCESS;
        }

        if (in_array($status, ['pending', 'processing', 'redirect'], true)) {
            return self::STATUS_PENDING;
        }

        return self::STATUS_FAILED;
    }

    // -------------------------------------------------------------------------
    // Predicate helpers
    // -------------------------------------------------------------------------

    /**
     * Whether this status means the payment should be recorded in Commerce7.
     */
    public static function isRecordable(string $status): bool
    {
        return $status === self::STATUS_SUCCESS;
    }

    /**
     * Whether Remita should be expected to retry delivering this webhook.
     * We return true for pending and processing to avoid marking them idempotent.
     */
    public static function isRetryable(string $status): bool
    {
        return $status === self::STATUS_PENDING || $status === self::STATUS_PROCESSING;
    }

    /**
     * Whether the payment definitively failed (no retry expected from payer).
     */
    public static function isFailure(string $status): bool
    {
        return $status === self::STATUS_FAILED;
    }

    /**
     * Human-readable label for logging / response messages.
     */
    public static function label(string $status): string
    {
        return match ($status) {
            self::STATUS_SUCCESS    => 'Payment success',
            self::STATUS_PENDING    => 'Payment pending',
            self::STATUS_PROCESSING => 'Payment processing',
            self::STATUS_FAILED     => 'Payment failed',
            default                 => 'Unknown status',
        };
    }
}
