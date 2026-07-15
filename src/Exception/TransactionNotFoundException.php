<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

final class TransactionNotFoundException extends RuntimeException
{
    public function __construct(int $transactionId)
    {
        parent::__construct(sprintf('Transaction %d not found.', $transactionId));
    }
}
