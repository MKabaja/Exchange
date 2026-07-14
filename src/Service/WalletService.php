<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Wallet;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Exception\WalletAlreadyExistsException;
use App\Exception\WalletHasPendingTransactionsException;
use App\Exception\WalletNotEmptyException;
use App\Exception\WalletNotFoundException;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\Util\DecimalMath;

readonly class WalletService
{
    public function __construct(
        private WalletRepositoryInterface $walletRepository,
        private TransactionRepositoryInterface $transactionRepository,
    ) {
    }

    public function createWallet(int $userId, Currency $currency): Wallet
    {
        $existing = $this->walletRepository->findByUserIdAndCurrency($userId, $currency);

        if (null !== $existing) {
            throw new WalletAlreadyExistsException($userId, $currency);
        }

        $wallet = Wallet::create($userId, $currency);
        $this->walletRepository->save($wallet);

        return $wallet;
    }

    public function deleteWallet(int $walletId, int $userId): void
    {
        $wallet = $this->getUserWallet($walletId, $userId);

        if (0 !== DecimalMath::compare($wallet->getBalance(), '0')) {
            throw new WalletNotEmptyException($walletId);
        }

        $pendingStatuses = [TransactionStatus::PENDING, TransactionStatus::FRAUD_REVIEW];
        $transactions = $this->transactionRepository->findByWalletId($walletId);

        foreach ($transactions as $transaction) {
            if (in_array($transaction->getStatus(), $pendingStatuses, true)) {
                throw new WalletHasPendingTransactionsException($walletId);
            }
        }

        $this->walletRepository->delete($walletId);
    }

    private function getUserWallet(int $walletId, int $userId): Wallet
    {
        $wallet = $this->walletRepository->findById($walletId);

        if (null === $wallet || $wallet->getUserId() !== $userId) {
            throw new WalletNotFoundException($walletId);
        }

        return $wallet;
    }
}
