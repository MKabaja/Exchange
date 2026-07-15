<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Wallet;
use App\Enum\Currency;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\Service\ExchangeRateService;
use App\Service\SpreadService;
use App\Service\TransferService;
use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TransferServiceTransactionTest extends TestCase
{
    public function testTransferRollsBackWalletDebitWhenTransactionSaveFails(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE test_wallets (id INTEGER PRIMARY KEY, balance TEXT NOT NULL)');
        $connection->insert('test_wallets', ['id' => 1, 'balance' => '500.0000']);

        $fromWallet = new Wallet(
            id: 1,
            userId: 1,
            currency: Currency::PLN,
            balance: '500.0000',
            isBlocked: false,
            lastActivityAt: null,
            createdAt: new DateTimeImmutable(),
        );
        $toWallet = new Wallet(
            id: 2,
            userId: 1,
            currency: Currency::EUR,
            balance: '0.0000',
            isBlocked: false,
            lastActivityAt: null,
            createdAt: new DateTimeImmutable(),
        );

        $walletRepository = $this->createMock(WalletRepositoryInterface::class);
        $walletRepository->expects(self::once())->method('findByIdForUpdate')->with(1)->willReturn($fromWallet);
        $walletRepository->expects(self::once())->method('findById')->with(2)->willReturn($toWallet);
        $walletRepository
            ->method('save')
            ->willReturnCallback(static function (Wallet $wallet) use ($connection): void {
                $connection->update('test_wallets', ['balance' => $wallet->getBalance()], ['id' => 1]);
            });

        $transactionRepository = $this->createStub(TransactionRepositoryInterface::class);
        $transactionRepository
            ->method('save')
            ->willThrowException(new RuntimeException('Transaction save failed.'));

        $exchangeRateService = $this->createStub(ExchangeRateService::class);
        $exchangeRateService->method('getExchangeRateBetween')->willReturn('1.000000');

        $spreadService = $this->createStub(SpreadService::class);
        $spreadService->method('calculateSpread')->willReturn('0.0000');

        $transferService = new TransferService(
            $walletRepository,
            $transactionRepository,
            $exchangeRateService,
            $spreadService,
            $connection,
        );

        try {
            $transferService->transfer(1, 1, 2, '100.0000');
            self::fail('Expected transaction save to fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('Transaction save failed.', $exception->getMessage());
        }

        self::assertSame('500.0000', $connection->fetchOne('SELECT balance FROM test_wallets WHERE id = 1'));
    }
}
