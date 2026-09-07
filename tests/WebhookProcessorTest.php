<?php

declare(strict_types=1);

namespace Remita\Commerce7\Tests;

use Remita\Commerce7\Commerce7ClientInterface;
use Remita\Commerce7\Support\IdempotencyStore;
use Remita\Commerce7\Support\LoggerService;
use Remita\Commerce7\Webhook\WebhookProcessor;

/**
 * Tests for WebhookProcessor.
 *
 * All external dependencies (Commerce7 HTTP, Remita HTTP) are replaced with
 * in-process stubs via constructor injection — no network calls are made.
 *
 * The Remita status query seam is the $remitaQueryFn callable accepted as
 * the final constructor argument of WebhookProcessor.
 */
final class WebhookProcessorTest extends TestCase
{
    private string $tmpDir;

    public function run(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/c7_webhook_test_' . uniqid();
        mkdir($this->tmpDir . '/idempotency', 0755, true);
        mkdir($this->tmpDir . '/order_map',   0755, true);
        mkdir($this->tmpDir . '/logs',        0755, true);

        try {
            // --- Signature & structural guards -----------------------------------
            $this->testRejectsInvalidSignature();
            $this->testRejectsMissingSignatureHeader();
            $this->testRejectsMissingPaymentReference();
            $this->testRejectsInvalidJsonBody();

            // --- Idempotency ------------------------------------------------------
            $this->testReturnsDuplicateOkImmediately();

            // --- Order map -------------------------------------------------------
            $this->testReturnsNotFoundForMissingOrderMap();

            // --- Remita status flows ----------------------------------------------
            $this->testSuccessfulPaymentRecordedInC7();
            $this->testPendingStatusReturnsRetryResponse();
            $this->testProcessingStatusReturnsRetryResponse();
            $this->testFailedStatusRecordsIdempotencyAndReturnsFailure();
            $this->testRemitaQueryExceptionReturns502();
            $this->testUnknownRemitaStatusReturnsError();

            // --- Commerce7 recording failure -------------------------------------
            $this->testC7RecordPaymentFailureReturns502();
            $this->testSuccessMarkedIdempotentOnSecondDelivery();

            // --- WebhookResult value object --------------------------------------
            $this->testWebhookResultFromArray();
            $this->testWebhookResultAccessors();
        } finally {
            $this->cleanup($this->tmpDir);
        }
    }

    // =========================================================================
    // Builder helpers
    // =========================================================================

    /**
     * Build a processor with all external calls replaced by stubs.
     *
     * @param array<string,mixed>   $remitaStatusResponse  What the Remita query returns.
     * @param Commerce7ClientInterface|null $c7Client      Defaults to the standard stub.
     * @param string                $secret                Webhook HMAC secret.
     */
    private function makeProcessor(
        array                   $remitaStatusResponse = ['status' => '00', 'rrr' => '230000000001', 'amount' => '1500.00'],
        ?Commerce7ClientInterface $c7Client = null,
        string                  $secret   = 'test_secret'
    ): WebhookProcessor {
        $idempotency = new IdempotencyStore($this->tmpDir);
        $logger      = new LoggerService($this->tmpDir . '/logs', 'debug', 'test');
        $c7Client  ??= $this->makeStubC7Client();

        $remitaFn = static function (string $paymentRef) use ($remitaStatusResponse): array {
            return $remitaStatusResponse;
        };

        return new WebhookProcessor(
            c7Client:         $c7Client,
            idempotency:      $idempotency,
            logger:           $logger,
            webhookSecret:    $secret,
            remitaBaseUrl:    'https://login.remita.net/remita/exapp/api/v1/send/api',
            remitaMerchantId: 'merchant001',
            remitaApiKey:     'apikey001',
            dataDir:          $this->tmpDir,
            remitaQueryFn:    $remitaFn
        );
    }

