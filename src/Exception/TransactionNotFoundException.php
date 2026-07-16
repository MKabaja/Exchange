<?php

declare(strict_types=1);

namespace App\Exception;

final class TransactionNotFoundException extends ApiException
{
    public function __construct(int $transactionId)
    {
        parent::__construct(sprintf('Transaction %d not found.', $transactionId));
    }

    public function getStatusCode(): int
    {
        return 404;
    }
}
