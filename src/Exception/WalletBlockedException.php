<?php

declare(strict_types=1);

namespace App\Exception;

final class WalletBlockedException extends ApiException
{
    public function __construct(int $walletId)
    {
        parent::__construct(sprintf('Wallet %d is blocked.', $walletId));
    }

    public function getStatusCode(): int
    {
        return 422;
    }
}
