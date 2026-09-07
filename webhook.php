<?php

declare(strict_types=1);

/**
 * Commerce7 + Remita Payment Engine — Webhook Entry Point
 *
 * Receives POST notifications from the Remita Payment Engine after a
 * customer completes (or fails) a payment.
 *
 * Security:
 *   - HMAC-SHA256 signature verified against X-Remita-Signature header.
 *   - Only POST requests are accepted.
 *   - All errors return 200 with a JSON body so Remita does not retry
 *     on client-side errors; genuine server errors return 5xx to trigger
 *     Remita's retry mechanism.
 *
 * Expected Remita webhook payload (JSON):
 *   {
 *     "paymentReference": "c7-ord123-a1b2c3d4",
 *     "rrr":              "230007654321",
 *     "status":           "00",
 *     "amount":           "1500.00",
 *     "transactionTime":  "2026-01-01 12:00:00"
 *   }
 */

require_once __DIR__ . '/vendor/autoload.php';

use Remita\Commerce7\Commerce7Client;
use Remita\Commerce7\Support\IdempotencyStore;
use Remita\Commerce7\Support\LoggerService;
use Remita\Commerce7\Webhook\WebhookProcessor;

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

$config = require __DIR__ . '/config.php';

$logger = new LoggerService(
    logDir:   $config['adapter']['log_dir'],
    minLevel: $config['adapter']['log_level'] ?? 'info',
    channel:  'webhook'
);

// ---------------------------------------------------------------------------
// Reject non-POST requests early
// ---------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// ---------------------------------------------------------------------------
// Capture raw body and headers
// ---------------------------------------------------------------------------

$rawBody = (string) file_get_contents('php://input');

// Collect all HTTP_ prefixed headers plus common ones.
$headers = [];
foreach ($_SERVER as $key => $value) {
    if (str_starts_with($key, 'HTTP_')) {
        // Convert HTTP_X_REMITA_SIGNATURE → x-remita-signature
        $headerName           = strtolower(str_replace('_', '-', substr($key, 5)));
        $headers[$headerName] = (string) $value;
    }
}

// Also capture headers set without HTTP_ prefix in some SAPI environments.
if (function_exists('getallheaders')) {
    foreach (getallheaders() as $name => $value) {
        $headers[strtolower($name)] = $value;
    }
}

// ---------------------------------------------------------------------------
// Process webhook
// ---------------------------------------------------------------------------

try {
    $c7Client = new Commerce7Client(
        tenantId: $config['commerce7']['tenant_id'],
        apiKey:   $config['commerce7']['api_key'],
        baseUrl:  $config['commerce7']['base_url'] ?? 'https://api.commerce7.com/v1'
    );

    $idempotency = new IdempotencyStore($config['adapter']['data_dir']);

    $processor = new WebhookProcessor(
        c7Client:         $c7Client,
        idempotency:      $idempotency,
        logger:           $logger,
        webhookSecret:    $config['remita']['webhook_secret'],
        remitaBaseUrl:    $config['remita']['base_url'],
        remitaMerchantId: $config['remita']['merchant_id'],
        remitaApiKey:     $config['remita']['api_key'],
        dataDir:          $config['adapter']['data_dir']
    );

    $result   = $processor->process($rawBody, $headers);
    $httpCode = $result['httpCode'] ?? 200;

    unset($result['httpCode']);

    http_response_code($httpCode);
    header('Content-Type: application/json');
    echo json_encode($result, JSON_UNESCAPED_SLASHES);

} catch (\Throwable $e) {
    $logger->error('Unhandled exception in webhook.php', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);

    // Return 500 so Remita will retry delivery.
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Internal server error.']);
}
