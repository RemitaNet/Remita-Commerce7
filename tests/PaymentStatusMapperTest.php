<?php

declare(strict_types=1);

namespace Remita\Commerce7\Tests;

use Remita\Commerce7\Support\PaymentStatusMapper;

final class PaymentStatusMapperTest extends TestCase
{
    public function run(): void
    {
        $this->testSuccessCodes();
        $this->testPendingCode();
        $this->testProcessingCode();
        $this->testFailureCodes();
        $this->testUnknownCode();
        $this->testIsRecordable();
        $this->testIsRetryable();
        $this->testIsFailure();
        $this->testLabels();
    }

    private function testSuccessCodes(): void
    {
        foreach (['00', '01', '025'] as $code) {
            $this->assertSame(
                PaymentStatusMapper::STATUS_SUCCESS,
                PaymentStatusMapper::fromRemitaCode($code),
                "Success code {$code}"
            );
        }
    }

    private function testPendingCode(): void
    {
        $this->assertSame(
            PaymentStatusMapper::STATUS_PENDING,
            PaymentStatusMapper::fromRemitaCode('02'),
            'Pending code 02'
        );
    }

    private function testProcessingCode(): void
    {
        $this->assertSame(
            PaymentStatusMapper::STATUS_PROCESSING,
            PaymentStatusMapper::fromRemitaCode('021'),
            'Processing code 021'
        );
    }

    private function testFailureCodes(): void
    {
        foreach (['07', '068', '069', '062', '063'] as $code) {
            $this->assertSame(
                PaymentStatusMapper::STATUS_FAILED,
                PaymentStatusMapper::fromRemitaCode($code),
                "Failure code {$code}"
            );
        }
    }

    private function testUnknownCode(): void
    {
        $this->assertSame(
            PaymentStatusMapper::STATUS_UNKNOWN,
            PaymentStatusMapper::fromRemitaCode('999'),
            'Unknown code 999'
        );
    }

    private function testIsRecordable(): void
    {
        $this->assertTrue(PaymentStatusMapper::isRecordable(PaymentStatusMapper::STATUS_SUCCESS), 'success is recordable');
        $this->assertFalse(PaymentStatusMapper::isRecordable(PaymentStatusMapper::STATUS_PENDING), 'pending not recordable');
        $this->assertFalse(PaymentStatusMapper::isRecordable(PaymentStatusMapper::STATUS_FAILED), 'failed not recordable');
    }

    private function testIsRetryable(): void
    {
        $this->assertTrue(PaymentStatusMapper::isRetryable(PaymentStatusMapper::STATUS_PENDING), 'pending is retryable');
        $this->assertTrue(PaymentStatusMapper::isRetryable(PaymentStatusMapper::STATUS_PROCESSING), 'processing is retryable');
        $this->assertFalse(PaymentStatusMapper::isRetryable(PaymentStatusMapper::STATUS_SUCCESS), 'success not retryable');
        $this->assertFalse(PaymentStatusMapper::isRetryable(PaymentStatusMapper::STATUS_FAILED), 'failed not retryable');
    }

    private function testIsFailure(): void
    {
        $this->assertTrue(PaymentStatusMapper::isFailure(PaymentStatusMapper::STATUS_FAILED), 'failed is failure');
        $this->assertFalse(PaymentStatusMapper::isFailure(PaymentStatusMapper::STATUS_SUCCESS), 'success not failure');
        $this->assertFalse(PaymentStatusMapper::isFailure(PaymentStatusMapper::STATUS_PENDING), 'pending not failure');
    }

    private function testLabels(): void
    {
        $this->assertStringContains('success', PaymentStatusMapper::label(PaymentStatusMapper::STATUS_SUCCESS));
        $this->assertStringContains('pending', PaymentStatusMapper::label(PaymentStatusMapper::STATUS_PENDING));
        $this->assertStringContains('processing', PaymentStatusMapper::label(PaymentStatusMapper::STATUS_PROCESSING));
        $this->assertStringContains('failed', PaymentStatusMapper::label(PaymentStatusMapper::STATUS_FAILED));
        $this->assertStringContains('Unknown', PaymentStatusMapper::label(PaymentStatusMapper::STATUS_UNKNOWN));
    }
}
