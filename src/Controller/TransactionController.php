<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\TransactionResponse;
use App\Service\TransactionProcessorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class TransactionController extends AbstractController
{
    public function __construct(private readonly TransactionProcessorService $transactionProcessorService)
    {
    }

    #[IsGranted('ROLE_ADMIN')]
    public function complete(int $id): JsonResponse
    {
        $transaction = $this->transactionProcessorService->complete($id);

        return new JsonResponse(new TransactionResponse($transaction));
    }

    #[IsGranted('ROLE_ADMIN')]
    public function reject(int $id): JsonResponse
    {
        $transaction = $this->transactionProcessorService->reject($id);

        return new JsonResponse(new TransactionResponse($transaction));
    }
}
