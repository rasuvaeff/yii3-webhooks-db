<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WebhooksDb\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3WebhooksDb\DbNonceStorage;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Yiisoft\Db\Command\CommandInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Test\Support\Clock\StaticClock;

use function Rasuvaeff\Understudy\verify;
use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(DbNonceStorage::class)]
final class DbNonceStorageTest
{
    public function addReturnsFalseWhenExecuteReturnsZero(): void
    {
        $command = Understudy::for(CommandInterface::class);
        when(fn() => $command->execute())->returns(0);

        $db = Understudy::for(ConnectionInterface::class);
        when(fn() => $db->createCommand())->returns($command);

        $storage = new DbNonceStorage(
            db: $db,
            clock: new StaticClock(new DateTimeImmutable('2026-06-12 10:00:00')),
        );

        Assert::false($storage->add(nonce: 'test-nonce'));
        verify(fn() => $command->insert(Arg::any(), Arg::any()), times: 1);
        verify(fn() => $command->execute(), times: 1);
    }

    public function rejectsInvalidTableName(): void
    {
        $db = Understudy::for(ConnectionInterface::class);

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

        Understudy::unused($db);
    }
}
