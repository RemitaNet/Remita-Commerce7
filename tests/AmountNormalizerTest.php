<?php

declare(strict_types=1);

namespace Remita\Commerce7\Tests;

use Remita\Commerce7\Support\AmountNormalizer;

final class AmountNormalizerTest extends TestCase
{
    public function run(): void
    {
        $this->testCentsToMajorBasic();
        $this->testCentsToMajorZero();
        $this->testCentsToMajorLargeAmount();
        $this->testCentsToMajorCustomScale();
        $this->testCentsToMajorNegativeThrows();
        $this->testMajorToCentsString();
        $this->testMajorToCentsFloat();
        $this->testMajorToCentsZero();
        $this->testMajorToCentsNegativeThrows();
        $this->testMajorToCentsNonNumericThrows();
        $this->testAssertMatchExact();
        $this->testAssertMatchWithinTolerance();
        $this->testAssertMatchOutsideTolerance();
    }

    private function testCentsToMajorBasic(): void
    {
        $this->assertSame('1500.00', AmountNormalizer::centsToMajor(150000), 'centsToMajor 150000');
    }

    private function testCentsToMajorZero(): void
    {
        $this->assertSame('0.00', AmountNormalizer::centsToMajor(0), 'centsToMajor 0');
    }

    private function testCentsToMajorLargeAmount(): void
    {
        $this->assertSame('10000.00', AmountNormalizer::centsToMajor(1000000), 'centsToMajor 1000000');
    }

    private function testCentsToMajorCustomScale(): void
    {
        $this->assertSame('1500.000', AmountNormalizer::centsToMajor(150000, 3), 'centsToMajor scale=3');
    }

    private function testCentsToMajorNegativeThrows(): void
    {
        $this->assertThrows(
            \InvalidArgumentException::class,
            fn () => AmountNormalizer::centsToMajor(-1),
            'centsToMajor negative throws'
        );
    }

    private function testMajorToCentsString(): void
    {
        $this->assertSame(150000, AmountNormalizer::majorToCents('1500.00'), 'majorToCents string');
    }

    private function testMajorToCentsFloat(): void
    {
        $this->assertSame(150000, AmountNormalizer::majorToCents(1500.0), 'majorToCents float');
    }

    private function testMajorToCentsZero(): void
    {
        $this->assertSame(0, AmountNormalizer::majorToCents('0.00'), 'majorToCents zero');
    }

    private function testMajorToCentsNegativeThrows(): void
    {
        $this->assertThrows(
            \InvalidArgumentException::class,
            fn () => AmountNormalizer::majorToCents('-1.00'),
            'majorToCents negative throws'
        );
    }

    private function testMajorToCentsNonNumericThrows(): void
    {
        $this->assertThrows(
            \InvalidArgumentException::class,
            fn () => AmountNormalizer::majorToCents('abc'),
            'majorToCents non-numeric throws'
        );
    }

    private function testAssertMatchExact(): void
    {
        // Should not throw
        AmountNormalizer::assertMatch(150000, 150000);
        $this->assertTrue(true, 'assertMatch exact — no exception');
    }

    private function testAssertMatchWithinTolerance(): void
    {
        AmountNormalizer::assertMatch(150001, 150000, 5);
        $this->assertTrue(true, 'assertMatch within tolerance — no exception');
    }

    private function testAssertMatchOutsideTolerance(): void
    {
        $this->assertThrows(
            \UnexpectedValueException::class,
            fn () => AmountNormalizer::assertMatch(150100, 150000, 50),
            'assertMatch outside tolerance throws'
        );
    }
}
