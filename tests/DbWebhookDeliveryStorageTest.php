<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WebhooksDb\Tests;

use InvalidArgumentException;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3Webhooks\ClaimingDeliveryStorage;
use Rasuvaeff\Yii3WebhooksDb\DbWebhookDeliveryStorage;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Yiisoft\Db\Connection\ConnectionInterface;

#[Test]
#[Covers(DbWebhookDeliveryStorage::class)]
final class DbWebhookDeliveryStorageTest
{
    public function rejectsInvalidTableName(): void
    {
        $db = Understudy::for(ConnectionInterface::class);

        try {
            new DbWebhookDeliveryStorage(
                db: $db,
                table: 'webhook_deliveries; DROP TABLE users',
            );
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Invalid table name "webhook_deliveries; DROP TABLE users"');
        }

        Understudy::unused($db);
    }

    /**
     * The core takes the claiming path only for a storage that declares
     * `ClaimingDeliveryStorage`; it detects that with `instanceof` and nothing
     * else. Having the two methods is not enough — drop the clause and every
     * worker silently falls back to `findPending()`, which hands the same
     * delivery to all of them.
     */
    public function declaresTheClaimingContractTheCoreDetects(): void
    {
        $db = Understudy::for(ConnectionInterface::class);
        $storage = new DbWebhookDeliveryStorage(db: $db);

        Assert::instanceOf($storage, ClaimingDeliveryStorage::class);
        Understudy::unused($db);
    }
}
