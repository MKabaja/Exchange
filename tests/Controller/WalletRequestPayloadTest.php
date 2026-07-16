<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Entity\UserToken;
use App\Repository\UserRepositoryInterface;
use App\Repository\UserTokenRepositoryInterface;
use App\Service\DepositService;
use App\Service\TransferService;
use App\Service\WalletService;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class WalletRequestPayloadTest extends KernelTestCase
{
    private const string TOKEN = 'valid-token';

    private WalletService&MockObject $walletService;
    private TransferService&MockObject $transferService;
    private DepositService&MockObject $depositService;

    protected function setUp(): void
    {
        self::bootKernel();

        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());
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

        $this->walletService = $this->createMock(WalletService::class);
        $this->walletService->expects(self::never())->method('createWallet');
        $this->transferService = $this->createMock(TransferService::class);
        $this->transferService->expects(self::never())->method('transfer');
        $this->depositService = $this->createMock(DepositService::class);
        $this->depositService->expects(self::never())->method('deposit');

        $container = self::getContainer();
        $container->set(UserTokenRepositoryInterface::class, $userTokenRepository);
        $container->set(UserRepositoryInterface::class, $userRepository);
        $container->set(WalletService::class, $this->walletService);
        $container->set(TransferService::class, $this->transferService);
        $container->set(DepositService::class, $this->depositService);
    }

    public function testRejectsMissingRequiredField(): void
    {
        $response = $this->request('/api/wallets/transfer', '{"fromWalletId":1,"toWalletId":2}');

        $this->assertBadRequest($response);
    }

    public function testRejectsWrongWalletIdType(): void
    {
        $response = $this->request(
            '/api/wallets/transfer',
            '{"fromWalletId":"1","toWalletId":2,"amount":"100.00"}',
        );

        $this->assertBadRequest($response);
    }

    public function testRejectsJsonNumberAmount(): void
    {
        $response = $this->request(
            '/api/wallets/transfer',
            '{"fromWalletId":1,"toWalletId":2,"amount":100}',
        );

        $this->assertBadRequest($response);
    }

    #[DataProvider('invalidAmountProvider')]
    public function testRejectsInvalidDecimalString(string $amount): void
    {
        $response = $this->request(
            '/api/wallets/transfer',
            json_encode([
                'fromWalletId' => 1,
                'toWalletId' => 2,
                'amount' => $amount,
            ], JSON_THROW_ON_ERROR),
        );

        $data = $this->assertBadRequest($response);
        self::assertSame('Amount must be a positive number.', $data['error']);
    }

    public function testRejectsDepositAboveMaximum(): void
    {
        $response = $this->request('/api/wallets/5/deposit', '{"amount":"10000.0001"}');

        $data = $this->assertBadRequest($response);
        self::assertSame('Amount cannot exceed 10000.', $data['error']);
    }

    public function testRejectsInvalidCurrency(): void
    {
        $response = $this->request('/api/wallets', '{"currency":"INVALID"}');

        $this->assertBadRequest($response);
    }

    public function testRejectsMalformedJson(): void
    {
        $response = $this->request('/api/wallets', '{');

        $this->assertBadRequest($response);
    }

    public static function invalidAmountProvider(): iterable
    {
        yield 'scientific notation' => ['1e5'];
        yield 'decimal comma' => ['1,5'];
        yield 'negative amount' => ['-1'];
        yield 'zero' => ['0'];
        yield 'more than four decimal places' => ['1.00000'];
    }

    private function request(string $path, string $content): Response
    {
        $request = Request::create(
            $path,
            'POST',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
            ],
            content: $content,
        );

        return self::$kernel->handle($request);
    }

    /** @return array{error: string} */
    private function assertBadRequest(Response $response): array
    {
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));

        $content = $response->getContent();
        self::assertIsString($content);
        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertArrayHasKey('error', $data);
        self::assertIsString($data['error']);
        self::assertNotSame('', $data['error']);

        return $data;
    }
}
