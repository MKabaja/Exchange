<?php

declare(strict_types=1);

namespace App\Util;

use RoundingMode;

/**
 * Fixed-point decimal arithmetic for monetary values.
 *
 * This is the single place in the codebase allowed to call the native `bc*` functions.
 * All amounts are passed in and returned as decimal strings — never as floats — so that
 * money never goes through IEEE-754 rounding.
 *
 * Callers pick a scale through the constants to express intent: intermediate steps run at
 * `CALC_SCALE` to avoid losing precision, and only the final result is rounded to the
 * domain scale (`AMOUNT_SCALE` for amounts — matching the DB `DECIMAL(15,4)` — and
 * `RATE_SCALE` for exchange rates). `divide()` throws `\DivisionByZeroError` on a zero divisor.
 */
final class DecimalMath
{
    public const int AMOUNT_SCALE = 4;
    public const int RATE_SCALE = 6;
    public const int CALC_SCALE = 16;

    private function __construct()
    {
    }

    public static function add(string $left, string $right, int $scale = self::AMOUNT_SCALE): string
    {
        return bcadd($left, $right, $scale);
    }

    public static function subtract(string $left, string $right, int $scale = self::AMOUNT_SCALE): string
    {
        return bcsub($left, $right, $scale);
    }

    public static function multiply(string $left, string $right, int $scale = self::CALC_SCALE): string
    {
        return bcmul($left, $right, $scale);
    }

    public static function divide(string $left, string $right, int $scale = self::CALC_SCALE): string
    {
        return bcdiv($left, $right, $scale);
    }

    public static function compare(string $left, string $right, int $scale = self::AMOUNT_SCALE): int
    {
        return bccomp($left, $right, $scale);
    }

    public static function round(string $number, int $scale = self::AMOUNT_SCALE): string
    {
        return bcround($number, $scale, RoundingMode::HalfAwayFromZero);
    }
}
