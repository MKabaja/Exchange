<?php

declare(strict_types=1);

namespace App\Tests\Util;

use App\Util\DecimalMath;
use DivisionByZeroError;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecimalMathTest extends TestCase
{
    public function testAddsDecimalNumbersWithoutFloatingPointError(): void
    {
        self::assertSame('0.3000', DecimalMath::add('0.1', '0.2'));
    }

    public function testSubtractsDecimalNumbersWithoutFloatingPointError(): void
    {
        self::assertSame('0.2000', DecimalMath::subtract('0.3', '0.1'));
    }

    public function testMultipliesAmountByRateWithCalculationScale(): void
    {
        self::assertSame(
            '523.3206056300000000',
            DecimalMath::multiply('123.4567', '4.2389'),
        );
    }

    public function testDividesWithCalculationScale(): void
    {
        self::assertSame('0.2500000000000000', DecimalMath::divide('1', '4'));
    }

    public function testComparesEqualDecimalNumbers(): void
    {
        self::assertSame(0, DecimalMath::compare('1.0000', '1'));
    }

    #[DataProvider('halfAwayFromZeroProvider')]
    public function testRoundsHalfAwayFromZero(string $number, string $expected): void
    {
        self::assertSame($expected, DecimalMath::round($number));
    }

    public static function halfAwayFromZeroProvider(): Generator
    {
        yield 'positive midpoint' => ['1.23455', '1.2346'];
        yield 'negative midpoint' => ['-1.23455', '-1.2346'];
    }

    public function testThrowsWhenDividingByZero(): void
    {
        $this->expectException(DivisionByZeroError::class);

        DecimalMath::divide('1', '0');
    }
}
