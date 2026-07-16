<?php

declare(strict_types=1);

namespace App\Exception;

final class InsufficientFundsException extends ApiException
{
    public function __construct()
    {
        parent::__construct('Insufficient funds.');
    }

    public function getStatusCode(): int
    {
        return 422;
    }
}
