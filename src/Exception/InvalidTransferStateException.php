<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\TransactionStatus;

final class InvalidTransferStateException extends ApiException
{
    public function __construct(TransactionStatus $status)
    {
        parent::__construct(sprintf('Transaction in status "%s" cannot be processed.', $status->value));
    }

    public function getStatusCode(): int
    {
        return 409;
    }
}
