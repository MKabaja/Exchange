<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\TransactionResponse;
use App\Dto\WalletResponse;
use App\Entity\User;
use App\Http\Request\CreateWalletRequest;
use App\Http\Request\DepositRequest;
use App\Http\Request\TransferRequest;
use App\Repository\WalletRepositoryInterface;
use App\Service\DepositService;
use App\Service\TransferService;
use App\Service\WalletService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class WalletController extends AbstractController
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly WalletRepositoryInterface $walletRepository,
        private readonly TransferService $transferService,
        private readonly DepositService $depositService,
    ) {
    }

    public function list(#[CurrentUser] User $user): JsonResponse
    {
        $wallets = $this->walletRepository->findByUserId($user->getIdNotNull());

        return new JsonResponse(array_map(static fn ($w) => new WalletResponse($w), $wallets));
    }

    public function create(
        #[MapRequestPayload(acceptFormat: 'json', validationFailedStatusCode: Response::HTTP_BAD_REQUEST)]
        CreateWalletRequest $request,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $wallet = $this->walletService->createWallet($user->getIdNotNull(), $request->currency);

        return new JsonResponse(new WalletResponse($wallet), Response::HTTP_CREATED);
    }

    public function transfer(
        #[MapRequestPayload(acceptFormat: 'json', validationFailedStatusCode: Response::HTTP_BAD_REQUEST)]
        TransferRequest $request,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $transaction = $this->transferService->transfer(
            $user->getIdNotNull(),
            $request->fromWalletId,
            $request->toWalletId,
            $request->amount,
        );

        return new JsonResponse(new TransactionResponse($transaction), Response::HTTP_CREATED);
    }

    public function deposit(
        int $id,
        #[MapRequestPayload(acceptFormat: 'json', validationFailedStatusCode: Response::HTTP_BAD_REQUEST)]
        DepositRequest $request,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $wallet = $this->depositService->deposit(
            $user->getIdNotNull(),
            $id,
            $request->amount,
        );

        return new JsonResponse(new WalletResponse($wallet));
    }

    public function delete(int $id, #[CurrentUser] User $user): Response
    {
        $this->walletService->deleteWallet($id, $user->getIdNotNull());

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
