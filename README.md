Project Structure

commerce7/
├── src/
│   ├── Commerce7Adapter.php          Checkout initiation orchestrator
│   ├── Commerce7Client.php           Commerce7 REST API wrapper (cURL)
│   ├── Commerce7ClientInterface.php  Contract for DI / testing
│   ├── Webhook/
│   │   ├── WebhookProcessor.php      Inbound webhook handler
│   │   └── WebhookResult.php         Typed value object for processor output
│   └── Support/
│       ├── AmountNormalizer.php      cents ↔ major-unit conversions
│       ├── IdempotencyStore.php      File-based duplicate prevention
│       ├── LoggerService.php         JSON-line rotating file logger
│       ├── OrderMapper.php           C7 order → Remita payload builder
│       ├── PaymentIdentifier.php     c7-{orderId}-{hex} generator
│       └── PaymentStatusMapper.php   Remita status codes → internal state
├── webhook.php                       HTTP entry point (POST from Remita)
├── initiate.php                      HTTP entry point (redirect to Remita)
├── config.php.example                Configuration template
├── data/
│   ├── idempotency/                  Auto-created; one file per processed payment
│   └── order_map/                    Auto-created; one file per initiated checkout
├── logs/                             Auto-created; daily JSON-line log files
└── tests/
    ├── bootstrap.php                 PSR-4 autoloader (no Composer required)
    ├── run.php                       Test runner (zero dependencies)
    ├── TestCase.php                  Base class with assertion helpers
    ├── AmountNormalizerTest.php      13 assertions
    ├── OrderMapperTest.php           21 assertions
    ├── PaymentIdentifierTest.php     16 assertions
    ├── PaymentStatusMapperTest.php   26 assertions
    └── WebhookProcessorTest.php      56 assertions (all flows, no HTTP)

⚙️ Configuration

Commerce7 Setup

1. Generate an API key

Log in to your Commerce7 admin panel.

Navigate to Settings → API Keys.

Click Create API Key.

Copy the key immediately — it is shown only once.

Note your Tenant ID (the slug in your Commerce7 URL, e.g. my-winery).

2. Required permissions

Resource

Permission

Orders

Read

Payments

Write

3. Configure the adapter

cp config.php.example config.php

Edit config.php:

'commerce7' => [
    'tenant_id' => 'my-winery',       // your Commerce7 tenant slug
    'api_key'   => 'c7sk_live_...',   // API key from step 1
],

Remita Setup

1. Obtain credentials

From your Remita merchant dashboard:

Credential

Where to find it

Merchant ID

Profile page

API Key

API Credentials section

Service Type ID

Assigned payment-type code

Webhook Secret

Choose a strong random string; configure the same value in Remita's portal

Generate a webhook secret locally:

php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"

2. Configure webhook delivery

In the Remita merchant portal, set your webhook URL to:

https://your-domain.com/webhook.php

Remita will POST JSON to this URL after each payment attempt.

3. Complete config.php

'remita' => [
    'merchant_id'     => '123456789',
    'api_key'         => 'remita_live_...',
    'service_type_id' => '4430731',
    'webhook_secret'  => 'a-long-random-secret-string',
    'base_url'        => 'https://login.remita.net/remita/exapp/api/v1/send/api',
    'checkout_url'    => 'https://login.remita.net/remita/ecomm/init.reg',
    'return_url'      => 'https://your-domain.com/payment/return',
],

🚀 Quick Start

Initiating a Checkout

Send a POST to initiate.php with the Commerce7 order ID:

# JSON body
curl -X POST https://your-domain.com/initiate.php \
  -H "Content-Type: application/json" \
  -d '{"orderId": "ord_abc123"}'

# Form-encoded
curl -X POST https://your-domain.com/initiate.php \
  -d "orderId=ord_abc123"

The response is an HTTP 302 redirect to the Remita checkout URL. In a browser context the customer is redirected automatically.

Commerce7 Order Hook

