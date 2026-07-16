<?php

declare(strict_types=1);

namespace App\Exception;

final class WalletHasPendingTransactionsException extends ApiException
{
    public function __construct(int $walletId)
    {
        parent::__construct(sprintf('Wallet %d has pending transactions.', $walletId));
    }

    public function getStatusCode(): int
    {
        return 409;
    }
}