    /**
     * Build a processor whose Remita query callable throws a RuntimeException.
     */
    private function makeProcessorWithRemitaError(string $errorMessage = 'Remita unreachable'): WebhookProcessor
    {
        $idempotency = new IdempotencyStore($this->tmpDir);
        $logger      = new LoggerService($this->tmpDir . '/logs', 'debug', 'test');

        $remitaFn = static function (string $paymentRef) use ($errorMessage): array {
            throw new \RuntimeException($errorMessage);
        };

        return new WebhookProcessor(
            c7Client:         $this->makeStubC7Client(),
            idempotency:      $idempotency,
            logger:           $logger,
            webhookSecret:    'test_secret',
            remitaBaseUrl:    'https://login.remita.net/remita/exapp/api/v1/send/api',
            remitaMerchantId: 'merchant001',
            remitaApiKey:     'apikey001',
            dataDir:          $this->tmpDir,
            remitaQueryFn:    $remitaFn
        );
    }

    /**
     * Build a stub Commerce7 client that records calls without HTTP.
     */
    private function makeStubC7Client(
        array $orderData     = [],
        bool  $recordThrows  = false
    ): Commerce7ClientInterface {
        return new class($orderData, $recordThrows) implements Commerce7ClientInterface {
            public array $recordedPayments = [];
            private array $order;
            private bool  $throws;

            public function __construct(array $order, bool $throws)
            {
                $this->order  = array_merge([
                    'id'    => 'ord_test001',
                    'total' => 150000,
                    'customer' => [
                        'email'     => 'test@example.com',
                        'firstName' => 'Test',
                        'lastName'  => 'User',
                        'phone'     => '+2348000000000',
                    ],
                ], $order);
                $this->throws = $throws;
            }

            public function getOrder(string $orderId): array
            {
                return $this->order;
            }

            public function recordPayment(string $orderId, int $amountCents, string $paymentIdentifier): array
            {
                if ($this->throws) {
                    throw new \RuntimeException('Commerce7 payment recording failed (stub).');
                }
                $this->recordedPayments[] = compact('orderId', 'amountCents', 'paymentIdentifier');
                return ['id' => 'pay_stub001', 'status' => 'paid'];
            }
        };
    }

    private function signPayload(string $rawBody, string $secret = 'test_secret'): string
    {
        return hash_hmac('sha256', $rawBody, $secret);
    }

    /**
     * Write an order map entry so the processor can resolve the Commerce7 order ID.
     */
    private function writeOrderMap(string $paymentRef, string $orderId = 'ord_test001', int $amountCents = 150000): void
    {
        $mapDir  = $this->tmpDir . '/order_map';
        $mapFile = $mapDir . '/' . hash('sha256', $paymentRef) . '.json';
        file_put_contents($mapFile, json_encode([
            'paymentIdentifier' => $paymentRef,
            'orderId'           => $orderId,
            'amountCents'       => $amountCents,
            'createdAt'         => date(\DateTimeInterface::ATOM),
        ]));
    }

    // =========================================================================
    // Tests — signature & structural guards
    // =========================================================================

    private function testRejectsInvalidSignature(): void
    {
        $processor = $this->makeProcessor(secret: 'correct_secret');
        $body      = json_encode(['paymentReference' => 'c7-test-00000001', 'status' => '00']);
        $result    = $processor->process((string) $body, ['x-remita-signature' => 'deadbeef']);

        $this->assertSame('error', $result['status'],   'Invalid signature → status:error');
        $this->assertSame(401,     $result['httpCode'], 'Invalid signature → HTTP 401');
    }

    private function testRejectsMissingSignatureHeader(): void
    {
        $processor = $this->makeProcessor();
        $body      = json_encode(['paymentReference' => 'c7-test-00000002']);
        $result    = $processor->process((string) $body, []);

        $this->assertSame('error', $result['status'],   'Missing header → status:error');
        $this->assertSame(401,     $result['httpCode'], 'Missing header → HTTP 401');
    }

    private function testRejectsMissingPaymentReference(): void
    {
        $body   = json_encode(['rrr' => '123456']);
        $sig    = $this->signPayload((string) $body);
        $result = $this->makeProcessor()->process((string) $body, ['x-remita-signature' => $sig]);

        $this->assertSame('error', $result['status'],   'Missing paymentRef → status:error');
        $this->assertSame(400,     $result['httpCode'], 'Missing paymentRef → HTTP 400');
    }

