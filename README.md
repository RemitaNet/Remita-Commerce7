💳 Commerce7 orders. Remita payments. Connected.

A production-ready PHP adapter that lets Commerce7 customers pay through Remita and automatically records successful payments back in Commerce7.

If you're looking for a simple way to connect a Commerce7 winery store to Remita Payment Engine, this adapter handles the difficult parts for you:

Creates a Remita checkout from a Commerce7 order

Sends the customer to Remita to complete payment

Receives and verifies Remita payment webhooks

Confirms the payment status with Remita

Records successful payments in Commerce7

Prevents duplicate payments when webhooks are retried

Keeps useful logs for troubleshooting

In short: Commerce7 creates the order → Remita collects the payment → this adapter connects the two.

🧭 Quick Navigation

How It Works

What You Need

Installation

Configuration

First Checkout

Webhooks

Testing

Deployment

Troubleshooting

Technical Reference

Security

License

🚀 How It Works

The adapter has two main jobs: start a payment and confirm a payment.

┌─────────────────────┐
│ Customer places     │
│ order in Commerce7  │
└──────────┬──────────┘
           │
           ▼
┌─────────────────────┐
│ initiate.php        │
│ Reads the order     │
│ from Commerce7      │
└──────────┬──────────┘
           │
           │ Creates payment reference
           ▼
┌─────────────────────┐
│ Remita Checkout     │
│ Customer pays       │
└──────────┬──────────┘
           │
           │ Payment webhook
           ▼
┌─────────────────────┐
│ webhook.php         │
│ Verifies webhook    │
│ Confirms status     │
│ Records payment     │
└──────────┬──────────┘
           │
           ▼
┌─────────────────────┐
│ Commerce7           │
│ Payment recorded    │
└─────────────────────┘

Why are there two steps?

A redirect back from a payment page is not enough to prove that money was successfully paid.

The adapter therefore:

Receives the payment notification from Remita.

Verifies that the notification really came from Remita.

Checks whether the payment was already processed.

Asks Remita for the authoritative payment status.

Records the payment in Commerce7 only when appropriate.

This makes the integration safer and retry-friendly.

✨ Features

For the customer

💳 Familiar Remita payment checkout

🔄 Reliable payment confirmation

⚡ Automatic return to the configured payment flow

For the winery / Commerce7 store

🧾 Commerce7 orders remain the source of truth

💰 Successful Remita payments are recorded against the correct order

🔁 Webhook retries do not create duplicate payments

🗂️ Order-to-payment mappings are persisted locally

📊 Structured logs make payment issues easier to investigate

For developers

🔐 HMAC-SHA256 webhook verification

🛡️ Constant-time signature comparison with hash_equals()

🔒 TLS certificate and hostname verification

🧪 Zero-dependency test suite

🧩 Interface-based Commerce7 client for testing

🔌 Injectable Remita status query for tests

📦 Works without Composer

📋 What You Need

Before installing the adapter, make sure you have:

Requirement

Why you need it

PHP 8.1+

Runs the adapter

PHP ext-curl

Communicates with Commerce7 and Remita

Commerce7 account

Provides the customer order

Commerce7 API key

Lets the adapter read orders and write payments

Commerce7 Tenant ID

Identifies your winery

Remita merchant account

Receives customer payments

Remita Payment Engine access

Creates payment checkouts

Remita credentials

Authenticates API requests

Public HTTPS URL

Receives Remita webhooks

Write access to data/ and logs/

Stores mappings, idempotency records and logs

Local development: You can use PHP's built-in server for local testing. A publicly reachable HTTPS endpoint is required when Remita needs to send webhooks to your machine.

📦 Installation

1. Get the project

Clone or copy the adapter into the directory where you want to host it.

git clone <repository-url>
cd commerce7-remita

If you already have the project files, simply open the project directory.

2. Check PHP

php -v

You should have PHP 8.1 or newer.

Make sure cURL is enabled:

php -m | grep curl

3. Create your configuration

Copy the example configuration:

cp config.php.example config.php

