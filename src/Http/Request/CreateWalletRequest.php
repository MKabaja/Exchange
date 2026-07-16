<?php

declare(strict_types=1);

namespace App\Http\Request;

use App\Enum\Currency;

final readonly class CreateWalletRequest
{
    public function __construct(public Currency $currency)
    {
    }
}
