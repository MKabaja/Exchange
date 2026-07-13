<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\TransactionStatus;
use RuntimeException;

final class InvalidTransferStateException extends RuntimeException
{
    public function __construct(TransactionStatus $status)
    {
        parent::__construct(sprintf('Transaction in status "%s" cannot be processed.', $status->value));
    }
}
