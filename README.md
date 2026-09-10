# Remita Commerce7

A Remita Checkout and Webhook adapter for Commerce7 winery order flows.

---

## Status

Implemented as a source adapter.

## Integration Details

| Field | Value |
|---|---|
| **Integration mode** | Webhook + Redirect |
| **SDK** | PHP SDK |
| **Platform** | Commerce7 |

## ✨ What It Does

- 🏷️ Builds a Commerce7-scoped `paymentIdentifier`
- 🔗 Initiates Remita Checkout redirect payments through the PHP SDK
- 🔔 Receives and verifies Remita payment webhooks with HMAC-SHA256
- ✅ Confirms payment status with Remita via authoritative status query
- 💾 Records successful payments back in Commerce7
- 🔁 Prevents duplicate payments when webhooks are retried
- 📊 Keeps structured JSON logs for troubleshooting

## 📁 Repository Shape

```text
commerce7/
├── src/
│   ├── Commerce7Adapter.php
│   ├── Commerce7Client.php
│   ├── Commerce7ClientInterface.php
│   ├── Webhook/
│   │   ├── WebhookProcessor.php
│   │   └── WebhookResult.php
│   └── Support/
│       ├── AmountNormalizer.php
│       ├── IdempotencyStore.php
│       ├── LoggerService.php
│       ├── OrderMapper.php
│       ├── PaymentIdentifier.php
│       └── PaymentStatusMapper.php
├── initiate.php
├── webhook.php
├── config.php.example
├── data/
│   ├── idempotency/
│   └── order_map/
├── logs/
└── tests/
```

## 🚀 How It Works

```text
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
```

A redirect back from a payment page is not enough to prove that money was successfully paid.

The adapter therefore:

- Receives the payment notification from Remita.
- Verifies that the notification really came from Remita.
- Checks whether the payment was already processed.
- Asks Remita for the authoritative payment status.
- Records the payment in Commerce7 only when appropriate.

This makes the integration safer and retry-friendly.

## 📋 What You Need

| Requirement | Why you need it |
|---|---|
| PHP 8.1+ | Runs the adapter |
| PHP ext-curl | Communicates with Commerce7 and Remita |
| Commerce7 account | Provides the customer order |
| Commerce7 API key | Lets the adapter read orders and write payments |
| Commerce7 Tenant ID | Identifies your winery |
| Remita merchant account | Receives customer payments |
| Remita Payment Engine access | Creates payment checkouts |
| Remita credentials | Authenticates API requests |
| Public HTTPS URL | Receives Remita webhooks |
| Write access to `data/` and `logs/` | Stores mappings, idempotency records and logs |

## 📦 Installation

```bash
git clone <repository-url>
cd commerce7-remita
cp config.php.example config.php
mkdir -p data/idempotency data/order_map logs
```

Verify PHP and cURL:

```bash
php -v
php -m | grep curl
```

## ⚙️ Configuration

### Commerce7

Create an API key with these permissions:

| Resource | Permission |
|---|---|
| Orders | Read |
| Payments | Write |

```php
'commerce7' => [
    'tenant_id' => 'my-winery',
    'api_key'   => 'c7sk_live_...',
],
```

### Remita

Generate a webhook secret:

```bash
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
```

Point your Remita webhook URL at:

```text
https://your-domain.com/webhook.php
```

```php
'remita' => [
    'merchant_id'     => '123456789',
    'api_key'         => 'remita_live_...',
    'service_type_id' => '4430731',
    'webhook_secret'  => 'your-random-webhook-secret',
    'base_url'        => 'https://login.remita.net/remita/exapp/api/v1/send/api',
    'checkout_url'    => 'https://login.remita.net/remita/ecomm/init.reg',
    'return_url'      => 'https://your-domain.com/payment/return',
],
```

## 🟢 First Checkout

```bash
curl -X POST https://your-domain.com/initiate.php \
  -H "Content-Type: application/json" \
  -d '{"orderId": "ord_abc123"}'
```

The adapter retrieves the order from Commerce7, builds the Remita payment request, creates a payment identifier such as `c7-ord_abc123-a3f9b210`, saves the order-to-payment mapping, and redirects the customer to Remita with an HTTP 302.

## 🔔 Webhooks

Remita POSTs to `/webhook.php`. A typical payload:

```json
{
  "paymentReference": "c7-ordabc123-a1b2c3d4",
  "rrr": "230007654321",
  "status": "00",
  "amount": "1500.00",
  "transactionTime": "2026-01-01 12:00:00"
}
```

With header `X-Remita-Signature: <hmac-sha256-hex>`.

### Webhook responses

| HTTP | Response | Meaning |
|---|---|---|
| 200 | `{"status":"ok"}` | Payment processed successfully |
| 200 | `{"status":"pending"}` | Payment not confirmed; retry expected |
| 200 | `{"status":"failed"}` | Terminal failure state |
| 200 | `{"status":"ok","message":"Already processed."}` | Duplicate webhook; ignored |
| 401 | `{"status":"error"}` | Invalid or missing signature |
| 404 | `{"status":"error"}` | No known order mapping |
| 502 | `{"status":"error"}` | Remita or Commerce7 unreachable |
| 500 | `{"status":"error"}` | Unexpected internal error |

### Remita status codes

| Remita code | Internal status | Action |
|---|---|---|
| 00, 01, 025 | success | Record payment in Commerce7 |
| 02 | pending | Return 200; wait for retry |
| 021 | processing | Return 200; wait for retry |
| 07, 068, 069, 062, 063 | failed | Mark as processed, return failure |
| Anything else | unknown | Log warning, return error |

## 🚀 Running Tests

```bash
php tests/run.php
```

Expected:

```text
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
```

Filter to one group:

```bash
php tests/run.php --filter WebhookProcessor
```

## 📦 Packaging

```bash
bash scripts/package-commerce7.sh
```

## 📝 Notes

- This is a hosted reference adapter, not a marketplace-ready Commerce7 app.
- The webhook handler verifies signatures and queries Remita before recording any payment.
- Successful and terminally failed payments are stored using idempotency records so retries do not record the payment twice.
- Final payment recording logic should be wired by the host Commerce7 connector.

## 🔐 Security

- Never commit `config.php` to Git.
- Keep API keys and webhook secrets outside source control.
- Never expose `data/` or `logs/` through the public web server.
- Verify webhook signatures with `hash_equals()`.
- Keep TLS certificate and hostname verification enabled.
- Do not log card data, API keys, or full webhook bodies.
- Keep idempotency enabled.
- Use HTTPS in production.

## 📄 License

See the repository `LICENSE` file for details.
