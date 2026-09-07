<?php

declare(strict_types=1);

namespace Remita\Commerce7;

/**
 * Lightweight HTTP client for the Commerce7 REST API v1.
 *
 * Authentication: HTTP Basic — base64(tenantId:apiKey)
 * Tenant routing: every request carries the C7-Tenant header.
 *
 * Error handling:
 *   - cURL transport errors throw \RuntimeException immediately.
 *   - Non-2xx HTTP responses throw \RuntimeException with the status code
 *     and decoded body message included.
 *
 * @link https://docs.commerce7.com/
 */
final class Commerce7Client implements Commerce7ClientInterface
{
    private const BASE_URL      = 'https://api.commerce7.com/v1';
    private const TIMEOUT_SEC   = 30;
    private const CONNECT_SEC   = 10;

    private string $tenantId;
    private string $authorization; // base64(tenantId:apiKey)
    private string $baseUrl;

    public function __construct(
        string $tenantId,
        string $apiKey,
        string $baseUrl = self::BASE_URL
    ) {
        if ($tenantId === '') {
            throw new \InvalidArgumentException('Commerce7 tenant ID must not be empty.');
        }
        if ($apiKey === '') {
            throw new \InvalidArgumentException('Commerce7 API key must not be empty.');
        }

        $this->tenantId      = $tenantId;
        $this->authorization = 'Basic ' . base64_encode($tenantId . ':' . $apiKey);
        $this->baseUrl       = rtrim($baseUrl, '/');
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Retrieve a single order from Commerce7.
     *
     * @param  string               $orderId  Commerce7 order ID.
     * @return array<string, mixed>           Decoded order object.
     *
     * @throws \RuntimeException              On transport error or non-2xx response.
     */
    public function getOrder(string $orderId): array
    {
        return $this->request('GET', '/order/' . rawurlencode($orderId));
    }

    /**
     * Record a completed payment against a Commerce7 order.
     *
     * @param  string               $orderId           Commerce7 order ID.
     * @param  int                  $amountCents       Payment amount in cents.
     * @param  string               $paymentIdentifier Unique c7-… identifier.
     * @return array<string, mixed>                    Decoded payment record.
     *
     * @throws \RuntimeException                       On transport error or non-2xx response.
     */
    public function recordPayment(string $orderId, int $amountCents, string $paymentIdentifier): array
    {
        $body = [
            'orderId'     => $orderId,
            'amount'      => $amountCents,
            'methodType'  => 'Custom',
            'description' => 'Remita Checkout',
            'externalId'  => $paymentIdentifier,
        ];

        return $this->request('POST', '/payment', $body);
    }

    // -------------------------------------------------------------------------
    // HTTP transport
    // -------------------------------------------------------------------------

    /**
     * Execute a cURL request and return the decoded JSON body.
     *
     * @param  array<string, mixed>|null $body
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $url = $this->baseUrl . $path;

        $headers = [
            'Authorization: ' . $this->authorization,
            'C7-Tenant: '     . $this->tenantId,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SEC,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_SEC,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FAILONERROR    => false, // We handle HTTP errors ourselves.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
            }
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        $raw      = curl_exec($ch);
        $errno    = curl_errno($ch);
        $errMsg   = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        // --- Transport error ------------------------------------------------
        if ($errno !== 0) {
            throw new \RuntimeException(
                sprintf(
                    'Commerce7 cURL error (%d) on %s %s: %s',
                    $errno,
                    $method,
                    $url,
                    $errMsg
                )
            );
        }

        // --- Parse response body --------------------------------------------
        $decoded = [];
        if (is_string($raw) && $raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new \RuntimeException(
                    sprintf(
                        'Commerce7 returned non-JSON response (HTTP %d) for %s %s: %s',
                        $httpCode,
                        $method,
                        $url,
                        substr($raw, 0, 200)
                    ),
                    0,
                    $e
                );
            }
        }

        // --- HTTP error -----------------------------------------------------
        if ($httpCode < 200 || $httpCode >= 300) {
            $apiMessage = $decoded['message'] ?? $decoded['error'] ?? 'No message';
            throw new \RuntimeException(
                sprintf(
                    'Commerce7 API error HTTP %d on %s %s: %s',
                    $httpCode,
                    $method,
                    $url,
                    $apiMessage
                ),
                $httpCode
            );
        }

        return is_array($decoded) ? $decoded : [];
    }
}
