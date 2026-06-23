<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Transaction;
use App\Entity\Wallet;
use App\Enum\TransactionStatus;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use DateTimeImmutable;

final readonly class TransactionProcessorService
{
    public function __construct(
        private WalletRepositoryInterface $walletRepository,
        private TransactionRepositoryInterface $transactionRepository,
    ) {
    }

    public function complete(Transaction $transaction): void
    {
        $fromWallet = $this->walletRepository->findById($transaction->getFromWalletId());
        $toWallet = $this->walletRepository->findById($transaction->getToWalletId());

        if (null === $fromWallet || null === $toWallet) {
            $this->reject($transaction);

            return;
        }

        $fromWallet->setBalance($fromWallet->getBalance() - (float) $transaction->getFromAmount());
        $toWallet->setBalance($toWallet->getBalance() + (float) $transaction->getToAmount());

        $this->updateWalletActivity($fromWallet);
        $this->updateWalletActivity($toWallet);

        $transaction->setStatus(TransactionStatus::COMPLETED);
        $this->markAntiFraudCheckedIfRequired($transaction);
        $this->transactionRepository->save($transaction);
    }

    public function reject(Transaction $transaction): void
    {
        $fromWallet = $this->walletRepository->findById($transaction->getFromWalletId());
        if (null !== $fromWallet) {
            $this->updateWalletActivity($fromWallet);
        }

        $transaction->setStatus(TransactionStatus::REJECTED);
        $this->markAntiFraudCheckedIfRequired($transaction);
        $this->transactionRepository->save($transaction);
    }

    private function updateWalletActivity(Wallet $wallet): void
    {
        $wallet->setLastActivityAt(new DateTimeImmutable());
        $this->walletRepository->save($wallet);
    }

    private function markAntiFraudCheckedIfRequired(Transaction $transaction): void
    {
        if ($transaction->requiresAntiFraudCheck()) {
            $transaction->setAntiFraudCheckedAt(new DateTimeImmutable());
        }
    }
}
