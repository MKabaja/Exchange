<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Transaction;
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
use App\Util\DecimalMath;
use Closure;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class TransferServiceTest extends TestCase
{
    private WalletRepositoryInterface&MockObject $walletRepository;
    private TransactionRepositoryInterface&MockObject $transactionRepository;
    private ExchangeRateService&MockObject $exchangeRateService;
    private SpreadService&MockObject $spreadService;
    private Connection&MockObject $connection;
    private TransferService $transferService;

    protected function setUp(): void
    {
        $this->walletRepository = $this->createMock(WalletRepositoryInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $this->exchangeRateService = $this->createMock(ExchangeRateService::class);
        $this->spreadService = $this->createMock(SpreadService::class);
        $this->connection = $this->createMock(Connection::class);

        $this->connection
            ->expects(self::once())
            ->method('transactional')
            ->willReturnCallback(static fn (Closure $callback): mixed => $callback());

        $this->transferService = new TransferService(
            $this->walletRepository,
            $this->transactionRepository,
            $this->exchangeRateService,
            $this->spreadService,
            $this->connection,
        );
    }

    public function testTransferCreatesTransactionWithCorrectData(): void
    {
        $userId = 1;
        $expectedRawToAmount = DecimalMath::multiply('1000.00', '0.250000', DecimalMath::CALC_SCALE);

        $fromWallet = $this->createMock(Wallet::class);
        $fromWallet->method('getCurrency')->willReturn(Currency::PLN);
        $fromWallet->method('getUserId')->willReturn($userId);
        $fromWallet->method('getBalance')->willReturn('5000.0000');

        $toWallet = $this->createMock(Wallet::class);
        $toWallet->method('getCurrency')->willReturn(Currency::EUR);
        $toWallet->method('getUserId')->willReturn($userId);

        $this->mockWallets($fromWallet, $toWallet);

        $this->exchangeRateService
            ->expects(self::exactly(2))
            ->method('getExchangeRateBetween')
            ->with(Currency::PLN, Currency::EUR)
            ->willReturn('0.250000');

        $this->spreadService
            ->expects(self::once())
            ->method('calculateSpread')
            ->with($expectedRawToAmount, Currency::PLN, Currency::EUR)
            ->willReturn('1.00');

        $this->walletRepository
            ->expects(self::once())
            ->method('save')
            ->with($fromWallet);

        $this->transactionRepository
            ->expects(self::once())
            ->method('save')
            ->with(self::isInstanceOf(Transaction::class));

        $transaction = $this->transferService->transfer($userId, 1, 2, '1000.00');

        self::assertSame(TransactionStatus::PENDING, $transaction->getStatus());
        self::assertFalse($transaction->requiresAntiFraudCheck());
        self::assertSame('1000.00', $transaction->getFromAmount());
        self::assertSame('249.0000', $transaction->getToAmount());
        self::assertSame('0.250000', $transaction->getExchangeRate());
        self::assertSame('1.0000', $transaction->getSpread());
        self::assertSame(Currency::PLN, $transaction->getFromCurrency());
        self::assertSame(Currency::EUR, $transaction->getToCurrency());
    }

    public function testTransferHoldsSourceFundsWithoutCreditingDestination(): void
    {
        $userId = 1;
        $fromWallet = Wallet::create($userId, Currency::PLN);
        $fromWallet->setBalance('5000.0000');

        $toWallet = Wallet::create($userId, Currency::EUR);
        $toWallet->setBalance('100.0000');

        $this->mockWallets($fromWallet, $toWallet);

        $this->exchangeRateService->method('getExchangeRateBetween')->willReturn('0.250000');
        $this->spreadService->method('calculateSpread')->willReturn('1.00');

        $this->transferService->transfer($userId, 1, 2, '1000.00');

        self::assertSame('4000.0000', $fromWallet->getBalance());
        self::assertSame('100.0000', $toWallet->getBalance());
    }

    public function testTransferUpdatesActivityAndPersistsOnlySourceWallet(): void
    {
        $userId = 1;
        $fromWallet = Wallet::create($userId, Currency::PLN);
        $fromWallet->setBalance('5000.0000');

        $toWallet = Wallet::create($userId, Currency::EUR);

        $this->mockWallets($fromWallet, $toWallet);

        $this->exchangeRateService->method('getExchangeRateBetween')->willReturn('0.250000');
        $this->spreadService->method('calculateSpread')->willReturn('1.00');

        $this->walletRepository
            ->expects(self::once())
            ->method('save')
            ->with($fromWallet);

        $this->transferService->transfer($userId, 1, 2, '1000.00');

        self::assertNotNull($fromWallet->getLastActivityAt());
        self::assertNull($toWallet->getLastActivityAt());
    }

    public function testTransferRequiresAntiFraudCheckForLargeAmount(): void
    {
        $userId = 1;
        $fromWallet = $this->createMock(Wallet::class);
        $fromWallet->method('getCurrency')->willReturn(Currency::PLN);
        $fromWallet->method('getUserId')->willReturn($userId);
        $fromWallet->method('getBalance')->willReturn('1000000.0000');

        $toWallet = $this->createMock(Wallet::class);
        $toWallet->method('getCurrency')->willReturn(Currency::EUR);
        $toWallet->method('getUserId')->willReturn($userId);

        $this->mockWallets($fromWallet, $toWallet);

        // 100000 PLN * 0.25 = 25000 EUR > 15000
        $this->exchangeRateService->method('getExchangeRateBetween')->willReturn('0.250000');
        $this->spreadService->method('calculateSpread')->willReturn('0.00');

        $transaction = $this->transferService->transfer($userId, 1, 2, '100000.00');

        self::assertTrue($transaction->requiresAntiFraudCheck());
    }

    public function testTransferDoesNotFlagWhenEurValueBelowThreshold(): void
    {
        $userId = 1;
        $fromWallet = $this->createMock(Wallet::class);
        $fromWallet->method('getCurrency')->willReturn(Currency::USD);
        $fromWallet->method('getUserId')->willReturn($userId);
        $fromWallet->method('getBalance')->willReturn('20000.0000');

        $toWallet = $this->createMock(Wallet::class);
        $toWallet->method('getCurrency')->willReturn(Currency::PLN);
        $toWallet->method('getUserId')->willReturn($userId);

        $this->mockWallets($fromWallet, $toWallet);

        // 10000 USD * 0.86 = 8600 EUR < 15000
        $this->exchangeRateService
            ->method('getExchangeRateBetween')
            ->willReturnMap([
                [Currency::USD, Currency::PLN, '3.646700'],
                [Currency::USD, Currency::EUR, '0.860000'],
            ]);
        $this->spreadService->method('calculateSpread')->willReturn('0.00');

        $transaction = $this->transferService->transfer($userId, 1, 2, '10000.00');

        self::assertFalse($transaction->requiresAntiFraudCheck());
        self::assertSame(TransactionStatus::PENDING, $transaction->getStatus());
    }

    public function testTransferFlagsWhenEurValueExactlyAtThreshold(): void
    {
        $userId = 1;
        $fromWallet = $this->createMock(Wallet::class);
        $fromWallet->method('getCurrency')->willReturn(Currency::GBP);
        $fromWallet->method('getUserId')->willReturn($userId);
        $fromWallet->method('getBalance')->willReturn('20000.0000');

        $toWallet = $this->createMock(Wallet::class);
        $toWallet->method('getCurrency')->willReturn(Currency::PLN);
        $toWallet->method('getUserId')->willReturn($userId);

        $this->mockWallets($fromWallet, $toWallet);

        // 15000 GBP * 1.0 = 15000 EUR == threshold
        $this->exchangeRateService
            ->method('getExchangeRateBetween')
            ->willReturnMap([
                [Currency::GBP, Currency::PLN, '4.881000'],
                [Currency::GBP, Currency::EUR, '1.000000'],
            ]);
        $this->spreadService->method('calculateSpread')->willReturn('0.00');

        $transaction = $this->transferService->transfer($userId, 1, 2, '15000.00');

        self::assertTrue($transaction->requiresAntiFraudCheck());
        self::assertSame(TransactionStatus::FRAUD_REVIEW, $transaction->getStatus());
    }

    public function testTransferFlagsWhenEurValueAboveThresholdDespiteSmallDestinationAmount(): void
    {
        $userId = 1;
        $fromWallet = $this->createMock(Wallet::class);
        $fromWallet->method('getCurrency')->willReturn(Currency::EUR);
        $fromWallet->method('getUserId')->willReturn($userId);
        $fromWallet->method('getBalance')->willReturn('20000.0000');

        $toWallet = $this->createMock(Wallet::class);
        $toWallet->method('getCurrency')->willReturn(Currency::GBP);
        $toWallet->method('getUserId')->willReturn($userId);

        $this->mockWallets($fromWallet, $toWallet);

        // 16000 EUR * 0.868 = 13888 GBP destination (< 15000), but source value is 16000 EUR >= 15000
        $this->exchangeRateService
            ->method('getExchangeRateBetween')
            ->willReturnMap([
                [Currency::EUR, Currency::GBP, '0.868000'],
                [Currency::EUR, Currency::EUR, '1.000000'],
            ]);
        $this->spreadService->method('calculateSpread')->willReturn('0.00');

        $transaction = $this->transferService->transfer($userId, 1, 2, '16000.00');

        self::assertSame('13888.0000', $transaction->getToAmount());
        self::assertTrue($transaction->requiresAntiFraudCheck());
        self::assertSame(TransactionStatus::FRAUD_REVIEW, $transaction->getStatus());
    }

    public function testTransferThrowsWhenInsufficientFunds(): void
    {
        $userId = 1;
        $fromWallet = Wallet::create($userId, Currency::PLN);
        $fromWallet->setBalance('100.0000');

        $toWallet = Wallet::create($userId, Currency::EUR);

        $this->mockWallets($fromWallet, $toWallet);

        $this->walletRepository->expects(self::never())->method('save');
        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(InsufficientFundsException::class);

        $this->transferService->transfer($userId, 1, 2, '500.00');
    }

    public function testTransferThrowsWhenFromWalletNotFound(): void
    {
        $this->walletRepository
            ->expects($this->once())
            ->method('findByIdForUpdate')
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

        $this->mockWallets($fromWallet, null, toWalletId: 99);

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
            ->method('findByIdForUpdate')
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

        $this->mockWallets($fromWallet, $toWallet);

        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 2 not found.');

        $this->transferService->transfer(1, 1, 2, '100.00');
    }

    private function mockWallets(
        ?Wallet $fromWallet,
        ?Wallet $toWallet,
        int $fromWalletId = 1,
        int $toWalletId = 2,
    ): void {
        $this->walletRepository
            ->expects(self::once())
            ->method('findByIdForUpdate')
            ->with($fromWalletId)
            ->willReturn($fromWallet);
        $this->walletRepository
            ->expects(self::once())
            ->method('findById')
            ->with($toWalletId)
            ->willReturn($toWallet);
    }
}
