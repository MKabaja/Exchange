<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Transaction;
use App\Entity\Wallet;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Exception\InvalidTransferStateException;
use App\Exception\TransactionNotFoundException;
use App\Repository\CompanyWalletRepositoryInterface;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\Service\TransactionProcessorService;
use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class TransactionProcessorServiceTest extends TestCase
{
    private WalletRepositoryInterface&MockObject $walletRepository;
    private TransactionRepositoryInterface&MockObject $transactionRepository;
    private CompanyWalletRepositoryInterface&MockObject $companyWalletRepository;
    private Connection&MockObject $connection;
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
        $fromWallet->setBalance('400.0000');

        $toWallet = Wallet::create(1, Currency::EUR);
        $toWallet->setBalance('100.0000');

        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);

        $this->walletRepository
            ->method('findByIdForUpdate')
            ->willReturnMap([
                [1, $fromWallet],
                [2, $toWallet],
            ]);

        $this->expectTransaction();

        $this->transactionProcessorService->complete(42);

        self::assertSame('400.0000', $fromWallet->getBalance());
        self::assertSame('125.0000', $toWallet->getBalance());
    }

    public function testCompleteCreditsCompanyWalletWithSpreadInDestinationCurrency(): void
    {
        $fromWallet = Wallet::create(1, Currency::PLN);
        $fromWallet->setBalance('400.0000');

        $toWallet = Wallet::create(1, Currency::EUR);
        $toWallet->setBalance('100.0000');

        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);

        $this->walletRepository
            ->method('findByIdForUpdate')
            ->willReturnMap([
                [1, $fromWallet],
                [2, $toWallet],
            ]);

        $this->companyWalletRepository
            ->expects(self::once())
            ->method('addToBalance')
            ->with(Currency::EUR, '0.5000');

        $this->expectTransaction();

        $this->transactionProcessorService->complete(42);
    }

    public function testCompleteUpdatesActivityAndPersistsBothWallets(): void
    {
        $fromWallet = Wallet::create(1, Currency::PLN);
        $fromWallet->setBalance('400.0000');

        $toWallet = Wallet::create(1, Currency::EUR);
        $toWallet->setBalance('100.0000');

        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);

        $this->walletRepository
            ->method('findByIdForUpdate')
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

        $processedTransaction = $this->transactionProcessorService->complete(42);

        self::assertNotNull($fromWallet->getLastActivityAt());
        self::assertNotNull($toWallet->getLastActivityAt());
        self::assertContains($fromWallet, $savedWallets);
        self::assertContains($toWallet, $savedWallets);
        self::assertSame(TransactionStatus::COMPLETED, $transaction->getStatus());
        self::assertSame($transaction, $processedTransaction);
        self::assertNull($transaction->getAntiFraudCheckedAt());
    }

    public function testCompleteSetsAntiFraudCheckedAtWhenRequired(): void
    {
        $fromWallet = Wallet::create(1, Currency::PLN);
        $fromWallet->setBalance('400.0000');

        $toWallet = Wallet::create(1, Currency::EUR);

        $transaction = $this->makeTransaction(requiresAntiFraudCheck: true);

        $this->walletRepository
            ->method('findByIdForUpdate')
            ->willReturnMap([
                [1, $fromWallet],
                [2, $toWallet],
            ]);

        $this->companyWalletRepository
            ->expects(self::once())
            ->method('addToBalance')
            ->with(Currency::EUR, '0.5000');

        $this->expectTransaction();

        $this->transactionProcessorService->complete(42);

        self::assertSame(TransactionStatus::COMPLETED, $transaction->getStatus());
        self::assertNotNull($transaction->getAntiFraudCheckedAt());
    }

    public function testCompleteRejectsWithoutMutatingDestinationWhenSourceWalletNotFound(): void
    {
        $toWallet = Wallet::create(1, Currency::EUR);
        $toWallet->setBalance('25.0000');

        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);

        $this->walletRepository
            ->method('findByIdForUpdate')
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

        $this->transactionProcessorService->complete(42);

        self::assertSame('25.0000', $toWallet->getBalance());
        self::assertNull($toWallet->getLastActivityAt());
        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
    }

    public function testCompleteRefundsSourceWhenDestinationWalletNotFound(): void
    {
        $fromWallet = Wallet::create(1, Currency::PLN);
        $fromWallet->setBalance('400.0000');

        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);

        $this->walletRepository
            ->method('findByIdForUpdate')
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

        $this->transactionProcessorService->complete(42);

        self::assertSame('500.0000', $fromWallet->getBalance());
        self::assertNotNull($fromWallet->getLastActivityAt());
        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
    }

    public function testRejectRefundsSourceWithoutMutatingDestinationOrCompanyWallet(): void
    {
        $fromWallet = Wallet::create(1, Currency::PLN);
        $fromWallet->setBalance('400.0000');

        $toWallet = Wallet::create(1, Currency::EUR);
        $toWallet->setBalance('25.0000');

        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);

        $this->walletRepository
            ->expects(self::once())
            ->method('findByIdForUpdate')
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

        $this->transactionProcessorService->reject(42);

        self::assertSame('500.0000', $fromWallet->getBalance());
        self::assertSame('25.0000', $toWallet->getBalance());
        self::assertNotNull($fromWallet->getLastActivityAt());
        self::assertNull($toWallet->getLastActivityAt());
        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
        self::assertNull($transaction->getAntiFraudCheckedAt());
    }

    public function testRejectSetsAntiFraudCheckedAtWhenRequired(): void
    {
        $fromWallet = Wallet::create(1, Currency::PLN);
        $fromWallet->setBalance('400.0000');

        $transaction = $this->makeTransaction(requiresAntiFraudCheck: true);

        $this->walletRepository
            ->method('findByIdForUpdate')
            ->willReturn($fromWallet);

        $this->companyWalletRepository->expects(self::never())->method('addToBalance');
        $this->expectTransaction();

        $this->transactionProcessorService->reject(42);

        self::assertSame('500.0000', $fromWallet->getBalance());
        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
        self::assertNotNull($transaction->getAntiFraudCheckedAt());
    }

    public function testRejectChangesStatusWithoutSavingWalletWhenSourceWalletNotFound(): void
    {
        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);

        $this->walletRepository
            ->expects(self::once())
            ->method('findByIdForUpdate')
            ->with(1)
            ->willReturn(null);
        $this->walletRepository->expects(self::never())->method('save');
        $this->companyWalletRepository->expects(self::never())->method('addToBalance');
        $this->transactionRepository
            ->expects(self::once())
            ->method('save')
            ->with($transaction);

        $this->expectTransaction();

        $this->transactionProcessorService->reject(42);

        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
    }

    #[DataProvider('terminalStatuses')]
    public function testCompleteThrowsForTerminalStatusWithoutSideEffects(TransactionStatus $status): void
    {
        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);
        $transaction->setStatus($status);

        $this->walletRepository->expects(self::never())->method('findByIdForUpdate');
        $this->walletRepository->expects(self::never())->method('save');
        $this->companyWalletRepository->expects(self::never())->method('addToBalance');
        $this->transactionRepository->expects(self::never())->method('save');
        $this->expectTransaction();

        $this->expectException(InvalidTransferStateException::class);
        $this->expectExceptionMessage(sprintf('Transaction in status "%s" cannot be processed.', $status->value));

        $this->transactionProcessorService->complete(42);
    }

    #[DataProvider('terminalStatuses')]
    public function testRejectThrowsForTerminalStatusWithoutSideEffects(TransactionStatus $status): void
    {
        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);
        $transaction->setStatus($status);

        $this->walletRepository->expects(self::never())->method('findByIdForUpdate');
        $this->walletRepository->expects(self::never())->method('save');
        $this->companyWalletRepository->expects(self::never())->method('addToBalance');
        $this->transactionRepository->expects(self::never())->method('save');
        $this->expectTransaction();

        $this->expectException(InvalidTransferStateException::class);
        $this->expectExceptionMessage(sprintf('Transaction in status "%s" cannot be processed.', $status->value));

        $this->transactionProcessorService->reject(42);
    }

    public function testCompleteThrowsWhenTransactionDoesNotExistWithoutSideEffects(): void
    {
        $this->transactionRepository
            ->expects(self::once())
            ->method('findByIdForUpdate')
            ->with(99)
            ->willReturn(null);
        $this->walletRepository->expects(self::never())->method('findByIdForUpdate');
        $this->walletRepository->expects(self::never())->method('save');
        $this->companyWalletRepository->expects(self::never())->method('addToBalance');
        $this->transactionRepository->expects(self::never())->method('save');
        $this->expectTransaction();

        $this->expectException(TransactionNotFoundException::class);
        $this->expectExceptionMessage('Transaction 99 not found.');

        $this->transactionProcessorService->complete(99);
    }

    public function testCompleteLocksTransactionInsideDatabaseTransactionBeforeCheckingStatus(): void
    {
        $transaction = new Transaction(
            id: 42,
            fromWalletId: 1,
            toWalletId: 2,
            fromAmount: '100.0000',
            toAmount: '25.0000',
            fromCurrency: Currency::PLN,
            toCurrency: Currency::EUR,
            spread: '0.5000',
            exchangeRate: '0.250000',
            status: TransactionStatus::COMPLETED,
            requiresAntiFraudCheck: false,
            antiFraudCheckedAt: null,
            createdAt: new DateTimeImmutable(),
        );
        $insideDatabaseTransaction = false;

        $this->connection
            ->expects(self::once())
            ->method('transactional')
            ->willReturnCallback(static function (Closure $callback) use (&$insideDatabaseTransaction): mixed {
                $insideDatabaseTransaction = true;

                try {
                    return $callback();
                } finally {
                    $insideDatabaseTransaction = false;
                }
            });
        $this->transactionRepository
            ->expects(self::once())
            ->method('findByIdForUpdate')
            ->with(42)
            ->willReturnCallback(static function () use (&$insideDatabaseTransaction, $transaction): Transaction {
                self::assertTrue($insideDatabaseTransaction);

                return $transaction;
            });
        $this->walletRepository->expects(self::never())->method('findByIdForUpdate');

        $this->expectException(InvalidTransferStateException::class);

        $this->transactionProcessorService->complete(42);
    }

    public function testCompleteLocksWalletsInAscendingIdOrder(): void
    {
        $fromWallet = new Wallet(2, 1, Currency::PLN, '400.0000', false, null, new DateTimeImmutable());
        $toWallet = new Wallet(1, 1, Currency::EUR, '100.0000', false, null, new DateTimeImmutable());
        $transaction = $this->makeTransaction(
            requiresAntiFraudCheck: false,
            fromWalletId: 2,
            toWalletId: 1,
        );
        $lockedWalletIds = [];

        $this->walletRepository
            ->expects(self::exactly(2))
            ->method('findByIdForUpdate')
            ->willReturnCallback(static function (int $walletId) use (&$lockedWalletIds, $fromWallet, $toWallet): Wallet {
                $lockedWalletIds[] = $walletId;

                return 1 === $walletId ? $toWallet : $fromWallet;
            });
        $this->expectTransaction();

        $this->transactionProcessorService->complete(42);

        self::assertSame([1, 2], $lockedWalletIds);
        self::assertSame(TransactionStatus::COMPLETED, $transaction->getStatus());
    }

    public function testSecondCompleteDoesNotCreditBalancesAgain(): void
    {
        $fromWallet = new Wallet(1, 1, Currency::PLN, '400.0000', false, null, new DateTimeImmutable());
        $toWallet = new Wallet(2, 1, Currency::EUR, '100.0000', false, null, new DateTimeImmutable());
        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);

        $this->walletRepository
            ->expects(self::exactly(2))
            ->method('findByIdForUpdate')
            ->willReturnMap([
                [1, $fromWallet],
                [2, $toWallet],
            ]);
        $this->walletRepository->expects(self::exactly(2))->method('save');
        $this->companyWalletRepository->expects(self::once())->method('addToBalance');
        $this->transactionRepository->expects(self::once())->method('save')->with($transaction);
        $this->connection
            ->expects(self::exactly(2))
            ->method('transactional')
            ->willReturnCallback(static fn (Closure $callback): mixed => $callback());

        $this->transactionProcessorService->complete(42);

        try {
            $this->transactionProcessorService->complete(42);
            self::fail('Expected second completion to be rejected.');
        } catch (InvalidTransferStateException) {
        }

        self::assertSame('125.0000', $toWallet->getBalance());
        self::assertSame(TransactionStatus::COMPLETED, $transaction->getStatus());
    }

    public function testRejectAfterCompleteDoesNotRefundSource(): void
    {
        $fromWallet = new Wallet(1, 1, Currency::PLN, '400.0000', false, null, new DateTimeImmutable());
        $toWallet = new Wallet(2, 1, Currency::EUR, '100.0000', false, null, new DateTimeImmutable());
        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);

        $this->walletRepository
            ->expects(self::exactly(2))
            ->method('findByIdForUpdate')
            ->willReturnMap([
                [1, $fromWallet],
                [2, $toWallet],
            ]);
        $this->connection
            ->expects(self::exactly(2))
            ->method('transactional')
            ->willReturnCallback(static fn (Closure $callback): mixed => $callback());

        $this->transactionProcessorService->complete(42);

        try {
            $this->transactionProcessorService->reject(42);
            self::fail('Expected rejection after completion to be rejected.');
        } catch (InvalidTransferStateException) {
        }

        self::assertSame('400.0000', $fromWallet->getBalance());
        self::assertSame('125.0000', $toWallet->getBalance());
        self::assertSame(TransactionStatus::COMPLETED, $transaction->getStatus());
    }

    /**
     * @return iterable<string, array{TransactionStatus}>
     */
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

    private function makeTransaction(
        bool $requiresAntiFraudCheck,
        int $fromWalletId = 1,
        int $toWalletId = 2,
    ): Transaction {
        $transaction = new Transaction(
            id: 42,
            fromWalletId: $fromWalletId,
            toWalletId: $toWalletId,
            fromAmount: '100.0000',
            toAmount: '25.0000',
            fromCurrency: Currency::PLN,
            toCurrency: Currency::EUR,
            spread: '0.5000',
            exchangeRate: '0.250000',
            status: $requiresAntiFraudCheck ? TransactionStatus::FRAUD_REVIEW : TransactionStatus::PENDING,
            requiresAntiFraudCheck: $requiresAntiFraudCheck,
            antiFraudCheckedAt: null,
            createdAt: new DateTimeImmutable(),
        );

        $this->transactionRepository
            ->method('findByIdForUpdate')
            ->willReturn($transaction);

        return $transaction;
    }
}
