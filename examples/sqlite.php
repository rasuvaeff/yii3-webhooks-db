<?php

declare(strict_types=1);

use Rasuvaeff\Yii3Webhooks\WebhookDelivery;
use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;
use Rasuvaeff\Yii3Webhooks\WebhookEvent;
use Rasuvaeff\Yii3WebhooksDb\DbNonceStorage;
use Rasuvaeff\Yii3WebhooksDb\DbWebhookDeliveryStorage;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Sqlite\Connection;
use Yiisoft\Db\Sqlite\Driver;
use Yiisoft\Test\Support\Clock\StaticClock;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

require dirname(__DIR__) . '/vendor/autoload.php';

$db = new Connection(driver: new Driver(dsn: 'sqlite::memory:'), schemaCache: new SchemaCache(psrCache: new MemorySimpleCache()));
$db->open();
$db->createCommand(sql: 'CREATE TABLE webhook_deliveries (id VARCHAR(32) PRIMARY KEY, event_id VARCHAR(255) NOT NULL, event_type VARCHAR(255) NOT NULL, endpoint_url TEXT NOT NULL, status VARCHAR(32) NOT NULL, created_at VARCHAR(30) NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, last_attempt_at VARCHAR(30), last_error TEXT, claimed_at VARCHAR(30), claimed_by VARCHAR(32))')->execute();
$db->createCommand(sql: 'CREATE TABLE webhook_nonces (nonce VARCHAR(255) PRIMARY KEY, created_at VARCHAR(30) NOT NULL)')->execute();

$event = WebhookEvent::create(type: 'order.created', payload: '{"orderId":42}');
$endpoint = new WebhookEndpoint(url: 'https://partner.example/webhook', secret: 'whsec_example');
$delivery = WebhookDelivery::create(event: $event, endpoint: $endpoint);

$deliveries = new DbWebhookDeliveryStorage(db: $db);
$deliveries->save(delivery: $delivery);

$nonces = new DbNonceStorage(db: $db, clock: new StaticClock(now: new \DateTimeImmutable()));
$first = $nonces->add(nonce: 'signature-nonce');
$second = $nonces->add(nonce: 'signature-nonce');

echo 'pending=' . count($deliveries->findPending()) . PHP_EOL;
echo 'nonce first=' . ($first ? 'yes' : 'no') . ', second=' . ($second ? 'yes' : 'no') . PHP_EOL;

// Two workers polling the same table: the lease is what stops the receiver from
// seeing the same event twice. findPending() would hand the row to both.
$now = new \DateTimeImmutable();
$thresholds = [1 => $now->modify('-30 seconds')]; // WebhookRetryPolicy::readyThresholds() in real code

$workerA = $deliveries->claimReady(now: $now, readyThresholds: $thresholds, maxAttempts: 3);
$workerB = $deliveries->claimReady(now: $now, readyThresholds: $thresholds, maxAttempts: 3);

echo 'worker A claimed=' . count($workerA) . ', worker B claimed=' . count($workerB) . PHP_EOL;

// A retryable failure: record the attempt and hand the lease back, so the
// delivery waits out its backoff instead of the whole lease.
$deliveries->save(delivery: $workerA[0]->withAttempt(at: $now, error: 'HTTP 503'));
$deliveries->releaseClaim(delivery: $workerA[0]);

echo 'ready again now=' . count($deliveries->claimReady(now: $now, readyThresholds: $thresholds, maxAttempts: 3)) . PHP_EOL;
echo 'ready again in 30s=' . count($deliveries->claimReady(
    now: $now->modify('+30 seconds'),
    readyThresholds: [1 => $now],
    maxAttempts: 3,
)) . PHP_EOL;
