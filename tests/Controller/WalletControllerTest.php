<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\WalletController;
use App\Entity\Transaction;
use App\Entity\User;
use App\Entity\Wallet;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Repository\WalletRepositoryInterface;
use App\Service\DepositService;
use App\Service\TransferService;
use App\Service\WalletService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

#[AllowMockObjectsWithoutExpectations]
class WalletControllerTest extends TestCase
{
    private WalletService&MockObject $walletService;
    private WalletRepositoryInterface&MockObject $walletRepository;
    private TransferService&MockObject $transferService;
    private DepositService&MockObject $depositService;
    private WalletController $controller;

    protected function setUp(): void
    {
        $this->walletService = $this->createMock(WalletService::class);
        $this->walletRepository = $this->createMock(WalletRepositoryInterface::class);
        $this->transferService = $this->createMock(TransferService::class);
        $this->depositService = $this->createMock(DepositService::class);

        $this->controller = new WalletController(
            $this->walletService,
            $this->walletRepository,
            $this->transferService,
            $this->depositService,
        );
    }

    /**
     * @throws Throwable
     */
    public function testListReturnsWallets(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());
        $wallet1 = Wallet::create(1, Currency::PLN);
        $wallet2 = Wallet::create(1, Currency::EUR);

        $this->walletRepository
            ->expects(self::once())
            ->method('findByUserId')
            ->with(1)
            ->willReturn([$wallet1, $wallet2]);

        $response = $this->controller->list($user);

