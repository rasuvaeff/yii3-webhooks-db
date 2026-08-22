<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WebhooksDb\Tests;

use InvalidArgumentException;
use Rasuvaeff\Yii3Webhooks\ClaimingDeliveryStorage;
use Rasuvaeff\Yii3WebhooksDb\DbWebhookDeliveryStorage;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(DbWebhookDeliveryStorage::class)]
final class DbWebhookDeliveryStorageTest
{
    public function rejectsInvalidTableName(): void
    {
        $db = new FakeConnection(command: new FakeCommand());

        try {
            new DbWebhookDeliveryStorage(
                db: $db,
                table: 'webhook_deliveries; DROP TABLE users',
            );
            Assert::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            Assert::string($e->getMessage())->contains('Invalid table name "webhook_deliveries; DROP TABLE users"');
        }
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
        $storage = new DbWebhookDeliveryStorage(db: new FakeConnection(command: new FakeCommand()));

        Assert::instanceOf($storage, ClaimingDeliveryStorage::class);
    }
}
