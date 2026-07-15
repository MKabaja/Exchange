<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Transaction;
use App\Entity\Wallet;
use App\Enum\TransactionStatus;
use App\Exception\InvalidTransferStateException;
use App\Exception\TransactionNotFoundException;
use App\Repository\CompanyWalletRepositoryInterface;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\Util\DecimalMath;
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

    public function complete(int $transactionId): Transaction
    {
        return $this->connection->transactional(function () use ($transactionId): Transaction {
            $transaction = $this->getTransactionForUpdate($transactionId);
            $this->ensureNotTerminal($transaction);

            [$fromWallet, $toWallet] = $this->getWalletsForUpdate($transaction);

            if (null === $fromWallet || null === $toWallet) {
                $this->rejectWithinTransaction($transaction, $fromWallet);

                return $transaction;
            }

            $toWallet->setBalance(DecimalMath::add($toWallet->getBalance(), $transaction->getToAmount()));

            $this->updateWalletActivity($fromWallet);
            $this->updateWalletActivity($toWallet);

            $this->companyWalletRepository->addToBalance(
                $transaction->getToCurrency(),
                $transaction->getSpread(),
            );

            $transaction->setStatus(TransactionStatus::COMPLETED);
            $this->markAntiFraudCheckedIfRequired($transaction);
            $this->transactionRepository->save($transaction);

            return $transaction;
        });
    }

    public function reject(int $transactionId): Transaction
    {
        return $this->connection->transactional(function () use ($transactionId): Transaction {
            $transaction = $this->getTransactionForUpdate($transactionId);
            $this->ensureNotTerminal($transaction);
            $fromWallet = $this->walletRepository->findByIdForUpdate($transaction->getFromWalletId());

            $this->rejectWithinTransaction($transaction, $fromWallet);

            return $transaction;
        });
    }

    private function getTransactionForUpdate(int $transactionId): Transaction
    {
        $transaction = $this->transactionRepository->findByIdForUpdate($transactionId);
        if (null === $transaction) {
            throw new TransactionNotFoundException($transactionId);
        }

        return $transaction;
    }

    /** @return array{?Wallet, ?Wallet} */
    private function getWalletsForUpdate(Transaction $transaction): array
    {
        $walletIds = [$transaction->getFromWalletId(), $transaction->getToWalletId()];
        sort($walletIds, SORT_NUMERIC);

        $wallets = [];
        foreach (array_unique($walletIds) as $walletId) {
            $wallets[$walletId] = $this->walletRepository->findByIdForUpdate($walletId);
        }

        return [
            $wallets[$transaction->getFromWalletId()],
            $wallets[$transaction->getToWalletId()],
        ];
    }

    private function rejectWithinTransaction(Transaction $transaction, ?Wallet $fromWallet): void
    {
        if (null !== $fromWallet) {
            $fromWallet->setBalance(DecimalMath::add($fromWallet->getBalance(), $transaction->getFromAmount()));
            $this->updateWalletActivity($fromWallet);
        }

        $transaction->setStatus(TransactionStatus::REJECTED);
        $this->markAntiFraudCheckedIfRequired($transaction);
        $this->transactionRepository->save($transaction);
    }

    private function ensureNotTerminal(Transaction $transaction): void
    {
        $status = $transaction->getStatus();
        if (TransactionStatus::COMPLETED === $status || TransactionStatus::REJECTED === $status) {
            throw new InvalidTransferStateException($status);
        }
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
