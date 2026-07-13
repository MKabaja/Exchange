<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Transaction;
use App\Entity\Wallet;
use App\Enum\Currency;
use App\Exception\WalletAlreadyExistsException;
use App\Exception\WalletHasPendingTransactionsException;
use App\Exception\WalletNotEmptyException;
use App\Exception\WalletNotFoundException;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\Service\WalletService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class WalletServiceTest extends TestCase
{
    private WalletRepositoryInterface $walletRepository;
    private TransactionRepositoryInterface $transactionRepository;
    private WalletService $walletService;

    protected function setUp(): void
    {
        $this->walletRepository = $this->createMock(WalletRepositoryInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $this->walletService = new WalletService($this->walletRepository, $this->transactionRepository);
    }

    public function testCreateWalletSuccessfully(): void
    {
        $userId = 1;
        $currency = Currency::EUR;

        $this->walletRepository
            ->expects(self::once())
            ->method('findByUserIdAndCurrency')
            ->with($userId, $currency)
            ->willReturn(null);

        $this->walletRepository
            ->expects(self::once())
            ->method('save')
            ->with($this->isInstanceOf(Wallet::class));

        $wallet = $this->walletService->createWallet($userId, $currency);

        self::assertSame($userId, $wallet->getUserId());
        self::assertSame($currency, $wallet->getCurrency());
        self::assertSame('0.0000', $wallet->getBalance());
        self::assertFalse($wallet->isBlocked());
    }

    public function testCreateWalletThrowsWhenWalletAlreadyExists(): void
    {
        $userId = 1;
        $currency = Currency::PLN;

        $existingWallet = Wallet::create($userId, $currency);

        $this->walletRepository
            ->expects(self::once())
            ->method('findByUserIdAndCurrency')
            ->with($userId, $currency)
            ->willReturn($existingWallet);

        $this->walletRepository
            ->expects(self::never())
            ->method('save');

        $this->expectException(WalletAlreadyExistsException::class);
        $this->expectExceptionMessage('Wallet for user 1 in currency PLN already exists.');

        $this->walletService->createWallet($userId, $currency);
    }

    public function testDeleteWalletSuccessfully(): void
    {
        $wallet = Wallet::create(userId: 1, currency: Currency::EUR);

        $this->walletRepository
            ->expects(self::once())
            ->method('findById')
            ->with(1)
            ->willReturn($wallet);

        $this->transactionRepository
            ->expects(self::once())
            ->method('findByWalletId')
            ->with(1)
            ->willReturn([]);

        $this->walletRepository
            ->expects(self::once())
            ->method('delete')
            ->with(1);

        $this->walletService->deleteWallet(walletId: 1, userId: 1);
    }

    public function testDeleteWalletThrowsWhenWalletNotFound(): void
    {
        $this->walletRepository
            ->method('findById')
            ->willReturn(null);

        $this->walletRepository
            ->expects(self::never())
            ->method('delete');

        $this->expectException(WalletNotFoundException::class);

        $this->walletService->deleteWallet(walletId: 99, userId: 1);
    }

    public function testDeleteWalletThrowsWhenWalletNotOwnedByUser(): void
    {
        $wallet = Wallet::create(userId: 2, currency: Currency::EUR);

        $this->walletRepository
            ->method('findById')
            ->willReturn($wallet);

        $this->walletRepository
            ->expects(self::never())
            ->method('delete');

        $this->expectException(WalletNotFoundException::class);

        $this->walletService->deleteWallet(walletId: 1, userId: 1);
    }

    public function testDeleteWalletThrowsWhenBalanceIsNonZero(): void
    {
        $wallet = Wallet::create(userId: 1, currency: Currency::EUR);
        $wallet->setBalance('100.0000');

        $this->walletRepository
            ->method('findById')
            ->willReturn($wallet);

        $this->walletRepository
            ->expects(self::never())
            ->method('delete');

        $this->expectException(WalletNotEmptyException::class);

        $this->walletService->deleteWallet(walletId: 1, userId: 1);
    }

    public function testDeleteWalletThrowsWhenWalletHasPendingTransaction(): void
    {
        $wallet = Wallet::create(userId: 1, currency: Currency::EUR);
        $transaction = Transaction::create(
            fromWalletId: 1,
            toWalletId: 2,
            fromAmount: '100.0000',
            toAmount: '25.0000',
            fromCurrency: Currency::PLN,
            toCurrency: Currency::EUR,
            spread: '1.0000',
            exchangeRate: '1.000000',
            requiresAntiFraudCheck: false,
        );

        $this->walletRepository
            ->method('findById')
            ->willReturn($wallet);

        $this->transactionRepository
            ->method('findByWalletId')
            ->willReturn([$transaction]);

        $this->walletRepository
            ->expects(self::never())
            ->method('delete');

        $this->expectException(WalletHasPendingTransactionsException::class);

        $this->walletService->deleteWallet(walletId: 1, userId: 1);
    }

    public function testDeleteWalletThrowsWhenWalletHasFraudReviewTransaction(): void
    {
        $wallet = Wallet::create(userId: 1, currency: Currency::EUR);
        $transaction = Transaction::create(
            fromWalletId: 1,
            toWalletId: 2,
            fromAmount: '20000.0000',
            toAmount: '5000.0000',
            fromCurrency: Currency::PLN,
            toCurrency: Currency::EUR,
            spread: '1.0000',
            exchangeRate: '1.000000',
            requiresAntiFraudCheck: true,
        );

        $this->walletRepository
            ->method('findById')
            ->willReturn($wallet);

        $this->transactionRepository
            ->method('findByWalletId')
            ->willReturn([$transaction]);

        $this->walletRepository
            ->expects(self::never())
            ->method('delete');

        $this->expectException(WalletHasPendingTransactionsException::class);

        $this->walletService->deleteWallet(walletId: 1, userId: 1);
    }
}
