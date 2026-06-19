<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WebhooksDb\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3WebhooksDb\DbWebhookDeliveryStorage;
use Yiisoft\Db\Connection\ConnectionInterface;

#[CoversClass(DbWebhookDeliveryStorage::class)]
final class DbWebhookDeliveryStorageTest extends TestCase
{
    #[Test]
    public function rejectsInvalidTableName(): void
    {
        $db = $this->createMock(ConnectionInterface::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid table name "webhook_deliveries; DROP TABLE users"');

        new DbWebhookDeliveryStorage(
            db: $db,
            table: 'webhook_deliveries; DROP TABLE users',
        );
    }
}
