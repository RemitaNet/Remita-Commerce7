<?php

declare(strict_types=1);

namespace Remita\Commerce7\Webhook;

use Remita\Commerce7\Commerce7ClientInterface;
use Remita\Commerce7\Support\AmountNormalizer;
use Remita\Commerce7\Support\IdempotencyStore;
use Remita\Commerce7\Support\LoggerService;
use Remita\Commerce7\Support\PaymentIdentifier;
use Remita\Commerce7\Support\PaymentStatusMapper;

/**
 * Processes inbound Remita payment webhook notifications.
 *
 * Responsibilities:
 *   1. Verify the HMAC-SHA256 signature in the X-Remita-Signature header.
 *   2. Decode and validate the JSON body.
 *   3. Short-circuit duplicate deliveries via the idempotency store.
 *   4. Load the order-map entry written by Commerce7Adapter::initiateCheckout().
 *   5. Query Remita for the authoritative payment status.
 *   6. Branch on status: record in Commerce7, return retry signal, or record failure.
 *   7. Return a structured result array consumed by webhook.php.
 *
 * All external I/O (Commerce7 HTTP, Remita status query) is injected so the
 * class is fully testable without network access.
 */
final class WebhookProcessor
{
    private const ORDER_MAP_DIR = 'order_map';

    private Commerce7ClientInterface $c7Client;
    private IdempotencyStore         $idempotency;
    private LoggerService            $logger;
    private string                   $webhookSecret;
    private string                   $remitaBaseUrl;
    private string                   $remitaMerchantId;
    private string                   $remitaApiKey;
    private string                   $dataDir;
    /** @var callable(string): array<string, mixed> */
    private $remitaQueryFn;

    /**
     * @param callable(string): array<string, mixed> $remitaQueryFn
     *        Callable that accepts a paymentIdentifier and returns the Remita
     *        status-query response array.  Defaults to the real HTTP query.
     *        Inject a stub in tests to avoid network calls.
     */
    public function __construct(
        Commerce7ClientInterface $c7Client,
        IdempotencyStore         $idempotency,
        LoggerService            $logger,
        string                   $webhookSecret,
        string                   $remitaBaseUrl,
        string                   $remitaMerchantId,
        string                   $remitaApiKey,
        string                   $dataDir,
        ?callable                $remitaQueryFn = null
    ) {
        $this->c7Client         = $c7Client;
        $this->idempotency      = $idempotency;
        $this->logger           = $logger;
        $this->webhookSecret    = $webhookSecret;
        $this->remitaBaseUrl    = rtrim($remitaBaseUrl, '/');
        $this->remitaMerchantId = $remitaMerchantId;
        $this->remitaApiKey     = $remitaApiKey;
        $this->dataDir          = rtrim($dataDir, '/');
        $this->remitaQueryFn    = $remitaQueryFn ?? $this->makeDefaultQueryFn();
    }

    // -------------------------------------------------------------------------
    // Public
    // -------------------------------------------------------------------------

    /**
     * Process a raw webhook delivery.
     *
     * @param  string                $rawBody  Raw JSON body from php://input.
     * @param  array<string, string> $headers  Normalised lowercase header map.
     * @return array<string, mixed>            Result array including 'httpCode' key.
     */
    public function process(string $rawBody, array $headers): array
    {
        // 1. Verify HMAC signature -----------------------------------------------
        $signature = $headers['x-remita-signature'] ?? '';
        if ($signature === '') {
            $this->logger->warning('Webhook rejected: missing signature header.');
            return WebhookResult::error('Missing X-Remita-Signature header.', 401)->toArray();
        }

        $expected = hash_hmac('sha256', $rawBody, $this->webhookSecret);
        if (!hash_equals($expected, $signature)) {
            $this->logger->warning('Webhook rejected: invalid signature.');
            return WebhookResult::error('Invalid signature.', 401)->toArray();
        }

        // 2. Decode JSON body -----------------------------------------------------
        try {
            $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new \JsonException('Decoded value is not an array.');
            }
        } catch (\JsonException $e) {
            $this->logger->warning('Webhook rejected: invalid JSON body.', ['error' => $e->getMessage()]);
            return WebhookResult::error('Invalid JSON body.', 400)->toArray();
        }

        // 3. Extract payment reference --------------------------------------------
        $paymentRef = (string) ($payload['paymentReference'] ?? '');
        if ($paymentRef === '') {
            $this->logger->warning('Webhook rejected: missing paymentReference.');
            return WebhookResult::error('Missing paymentReference field.', 400)->toArray();
        }

        $this->logger->info('Webhook received.', ['paymentRef' => $paymentRef]);

        // 4. Idempotency check ----------------------------------------------------
        if ($this->idempotency->has($paymentRef)) {
            $this->logger->info('Webhook duplicate — already processed.', ['paymentRef' => $paymentRef]);
            return WebhookResult::ok('Already processed.')->toArray();
        }

        // 5. Load order map -------------------------------------------------------
        $orderMap = $this->loadOrderMap($paymentRef);
        if ($orderMap === null) {
            $this->logger->warning('Webhook: order map not found.', ['paymentRef' => $paymentRef]);
            return WebhookResult::error(
                sprintf('No order found for payment reference "%s".', $paymentRef),
                404
            )->toArray();
        }

        $orderId     = (string) ($orderMap['orderId']     ?? '');
        $amountCents = (int)    ($orderMap['amountCents'] ?? 0);