To trigger initiation when an order is placed, configure a Commerce7 webhook or a custom Pay with Remita button to POST to initiate.php immediately after order creation.

🔔 Webhook Endpoint

Remita sends a POST to webhook.php after payment:

{
  "paymentReference": "c7-ordabc123-a1b2c3d4",
  "rrr":              "230007654321",
  "status":           "00",
  "amount":           "1500.00",
  "transactionTime":  "2026-01-01 12:00:00"
}

With the header:

X-Remita-Signature: <hmac-sha256-hex>

Webhook Processing Flow

Verify the HMAC-SHA256 signature.

Check file-based idempotency.

Load the order_map entry.

Resolve the Commerce7 order ID.

Query Remita for authoritative status.

Record successful payments in Commerce7.

Return the appropriate HTTP response.

Webhook Response Codes

Code

Body

Meaning

200

{"status":"ok"}

Processed successfully

200

{"status":"pending"}

Payment not yet confirmed — Remita retries

200

{"status":"failed"}

Terminal failure — no retry needed

200

{"status":"ok","message":"Already processed."}

Duplicate — idempotent

401

{"status":"error"}

Signature verification failed

404

{"status":"error"}

Order not found in order map

502

{"status":"error"}

Upstream error (Remita or Commerce7)

500

{"status":"error"}

Internal error — Remita will retry

📚 Documentation

WebhookResult Value Object

WebhookProcessor::process() returns a plain array. The WebhookResult class wraps it for typed access:

use Remita\Commerce7\Webhook\WebhookResult;

$raw    = $processor->process($rawBody, $headers);
$result = WebhookResult::fromArray($raw);

if ($result->isSuccess()) {
    $orderId = $result->getData()['orderId'];
}

if ($result->isError()) {
    error_log($result->getMessage());
}

http_response_code($result->getHttpCode());
echo json_encode($result->toArray());

Payment Identifier Format

c7-{sanitisedOrderId}-{8hexchars}

Examples:

c7-ord12345-a3f9b210
c7-ordabc123-ff01cc7e

The c7 prefix scopes identifiers to this adapter within the Remita merchant account, preventing collisions with other integrations sharing the same merchant credentials.

Remita Status Codes

Code

Internal status

Action

00, 01, 025

success

Record payment in Commerce7

02

pending

Return 200, wait for Remita retry

021

processing

Return 200, wait for Remita retry

07, 068, 069, 062, 063

failed

Mark idempotent, return 200 failed

anything else

unknown

Log warning, return 200 error

Logging

Log files are written to logs/ as daily JSON-line files:

logs/commerce7-YYYY-MM-DD.log
logs/webhook-YYYY-MM-DD.log
logs/initiate-YYYY-MM-DD.log

Each line follows a structured JSON format:

{"ts":"2026-01-01T12:00:00+00:00","level":"info","message":"Payment recorded in Commerce7","context":{"orderId":"ord_abc123","amountCents":150000}}

Set log_level to debug in config.php for full request/response tracing during development.

🧪 Testing

Run the Full Test Suite

php tests/run.php

Expected output:

──────────────────────────────────────────────────────────────────────
  Commerce7 + Remita Adapter — Test Suite
──────────────────────────────────────────────────────────────────────
  ✓  AmountNormalizerTest                           13 passed    0 failed
  ✓  OrderMapperTest                                21 passed    0 failed
  ✓  PaymentIdentifierTest                          16 passed    0 failed
  ✓  PaymentStatusMapperTest                        26 passed    0 failed
  ✓  WebhookProcessorTest                           56 passed    0 failed
──────────────────────────────────────────────────────────────────────
  Total: 132 passed, 0 failed

Filter by Suite Name

php tests/run.php --filter WebhookProcessor
php tests/run.php --filter AmountNormalizer

Webhook Flows Covered Without HTTP

WebhookProcessorTest exercises every code path using injected stubs — no live network calls:

Test

What it verifies

testRejectsInvalidSignature

HMAC mismatch → 401

testRejectsMissingSignatureHeader

