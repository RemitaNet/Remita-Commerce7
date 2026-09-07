<?php

declare(strict_types=1);

namespace Remita\Commerce7;

/**
 * Contract for the Commerce7 API client.
 *
 * Typed against this interface so the WebhookProcessor and Commerce7Adapter
 * accept test doubles without a concrete dependency on Commerce7Client.
 */
interface Commerce7ClientInterface
{
    /**
     * Retrieve a single order by ID.
     *
     * @return array<string, mixed>
     * @throws \RuntimeException on transport or API error.
     */
    public function getOrder(string $orderId): array;

    /**
     * Record a completed Remita payment against a Commerce7 order.
     *
     * @return array<string, mixed>
     * @throws \RuntimeException on transport or API error.
     */
    public function recordPayment(string $orderId, int $amountCents, string $paymentIdentifier): array;
}
