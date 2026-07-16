<?php

declare(strict_types=1);

namespace App\Exception;

final class WalletNotFoundException extends ApiException
{
    public function __construct(int $walletId)
    {
        parent::__construct(sprintf('Wallet %d not found.', $walletId));
    }

    public function getStatusCode(): int
    {
        return 404;
    }
}