Then open config.php and add your Commerce7 and Remita credentials.

4. Create writable directories

mkdir -p data/idempotency data/order_map logs

The application will use these directories automatically.

⚙️ Configuration

You need to configure two services: Commerce7 and Remita.

🏪 Commerce7

Step 1 — Create an API key

Log in to your Commerce7 admin panel.

Go to Settings → API Keys.

Click Create API Key.

Copy the key and store it securely.

Find your Tenant ID — this is the slug used by your Commerce7 tenant, such as my-winery.

Step 2 — Give the key the required permissions

Resource

Permission

Orders

Read

Payments

Write

Step 3 — Add the credentials

'commerce7' => [
    'tenant_id' => 'my-winery',
    'api_key'   => 'c7sk_live_...',
],

💚 Remita

Step 1 — Get your Remita credentials

From your Remita merchant dashboard, obtain:

Credential

What it is used for

Merchant ID

Identifies your Remita merchant account

API Key

Authenticates API requests

Service Type ID

Identifies the payment type

Webhook Secret

Verifies incoming webhook requests

For the webhook secret, generate a strong random value:

php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"

Use the same secret when configuring webhook signing with Remita.

Step 2 — Configure your webhook

Set your Remita webhook URL to:

https://your-domain.com/webhook.php

Remita will send a POST request to this endpoint after payment activity.

Step 3 — Complete your configuration

'remita' => [
    'merchant_id'     => '123456789',
    'api_key'         => 'remita_live_...',
    'service_type_id' => '4430731',
    'webhook_secret'  => 'your-random-webhook-secret',
    'base_url'        => 'https://login.remita.net/remita/exapp/api/v1/send/api',
    'checkout_url'    => 'https://login.remita.net/remita/ecomm/init.reg',
    'return_url'      => 'https://your-domain.com/payment/return',
],

Important: Do not commit config.php to Git. Keep production credentials outside version control.

🗂️ Project Structure

commerce7/
├── src/
│   ├── Commerce7Adapter.php          Main checkout orchestrator
│   ├── Commerce7Client.php           Commerce7 REST API client
│   ├── Commerce7ClientInterface.php  Client contract for testing
│   ├── Webhook/
│   │   ├── WebhookProcessor.php      Processes Remita webhooks
│   │   └── WebhookResult.php         Typed webhook result
│   └── Support/
│       ├── AmountNormalizer.php      cents ↔ major-unit conversion
│       ├── IdempotencyStore.php      Prevents duplicate processing
│       ├── LoggerService.php         JSON-line logger
│       ├── OrderMapper.php           Builds Remita payment payloads
│       ├── PaymentIdentifier.php     Creates payment references
│       └── PaymentStatusMapper.php   Maps Remita status codes
│
├── initiate.php                      Starts a Remita checkout
├── webhook.php                       Receives Remita webhooks
├── config.php.example                Configuration template
│
├── data/
│   ├── idempotency/                  Processed payment markers
│   └── order_map/                    Order/payment mappings
│
├── logs/                             Daily application logs
│
└── tests/
    ├── bootstrap.php
    ├── run.php
    ├── TestCase.php
    ├── AmountNormalizerTest.php
    ├── OrderMapperTest.php
    ├── PaymentIdentifierTest.php
    ├── PaymentStatusMapperTest.php
    └── WebhookProcessorTest.php

🟢 First Checkout

Once your credentials are configured, the basic checkout flow is straightforward.

1. Send a Commerce7 order ID

curl -X POST https://your-domain.com/initiate.php   -H "Content-Type: application/json"   -d '{"orderId": "ord_abc123"}'

You can also send form data:

curl -X POST https://your-domain.com/initiate.php   -d "orderId=ord_abc123"

2. What happens next?

The adapter:

Retrieves the order from Commerce7.

Builds the Remita payment request.

Creates a payment identifier such as:

c7-ord_abc123-a3f9b210

Saves the order-to-payment mapping.

Redirects the customer to Remita.

