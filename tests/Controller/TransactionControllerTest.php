<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Transaction;
use App\Entity\User;
use App\Entity\UserToken;
use App\Entity\Wallet;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Repository\CompanyWalletRepositoryInterface;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\UserRepositoryInterface;
use App\Repository\UserTokenRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\Service\TransactionProcessorService;
use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[AllowMockObjectsWithoutExpectations]
final class TransactionControllerTest extends KernelTestCase
{
    private const string TOKEN = 'valid-token';

    private WalletRepositoryInterface&MockObject $walletRepository;
    private TransactionRepositoryInterface&MockObject $transactionRepository;
    private CompanyWalletRepositoryInterface&MockObject $companyWalletRepository;
    private Connection&MockObject $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->walletRepository = $this->createMock(WalletRepositoryInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $this->companyWalletRepository = $this->createMock(CompanyWalletRepositoryInterface::class);
        $this->connection = $this->createMock(Connection::class);

        self::getContainer()->set(TransactionProcessorService::class, new TransactionProcessorService(
            $this->walletRepository,
            $this->transactionRepository,
            $this->companyWalletRepository,
            $this->connection,
        ));
    }

    public function testAdminCompletesTransaction(): void
    {
        $this->authenticateAs(['ROLE_ADMIN']);
        $transaction = $this->makeTransaction();
        $fromWallet = new Wallet(1, 1, Currency::PLN, '400.0000', false, null, new DateTimeImmutable());
        $toWallet = new Wallet(2, 1, Currency::EUR, '100.0000', false, null, new DateTimeImmutable());

        $this->transactionRepository
            ->expects(self::once())
            ->method('findByIdForUpdate')
            ->with(42)
            ->willReturn($transaction);
        $this->walletRepository
            ->expects(self::exactly(2))
            ->method('findByIdForUpdate')
            ->willReturnMap([
                [1, $fromWallet],
                [2, $toWallet],
            ]);
        $this->expectTransaction();

        $response = $this->request('/api/transactions/42/complete');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame('completed', $this->decodeResponse($response)['status']);
    }

    public function testAdminRejectsTransaction(): void
    {
        $this->authenticateAs(['ROLE_ADMIN']);
        $transaction = $this->makeTransaction();
        $fromWallet = new Wallet(1, 1, Currency::PLN, '400.0000', false, null, new DateTimeImmutable());

        $this->transactionRepository
            ->expects(self::once())
            ->method('findByIdForUpdate')
            ->with(42)
            ->willReturn($transaction);
        $this->walletRepository
            ->expects(self::once())
            ->method('findByIdForUpdate')
            ->with(1)
            ->willReturn($fromWallet);
        $this->expectTransaction();

        $response = $this->request('/api/transactions/42/reject');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame('rejected', $this->decodeResponse($response)['status']);
    }

    #[DataProvider('transactionActionProvider')]
    public function testUserCannotProcessTransaction(string $path): void
    {
        $this->authenticateAs(['ROLE_USER']);
        $this->connection->expects(self::never())->method('transactional');
        $this->transactionRepository->expects(self::never())->method('findByIdForUpdate');

        $response = $this->request($path);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testUnauthenticatedRequestCannotProcessTransaction(): void
    {
        $this->connection->expects(self::never())->method('transactional');
        $this->transactionRepository->expects(self::never())->method('findByIdForUpdate');

        $response = $this->request('/api/transactions/42/complete', authenticated: false);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testReturnsNotFoundWhenTransactionDoesNotExist(): void
    {
        $this->authenticateAs(['ROLE_ADMIN']);
        $this->transactionRepository
            ->expects(self::once())
            ->method('findByIdForUpdate')
            ->with(99)
            ->willReturn(null);
        $this->expectTransaction();

        $response = $this->request('/api/transactions/99/complete');

        $this->assertErrorResponse($response, Response::HTTP_NOT_FOUND, 'Transaction 99 not found.');
    }

    public function testReturnsConflictWhenTransactionIsTerminal(): void
    {
        $this->authenticateAs(['ROLE_ADMIN']);
        $this->transactionRepository
            ->expects(self::once())
            ->method('findByIdForUpdate')
            ->with(42)
            ->willReturn($this->makeTransaction(TransactionStatus::COMPLETED));
        $this->walletRepository->expects(self::never())->method('findByIdForUpdate');
        $this->expectTransaction();

        $response = $this->request('/api/transactions/42/reject');

        $this->assertErrorResponse(
            $response,
            Response::HTTP_CONFLICT,
            'Transaction in status "completed" cannot be processed.',
        );
    }

    public static function transactionActionProvider(): iterable
    {
        yield 'complete' => ['/api/transactions/42/complete'];
        yield 'reject' => ['/api/transactions/42/reject'];
    }

    /** @param list<string> $roles */
    private function authenticateAs(array $roles): void
    {
        $user = new User(1, 'test@example.com', $roles, new DateTimeImmutable());
        $userToken = new UserToken(
            id: 1,
            userId: 1,
            token: self::TOKEN,
            expiresAt: new DateTimeImmutable('+1 hour'),
            createdAt: new DateTimeImmutable(),
        );

        $userTokenRepository = $this->createStub(UserTokenRepositoryInterface::class);
        $userTokenRepository->method('findByToken')->willReturn($userToken);
        $userRepository = $this->createStub(UserRepositoryInterface::class);
        $userRepository->method('findById')->willReturn($user);

        self::getContainer()->set(UserTokenRepositoryInterface::class, $userTokenRepository);
        self::getContainer()->set(UserRepositoryInterface::class, $userRepository);
    }

    private function request(string $path, bool $authenticated = true): Response
    {
        $server = ['HTTP_ACCEPT' => 'application/json'];
        if ($authenticated) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.self::TOKEN;
        }

        return self::$kernel->handle(Request::create($path, 'POST', server: $server));
    }

    /** @return array<string, mixed> */
    private function decodeResponse(Response $response): array
    {
        $content = $response->getContent();
        self::assertIsString($content);
        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    private function assertErrorResponse(Response $response, int $statusCode, string $message): void
    {
        self::assertSame($statusCode, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame(['error' => $message], $this->decodeResponse($response));
    }

    private function expectTransaction(): void
    {
        $this->connection
            ->expects(self::once())
            ->method('transactional')
            ->willReturnCallback(static fn (Closure $callback): mixed => $callback());
    }

    private function makeTransaction(TransactionStatus $status = TransactionStatus::PENDING): Transaction
    {
        return new Transaction(
            id: 42,
            fromWalletId: 1,
            toWalletId: 2,
            fromAmount: '100.0000',
            toAmount: '25.0000',
            fromCurrency: Currency::PLN,
            toCurrency: Currency::EUR,
            spread: '0.5000',
            exchangeRate: '0.250000',
            status: $status,
            requiresAntiFraudCheck: true,
            antiFraudCheckedAt: new DateTimeImmutable(),
            createdAt: new DateTimeImmutable('2026-01-15T10:00:00+00:00'),
        );
    }
}
