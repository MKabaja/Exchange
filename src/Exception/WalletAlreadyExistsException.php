<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\Currency;

final class WalletAlreadyExistsException extends ApiException
{
    public function __construct(int $userId, Currency $currency)
    {
        parent::__construct(
            sprintf('Wallet for user %d in currency %s already exists.', $userId, $currency->value),
        );
    }

    public function getStatusCode(): int
    {
        return 409;
    }
}