The response is an HTTP 302 redirect to the Remita checkout URL, so a browser can follow it automatically.

3. Connecting this to Commerce7

You can trigger initiate.php using a Commerce7 webhook or a custom Pay with Remita button immediately after an order is created.

🔔 Webhooks

After the customer attempts payment, Remita sends a POST request to:

/webhook.php

A typical payload looks like:

{
  "paymentReference": "c7-ordabc123-a1b2c3d4",
  "rrr": "230007654321",
  "status": "00",
  "amount": "1500.00",
  "transactionTime": "2026-01-01 12:00:00"
}

The request also contains:

X-Remita-Signature: <hmac-sha256-hex>

What the webhook handler does

Remita webhook
     │
     ▼
Verify signature
     │
     ▼
Already processed?
   │       │
  yes      no
   │       │
   ▼       ▼
Return    Find order
200       mapping
             │
             ▼
       Ask Remita for
       authoritative status
             │
       ┌─────┼─────┐
       ▼     ▼     ▼
    Success Pending Failed
       │     │       │
       ▼     ▼       ▼
    Record  Retry   Finish
    in C7

This is important because the webhook itself is treated as a notification, while the Remita status query is used to confirm the actual payment state.

Webhook responses

HTTP

Response

Meaning

200

{"status":"ok"}

Payment processed successfully

200

{"status":"pending"}

Payment is not confirmed yet; retry is expected

200

{"status":"failed"}

Payment has reached a terminal failure state

200

{"status":"ok","message":"Already processed."}

Duplicate webhook; safely ignored

401

{"status":"error"}

Invalid or missing signature

404

{"status":"error"}

Payment reference has no known order mapping

502

{"status":"error"}

Remita or Commerce7 could not be reached

500

{"status":"error"}

Unexpected internal error

🧪 Testing

The project includes a zero-dependency test suite, so you do not need Composer or live Commerce7/Remita calls to run the tests.

Run everything

php tests/run.php

Expected result:

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

Run one test group

php tests/run.php --filter WebhookProcessor

or:

php tests/run.php --filter AmountNormalizer

Test the webhook locally

Start PHP's built-in server:

php -S localhost:8000

Then send a signed test webhook from another terminal.

