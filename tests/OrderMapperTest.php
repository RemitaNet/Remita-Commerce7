<?php

declare(strict_types=1);

namespace Remita\Commerce7\Tests;

use Remita\Commerce7\Support\OrderMapper;

final class OrderMapperTest extends TestCase
{
    private function sampleOrder(): array
    {
        return [
            'id'    => 'ord_abc123',
            'total' => 150000,
            'customer' => [
                'email'     => 'jane@example.com',
                'firstName' => 'Jane',
                'lastName'  => 'Doe',
                'phone'     => '+2348012345678',
            ],
        ];
    }

    public function run(): void
    {
        $this->testToRemitaPayloadKeys();
        $this->testToRemitaPayloadAmount();
        $this->testToRemitaPayloadPayerName();
        $this->testToRemitaPayloadHash();
        $this->testToRemitaPayloadMissingOrderIdThrows();
        $this->testToRemitaPayloadMissingCustomerEmailThrows();
        $this->testToCheckoutQueryString();
        $this->testToLogSummary();
        $this->testToLogSummaryNoSensitiveData();
    }

    private function testToRemitaPayloadKeys(): void
    {
        $payload = OrderMapper::toRemitaPayload(
            order:             $this->sampleOrder(),
            paymentIdentifier: 'c7-ordabc123-a1b2c3d4',
            merchantId:        'merchant001',
            serviceTypeId:     'svc001',
            apiKey:            'apikey001',
            returnUrl:         'https://example.com/return'
        );

        foreach (['merchantId', 'serviceTypeId', 'orderId', 'totalAmount', 'payerEmail', 'payerName', 'payerPhone', 'responseurl', 'hash'] as $key) {
            $this->assertArrayHasKey($key, $payload, "Payload has key {$key}");
        }
    }

    private function testToRemitaPayloadAmount(): void
    {
        $payload = OrderMapper::toRemitaPayload(
            order:             $this->sampleOrder(),
            paymentIdentifier: 'c7-ordabc123-a1b2c3d4',
            merchantId:        'merchant001',
            serviceTypeId:     'svc001',
            apiKey:            'apikey001',
            returnUrl:         'https://example.com/return'
        );

        $this->assertSame('1500.00', $payload['totalAmount'], 'totalAmount is 1500.00');
    }

    private function testToRemitaPayloadPayerName(): void
    {
        $payload = OrderMapper::toRemitaPayload(
            order:             $this->sampleOrder(),
            paymentIdentifier: 'c7-ordabc123-a1b2c3d4',
            merchantId:        'merchant001',
            serviceTypeId:     'svc001',
            apiKey:            'apikey001',
            returnUrl:         'https://example.com/return'
        );

        $this->assertSame('Jane Doe', $payload['payerName'], 'payerName is full name');
    }

    private function testToRemitaPayloadHash(): void
    {
        $merchantId    = 'merchant001';
        $serviceTypeId = 'svc001';
        $identifier    = 'c7-ordabc123-a1b2c3d4';
        $amount        = '1500.00';
        $apiKey        = 'apikey001';

        $expectedHash = hash('sha512', $merchantId . $serviceTypeId . $identifier . $amount . $apiKey);

        $payload = OrderMapper::toRemitaPayload(
            order:             $this->sampleOrder(),
            paymentIdentifier: $identifier,
            merchantId:        $merchantId,
            serviceTypeId:     $serviceTypeId,
            apiKey:            $apiKey,
            returnUrl:         'https://example.com/return'
        );

        $this->assertSame($expectedHash, $payload['hash'], 'Hash matches SHA-512 of concatenated fields');
    }

    private function testToRemitaPayloadMissingOrderIdThrows(): void
    {
        $order = $this->sampleOrder();
        unset($order['id']);

        $this->assertThrows(
            \InvalidArgumentException::class,
            fn () => OrderMapper::toRemitaPayload($order, 'c7-x-00000000', 'm', 's', 'k', 'https://example.com'),
            'Missing id throws'
        );
    }

    private function testToRemitaPayloadMissingCustomerEmailThrows(): void
    {
        $order = $this->sampleOrder();
        unset($order['customer']['email']);

        $this->assertThrows(
            \InvalidArgumentException::class,
            fn () => OrderMapper::toRemitaPayload($order, 'c7-x-00000000', 'm', 's', 'k', 'https://example.com'),
            'Missing customer email throws'
        );
    }

    private function testToCheckoutQueryString(): void
    {
        $payload = ['merchantId' => 'abc', 'totalAmount' => '1500.00'];
        $qs      = OrderMapper::toCheckoutQueryString($payload);

        $this->assertStringContains('merchantId=abc', $qs, 'Query string contains merchantId');
        $this->assertStringContains('totalAmount=1500.00', $qs, 'Query string contains totalAmount');
    }

    private function testToLogSummary(): void
    {
        $summary = OrderMapper::toLogSummary($this->sampleOrder());

        $this->assertArrayHasKey('orderId', $summary, 'Summary has orderId');
        $this->assertArrayHasKey('amountCents', $summary, 'Summary has amountCents');
        $this->assertArrayHasKey('payerEmail', $summary, 'Summary has payerEmail');
    }

    private function testToLogSummaryNoSensitiveData(): void
    {
        $summary = OrderMapper::toLogSummary($this->sampleOrder());
        $keys    = array_keys($summary);

        // phone should NOT be in summary
        $this->assertFalse(in_array('payerPhone', $keys, true), 'Log summary omits payerPhone');
        $this->assertFalse(in_array('payerName', $keys, true), 'Log summary omits payerName');
    }
}
