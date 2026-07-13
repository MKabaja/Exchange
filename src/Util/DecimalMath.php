<?php

declare(strict_types=1);

namespace App\Util;

use RoundingMode;

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
