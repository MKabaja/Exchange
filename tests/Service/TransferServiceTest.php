<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Wallet;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Exception\InsufficientFundsException;
use App\Exception\WalletNotFoundException;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\Service\ExchangeRateService;
use App\Service\SpreadService;
use App\Service\TransferService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class TransferServiceTest extends TestCase
{
    private WalletRepositoryInterface $walletRepository;
    private TransactionRepositoryInterface $transactionRepository;
    private ExchangeRateService $exchangeRateService;
    private SpreadService $spreadService;
    private TransferService $transferService;

    protected function setUp(): void
    {
        $this->walletRepository = $this->createMock(WalletRepositoryInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $this->exchangeRateService = $this->createMock(ExchangeRateService::class);
        $this->spreadService = $this->createMock(SpreadService::class);

        $this->transferService = new TransferService(
            $this->walletRepository,
            $this->transactionRepository,
            $this->exchangeRateService,
            $this->spreadService,
        );
    }

    public function testTransferCreatesTransactionWithCorrectData(): void
    {
        $userId = 1;
        $fromWallet = $this->createMock(Wallet::class);
        $fromWallet->method('getCurrency')->willReturn(Currency::PLN);
        $fromWallet->method('getUserId')->willReturn($userId);
        $fromWallet->method('getBalance')->willReturn(5000.0);

        $toWallet = $this->createMock(Wallet::class);
        $toWallet->method('getCurrency')->willReturn(Currency::EUR);
        $toWallet->method('getUserId')->willReturn($userId);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [2, $toWallet],
            ]);

        $this->exchangeRateService
            ->expects(self::once())
            ->method('getExchangeRateBetween')
            ->with(Currency::PLN, Currency::EUR)
            ->willReturn(0.25);

        $this->spreadService
            ->expects(self::once())
            ->method('calculateSpread')
            ->with(250.0, Currency::PLN, Currency::EUR)
            ->willReturn('1.00');

        $transaction = $this->transferService->transfer($userId, 1, 2, '1000.00');

        self::assertSame(TransactionStatus::PENDING, $transaction->getStatus());
        self::assertFalse($transaction->requiresAntiFraudCheck());
        self::assertSame('1000.00', $transaction->getFromAmount());
        self::assertSame('249.0000', $transaction->getToAmount());
        self::assertSame('0.250000', $transaction->getExchangeRate());
        self::assertSame('1.00', $transaction->getSpread());
        self::assertSame(Currency::PLN, $transaction->getFromCurrency());
        self::assertSame(Currency::EUR, $transaction->getToCurrency());
    }

    public function testTransferMutatesWalletBalancesImmediately(): void
    {
        $userId = 1;
        $fromWallet = Wallet::create($userId, Currency::PLN);
        $fromWallet->setBalance(5000.0);

        $toWallet = Wallet::create($userId, Currency::EUR);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [2, $toWallet],
            ]);

        $this->exchangeRateService->method('getExchangeRateBetween')->willReturn(0.25);
        $this->spreadService->method('calculateSpread')->willReturn('1.00');

        $this->transferService->transfer($userId, 1, 2, '1000.00');

        self::assertSame(4000.0, $fromWallet->getBalance());
        self::assertSame(249.0, $toWallet->getBalance());
    }

    public function testTransferUpdatesLastActivityAtOnBothWallets(): void
    {
        $userId = 1;
        $fromWallet = Wallet::create($userId, Currency::PLN);
        $fromWallet->setBalance(5000.0);

        $toWallet = Wallet::create($userId, Currency::EUR);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [2, $toWallet],
            ]);

        $this->exchangeRateService->method('getExchangeRateBetween')->willReturn(0.25);
        $this->spreadService->method('calculateSpread')->willReturn('1.00');

        $this->transferService->transfer($userId, 1, 2, '1000.00');

        self::assertNotNull($fromWallet->getLastActivityAt());
        self::assertNotNull($toWallet->getLastActivityAt());
    }

    public function testTransferRequiresAntiFraudCheckForLargeAmount(): void
    {
        $userId = 1;
        $fromWallet = $this->createMock(Wallet::class);
        $fromWallet->method('getCurrency')->willReturn(Currency::PLN);
        $fromWallet->method('getUserId')->willReturn($userId);
        $fromWallet->method('getBalance')->willReturn(1_000_000.0);

        $toWallet = $this->createMock(Wallet::class);
        $toWallet->method('getCurrency')->willReturn(Currency::EUR);
        $toWallet->method('getUserId')->willReturn($userId);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [2, $toWallet],
            ]);

        // 100000 PLN * 0.25 = 25000 EUR > 15000
        $this->exchangeRateService->method('getExchangeRateBetween')->willReturn(0.25);
        $this->spreadService->method('calculateSpread')->willReturn('0.00');

        $transaction = $this->transferService->transfer($userId, 1, 2, '100000.00');

        self::assertTrue($transaction->requiresAntiFraudCheck());
    }

    public function testTransferThrowsWhenInsufficientFunds(): void
    {
        $userId = 1;
        $fromWallet = Wallet::create($userId, Currency::PLN);
        $fromWallet->setBalance(100.0);

        $toWallet = Wallet::create($userId, Currency::EUR);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [2, $toWallet],
            ]);

        $this->walletRepository->expects(self::never())->method('save');
        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(InsufficientFundsException::class);

        $this->transferService->transfer($userId, 1, 2, '500.00');
    }

    public function testTransferThrowsWhenFromWalletNotFound(): void
    {
        $this->walletRepository
            ->expects($this->once())
            ->method('findById')
            ->with(99)
            ->willReturn(null);

        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 99 not found.');

        $this->transferService->transfer(1, 99, 2, '100.00');
    }

    public function testTransferThrowsWhenToWalletNotFound(): void
    {
        $fromWallet = Wallet::create(1, Currency::PLN);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [99, null],
            ]);

        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 99 not found.');

        $this->transferService->transfer(1, 1, 99, '100.00');
    }

    public function testTransferThrowsWhenFromWalletBelongsToOtherUser(): void
    {
        $fromWallet = Wallet::create(2, Currency::PLN);

        $this->walletRepository
            ->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn($fromWallet);

        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 1 not found.');

        $this->transferService->transfer(1, 1, 2, '100.00');
    }

    public function testTransferThrowsWhenToWalletBelongsToOtherUser(): void
    {
        $fromWallet = Wallet::create(1, Currency::PLN);
        $toWallet = Wallet::create(2, Currency::EUR);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [2, $toWallet],
            ]);

        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 2 not found.');

        $this->transferService->transfer(1, 1, 2, '100.00');
    }
}