        // 6. Query Remita for authoritative status --------------------------------
        try {
            $remitaResponse = ($this->remitaQueryFn)($paymentRef);
        } catch (\Throwable $e) {
            $this->logger->error('Remita status query failed.', [
                'paymentRef' => $paymentRef,
                'error'      => $e->getMessage(),
            ]);
            return WebhookResult::error('Remita status query failed: ' . $e->getMessage(), 502)->toArray();
        }

        $rrr    = (string) ($remitaResponse['rrr']    ?? ($payload['rrr'] ?? ''));
        $status = PaymentStatusMapper::fromQueryResult($remitaResponse);

        $this->logger->info('Remita status resolved.', [
            'paymentRef' => $paymentRef,
            'status'     => $status,
            'rrr'        => $rrr,
        ]);

        // 7. Branch on status ------------------------------------------------------

        if (PaymentStatusMapper::isRecordable($status)) {
            // SUCCESS — record payment in Commerce7 then mark idempotent.
            try {
                $this->c7Client->recordPayment($orderId, $amountCents, $paymentRef);
            } catch (\Throwable $e) {
                $this->logger->error('Commerce7 recordPayment failed.', [
                    'paymentRef' => $paymentRef,
                    'orderId'    => $orderId,
                    'error'      => $e->getMessage(),
                ]);
                // Do NOT mark idempotent — Remita should retry so we can attempt again.
                return WebhookResult::error('Failed to record payment in Commerce7.', 502)->toArray();
            }

            $this->idempotency->record($paymentRef, ['status' => $status, 'rrr' => $rrr]);

            $this->logger->info('Payment recorded successfully.', [
                'paymentRef' => $paymentRef,
                'orderId'    => $orderId,
                'rrr'        => $rrr,
            ]);

            return WebhookResult::ok('Payment recorded successfully.', [
                'orderId' => $orderId,
                'rrr'     => $rrr,
            ])->toArray();
        }

        if (PaymentStatusMapper::isRetryable($status)) {
            // PENDING / PROCESSING — do not mark idempotent; Remita will retry.
            $this->logger->info('Payment not yet complete — awaiting retry.', [
                'paymentRef' => $paymentRef,
                'status'     => $status,
            ]);
            return WebhookResult::pending(PaymentStatusMapper::label($status))->toArray();
        }

        if (PaymentStatusMapper::isFailure($status)) {
            // FAILED — mark idempotent to prevent re-querying a definitively failed payment.
            $this->idempotency->record($paymentRef, ['status' => $status, 'rrr' => $rrr]);

            $this->logger->warning('Payment failed.', [
                'paymentRef' => $paymentRef,
                'orderId'    => $orderId,
                'rrr'        => $rrr,
            ]);

            return WebhookResult::failed(PaymentStatusMapper::label($status))->toArray();
        }

        // UNKNOWN status — return error but do not mark idempotent so operators
        // can investigate without Remita retrying indefinitely.
        $this->logger->warning('Unknown Remita status received.', [
            'paymentRef' => $paymentRef,
            'rawStatus'  => $remitaResponse['status'] ?? 'n/a',
        ]);

        return WebhookResult::error(
            sprintf('Unknown payment status for reference "%s".', $paymentRef),
            200
        )->toArray();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Load the order-map JSON file written by Commerce7Adapter.
     *
     * @param  string                    $paymentRef
     * @return array<string, mixed>|null  Decoded map or null if not found.
     */
    private function loadOrderMap(string $paymentRef): ?array
    {
        $mapDir  = $this->dataDir . '/' . self::ORDER_MAP_DIR;
        $mapFile = $mapDir . '/' . hash('sha256', $paymentRef) . '.json';

        if (!file_exists($mapFile)) {
            return null;
        }

        $raw = file_get_contents($mapFile);
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
     * Build the default Remita status-query callable used in production.
     *
     * Queries: {remitaBaseUrl}/remita/ecomm/{merchantId}/{paymentRef}/{hash}/status.reg
     * Hash: SHA-512 of paymentRef + apiKey + merchantId
     *
     * @return callable(string): array<string, mixed>
     */
    private function makeDefaultQueryFn(): callable
    {
        $baseUrl    = $this->remitaBaseUrl;
        $merchantId = $this->remitaMerchantId;
        $apiKey     = $this->remitaApiKey;

        return static function (string $paymentRef) use ($baseUrl, $merchantId, $apiKey): array {
            $hash = hash('sha512', $paymentRef . $apiKey . $merchantId);
            $url  = sprintf(
                '%s/remita/ecomm/%s/%s/%s/status.reg',
                $baseUrl,
                rawurlencode($merchantId),
                rawurlencode($paymentRef),
                $hash
            );

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: remitaConsumerKey=' . $merchantId . ',remitaConsumerToken=' . $hash,
                ],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $raw    = curl_exec($ch);
            $errno  = curl_errno($ch);
            $errMsg = curl_error($ch);
            curl_close($ch);

            if ($errno !== 0) {
                throw new \RuntimeException(
                    sprintf('Remita status query cURL error (%d): %s', $errno, $errMsg)
                );
            }

            if (!is_string($raw) || $raw === '') {
                throw new \RuntimeException('Remita status query returned empty response.');
            }

            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new \RuntimeException(
                    sprintf('Remita status query returned non-JSON: %s', substr($raw, 0, 200)),
                    0,
                    $e
                );
            }

            return is_array($decoded) ? $decoded : [];
        };
    }
}