SECRET="your_webhook_secret"
BODY='{"paymentReference":"c7-test-a1b2c3d4","status":"00","amount":"1500.00","rrr":"230000000001"}'
SIG=$(echo -n "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $2}')

curl -X POST http://localhost:8000/webhook.php   -H "Content-Type: application/json"   -H "X-Remita-Signature: $SIG"   -d "$BODY"

What is covered?

The webhook tests cover:

Scenario

Expected result

Invalid signature

401

Missing signature

401

Missing payment reference

400

Invalid JSON

400

Duplicate payment

200, already processed

Missing order mapping

404

Successful payment

Record payment in C7

Pending payment

200, wait for retry

Processing payment

200, wait for retry

Failed payment

200, mark as processed

Remita unavailable

502

Unknown status

200, error

Commerce7 payment recording fails

502

Same webhook delivered twice

Payment recorded only once

🔎 Troubleshooting

"The checkout does not open"

Check:

config.php contains the correct Remita credentials.

The service_type_id is correct.

The Remita checkout URL is correct.

initiate.php can reach both Commerce7 and Remita.

The logs/ directory contains no API errors.

"The webhook returns 401"

A 401 normally means the webhook signature could not be verified.

Check:

webhook_secret matches the secret configured with Remita.

The X-Remita-Signature header is present.

The request body is being passed to the signature verification code unchanged.

"The webhook returns 404"

The adapter could not find the payment reference in data/order_map/.

This usually means the payment was not initiated through this adapter, or the order mapping was removed.

"The payment was successful but Commerce7 was not updated"

Check:

The Remita status returned by the authoritative status query.

Commerce7 API credentials.

The API key's Payments: Write permission.

The application logs.

Whether Commerce7 was temporarily unavailable.

"The same webhook keeps arriving"

That can be normal for pending/processing payments.

Successful or terminally failed payments are stored using idempotency records so repeated deliveries do not record the payment twice.

📚 Technical Reference

Payment Identifier

Every initiated payment receives an identifier in this format:

c7-{sanitisedOrderId}-{8hexchars}

Examples:

c7-ord12345-a3f9b210
c7-ordabc123-ff01cc7e

The c7 prefix scopes identifiers to this adapter within the Remita merchant account and helps prevent collisions with other integrations using the same merchant credentials.

Remita Status Codes

Remita code

Internal status

What the adapter does

00, 01, 025

success

Record payment in Commerce7

02

pending

Return 200; wait for retry

021

processing

Return 200; wait for retry

07, 068, 069, 062, 063

failed

Mark as processed and return failure

Anything else

unknown

Log a warning and return an error

WebhookResult

WebhookProcessor::process() returns a plain array. WebhookResult provides a typed wrapper around that result:

use Remita\Commerce7\Webhook\WebhookResult;

$raw = $processor->process($rawBody, $headers);
$result = WebhookResult::fromArray($raw);

if ($result->isSuccess()) {
    $orderId = $result->getData()['orderId'];
}

if ($result->isError()) {
    error_log($result->getMessage());
}

http_response_code($result->getHttpCode());
echo json_encode($result->toArray());

Logging

Logs are written to logs/ as daily JSON-line files:

logs/commerce7-YYYY-MM-DD.log
logs/webhook-YYYY-MM-DD.log
logs/initiate-YYYY-MM-DD.log

Example:

{"ts":"2026-01-01T12:00:00+00:00","level":"info","message":"Payment recorded in Commerce7","context":{"orderId":"ord_abc123","amountCents":150000}}

For development troubleshooting, set:

'log_level' => 'debug'

This enables more detailed request/response tracing.

Testability

WebhookProcessor accepts an optional $remitaQueryFn callable as its final constructor argument.

This allows tests to simulate Remita responses without making real HTTP requests:

$processor = new WebhookProcessor(
    c7Client:      $stubC7,
    idempotency:   $idempotency,
    logger:        $logger,
    webhookSecret: 'secret',
    remitaBaseUrl: 'https://...',
    // ...
    remitaQueryFn: fn(string $ref) => [
        'status' => '00',
        'rrr' => '23000001'
    ],
);

🚢 Deployment

1. Create the required directories

mkdir -p data/idempotency data/order_map logs

2. Give the web server access

Example:

chmod 755 data data/idempotency data/order_map logs
chown www-data:www-data data data/idempotency data/order_map logs

Use permissions appropriate for your server environment.

3. Protect sensitive files

The data/ and logs/ directories should never be publicly accessible.

Your config.php must also be blocked from direct web access.

Apache

Options -Indexes
php_flag display_errors Off

<FilesMatch "config\.php$">
    Require all denied
</FilesMatch>

<DirectoryMatch "^.*/data/">
    Require all denied
</DirectoryMatch>

Nginx

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

🧩 Autoloading

The adapter can work without Composer.

The test bootstrap in tests/bootstrap.php registers a PSR-4 autoloader:

Remita\Commerce7\*         → src/*.php
Remita\Commerce7\Support\ → src/Support/*.php
Remita\Commerce7\Webhook\ → src/Webhook/*.php

For production, you can either load the required classes manually or use Composer.

Composer configuration:

{
    "autoload": {
        "psr-4": {
            "Remita\\Commerce7\\": "src/"
        }
    }
}

Then run:

composer dump-autoload

🔐 Security

Treat the adapter as a payment integration and protect it accordingly.

Never commit config.php to Git.

Keep API keys and webhook secrets outside source control.

Never expose data/ or logs/ through the public web server.

Verify webhook signatures with hash_equals().

Keep TLS certificate verification enabled.

Keep TLS hostname verification enabled.

Do not log payment card data, API keys or complete webhook bodies.

Keep idempotency enabled to prevent duplicate payment recording.

Use HTTPS in production.

Restrict filesystem permissions to the application/web-server user where possible.
