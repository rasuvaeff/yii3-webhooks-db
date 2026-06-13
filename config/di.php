<?php

declare(strict_types=1);

use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Webhooks\NonceStorage;
use Rasuvaeff\Yii3Webhooks\WebhookDeliveryStorage;
use Rasuvaeff\Yii3WebhooksDb\DbNonceStorage;
use Rasuvaeff\Yii3WebhooksDb\DbWebhookDeliveryStorage;
use Yiisoft\Db\Connection\ConnectionInterface;

/** @var array $params */

return [
    WebhookDeliveryStorage::class => static function (ConnectionInterface $db) use ($params): DbWebhookDeliveryStorage {
        $config = $params['rasuvaeff/yii3-webhooks-db'] ?? [];

        return new DbWebhookDeliveryStorage(
            db: $db,
            table: $config['deliveryTable'] ?? 'webhook_deliveries',
        );
    },
    NonceStorage::class => static function (ConnectionInterface $db, ClockInterface $clock) use ($params): DbNonceStorage {
        $config = $params['rasuvaeff/yii3-webhooks-db'] ?? [];

        return new DbNonceStorage(
            db: $db,
            clock: $clock,
            table: $config['nonceTable'] ?? 'webhook_nonces',
        );
    },
];