    private function testRejectsInvalidJsonBody(): void
    {
        $body   = 'not-valid-json{{{';
        $sig    = $this->signPayload($body);
        $result = $this->makeProcessor()->process($body, ['x-remita-signature' => $sig]);

        $this->assertSame('error', $result['status'],   'Invalid JSON → status:error');
        $this->assertSame(400,     $result['httpCode'], 'Invalid JSON → HTTP 400');
    }

    // =========================================================================
    // Tests — idempotency
    // =========================================================================

    private function testReturnsDuplicateOkImmediately(): void
    {
        $paymentRef  = 'c7-ord001-dup00001';
        $body        = json_encode(['paymentReference' => $paymentRef]);
        $sig         = $this->signPayload((string) $body);

        // Pre-seed idempotency store as if this payment was already processed.
        (new IdempotencyStore($this->tmpDir))->record($paymentRef, ['status' => 'success']);

        $result = $this->makeProcessor()->process((string) $body, ['x-remita-signature' => $sig]);

        $this->assertSame('ok',  $result['status'],   'Duplicate → status:ok');
        $this->assertSame(200,   $result['httpCode'], 'Duplicate → HTTP 200');
        $this->assertStringContains('Already processed', $result['message'], 'Duplicate → already-processed message');
    }

    // =========================================================================
    // Tests — order map
    // =========================================================================

    private function testReturnsNotFoundForMissingOrderMap(): void
    {
        $paymentRef = 'c7-ordNOMAP-aabbccdd';
        $body       = json_encode(['paymentReference' => $paymentRef]);
        $sig        = $this->signPayload((string) $body);

        $result = $this->makeProcessor()->process((string) $body, ['x-remita-signature' => $sig]);

        $this->assertSame('error', $result['status'],   'Missing order map → status:error');
        $this->assertSame(404,     $result['httpCode'], 'Missing order map → HTTP 404');
    }

    // =========================================================================
    // Tests — Remita status flows
    // =========================================================================

    private function testSuccessfulPaymentRecordedInC7(): void
    {
        $paymentRef = 'c7-ordSUCCESS-aa000001';
        $this->writeOrderMap($paymentRef);

        $stubC7  = $this->makeStubC7Client();
        $processor = $this->makeProcessor(
            remitaStatusResponse: ['status' => '00', 'rrr' => '230000000099', 'amount' => '1500.00'],
            c7Client:             $stubC7
        );

        $body   = json_encode(['paymentReference' => $paymentRef, 'rrr' => '230000000099']);
        $sig    = $this->signPayload((string) $body);
        $result = $processor->process((string) $body, ['x-remita-signature' => $sig]);

        $this->assertSame('ok',  $result['status'],   'Success → status:ok');
        $this->assertSame(200,   $result['httpCode'], 'Success → HTTP 200');
        $this->assertStringContains('successfully', $result['message'], 'Success → success message');

        // Commerce7 recordPayment must have been called once.
        $this->assertCount(1, $stubC7->recordedPayments, 'recordPayment called once');
        $this->assertSame('ord_test001', $stubC7->recordedPayments[0]['orderId'],           'Correct orderId recorded');
        $this->assertSame(150000,        $stubC7->recordedPayments[0]['amountCents'],        'Correct amount recorded');
        $this->assertSame($paymentRef,   $stubC7->recordedPayments[0]['paymentIdentifier'], 'Correct paymentRef recorded');

        // Idempotency must be marked.
        $store = new IdempotencyStore($this->tmpDir);
        $this->assertTrue($store->has($paymentRef), 'Payment marked idempotent after success');

        // data block must contain orderId + rrr.
        $this->assertArrayHasKey('orderId',    $result['data'] ?? [], 'Response data has orderId');
        $this->assertArrayHasKey('rrr',        $result['data'] ?? [], 'Response data has rrr');
        $this->assertSame('230000000099', $result['data']['rrr'], 'Correct RRR in data');
    }

