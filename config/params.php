<?php

declare(strict_types=1);

return [
    'rasuvaeff/yii3-webhooks-db' => [
        // one source of truth: both storages and the bundled migration read the
        // resulting names through WebhookDeliveryTableName / WebhookNonceTableName
        'deliveryTable' => 'webhook_deliveries',
        'nonceTable' => 'webhook_nonces',
        // prepended to both table names; set it once to keep every rasuvaeff
        // table out of the way of your application's own
        'table_prefix' => '',
    ],
];
