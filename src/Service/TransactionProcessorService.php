<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Transaction;
use App\Entity\Wallet;
use App\Enum\TransactionStatus;
use App\Repository\CompanyWalletRepositoryInterface;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

final readonly class TransactionProcessorService
{
    public function __construct(
        private WalletRepositoryInterface $walletRepository,
        private TransactionRepositoryInterface $transactionRepository,
        private CompanyWalletRepositoryInterface $companyWalletRepository,
        private Connection $connection,
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

        $this->connection->transactional(function () use ($transaction, $fromWallet, $toWallet): void {
            $toWallet->setBalance($toWallet->getBalance() + (float) $transaction->getToAmount());

            $this->updateWalletActivity($fromWallet);
            $this->updateWalletActivity($toWallet);

            $this->companyWalletRepository->addToBalance(
                $transaction->getToCurrency(),
                $transaction->getSpread(),
            );

            $transaction->setStatus(TransactionStatus::COMPLETED);
            $this->markAntiFraudCheckedIfRequired($transaction);
            $this->transactionRepository->save($transaction);
        });
    }

    public function reject(Transaction $transaction): void
    {
        $fromWallet = $this->walletRepository->findById($transaction->getFromWalletId());

        $this->connection->transactional(function () use ($transaction, $fromWallet): void {
            if (null !== $fromWallet) {
                $fromWallet->setBalance($fromWallet->getBalance() + (float) $transaction->getFromAmount());
                $this->updateWalletActivity($fromWallet);
            }

            $transaction->setStatus(TransactionStatus::REJECTED);
            $this->markAntiFraudCheckedIfRequired($transaction);
            $this->transactionRepository->save($transaction);
        });
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
