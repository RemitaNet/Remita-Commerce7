<?php

declare(strict_types=1);

namespace Remita\Commerce7;

use Remita\Commerce7\Support\AmountNormalizer;
use Remita\Commerce7\Support\IdempotencyStore;
use Remita\Commerce7\Support\LoggerService;
use Remita\Commerce7\Support\OrderMapper;
use Remita\Commerce7\Support\PaymentIdentifier;

/**
 * Commerce7 → Remita Payment Engine checkout adapter.
 *
 * Orchestrates the initiation flow:
 *   1. Fetch the order from Commerce7.
 *   2. Generate a unique payment identifier.
 *   3. Persist the order-map entry so the webhook handler can look up the
 *      Commerce7 order ID from the payment reference later.
 *   4. Build and return the Remita checkout redirect URL (or form data).
 *
 * The adapter does NOT perform the HTTP redirect itself — that is the
 * responsibility of the entry-point script (initiate.php), keeping this
 * class testable without a live HTTP context.
 */
final class Commerce7Adapter
{
    private const ORDER_MAP_DIR = 'order_map';

    private Commerce7ClientInterface $c7Client;
    private IdempotencyStore $idempotency;
    private LoggerService    $logger;
    private array            $config;

    /**
     * @param array<string, mixed> $config  Full config array (from config.php)
     */
    public function __construct(
        Commerce7ClientInterface $c7Client,
        IdempotencyStore $idempotency,
        LoggerService    $logger,
        array            $config
    ) {
        $this->c7Client    = $c7Client;
        $this->idempotency = $idempotency;
        $this->logger      = $logger;
        $this->config      = $config;
    }

    // -------------------------------------------------------------------------
    // Initiation
    // -------------------------------------------------------------------------

    /**
     * Initiate a Remita checkout for the given Commerce7 order.
     *
     * @param  string $orderId  Commerce7 order ID.
     * @return array{checkoutUrl: string, paymentIdentifier: string, payload: array<string,string>}
     *
     * @throws \RuntimeException  On Commerce7 API failure or data problems.
     */
    public function initiateCheckout(string $orderId): array
    {
        $this->logger->info('Initiating checkout', ['orderId' => $orderId]);

        // 1. Fetch order --------------------------------------------------------
        $order = $this->c7Client->getOrder($orderId);

        $this->logger->debug('Commerce7 order fetched', OrderMapper::toLogSummary($order));

        // 2. Generate identifier ------------------------------------------------
        $paymentIdentifier = PaymentIdentifier::generate($orderId);

        // 3. Persist order map --------------------------------------------------
        $this->persistOrderMap($paymentIdentifier, $orderId, (int) ($order['total'] ?? 0));

        // 4. Build Remita payload -----------------------------------------------
        $remita      = $this->config['remita'];
        $payload     = OrderMapper::toRemitaPayload(
            order:              $order,
            paymentIdentifier:  $paymentIdentifier,
            merchantId:         $remita['merchant_id'],
            serviceTypeId:      $remita['service_type_id'],
            apiKey:             $remita['api_key'],
            returnUrl:          $remita['return_url']
        );

        $checkoutUrl = rtrim($remita['checkout_url'], '/') . '?' . OrderMapper::toCheckoutQueryString($payload);

        $this->logger->info('Checkout URL built', [
            'orderId'           => $orderId,
            'paymentIdentifier' => $paymentIdentifier,
        ]);

        return [
            'checkoutUrl'        => $checkoutUrl,
            'paymentIdentifier'  => $paymentIdentifier,
            'payload'            => $payload,
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Write the order-map entry so the webhook handler can reverse-lookup
     * the Commerce7 order ID from the payment identifier.
     *
     * File: {dataDir}/order_map/{sha256(paymentIdentifier)}.json
     *
     * @throws \RuntimeException on write failure.
     */
    private function persistOrderMap(
        string $paymentIdentifier,
        string $orderId,
        int    $amountCents
    ): void {
        $dataDir = $this->config['adapter']['data_dir'];
        $mapDir  = rtrim($dataDir, '/') . '/' . self::ORDER_MAP_DIR;

        if (!is_dir($mapDir) && !mkdir($mapDir, 0755, true) && !is_dir($mapDir)) {
            throw new \RuntimeException(
                sprintf('Cannot create order_map directory: %s', $mapDir)
            );
        }

        $mapFile = $mapDir . '/' . hash('sha256', $paymentIdentifier) . '.json';

        $contents = json_encode([
            'paymentIdentifier' => $paymentIdentifier,
            'orderId'           => $orderId,
            'amountCents'       => $amountCents,
            'createdAt'         => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

        $tmp = $mapFile . '.tmp';
        if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
            throw new \RuntimeException(
                sprintf('Failed to write order map: %s', $tmp)
            );
        }

        if (!rename($tmp, $mapFile)) {
            @unlink($tmp);
            throw new \RuntimeException(
                sprintf('Failed to rename order map: %s → %s', $tmp, $mapFile)
            );
        }

        $this->logger->debug('Order map persisted', [
            'paymentIdentifier' => $paymentIdentifier,
            'mapFile'           => $mapFile,
        ]);
    }
}
