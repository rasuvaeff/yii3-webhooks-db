<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WebhooksDb\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Rasuvaeff\Yii3WebhooksDb\DbNonceStorage;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Yiisoft\Test\Support\Clock\StaticClock;

#[Test]
#[Covers(DbNonceStorage::class)]
final class DbNonceStorageTest
{
    public function addReturnsFalseWhenExecuteReturnsZero(): void
    {
        $command = new FakeCommand(executeResult: 0);
        $db = new FakeConnection(command: $command);

        $storage = new DbNonceStorage(
            db: $db,
            clock: new StaticClock(new DateTimeImmutable('2026-06-12 10:00:00')),
        );

        Assert::false($storage->add(nonce: 'test-nonce'));
    }

    public function rejectsInvalidTableName(): void
    {
        $db = new FakeConnection(command: new FakeCommand());

        try {
            new DbNonceStorage(
                db: $db,
                clock: new StaticClock(new DateTimeImmutable('2026-06-12 10:00:00')),
                table: 'webhook_nonces; DROP TABLE users',
            );
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Invalid table name "webhook_nonces; DROP TABLE users"');
        }
    }
}
