<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\Currency;
use App\Util\DecimalMath;

class SpreadService
{
    private const array LIQUIDITY_SCORE = [
        Currency::USD->value => '1.00',
        Currency::EUR->value => '0.95',
        Currency::GBP->value => '0.85',
        Currency::CHF->value => '0.80',
        Currency::JPY->value => '0.75',
        Currency::PLN->value => '0.55',
        Currency::HUF->value => '0.40',
    ];

    private const string BASE_SPREAD_PERCENT = '0.5';

    private const int SPREAD_SCALE = 2;

    public function calculateSpread(
        string $price,
        Currency $fromCurrency,
        Currency $toCurrency,
    ): string {
        if ($fromCurrency === $toCurrency) {
            return '0.00';
        }

        $fromLiquidity = self::LIQUIDITY_SCORE[$fromCurrency->value];
        $toLiquidity = self::LIQUIDITY_SCORE[$toCurrency->value];

        $pairLiquidity = DecimalMath::divide(
            DecimalMath::add($fromLiquidity, $toLiquidity, DecimalMath::CALC_SCALE),
            '2',
        );
        $spreadPercent = DecimalMath::divide(self::BASE_SPREAD_PERCENT, $pairLiquidity);
        $spreadFraction = DecimalMath::divide($spreadPercent, '100');
        $spread = DecimalMath::multiply($price, $spreadFraction);

        return DecimalMath::round($spread, self::SPREAD_SCALE);
    }
}
