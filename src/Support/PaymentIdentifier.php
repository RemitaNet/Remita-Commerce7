<?php

declare(strict_types=1);

namespace Remita\Commerce7\Support;

/**
 * Generates and validates Commerce7 + Remita payment identifiers.
 *
 * Format: c7-{sanitisedOrderId}-{8 lowercase hex chars}
 *
 * Examples:
 *   c7-ord123-a1b2c3d4
 *   c7-ordabc123-ff00ee11
 *
 * The 8-character hex suffix provides ~4 billion unique combinations per order
 * ID, which is sufficient for retry-safe idempotency without a database.
 */
final class PaymentIdentifier
{
    /** Regex that a fully-formed identifier must satisfy. */
    private const PATTERN = '/^c7-([^-][^-]*)-([0-9a-f]{8})$/';

    /**
     * Generate a unique payment identifier for the given Commerce7 order.
     *
     * Hyphens and any character that is not alphanumeric (a-z, A-Z, 0-9) are
     * stripped from $orderId before embedding it in the identifier so the
     * three-segment structure (c7-{id}-{random}) is unambiguous.
     *
     * @param  string $orderId  Commerce7 order ID.
     * @return string           e.g. "c7-ord123-a1b2c3d4"
     *
     * @throws \InvalidArgumentException if $orderId is empty after sanitisation.
     */
    public static function generate(string $orderId): string
    {
        // Strip any character that is not alphanumeric.
        $safe = preg_replace('/[^a-zA-Z0-9]/', '', $orderId) ?? '';

        if ($safe === '') {
            throw new \InvalidArgumentException(
                sprintf(
                    'Order ID "%s" contains no safe characters after sanitisation.',
                    $orderId
                )
            );
        }

        $random = bin2hex(random_bytes(4)); // 4 bytes = 8 hex chars

        return sprintf('c7-%s-%s', $safe, $random);
    }

    /**
     * Validate whether a string is a well-formed payment identifier.
     *
     * @param  string $id  Value to test.
     * @return bool        True if the identifier matches the expected pattern.
     */
    public static function isValid(string $id): bool
    {
        return (bool) preg_match(self::PATTERN, $id);
    }

    /**
     * Extract the sanitised order ID from a payment identifier.
     *
     * @param  string $id  A well-formed payment identifier.
     * @return string      The embedded order ID segment.
     *
     * @throws \InvalidArgumentException if $id is not a valid payment identifier.
     */
    public static function extractOrderId(string $id): string
    {
        if (preg_match(self::PATTERN, $id, $matches)) {
            return $matches[1];
        }

        throw new \InvalidArgumentException(
            sprintf('"%s" is not a valid payment identifier.', $id)
        );
    }
}
