<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Transaction;
use App\Entity\Wallet;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Exception\InvalidTransferStateException;
use App\Repository\CompanyWalletRepositoryInterface;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\Service\TransactionProcessorService;
use Closure;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class TransactionProcessorServiceTest extends TestCase
{
    private WalletRepositoryInterface $walletRepository;
    private TransactionRepositoryInterface $transactionRepository;
    private CompanyWalletRepositoryInterface $companyWalletRepository;
    private Connection $connection;
    private TransactionProcessorService $transactionProcessorService;

    protected function setUp(): void
    {
        $this->walletRepository = $this->createMock(WalletRepositoryInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $this->companyWalletRepository = $this->createMock(CompanyWalletRepositoryInterface::class);
        $this->connection = $this->createMock(Connection::class);

        $this->transactionProcessorService = new TransactionProcessorService(
            $this->walletRepository,
            $this->transactionRepository,
            $this->companyWalletRepository,
            $this->connection,
        );
    }

    public function testCompleteCreditsDestinationWithoutDebitingSourceAgain(): void
    {
        $fromWallet = Wallet::create(1, Currency::PLN);
        $fromWallet->setBalance(400.0);

        $toWallet = Wallet::create(1, Currency::EUR);
        $toWallet->setBalance(100.0);

        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [2, $toWallet],
            ]);

        $this->expectTransaction();

        $this->transactionProcessorService->complete($transaction);

        self::assertSame(400.0, $fromWallet->getBalance());
        self::assertSame(125.0, $toWallet->getBalance());
    }

    public function testCompleteCreditsCompanyWalletWithSpreadInDestinationCurrency(): void
    {
        $fromWallet = Wallet::create(1, Currency::PLN);
        $fromWallet->setBalance(400.0);

        $toWallet = Wallet::create(1, Currency::EUR);
        $toWallet->setBalance(100.0);

        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [2, $toWallet],
            ]);

        $this->companyWalletRepository
            ->expects(self::once())
            ->method('addToBalance')
            ->with(Currency::EUR, '0.5000');

        $this->expectTransaction();

        $this->transactionProcessorService->complete($transaction);
    }

    public function testCompleteUpdatesActivityAndPersistsBothWallets(): void
    {
        $fromWallet = Wallet::create(1, Currency::PLN);
        $fromWallet->setBalance(400.0);

        $toWallet = Wallet::create(1, Currency::EUR);
        $toWallet->setBalance(100.0);

        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [2, $toWallet],
            ]);

        $savedWallets = [];
        $this->walletRepository
            ->expects(self::exactly(2))
            ->method('save')
            ->willReturnCallback(static function (Wallet $wallet) use (&$savedWallets): void {
                $savedWallets[] = $wallet;
            });

        $this->transactionRepository
            ->expects(self::once())
            ->method('save')
            ->with($transaction);

        $this->expectTransaction();

        $this->transactionProcessorService->complete($transaction);

        self::assertNotNull($fromWallet->getLastActivityAt());
        self::assertNotNull($toWallet->getLastActivityAt());
        self::assertContains($fromWallet, $savedWallets);
        self::assertContains($toWallet, $savedWallets);
        self::assertSame(TransactionStatus::COMPLETED, $transaction->getStatus());
        self::assertNull($transaction->getAntiFraudCheckedAt());
    }

    public function testCompleteSetsAntiFraudCheckedAtWhenRequired(): void
    {
        $fromWallet = Wallet::create(1, Currency::PLN);
        $fromWallet->setBalance(400.0);

        $toWallet = Wallet::create(1, Currency::EUR);

        $transaction = $this->makeTransaction(requiresAntiFraudCheck: true);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [2, $toWallet],
            ]);

        $this->companyWalletRepository
            ->expects(self::once())
            ->method('addToBalance')
            ->with(Currency::EUR, '0.5000');

        $this->expectTransaction();

        $this->transactionProcessorService->complete($transaction);

        self::assertSame(TransactionStatus::COMPLETED, $transaction->getStatus());
        self::assertNotNull($transaction->getAntiFraudCheckedAt());
    }

    public function testCompleteRejectsWithoutMutatingDestinationWhenSourceWalletNotFound(): void
    {
        $toWallet = Wallet::create(1, Currency::EUR);
        $toWallet->setBalance(25.0);

        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, null],
                [2, $toWallet],
            ]);

        $this->walletRepository->expects(self::never())->method('save');
        $this->companyWalletRepository->expects(self::never())->method('addToBalance');
        $this->transactionRepository
            ->expects(self::once())
            ->method('save')
            ->with($transaction);

        $this->expectTransaction();

        $this->transactionProcessorService->complete($transaction);

        self::assertSame(25.0, $toWallet->getBalance());
        self::assertNull($toWallet->getLastActivityAt());
        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
    }

    public function testCompleteRefundsSourceWhenDestinationWalletNotFound(): void
    {
        $fromWallet = Wallet::create(1, Currency::PLN);
        $fromWallet->setBalance(400.0);

        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [2, null],
            ]);

        $this->walletRepository
            ->expects(self::once())
            ->method('save')
            ->with($fromWallet);
        $this->companyWalletRepository->expects(self::never())->method('addToBalance');
        $this->transactionRepository
            ->expects(self::once())
            ->method('save')
            ->with($transaction);

        $this->expectTransaction();

        $this->transactionProcessorService->complete($transaction);

        self::assertSame(500.0, $fromWallet->getBalance());
        self::assertNotNull($fromWallet->getLastActivityAt());
        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
    }

    public function testRejectRefundsSourceWithoutMutatingDestinationOrCompanyWallet(): void
    {
        $fromWallet = Wallet::create(1, Currency::PLN);
        $fromWallet->setBalance(400.0);

        $toWallet = Wallet::create(1, Currency::EUR);
        $toWallet->setBalance(25.0);

        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);

        $this->walletRepository
            ->expects(self::once())
            ->method('findById')
            ->with(1)
            ->willReturn($fromWallet);
        $this->walletRepository
            ->expects(self::once())
            ->method('save')
            ->with($fromWallet);
        $this->companyWalletRepository->expects(self::never())->method('addToBalance');
        $this->transactionRepository
            ->expects(self::once())
            ->method('save')
            ->with($transaction);

        $this->expectTransaction();

        $this->transactionProcessorService->reject($transaction);

        self::assertSame(500.0, $fromWallet->getBalance());
        self::assertSame(25.0, $toWallet->getBalance());
        self::assertNotNull($fromWallet->getLastActivityAt());
        self::assertNull($toWallet->getLastActivityAt());
        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
        self::assertNull($transaction->getAntiFraudCheckedAt());
    }

    public function testRejectSetsAntiFraudCheckedAtWhenRequired(): void
    {
        $fromWallet = Wallet::create(1, Currency::PLN);
        $fromWallet->setBalance(400.0);

        $transaction = $this->makeTransaction(requiresAntiFraudCheck: true);

        $this->walletRepository
            ->method('findById')
            ->willReturn($fromWallet);

        $this->companyWalletRepository->expects(self::never())->method('addToBalance');
        $this->expectTransaction();

        $this->transactionProcessorService->reject($transaction);

        self::assertSame(500.0, $fromWallet->getBalance());
        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
        self::assertNotNull($transaction->getAntiFraudCheckedAt());
    }

    public function testRejectChangesStatusWithoutSavingWalletWhenSourceWalletNotFound(): void
    {
        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);

        $this->walletRepository
            ->expects(self::once())
            ->method('findById')
            ->with(1)
            ->willReturn(null);
        $this->walletRepository->expects(self::never())->method('save');
        $this->companyWalletRepository->expects(self::never())->method('addToBalance');
        $this->transactionRepository
            ->expects(self::once())
            ->method('save')
            ->with($transaction);

        $this->expectTransaction();

        $this->transactionProcessorService->reject($transaction);

        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
    }

    #[DataProvider('terminalStatuses')]
    public function testCompleteThrowsForTerminalStatusWithoutSideEffects(TransactionStatus $status): void
    {
        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);
        $transaction->setStatus($status);

        $this->walletRepository->expects(self::never())->method('findById');
        $this->walletRepository->expects(self::never())->method('save');
        $this->companyWalletRepository->expects(self::never())->method('addToBalance');
        $this->transactionRepository->expects(self::never())->method('save');
        $this->connection->expects(self::never())->method('transactional');

        $this->expectException(InvalidTransferStateException::class);
        $this->expectExceptionMessage(sprintf('Transaction in status "%s" cannot be processed.', $status->value));

        $this->transactionProcessorService->complete($transaction);
    }

    #[DataProvider('terminalStatuses')]
    public function testRejectThrowsForTerminalStatusWithoutSideEffects(TransactionStatus $status): void
    {
        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);
        $transaction->setStatus($status);

        $this->walletRepository->expects(self::never())->method('findById');
        $this->walletRepository->expects(self::never())->method('save');
        $this->companyWalletRepository->expects(self::never())->method('addToBalance');
        $this->transactionRepository->expects(self::never())->method('save');
        $this->connection->expects(self::never())->method('transactional');

        $this->expectException(InvalidTransferStateException::class);
        $this->expectExceptionMessage(sprintf('Transaction in status "%s" cannot be processed.', $status->value));

        $this->transactionProcessorService->reject($transaction);
    }

    public static function terminalStatuses(): iterable
    {
        yield 'completed' => [TransactionStatus::COMPLETED];
        yield 'rejected' => [TransactionStatus::REJECTED];
    }

    private function expectTransaction(): void
    {
        $this->connection
            ->expects(self::once())
            ->method('transactional')
            ->willReturnCallback(static fn (Closure $callback): mixed => $callback());
    }

    private function makeTransaction(bool $requiresAntiFraudCheck): Transaction
    {
        return Transaction::create(
            fromWalletId: 1,
            toWalletId: 2,
            fromAmount: '100.0000',
            toAmount: '25.0000',
            fromCurrency: Currency::PLN,
            toCurrency: Currency::EUR,
            spread: '0.5000',
            exchangeRate: '0.250000',
            requiresAntiFraudCheck: $requiresAntiFraudCheck,
        );
    }
}
