<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Transaction;
use App\Entity\Wallet;
use App\Enum\Currency;
use App\Exception\InsufficientFundsException;
use App\Exception\WalletNotFoundException;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\Util\DecimalMath;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

readonly class TransferService
{
    public function __construct(
        private WalletRepositoryInterface $walletRepository,
        private TransactionRepositoryInterface $transactionRepository,
        private ExchangeRateService $exchangeRateService,
        private SpreadService $spreadService,
        private Connection $connection,
    ) {
    }

    public function transfer(
        int $userId,
        int $fromWalletId,
        int $toWalletId,
        string $fromAmount,
    ): Transaction {
        return $this->connection->transactional(function () use ($userId, $fromWalletId, $toWalletId, $fromAmount): Transaction {
            $fromWallet = $this->getUserWalletForUpdate($fromWalletId, $userId);
            $toWallet = $this->getUserWallet($toWalletId, $userId);

            $this->ensureSufficientFunds($fromWallet, $fromAmount);

            $fromCurrency = $fromWallet->getCurrency();
            $toCurrency = $toWallet->getCurrency();

            $exchangeRate = $this->exchangeRateService->getExchangeRateBetween($fromCurrency, $toCurrency);
            $rawToAmount = DecimalMath::multiply($fromAmount, $exchangeRate, DecimalMath::CALC_SCALE);
            $spread = $this->spreadService->calculateSpread($rawToAmount, $fromCurrency, $toCurrency);
            $exactToAmount = DecimalMath::subtract($rawToAmount, $spread, DecimalMath::CALC_SCALE);
            $toAmount = DecimalMath::round($exactToAmount, DecimalMath::AMOUNT_SCALE);

            $fromWallet->setBalance(DecimalMath::subtract($fromWallet->getBalance(), $fromAmount));

            $fromWallet->setLastActivityAt(new DateTimeImmutable());
            $this->walletRepository->save($fromWallet);

            $transaction = Transaction::create(
                fromWalletId: $fromWalletId,
                toWalletId: $toWalletId,
                fromAmount: $fromAmount,
                toAmount: $toAmount,
                fromCurrency: $fromCurrency,
                toCurrency: $toCurrency,
                spread: DecimalMath::round($spread, DecimalMath::AMOUNT_SCALE),
                exchangeRate: $exchangeRate,
                requiresAntiFraudCheck: $this->exceedsAntiFraudThreshold($fromAmount, $fromCurrency),
            );

            $this->transactionRepository->save($transaction);

            return $transaction;
        });
    }

    private function getUserWallet(int $walletId, int $userId): Wallet
    {
        $wallet = $this->walletRepository->findById($walletId);
        if (null === $wallet || $wallet->getUserId() !== $userId) {
            throw new WalletNotFoundException($walletId);
        }

        return $wallet;
    }

    private function getUserWalletForUpdate(int $walletId, int $userId): Wallet
    {
        $wallet = $this->walletRepository->findByIdForUpdate($walletId);
        if (null === $wallet || $wallet->getUserId() !== $userId) {
            throw new WalletNotFoundException($walletId);
        }

        return $wallet;
    }

    private function ensureSufficientFunds(Wallet $wallet, string $amount): void
    {
        if (DecimalMath::compare($wallet->getBalance(), $amount) < 0) {
            throw new InsufficientFundsException();
        }
    }

    private function exceedsAntiFraudThreshold(string $fromAmount, Currency $fromCurrency): bool
    {
        $eurRate = $this->exchangeRateService->getExchangeRateBetween($fromCurrency, Currency::EUR);
        $eurValue = DecimalMath::multiply($fromAmount, $eurRate, DecimalMath::CALC_SCALE);

        return DecimalMath::compare($eurValue, '15000', DecimalMath::CALC_SCALE) >= 0;
    }
}
