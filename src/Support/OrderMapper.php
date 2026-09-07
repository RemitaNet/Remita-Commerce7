<?php

declare(strict_types=1);

namespace Remita\Commerce7\Support;

/**
 * Transforms a Commerce7 order array into the payloads expected by the
 * Remita Payment Engine redirect checkout API.
 *
 * Remita redirect checkout field reference:
 *   merchantId      — Remita merchant ID
 *   serviceTypeId   — Remita service type / product ID
 *   orderId         — our unique payment identifier (c7-…)
 *   totalAmount     — amount as decimal string ("1500.00")
 *   payerEmail      — customer email
 *   payerName       — "FirstName LastName"
 *   payerPhone      — customer phone number
 *   responseurl     — URL Remita POSTs the payment result to (our webhook)
 *   hash            — SHA-512 of merchantId + serviceTypeId + orderId + totalAmount + apiKey
 */
final class OrderMapper
{
    /**
     * Build the Remita redirect-checkout payload from a Commerce7 order.
     *
     * @param  array<string, mixed> $order              Commerce7 order object.
     * @param  string               $paymentIdentifier  Unique c7-… identifier.
     * @param  string               $merchantId         Remita merchant ID.
     * @param  string               $serviceTypeId      Remita service type ID.
     * @param  string               $apiKey             Remita API key (used for hashing only).
     * @param  string               $returnUrl          URL Remita redirects the customer to after payment.
     * @return array<string, string>                    Flat key→value payload ready for query-string encoding.
     *
     * @throws \InvalidArgumentException if required fields are missing.
     */
    public static function toRemitaPayload(
        array  $order,
        string $paymentIdentifier,
        string $merchantId,
        string $serviceTypeId,
        string $apiKey,
        string $returnUrl
    ): array {
        // Validate required fields.
        if (empty($order['id'])) {
            throw new \InvalidArgumentException('Commerce7 order is missing the required "id" field.');
        }

        $customer = $order['customer'] ?? [];

        if (empty($customer['email'])) {
            throw new \InvalidArgumentException('Commerce7 order customer is missing the required "email" field.');
        }

        $amountCents = (int) ($order['total'] ?? 0);
        $totalAmount = AmountNormalizer::centsToMajor($amountCents);

        $firstName = (string) ($customer['firstName'] ?? '');
        $lastName  = (string) ($customer['lastName'] ?? '');
        $payerName = trim($firstName . ' ' . $lastName);

        // SHA-512 hash: merchantId + serviceTypeId + orderId + totalAmount + apiKey
        $hash = hash(
            'sha512',
            $merchantId . $serviceTypeId . $paymentIdentifier . $totalAmount . $apiKey
        );

        return [
            'merchantId'    => $merchantId,
            'serviceTypeId' => $serviceTypeId,
            'orderId'       => $paymentIdentifier,
            'totalAmount'   => $totalAmount,
            'payerEmail'    => (string) ($customer['email'] ?? ''),
            'payerName'     => $payerName,
            'payerPhone'    => (string) ($customer['phone'] ?? ''),
            'responseurl'   => $returnUrl,
            'hash'          => $hash,
        ];
    }

    /**
     * Encode a payload array as a URL query string.
     *
     * @param  array<string, string> $payload
     * @return string
     */
    public static function toCheckoutQueryString(array $payload): string
    {
        return http_build_query($payload);
    }

    /**
     * Extract a safe, non-sensitive summary suitable for log lines.
     *
     * Deliberately omits payer name and phone to reduce PII in log files.
     *
     * @param  array<string, mixed> $order
     * @return array<string, mixed>
     */
    public static function toLogSummary(array $order): array
    {
        return [
            'orderId'     => $order['id'] ?? null,
            'amountCents' => (int) ($order['total'] ?? 0),
            'payerEmail'  => $order['customer']['email'] ?? null,
        ];
    }
}
