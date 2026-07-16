<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\RequestPayloadExceptionListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Throwable;

final class RequestPayloadExceptionListenerTest extends TestCase
{
    #[DataProvider('requestPayloadExceptionProvider')]
    public function testConvertsExpectedRequestPayloadExceptionToJsonResponse(Throwable $previous): void
    {
        $event = $this->createExceptionEvent(new BadRequestHttpException('Invalid request payload.', $previous));

        (new RequestPayloadExceptionListener())($event);

        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(400, $response->getStatusCode());
        self::assertJsonStringEqualsJsonString(
            '{"error":"Invalid request payload."}',
            (string) $response->getContent(),
        );
    }

    public function testLeavesUnrelatedBadRequestForSymfonyToHandle(): void
    {
        $exception = new BadRequestHttpException('Unrelated bad request.');
        $event = $this->createExceptionEvent($exception);

        (new RequestPayloadExceptionListener())($event);

        self::assertFalse($event->hasResponse());
        self::assertSame($exception, $event->getThrowable());
    }

    public static function requestPayloadExceptionProvider(): iterable
    {
        yield 'validation failure' => [
            new ValidationFailedException(null, new ConstraintViolationList()),
        ];
        yield 'malformed JSON' => [
            new NotEncodableValueException('Malformed JSON.'),
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
