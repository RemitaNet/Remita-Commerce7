💳 Commerce7 Orders + Remita Payments
A simple, production-ready PHP adapter that connects your Commerce7 winery store to Remita.

No more manual payment reconciliation. Your customers check out through Remita, and successful payments are recorded back in Commerce7 automatically.

In one sentence: Commerce7 creates the order → Remita collects the payment → this adapter connects the two.

🎯 What This Does
This adapter handles the tricky parts of connecting Commerce7 to Remita so you don't have to:

✅ Creates a Remita checkout from a Commerce7 order

✅ Sends customers to Remita to complete payment

✅ Receives payment notifications from Remita

✅ Confirms payment status with Remita before trusting it

✅ Records successful payments back in Commerce7

✅ Prevents duplicate payments when notifications are retried

✅ Keeps logs so you can troubleshoot issues

👥 Who It's For
This adapter is built for:

Wineries using Commerce7 who want to accept Remita payments

Developers integrating Commerce7 with Remita Payment Engine

Store owners who need reliable, automated payment recording

🚀 How It Works
The adapter has two simple jobs: start a payment and confirm a payment.

The Flow
text
Customer places order in Commerce7
           │
           ▼
    initiate.php starts the payment
           │
           ▼
    Customer pays on Remita
           │
           ▼
    webhook.php confirms the payment
           │
           ▼
    Commerce7 records the payment
Why Two Steps?
Here's the important part: a redirect back from a payment page doesn't prove the payment succeeded.

So instead of trusting the redirect, the adapter:

📩 Receives the payment notification from Remita

🔐 Verifies it really came from Remita

🔍 Checks if it was already processed

📞 Asks Remita for the authoritative payment status

✍️ Records the payment in Commerce7 only when confirmed

This makes the integration safe and retry-friendly.

✨ What You Get
For Your Customers
Feature	Benefit
💳 Familiar Remita checkout	No learning curve
🔄 Reliable confirmation	They know payment went through
⚡ Smooth return flow	Back to your store quickly
For Your Winery
Feature	Benefit
🧾 Commerce7 stays the source of truth	No data sync headaches
💰 Payments recorded correctly	Accurate accounting
🔁 No duplicate payments	Webhook retries are safe
🗂️ Local order mappings	Easy to audit
📊 Structured logs	Fast troubleshooting
For Developers
Feature	Benefit
🔐 HMAC-SHA256 verification	Secure webhooks
🛡️ Constant-time comparison	No timing attacks
🔒 TLS verification	Secure connections
🧪 Zero-dependency tests	No Composer needed
🧩 Interface-based clients	Easy to test
🔌 Injectable dependencies	Flexible testing
📋 What You'll Need
Before you start, make sure you have:

Requirement	Why You Need It
PHP 8.1+	Runs the adapter
PHP ext-curl	Talks to Commerce7 and Remita
Commerce7 account	Where your orders live
Commerce7 API key	Reads orders, writes payments
Commerce7 Tenant ID	Identifies your winery
Remita merchant account	Where payments land
Remita Payment Engine access	Creates checkouts
Remita credentials	API authentication
Public HTTPS URL	Receives Remita webhooks
Writable data/ and logs/	Stores mappings and logs
💡 Local development: PHP's built-in server works for testing. But you'll need a public HTTPS endpoint when Remita needs to send webhooks to your machine.

📦 Installation
Step 1: Get the Files
bash
git clone <repository-url>
cd commerce7-remita
Or just copy the project files to your hosting directory.

Step 2: Check PHP
bash
php -v
You need PHP 8.1 or newer.

Verify cURL is enabled:

