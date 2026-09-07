<?php

declare(strict_types=1);

/**
 * Commerce7 → Remita Payment Engine — Checkout Initiation Entry Point
 *
 * Called by Commerce7 (or a custom button) to start the payment flow.
 *
 * Expected input (POST body as JSON or query string):
 *   orderId  string  Commerce7 order ID
 *
 * On success: HTTP 302 redirect to Remita checkout URL.
 * On error:   HTTP 4xx/5xx with JSON error body.
 *
 * Integration note: Commerce7 webhooks or a custom "Pay with Remita" button
 * should POST to this endpoint immediately after order creation.
 */

require_once __DIR__ . '/vendor/autoload.php';

use Remita\Commerce7\Commerce7Adapter;
use Remita\Commerce7\Commerce7Client;
use Remita\Commerce7\Support\IdempotencyStore;
use Remita\Commerce7\Support\LoggerService;

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

$config = require __DIR__ . '/config.php';

$logger = new LoggerService(
    logDir:   $config['adapter']['log_dir'],
    minLevel: $config['adapter']['log_level'] ?? 'info',
    channel:  'initiate'
);

// ---------------------------------------------------------------------------
// Parse order ID
// ---------------------------------------------------------------------------

$orderId = null;

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (str_contains($contentType, 'application/json')) {
    $body    = (string) file_get_contents('php://input');
    $decoded = json_decode($body, true);
    $orderId = $decoded['orderId'] ?? null;
} else {
    $orderId = $_POST['orderId'] ?? $_GET['orderId'] ?? null;
}

if (!is_string($orderId) || trim($orderId) === '') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing required parameter: orderId']);
    exit;
}

$orderId = trim($orderId);

// ---------------------------------------------------------------------------
// Build adapter and initiate checkout
// ---------------------------------------------------------------------------

try {
    $c7Client = new Commerce7Client(
        tenantId: $config['commerce7']['tenant_id'],
        apiKey:   $config['commerce7']['api_key'],
        baseUrl:  $config['commerce7']['base_url'] ?? 'https://api.commerce7.com/v1'
    );

    $idempotency = new IdempotencyStore($config['adapter']['data_dir']);

    $adapter = new Commerce7Adapter(
        c7Client:    $c7Client,
        idempotency: $idempotency,
        logger:      $logger,
        config:      $config
    );

    $result = $adapter->initiateCheckout($orderId);

    $logger->info('Redirecting customer to Remita checkout', [
        'orderId'           => $orderId,
        'paymentIdentifier' => $result['paymentIdentifier'],
    ]);

    // Redirect to Remita Payment Engine checkout page.
    header('Location: ' . $result['checkoutUrl'], true, 302);
    exit;

} catch (\InvalidArgumentException $e) {
    $logger->warning('Invalid request to initiate.php', ['error' => $e->getMessage()]);
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
    exit;

} catch (\RuntimeException $e) {
    $logger->error('Checkout initiation failed', [
        'orderId' => $orderId,
        'error'   => $e->getMessage(),
    ]);
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Checkout initiation failed. Please try again.']);
    exit;

} catch (\Throwable $e) {
    $logger->error('Unexpected error in initiate.php', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'An unexpected error occurred.']);
    exit;
}
