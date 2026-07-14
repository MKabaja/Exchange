<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Transaction;
use App\Entity\Wallet;
use App\Exception\InsufficientFundsException;
use App\Exception\WalletNotFoundException;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\Util\DecimalMath;
use DateTimeImmutable;

readonly class TransferService
{
    public function __construct(
        private WalletRepositoryInterface $walletRepository,
        private TransactionRepositoryInterface $transactionRepository,
        private ExchangeRateService $exchangeRateService,
        private SpreadService $spreadService,
    ) {
    }

    public function transfer(
        int $userId,
        int $fromWalletId,
        int $toWalletId,
        string $fromAmount,
    ): Transaction {
        $fromWallet = $this->getUserWallet($fromWalletId, $userId);
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
            requiresAntiFraudCheck: DecimalMath::compare($toAmount, '15000') > 0,
        );

        $this->transactionRepository->save($transaction);

        return $transaction;
    }

    private function getUserWallet(int $walletId, int $userId): Wallet
    {
        $wallet = $this->walletRepository->findById($walletId);
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
}