bash
php -m | grep curl
Step 3: Create Your Config
bash
cp config.php.example config.php
Then open config.php and add your credentials (we'll cover this next).

Step 4: Create Writable Folders
bash
mkdir -p data/idempotency data/order_map logs
That's it — the adapter uses these automatically.

⚙️ Configuration
You'll configure two services: Commerce7 and Remita.

🏪 Commerce7 Setup
Create an API Key
Log in to your Commerce7 admin panel

Go to Settings → API Keys

Click Create API Key

Copy the key and store it safely

Find your Tenant ID (the slug, like my-winery)

Set Required Permissions
Resource	Permission
Orders	Read
Payments	Write
Add to Config
php
'commerce7' => [
    'tenant_id' => 'my-winery',
    'api_key'   => 'c7sk_live_...',
],
💚 Remita Setup
Get Your Credentials
From your Remita merchant dashboard:

Credential	What It's For
Merchant ID	Your account identifier
API Key	API authentication
Service Type ID	Payment type
Webhook Secret	Verifying webhook requests
Generate a Webhook Secret
bash
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
Use this same secret when configuring webhook signing in Remita.

Set Your Webhook URL
Point Remita to:

text
https://your-domain.com/webhook.php
Complete the Config
php
'remita' => [
    'merchant_id'     => '123456789',
    'api_key'         => 'remita_live_...',
    'service_type_id' => '4430731',
    'webhook_secret'  => 'your-random-webhook-secret',
    'base_url'        => 'https://login.remita.net/remita/exapp/api/v1/send/api',
    'checkout_url'    => 'https://login.remita.net/remita/ecomm/init.reg',
    'return_url'      => 'https://your-domain.com/payment/return',
],
⚠️ Important: Never commit config.php to Git. Keep production credentials outside version control.

🗂️ Project Structure
text
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
🟢 Your First Checkout
Once configured, the flow is simple.

1. Send a Commerce7 Order ID
bash
curl -X POST https://your-domain.com/initiate.php \
  -H "Content-Type: application/json" \
  -d '{"orderId": "ord_abc123"}'
Form data works too:

bash
curl -X POST https://your-domain.com/initiate.php \
  -d "orderId=ord_abc123"
2. What Happens Next
The adapter:

📥 Retrieves the order from Commerce7

🔧 Builds the Remita payment request

🏷️ Creates a payment identifier like c7-ord_abc123-a3f9b210

💾 Saves the order-to-payment mapping

🔀 Redirects the customer to Remita

You'll get an HTTP 302 redirect — browsers follow it automatically.

3. Connecting to Commerce7
Trigger initiate.php using:

A Commerce7 webhook, or

A custom "Pay with Remita" button right after order creation

🔔 Webhooks
After the customer pays, Remita sends a POST request to:

text
/webhook.php
Example Payload
json
{
  "paymentReference": "c7-ordabc123-a1b2c3d4",
  "rrr": "230007654321",
  "status": "00",
  "amount": "1500.00",
  "transactionTime": "2026-01-01 12:00:00"
}
The request includes a signature header:

text
X-Remita-Signature: <hmac-sha256-hex>
What the Handler Does
text
📩 Remita webhook arrives
           │
           ▼
   🔐 Verify signature
           │
           ▼
   🔍 Already processed?
      │           │
     Yes          No
      │           │
      ▼           ▼
   Return     Find order mapping
   200              │
                    ▼
          📞 Ask Remita for status
                    │
        ┌───────────┼───────────┐
        ▼           ▼           ▼
     Success     Pending     Failed
        │           │           │
        ▼           ▼           ▼
    Record in    Retry      Mark as
    Commerce7               processed
Key point: The webhook is just a notification. The Remita status query provides the authoritative payment state.

Webhook Responses
HTTP	Response	Meaning
200	{"status":"ok"}	Payment processed successfully
200	{"status":"pending"}	Not confirmed yet; retry expected
200	{"status":"failed"}	Terminal failure state
200	{"status":"ok","message":"Already processed."}	Duplicate webhook; safely ignored
401	{"status":"error"}	Invalid or missing signature
404	{"status":"error"}	No known order mapping
502	{"status":"error"}	Remita or Commerce7 unreachable
500	{"status":"error"}	Unexpected internal error
🧪 Testing
No Composer or live API calls needed — the test suite is zero-dependency.

Run All Tests
bash
php tests/run.php
Expected output:

text
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
Run One Test Group
bash
php tests/run.php --filter WebhookProcessor
Or:

bash
php tests/run.php --filter AmountNormalizer
Test the Webhook Locally
Start PHP's built-in server:

bash
php -S localhost:8000
In another terminal, send a signed test webhook:

bash
SECRET="your_webhook_secret"
BODY='{"paymentReference":"c7-test-a1b2c3d4","status":"00","amount":"1500.00","rrr":"230000000001"}'
SIG=$(echo -n "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $2}')

curl -X POST http://localhost:8000/webhook.php \
  -H "Content-Type: application/json" \
  -H "X-Remita-Signature: $SIG" \
  -d "$BODY"
What's Covered
Scenario	Expected Result
Invalid signature	401
Missing signature	401
Missing payment reference	400
Invalid JSON	400
Duplicate payment	200, already processed
Missing order mapping	404
Successful payment	Record payment in Commerce7
Pending payment	200, wait for retry
Processing payment	200, wait for retry
Failed payment	200, mark as processed
Remita unavailable	502
Unknown status	200, error
Commerce7 recording fails	502
Same webhook twice	Recorded only once
🚢 Going Live
1. Create Required Directories
bash
mkdir -p data/idempotency data/order_map logs
2. Give the Web Server Access
bash
chmod 755 data data/idempotency data/order_map logs
chown www-data:www-data data data/idempotency data/order_map logs
Adjust permissions to match your server environment.

3. Protect Sensitive Files
The data/ and logs/ directories should never be publicly accessible. Block config.php too.

Apache
apache
Options -Indexes
php_flag display_errors Off

<FilesMatch "config\.php$">
    Require all denied
</FilesMatch>

<DirectoryMatch "^.*/data/">
    Require all denied
</DirectoryMatch>
Nginx
nginx
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
🔎 Troubleshooting
"The checkout doesn't open"
Check these:

✅ config.php has correct Remita credentials

✅ service_type_id is correct

✅ Remita checkout URL is correct

✅ initiate.php can reach Commerce7 and Remita

✅ logs/ has no API errors

"The webhook returns 401"
A 401 means the signature couldn't be verified.

Check:

✅ webhook_secret matches Remita's configured secret

✅ X-Remita-Signature header is present

✅ Request body is passed unchanged to signature verification

"The webhook returns 404"
The adapter couldn't find the payment reference in data/order_map/.

This means either:

The payment wasn't initiated through this adapter

The order mapping was removed

"Payment succeeded but Commerce7 wasn't updated"
Check:

✅ Remita's authoritative status query response

✅ Commerce7 API credentials

✅ API key has Payments: Write permission

✅ Application logs

✅ Whether Commerce7 was temporarily unavailable

"The same webhook keeps arriving"
That's normal for pending/processing payments.

Successful or terminally failed payments are stored with idempotency records, so repeated deliveries won't record the payment twice.

📚 Technical Details
Payment Identifier Format
Every payment gets an identifier like:

text
c7-{sanitisedOrderId}-{8hexchars}
Examples:

text
c7-ord12345-a3f9b210
c7-ordabc123-ff01cc7e
The c7- prefix scopes identifiers to this adapter, preventing collisions with other integrations using the same Remita merchant credentials.

Remita Status Codes
Remita Code	Internal Status	What Happens
00, 01, 025	success	Record payment in Commerce7
02	pending	Return 200; wait for retry
021	processing	Return 200; wait for retry
07, 068, 069, 062, 063	failed	Mark as processed, return failure
Anything else	unknown	Log warning, return error
WebhookResult
WebhookProcessor::process() returns a plain array. WebhookResult wraps it with typed methods:

php
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
Logs are daily JSON-line files in logs/:

text
logs/commerce7-YYYY-MM-DD.log
logs/webhook-YYYY-MM-DD.log
logs/initiate-YYYY-MM-DD.log
Example entry:

json
{"ts":"2026-01-01T12:00:00+00:00","level":"info","message":"Payment recorded in Commerce7","context":{"orderId":"ord_abc123","amountCents":150000}}
For detailed troubleshooting, enable debug mode:

php
'log_level' => 'debug'
This adds request/response tracing.

Testability
WebhookProcessor accepts an optional $remitaQueryFn callable. This lets tests simulate Remita responses without HTTP calls:

php
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
🧩 Autoloading
The adapter works without Composer.

The test bootstrap (tests/bootstrap.php) registers a PSR-4 autoloader:

text
Remita\Commerce7\*         → src/*.php
Remita\Commerce7\Support\  → src/Support/*.php
Remita\Commerce7\Webhook\  → src/Webhook/*.php
For production, either:

Load required classes manually, or

Use Composer

Composer Config
json
{
    "autoload": {
        "psr-4": {
            "Remita\\Commerce7\\": "src/"
        }
    }
}
Then run:

bash
composer dump-autoload
🔐 Security
Treat this adapter like any payment integration — protect it properly.

Rule	Why
❌ Never commit config.php	Keeps credentials out of Git
🔑 Keep API keys outside source control	Prevents leaks
🚫 Never expose data/ or logs/ publicly	Protects payment data
✅ Verify webhook signatures with hash_equals()	Prevents timing attacks
🔒 Keep TLS certificate verification enabled	Prevents MITM attacks
🔒 Keep TLS hostname verification enabled	Ensures correct server
📝 Don't log card data, API keys, or full webhook bodies	Protects sensitive info
🔁 Keep idempotency enabled	Prevents duplicate payments
🔐 Use HTTPS in production	Encrypts traffic
👤 Restrict filesystem permissions	Limits access
