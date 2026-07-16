<?php

declare(strict_types=1);

namespace App\Exception;

final class WalletNotEmptyException extends ApiException
{
    public function __construct(int $walletId)
    {
        parent::__construct(sprintf('Wallet %d has non-zero balance.', $walletId));
    }

    public function getStatusCode(): int
    {
        return 409;
    }
}
