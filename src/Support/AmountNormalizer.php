<?php

declare(strict_types=1);

namespace Remita\Commerce7\Support;

/**
 * Currency amount conversion utilities for the Commerce7 → Remita adapter.
 *
 * Commerce7 stores amounts in the smallest currency unit (cents / kobo).
 * Remita's redirect checkout API expects the amount as a decimal string
 * ("1500.00"). This class handles the round-trip conversions and provides
 * an amount-match assertion used during webhook reconciliation.
 */
final class AmountNormalizer
{
    /**
     * Convert a cent/kobo integer to a human-readable decimal string.
     *
     * @param  int $cents  Amount in smallest currency unit (must be >= 0).
     * @param  int $scale  Number of decimal places (default: 2).
     * @return string      e.g. 150000 → "1500.00"
     *
     * @throws \InvalidArgumentException if $cents is negative.
     */
    public static function centsToMajor(int $cents, int $scale = 2): string
    {
        if ($cents < 0) {
            throw new \InvalidArgumentException(
                sprintf('Amount in cents must be non-negative; got %d.', $cents)
            );
        }

        return number_format($cents / 100, $scale, '.', '');
    }

    /**
     * Convert a major-unit amount (string or float) to an integer cent/kobo value.
     *
     * Handles strings like "1500.00", "1500", and float 1500.0.
     *
     * @param  float|int|string $amount  Major-unit amount (must be >= 0).
     * @return int                        Amount in smallest currency unit.
     *
     * @throws \InvalidArgumentException if $amount is not numeric or is negative.
     */
    public static function majorToCents(float|int|string $amount): int
    {
        if (!is_numeric($amount)) {
            throw new \InvalidArgumentException(
                sprintf('Amount must be numeric; got "%s".', $amount)
            );
        }

        $float = (float) $amount;

        if ($float < 0.0) {
            throw new \InvalidArgumentException(
                sprintf('Amount must be non-negative; got %s.', $amount)
            );
        }

        // Round to avoid floating-point drift (e.g. 1500.005 * 100 = 150000.4999…).
        return (int) round($float * 100);
    }

    /**
     * Assert that two cent amounts match within an optional tolerance.
     *
     * Used during webhook reconciliation to compare the Remita-reported amount
     * against the amount stored in the order map.
     *
     * @param  int $actual     Amount received from Remita (cents).
     * @param  int $expected   Amount from the order map (cents).
     * @param  int $tolerance  Allowed absolute difference in cents (default: 0).
     *
     * @throws \UnexpectedValueException if the difference exceeds $tolerance.
     */
    public static function assertMatch(int $actual, int $expected, int $tolerance = 0): void
    {
        $diff = abs($actual - $expected);
        if ($diff > $tolerance) {
            throw new \UnexpectedValueException(
                sprintf(
                    'Amount mismatch: expected %d cents, got %d cents (diff %d, tolerance %d).',
                    $expected,
                    $actual,
                    $diff,
                    $tolerance
                )
            );
        }
    }

    // -------------------------------------------------------------------------
    // Legacy / convenience aliases kept for adapter compatibility
    // -------------------------------------------------------------------------

    /**
     * Convert a major-unit amount to kobo (NGN smallest unit).
     * Alias for majorToCents() — identical semantics for NGN.
     *
     * @throws \InvalidArgumentException
     */
    public static function toKobo(float|int|string $amount): int
    {
        return self::majorToCents($amount);
    }

    /**
     * Convert kobo to major-unit NGN float.
     */
    public static function fromKobo(int $kobo): float
    {
        return $kobo / 100;
    }

    /**
     * Commerce7 stores amounts in cents; for NGN, cents == kobo — return as-is.
     */
    public static function centsToKobo(int $cents): int
    {
        return $cents;
    }
}