No header → 401

testRejectsMissingPaymentReference

No paymentReference → 400

testRejectsInvalidJsonBody

Malformed JSON → 400

testReturnsDuplicateOkImmediately

Pre-seeded idempotency → 200 ok

testReturnsNotFoundForMissingOrderMap

No order_map file → 404

testSuccessfulPaymentRecordedInC7

Status 00 → C7 recordPayment called, idempotent

testPendingStatusReturnsRetryResponse

Status 02 → 200 pending, NOT idempotent

testProcessingStatusReturnsRetryResponse

Status 021 → 200 pending

testFailedStatusRecordsIdempotencyAndReturnsFailure

Status 07 → 200 failed, idempotent

testRemitaQueryExceptionReturns502

Remita unreachable → 502

testUnknownRemitaStatusReturnsError

Unknown code → 200 error

testC7RecordPaymentFailureReturns502

C7 throws → 502, NOT idempotent

testSuccessMarkedIdempotentOnSecondDelivery

Two deliveries → recordPayment called once

testWebhookResultFromArray

WebhookResult value object

testWebhookResultAccessors

WebhookResult::error() + toArray()

Testability Seam

WebhookProcessor accepts an optional $remitaQueryFn callable as its last constructor argument. When provided, it replaces the real cURL Remita status query — no subclassing or reflection needed:

$processor = new WebhookProcessor(
    c7Client:      $stubC7,
    idempotency:   $idempotency,
    logger:        $logger,
    webhookSecret: 'secret',
    remitaBaseUrl: 'https://...',
    // ...
    remitaQueryFn: fn(string $ref) => ['status' => '00', 'rrr' => '23000001'],
);

Test Webhook Signature Manually

SECRET="your_webhook_secret"
BODY='{"paymentReference":"c7-test-a1b2c3d4","status":"00","amount":"1500.00","rrr":"230000000001"}'
SIG=$(echo -n "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $2}')

curl -X POST http://localhost:8000/webhook.php \
  -H "Content-Type: application/json" \
  -H "X-Remita-Signature: $SIG" \
  -d "$BODY"

PHP Built-in Server

# From the adapter root
php -S localhost:8000

# In another terminal
php tests/run.php

🚢 Deployment

Directory Permissions

mkdir -p data/idempotency data/order_map logs
chmod 755 data data/idempotency data/order_map logs
chown www-data:www-data data data/idempotency data/order_map logs

Protect Sensitive Directories

The data/ directory must not be web-accessible. Add to .htaccess or Nginx config.

Apache:

Options -Indexes
php_flag display_errors Off

# Block web access to data/ and config
<FilesMatch "config\.php$">
    Require all denied
</FilesMatch>
<DirectoryMatch "^.*/data/">
    Require all denied
</DirectoryMatch>

Nginx:

location ~ ^/(data|logs)/ {
    deny all;
    return 404;
}

location /payment/ {
    root /var/www/commerce7-adapter;
    try_files $uri $uri/ =404;

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}

Autoloader

The adapter is designed to work without Composer. The test bootstrap in tests/bootstrap.php registers a PSR-4 autoloader that resolves:

Remita\Commerce7\*        → src/*.php
Remita\Commerce7\Support\ → src/Support/*.php
Remita\Commerce7\Webhook\ → src/Webhook/*.php

For production, either add require_once calls for each class in your entry points, or use Composer:

{
    "autoload": {
        "psr-4": {
            "Remita\\Commerce7\\": "src/"
        }
    }
}

composer dump-autoload

🔐 Security Notes

config.php must never be committed to version control — add it to .gitignore.

The data/ directory must not be web-accessible.

Webhook signature verification uses hash_equals() (constant-time) to prevent timing attacks.

All cURL calls enforce CURLOPT_SSL_VERIFYPEER and CURLOPT_SSL_VERIFYHOST.

Log files never contain payment card data, API keys, or full webhook bodies.

Idempotency files prevent double-recording of replayed webhooks, including after server restarts
