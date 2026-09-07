<?php

declare(strict_types=1);

namespace Remita\Commerce7\Tests;

use Remita\Commerce7\Support\PaymentIdentifier;

final class PaymentIdentifierTest extends TestCase
{
    public function run(): void
    {
        $this->testGenerateStartsWithPrefix();
        $this->testGenerateContainsOrderId();
        $this->testGenerateIsUniqueEachCall();
        $this->testGenerateRandomPartIsEightHex();
        $this->testIsValidAcceptsWellFormedIdentifier();
        $this->testIsValidRejectsWrongPrefix();
        $this->testIsValidRejectsShortRandom();
        $this->testIsValidRejectsNonHexRandom();
        $this->testIsValidRejectsTooFewParts();
        $this->testExtractOrderId();
        $this->testExtractOrderIdThrowsOnInvalid();
        $this->testGenerateStripsUnsafeChars();
        $this->testGenerateEmptyOrderIdThrows();
    }

    private function testGenerateStartsWithPrefix(): void
    {
        $id = PaymentIdentifier::generate('ord123');
        $this->assertStringStartsWith('c7-', $id, 'Identifier starts with c7-');
    }

    private function testGenerateContainsOrderId(): void
    {
        $id = PaymentIdentifier::generate('ord123');
        $this->assertStringContains('ord123', $id, 'Identifier contains orderId');
    }

    private function testGenerateIsUniqueEachCall(): void
    {
        $a = PaymentIdentifier::generate('ord123');
        $b = PaymentIdentifier::generate('ord123');
        $this->assertFalse($a === $b, 'Two calls produce different identifiers');
    }

    private function testGenerateRandomPartIsEightHex(): void
    {
        $id    = PaymentIdentifier::generate('ord999');
        $parts = explode('-', $id, 3);
        $this->assertSame(3, count($parts), 'Identifier has 3 parts');
        $this->assertTrue(
            (bool) preg_match('/^[0-9a-f]{8}$/', $parts[2]),
            'Random part is 8 hex chars'
        );
    }

    private function testIsValidAcceptsWellFormedIdentifier(): void
    {
        $id = PaymentIdentifier::generate('ord001');
        $this->assertTrue(PaymentIdentifier::isValid($id), 'Generated identifier is valid');
    }

    private function testIsValidRejectsWrongPrefix(): void
    {
        $this->assertFalse(PaymentIdentifier::isValid('xx-ord123-a1b2c3d4'), 'Wrong prefix is invalid');
    }

    private function testIsValidRejectsShortRandom(): void
    {
        $this->assertFalse(PaymentIdentifier::isValid('c7-ord123-a1b2c3'), 'Short random part is invalid');
    }

    private function testIsValidRejectsNonHexRandom(): void
    {
        $this->assertFalse(PaymentIdentifier::isValid('c7-ord123-ZZZZZZZZ'), 'Non-hex random part is invalid');
    }

    private function testIsValidRejectsTooFewParts(): void
    {
        $this->assertFalse(PaymentIdentifier::isValid('c7-ord123'), 'Two-part identifier is invalid');
        $this->assertFalse(PaymentIdentifier::isValid('c7'), 'One-part identifier is invalid');
    }

    private function testExtractOrderId(): void
    {
        $id      = PaymentIdentifier::generate('ord456');
        $orderId = PaymentIdentifier::extractOrderId($id);
        $this->assertSame('ord456', $orderId, 'extractOrderId returns correct ID');
    }

    private function testExtractOrderIdThrowsOnInvalid(): void
    {
        $this->assertThrows(
            \InvalidArgumentException::class,
            fn () => PaymentIdentifier::extractOrderId('notvalid'),
            'extractOrderId throws on invalid identifier'
        );
    }

    private function testGenerateStripsUnsafeChars(): void
    {
        // Hyphens in order ID should be stripped; the result should still be valid.
        $id = PaymentIdentifier::generate('ord-456');
        $this->assertTrue(PaymentIdentifier::isValid($id), 'Identifier with sanitised orderId is valid');
        $this->assertStringContains('ord456', $id, 'Hyphens stripped from orderId');
    }

    private function testGenerateEmptyOrderIdThrows(): void
    {
        $this->assertThrows(
            \InvalidArgumentException::class,
            fn () => PaymentIdentifier::generate('---'),
            'All-unsafe orderId throws InvalidArgumentException'
        );
    }
}