        self::assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('0.0000', $data[0]['balance']);
    }

    /**
     * @throws Throwable
     */
    public function testCreateWalletSuccessfully(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());
        $wallet = Wallet::create(1, Currency::USD);

        $this->walletService
            ->expects(self::once())
            ->method('createWallet')
            ->with(1, Currency::USD)
            ->willReturn($wallet);

        $request = new Request(content: json_encode(['currency' => 'USD'], JSON_THROW_ON_ERROR));
        $response = $this->controller->create($request, $user);

        self::assertSame(201, $response->getStatusCode());
    }

    /**
     * @throws Throwable
     */
    public function testCreateReturnsBadRequestWhenCurrencyMissing(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $request = new Request(content: json_encode([], JSON_THROW_ON_ERROR));
        $response = $this->controller->create($request, $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $data);
    }

    /**
     * @throws Throwable
     */
    public function testCreateReturnsBadRequestWhenCurrencyInvalid(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $request = new Request(content: json_encode(['currency' => 'INVALID'], JSON_THROW_ON_ERROR));
        $response = $this->controller->create($request, $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Invalid currency.', $data['error']);
    }

    /**
     * @throws Throwable
     */
    public function testTransferSuccessfully(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());
        $transaction = new Transaction(
            id: 42,
            fromWalletId: 1,
            toWalletId: 2,
            fromAmount: '100.0000',
            toAmount: '25.1234',
            fromCurrency: Currency::PLN,
            toCurrency: Currency::EUR,
            spread: '0.1260',
            exchangeRate: '0.250000',
            status: TransactionStatus::PENDING,
            requiresAntiFraudCheck: false,
            antiFraudCheckedAt: null,
            createdAt: new DateTimeImmutable(),
        );

        $this->transferService
            ->expects(self::once())
            ->method('transfer')
            ->with(1, 1, 2, '100.00')
            ->willReturn($transaction);

        $request = new Request(content: json_encode([
            'fromWalletId' => 1,
            'toWalletId' => 2,
            'amount' => '100.00',
        ], JSON_THROW_ON_ERROR));
        $response = $this->controller->transfer($request, $user);

        self::assertSame(201, $response->getStatusCode());
    }

    /**
     * @throws Throwable
     */
    public function testTransferReturnsBadRequestWhenFromWalletIdMissing(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $request = new Request(content: json_encode(['toWalletId' => 2, 'amount' => '100.00'], JSON_THROW_ON_ERROR));
        $response = $this->controller->transfer($request, $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $data);
    }

    /**
     * @throws Throwable
     */
    public function testTransferReturnsBadRequestWhenToWalletIdMissing(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $request = new Request(content: json_encode(['fromWalletId' => 1, 'amount' => '100.00'], JSON_THROW_ON_ERROR));
        $response = $this->controller->transfer($request, $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $data);
    }

    /**
     * @throws Throwable
     */
    public function testTransferReturnsBadRequestWhenAmountMissing(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $request = new Request(content: json_encode(['fromWalletId' => 1, 'toWalletId' => 2], JSON_THROW_ON_ERROR));
        $response = $this->controller->transfer($request, $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $data);
    }

    /**
     * @throws Throwable
     */
    #[DataProvider('invalidAmountProvider')]
    public function testTransferReturnsBadRequestWhenAmountInvalid(mixed $amount): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->transferService->expects(self::never())->method('transfer');

        $request = new Request(content: json_encode([
            'fromWalletId' => 1,
            'toWalletId' => 2,
            'amount' => $amount,
        ], JSON_THROW_ON_ERROR));
        $response = $this->controller->transfer($request, $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Amount must be a positive number.', $data['error']);
    }

    /**
     * @throws Throwable
     */
    #[DataProvider('validAmountProvider')]
    public function testDepositSuccessfully(string $amount): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());
        $wallet = Wallet::create(1, Currency::PLN);

        $this->depositService
            ->expects(self::once())
            ->method('deposit')
            ->with(1, 5, $amount)
            ->willReturn($wallet);

        $request = new Request(content: json_encode(['amount' => $amount], JSON_THROW_ON_ERROR));
        $response = $this->controller->deposit(5, $request, $user);

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @throws Throwable
     */
    public function testDepositReturnsBadRequestWhenAmountMissing(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $request = new Request(content: json_encode([], JSON_THROW_ON_ERROR));
        $response = $this->controller->deposit(5, $request, $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $data);
    }

    /**
     * @throws Throwable
     */
    #[DataProvider('invalidAmountProvider')]
    public function testDepositReturnsBadRequestWhenAmountInvalid(mixed $amount): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->depositService->expects(self::never())->method('deposit');

        $request = new Request(content: json_encode(['amount' => $amount], JSON_THROW_ON_ERROR));
        $response = $this->controller->deposit(5, $request, $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Amount must be a positive number.', $data['error']);
    }

    /**
     * @throws Throwable
     */
    public function testDepositReturnsBadRequestWhenAmountExceedsMax(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->depositService->expects(self::never())->method('deposit');

        $request = new Request(content: json_encode(['amount' => '10000.0001'], JSON_THROW_ON_ERROR));
        $response = $this->controller->deposit(5, $request, $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(sprintf('Amount cannot exceed %s.', DepositService::MAX_AMOUNT), $data['error']);
    }

    public static function validAmountProvider(): iterable
    {
        yield 'integer string' => ['1'];
        yield 'decimal string' => ['500.00'];
        yield 'smallest positive amount with supported precision' => ['0.0001'];
        yield 'maximum deposit amount' => ['10000.0000'];
    }

    public static function invalidAmountProvider(): iterable
    {
        yield 'JSON integer' => [1];
        yield 'JSON float' => [1.5];
        yield 'scientific notation' => ['1e5'];
        yield 'decimal comma' => ['1,5'];
        yield 'negative amount' => ['-1'];
        yield 'zero' => ['0'];
        yield 'zero with decimal places' => ['0.0000'];
        yield 'more than four decimal places' => ['1.00000'];
        yield 'empty string' => [''];
        yield 'leading whitespace' => [' 1'];
        yield 'explicit plus sign' => ['+1'];
        yield 'missing integer part' => ['.5'];
        yield 'missing fractional part' => ['1.'];
        yield 'array' => [[]];
        yield 'object' => [(object) ['value' => '1']];
    }
}
