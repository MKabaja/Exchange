<?php

declare(strict_types=1);

namespace App\Http\Request;

use App\Service\DepositService;
use App\Util\DecimalMath;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[Assert\Callback('validate')]
final readonly class DepositRequest
{
    public function __construct(public string $amount)
    {
    }

    public function validate(ExecutionContextInterface $context): void
    {
        if (1 !== preg_match('/\A\d+(?:\.\d{1,4})?\z/', $this->amount)
            || DecimalMath::compare($this->amount, '0') <= 0) {
            $context->buildViolation('Amount must be a positive number.')
                ->atPath('amount')
                ->addViolation();

            return;
        }

        if (DecimalMath::compare($this->amount, DepositService::MAX_AMOUNT) > 0) {
            $context->buildViolation(sprintf('Amount cannot exceed %s.', DepositService::MAX_AMOUNT))
                ->atPath('amount')
                ->addViolation();
        }
    }
}