    private function testPendingStatusReturnsRetryResponse(): void
    {
        $paymentRef = 'c7-ordPENDING-bb000002';
        $this->writeOrderMap($paymentRef);

        $processor = $this->makeProcessor(
            remitaStatusResponse: ['status' => '02', 'amount' => '1500.00']
        );

        $body   = json_encode(['paymentReference' => $paymentRef]);
        $sig    = $this->signPayload((string) $body);
        $result = $processor->process((string) $body, ['x-remita-signature' => $sig]);

        $this->assertSame('pending', $result['status'],   'Pending → status:pending');
        $this->assertSame(200,       $result['httpCode'], 'Pending → HTTP 200');

        // Must NOT be in idempotency store — Remita should retry.
        $store = new IdempotencyStore($this->tmpDir);
        $this->assertFalse($store->has($paymentRef), 'Pending payment NOT marked idempotent');
    }

    private function testProcessingStatusReturnsRetryResponse(): void
    {
        $paymentRef = 'c7-ordPROC-cc000003';
        $this->writeOrderMap($paymentRef);

        $processor = $this->makeProcessor(
            remitaStatusResponse: ['status' => '021', 'amount' => '1500.00']
        );

        $body   = json_encode(['paymentReference' => $paymentRef]);
        $sig    = $this->signPayload((string) $body);
        $result = $processor->process((string) $body, ['x-remita-signature' => $sig]);

        $this->assertSame('pending', $result['status'],   'Processing → status:pending');
        $this->assertSame(200,       $result['httpCode'], 'Processing → HTTP 200');
    }

    private function testFailedStatusRecordsIdempotencyAndReturnsFailure(): void
    {
        $paymentRef = 'c7-ordFAIL-dd000004';
        $this->writeOrderMap($paymentRef);

        $processor = $this->makeProcessor(
            remitaStatusResponse: ['status' => '07', 'amount' => '1500.00']
        );

        $body   = json_encode(['paymentReference' => $paymentRef]);
        $sig    = $this->signPayload((string) $body);
        $result = $processor->process((string) $body, ['x-remita-signature' => $sig]);

        $this->assertSame('failed', $result['status'],   'Failed → status:failed');
        $this->assertSame(200,      $result['httpCode'], 'Failed → HTTP 200');

        // Idempotency must be marked so we do not re-query the failed payment.
        $store = new IdempotencyStore($this->tmpDir);
        $this->assertTrue($store->has($paymentRef), 'Failed payment IS marked idempotent');

        // Stored context must record the failure status.
        $ctx = $store->get($paymentRef);
        $this->assertSame('failed', $ctx['context']['status'] ?? null, 'Idempotency context records failed status');
    }

    private function testRemitaQueryExceptionReturns502(): void
    {
        $paymentRef = 'c7-ordRQERR-ee000005';
        $this->writeOrderMap($paymentRef);

        $processor = $this->makeProcessorWithRemitaError('Remita timeout');

        $body   = json_encode(['paymentReference' => $paymentRef]);
        $sig    = $this->signPayload((string) $body);
        $result = $processor->process((string) $body, ['x-remita-signature' => $sig]);

        $this->assertSame('error', $result['status'],   'Remita query error → status:error');
        $this->assertSame(502,     $result['httpCode'], 'Remita query error → HTTP 502');
    }

    private function testUnknownRemitaStatusReturnsError(): void
    {
        $paymentRef = 'c7-ordUNKNOWN-ff000006';
        $this->writeOrderMap($paymentRef);

        $processor = $this->makeProcessor(
            remitaStatusResponse: ['status' => '999', 'amount' => '1500.00']
        );

        $body   = json_encode(['paymentReference' => $paymentRef]);
        $sig    = $this->signPayload((string) $body);
        $result = $processor->process((string) $body, ['x-remita-signature' => $sig]);

        $this->assertSame('error', $result['status'],   'Unknown status → status:error');
        $this->assertSame(200,     $result['httpCode'], 'Unknown status → HTTP 200 (Remita does not retry)');
    }

    // =========================================================================
    // Tests — Commerce7 recording failure
    // =========================================================================

