<?php

declare(strict_types=1);

use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Webhooks\NonceStorage;
use Rasuvaeff\Yii3Webhooks\WebhookDeliveryStorage;
use Rasuvaeff\Yii3WebhooksDb\DbNonceStorage;
use Rasuvaeff\Yii3WebhooksDb\DbWebhookDeliveryStorage;
use Rasuvaeff\Yii3WebhooksDb\WebhookDeliveryTableName;
use Rasuvaeff\Yii3WebhooksDb\WebhookNonceTableName;
use Yiisoft\Db\Connection\ConnectionInterface;

/** @var array $params */

$config = $params['rasuvaeff/yii3-webhooks-db'] ?? [];
$prefix = (string) ($config['table_prefix'] ?? '');

return [
    // the migration resolves these by type through Injector::make(), so the
    // storages and the migration can never disagree about the tables
    WebhookDeliveryTableName::class => static fn (): WebhookDeliveryTableName => new WebhookDeliveryTableName(
        $prefix . ((string) ($config['deliveryTable'] ?? 'webhook_deliveries')),
    ),
    WebhookNonceTableName::class => static fn (): WebhookNonceTableName => new WebhookNonceTableName(
        $prefix . ((string) ($config['nonceTable'] ?? 'webhook_nonces')),
    ),
    WebhookDeliveryStorage::class => static fn (
        ConnectionInterface $db,
        WebhookDeliveryTableName $table,
    ): DbWebhookDeliveryStorage => new DbWebhookDeliveryStorage(db: $db, table: $table->value),
    NonceStorage::class => static fn (
        ConnectionInterface $db,
        ClockInterface $clock,
        WebhookNonceTableName $table,
    ): DbNonceStorage => new DbNonceStorage(db: $db, clock: $clock, table: $table->value),
];
