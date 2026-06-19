<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WebhooksDb\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3WebhooksDb\DbNonceStorage;
use Yiisoft\Db\Command\CommandInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Test\Support\Clock\StaticClock;

#[CoversClass(DbNonceStorage::class)]
final class DbNonceStorageTest extends TestCase
{
    #[Test]
    public function addReturnsFalseWhenExecuteReturnsZero(): void
    {
        $command = $this->createMock(CommandInterface::class);
        $command->method('insert')->willReturnSelf();
        $command->method('execute')->willReturn(0);

        $db = $this->createMock(ConnectionInterface::class);
        $db->method('createCommand')->willReturn($command);

        $storage = new DbNonceStorage(
            db: $db,
            clock: new StaticClock(new DateTimeImmutable('2026-06-12 10:00:00')),
        );

        $this->assertFalse($storage->add(nonce: 'test-nonce'));
    }

    #[Test]
    public function rejectsInvalidTableName(): void
    {
        $db = $this->createMock(ConnectionInterface::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid table name "webhook_nonces; DROP TABLE users"');

        new DbNonceStorage(
            db: $db,
            clock: new StaticClock(new DateTimeImmutable('2026-06-12 10:00:00')),
            table: 'webhook_nonces; DROP TABLE users',
        );
    }
}
