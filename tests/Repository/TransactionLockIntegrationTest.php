<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;

final class TransactionLockIntegrationTest extends TestCase
{
    public function testForUpdatePreventsAConcurrentReaderFromSeeingStaleTransactionStatus(): void
    {
        $databaseUrl = $_SERVER['DATABASE_URL'] ?? null;
        self::assertIsString($databaseUrl);

        $connectionParameters = new DsnParser(['mysql' => 'pdo_mysql'])->parse($databaseUrl);
        $firstConnection = DriverManager::getConnection($connectionParameters);
        $secondConnection = DriverManager::getConnection($connectionParameters);
        $tableName = sprintf('transaction_lock_test_%s', bin2hex(random_bytes(6)));
        $table = $firstConnection->quoteIdentifier($tableName);

        try {
            $firstConnection->executeStatement(sprintf(
                'CREATE TABLE %s (id INT NOT NULL PRIMARY KEY, status VARCHAR(20) NOT NULL) ENGINE=InnoDB',
                $table,
            ));
            $firstConnection->insert($tableName, ['id' => 1, 'status' => 'PENDING']);

            $firstConnection->beginTransaction();
            self::assertSame(
                'PENDING',
                $firstConnection->fetchOne(sprintf('SELECT status FROM %s WHERE id = 1 FOR UPDATE', $table)),
            );

            $secondConnection->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
            $secondConnection->beginTransaction();

            try {
                $secondConnection->fetchOne(sprintf('SELECT status FROM %s WHERE id = 1 FOR UPDATE', $table));
                self::fail('The concurrent reader should wait for the transaction lock.');
            } catch (LockWaitTimeoutException) {
            } finally {
                $secondConnection->rollBack();
            }

            $firstConnection->update($tableName, ['status' => 'COMPLETED'], ['id' => 1]);
            $firstConnection->commit();

            $secondConnection->beginTransaction();
            self::assertSame(
                'COMPLETED',
                $secondConnection->fetchOne(sprintf('SELECT status FROM %s WHERE id = 1 FOR UPDATE', $table)),
            );
            $secondConnection->commit();
        } finally {
            $this->rollBackIfActive($secondConnection);
            $this->rollBackIfActive($firstConnection);
            $firstConnection->executeStatement(sprintf('DROP TABLE IF EXISTS %s', $table));
            $secondConnection->close();
            $firstConnection->close();
        }
    }

    private function rollBackIfActive(Connection $connection): void
    {
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
    }
}
