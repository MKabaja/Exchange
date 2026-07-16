<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\EventListener\ApiExceptionListener;
use App\Exception\ApiException;
use App\Exception\InsufficientFundsException;
use App\Exception\InvalidTransferStateException;
use App\Exception\TransactionNotFoundException;
use App\Exception\WalletAlreadyExistsException;
use App\Exception\WalletBlockedException;
use App\Exception\WalletHasPendingTransactionsException;
use App\Exception\WalletNotEmptyException;
use App\Exception\WalletNotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

final class ApiExceptionListenerTest extends TestCase
{
    #[DataProvider('apiExceptionProvider')]
    public function testConvertsApiExceptionToJsonResponse(
        ApiException $exception,
        int $expectedStatusCode,
        string $expectedMessage,
    ): void {
        $event = $this->createExceptionEvent($exception);

        (new ApiExceptionListener())($event);

        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame($expectedStatusCode, $response->getStatusCode());

        $content = $response->getContent();
        self::assertIsString($content);
        self::assertJsonStringEqualsJsonString(
            json_encode(['error' => $expectedMessage], JSON_THROW_ON_ERROR),
            $content,
        );
    }

    public function testLeavesUnexpectedExceptionForSymfonyToHandle(): void
    {
        $exception = new RuntimeException('Sensitive internal details.');
        $event = $this->createExceptionEvent($exception);

        (new ApiExceptionListener())($event);

        self::assertFalse($event->hasResponse());
        self::assertSame($exception, $event->getThrowable());
    }

    public static function apiExceptionProvider(): iterable
    {
        yield 'wallet not found' => [
            new WalletNotFoundException(99),
            404,
            'Wallet 99 not found.',
        ];
        yield 'transaction not found' => [
            new TransactionNotFoundException(42),
            404,
            'Transaction 42 not found.',
        ];
        yield 'insufficient funds' => [
            new InsufficientFundsException(),
            422,
            'Insufficient funds.',
        ];
        yield 'wallet blocked' => [
            new WalletBlockedException(5),
            422,
            'Wallet 5 is blocked.',
        ];
        yield 'wallet already exists' => [
            new WalletAlreadyExistsException(1, Currency::PLN),
            409,
            'Wallet for user 1 in currency PLN already exists.',
        ];
        yield 'wallet not empty' => [
            new WalletNotEmptyException(5),
            409,
            'Wallet 5 has non-zero balance.',
        ];
        yield 'wallet has pending transactions' => [
            new WalletHasPendingTransactionsException(5),
            409,
            'Wallet 5 has pending transactions.',
        ];
        yield 'invalid transfer state' => [
            new InvalidTransferStateException(TransactionStatus::COMPLETED),
            409,
            'Transaction in status "completed" cannot be processed.',
        ];
    }

    private function createExceptionEvent(Throwable $exception): ExceptionEvent
    {
        return new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            new Request(),
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        );
    }
}
