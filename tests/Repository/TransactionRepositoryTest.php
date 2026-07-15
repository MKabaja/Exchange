<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\TransactionRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class TransactionRepositoryTest extends TestCase
{
    public function testFindByIdForUpdateAddsWriteLockToQuery(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MariaDBPlatform());
        $connection->method('createQueryBuilder')->willReturn(new QueryBuilder($connection));
        $connection
            ->expects(self::once())
            ->method('fetchAssociative')
            ->with(
                self::callback(static fn (string $sql): bool => str_ends_with($sql, ' FOR UPDATE')),
                ['id' => 42],
            )
            ->willReturn(false);

        $repository = new TransactionRepository($connection);

        self::assertNull($repository->findByIdForUpdate(42));
    }
}