    private function testC7RecordPaymentFailureReturns502(): void
    {
        $paymentRef = 'c7-ordC7FAIL-gg000007';
        $this->writeOrderMap($paymentRef);

        $stubC7    = $this->makeStubC7Client(recordThrows: true);
        $processor = $this->makeProcessor(
            remitaStatusResponse: ['status' => '00', 'rrr' => '230000000007', 'amount' => '1500.00'],
            c7Client:             $stubC7
        );

        $body   = json_encode(['paymentReference' => $paymentRef]);
        $sig    = $this->signPayload((string) $body);
        $result = $processor->process((string) $body, ['x-remita-signature' => $sig]);

        $this->assertSame('error', $result['status'],   'C7 recording failure → status:error');
        $this->assertSame(502,     $result['httpCode'], 'C7 recording failure → HTTP 502');

        // Must NOT be idempotent — Remita should retry so we can attempt recording again.
        $store = new IdempotencyStore($this->tmpDir);
        $this->assertFalse($store->has($paymentRef), 'C7 failure does NOT mark idempotency (allow retry)');
    }

    /**
     * A second delivery of the same successful webhook must return 200 ok
     * immediately without calling Commerce7 a second time.
     */
    private function testSuccessMarkedIdempotentOnSecondDelivery(): void
    {
        $paymentRef = 'c7-ordIDEM2-hh000008';
        $this->writeOrderMap($paymentRef);

        $stubC7    = $this->makeStubC7Client();
        $processor = $this->makeProcessor(
            remitaStatusResponse: ['status' => '00', 'rrr' => '230000000008', 'amount' => '1500.00'],
            c7Client:             $stubC7
        );

        $body = json_encode(['paymentReference' => $paymentRef]);
        $sig  = $this->signPayload((string) $body);

        // First delivery — processes successfully.
        $result1 = $processor->process((string) $body, ['x-remita-signature' => $sig]);
        $this->assertSame('ok', $result1['status'], 'First delivery: ok');

        // Second delivery — idempotent short-circuit.
        $result2 = $processor->process((string) $body, ['x-remita-signature' => $sig]);
        $this->assertSame('ok', $result2['status'],                         'Second delivery: ok');
        $this->assertStringContains('Already processed', $result2['message'], 'Second delivery: already-processed');

        // recordPayment must have been called exactly once.
        $this->assertCount(1, $stubC7->recordedPayments, 'recordPayment called only once across two deliveries');
    }

    // =========================================================================
    // Tests — WebhookResult value object
    // =========================================================================

    private function testWebhookResultFromArray(): void
    {
        $raw = [
            'status'   => 'ok',
            'message'  => 'Payment processed successfully.',
            'httpCode' => 200,
            'data'     => ['orderId' => 'ord_abc', 'rrr' => '23000001'],
        ];

        $result = \Remita\Commerce7\Webhook\WebhookResult::fromArray($raw);

        $this->assertSame('ok',  $result->getStatus(),   'WebhookResult status');
        $this->assertSame(200,   $result->getHttpCode(), 'WebhookResult httpCode');
        $this->assertTrue($result->isSuccess(),          'WebhookResult isSuccess');
        $this->assertFalse($result->isPending(),         'WebhookResult not isPending');
        $this->assertFalse($result->isFailed(),          'WebhookResult not isFailed');
        $this->assertFalse($result->isError(),           'WebhookResult not isError');
    }

    private function testWebhookResultAccessors(): void
    {
        $error = \Remita\Commerce7\Webhook\WebhookResult::error('Something broke', 502);

        $this->assertSame('error', $error->getStatus(),    'Error result status');
        $this->assertSame(502,     $error->getHttpCode(),  'Error result httpCode');
        $this->assertTrue($error->isError(),               'Error result isError');

        $toArray = $error->toArray();
        $this->assertArrayHasKey('status',   $toArray, 'toArray has status');
        $this->assertArrayHasKey('message',  $toArray, 'toArray has message');
        $this->assertArrayHasKey('httpCode', $toArray, 'toArray has httpCode');
    }

    // =========================================================================
    // Cleanup
    // =========================================================================

    private function cleanup(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
