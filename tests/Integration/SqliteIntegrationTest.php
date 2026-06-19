<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WebhooksDb\Tests\Integration;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Webhooks\WebhookDelivery;
use Rasuvaeff\Yii3Webhooks\WebhookDeliveryStatus;
use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;
use Rasuvaeff\Yii3Webhooks\WebhookEvent;
use Rasuvaeff\Yii3WebhooksDb\DbNonceStorage;
use Rasuvaeff\Yii3WebhooksDb\DbWebhookDeliveryStorage;
use UnexpectedValueException;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\Clock\StaticClock;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

#[CoversClass(DbWebhookDeliveryStorage::class)]
#[CoversClass(DbNonceStorage::class)]
final class SqliteIntegrationTest extends TestCase
{
    private ConnectionInterface $db;

    #[\Override]
    protected function setUp(): void
    {
        $driver = new SqliteDriver(dsn: 'sqlite::memory:');
        $schemaCache = new SchemaCache(psrCache: new MemorySimpleCache());
        $this->db = new SqliteConnection(driver: $driver, schemaCache: $schemaCache);
        $this->db->open();
        $this->createTables();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->db->close();
    }

    #[Test]
    public function nonceAddIsAtomicAndRejectsDuplicate(): void
    {
        $storage = new DbNonceStorage(db: $this->db, clock: $this->fixedClock());

        $this->assertTrue($storage->add(nonce: 'nonce-1'));
        $this->assertFalse($storage->add(nonce: 'nonce-1'));
        $this->assertTrue($storage->has(nonce: 'nonce-1'));
    }

    #[Test]
    public function deleteOlderThanRemovesOldNonces(): void
    {
        $this->db->createCommand()->insert(
            table: 'webhook_nonces',
            columns: ['nonce' => 'old', 'created_at' => '2026-06-01 00:00:00'],
        )->execute();
        $this->db->createCommand()->insert(
            table: 'webhook_nonces',
            columns: ['nonce' => 'new', 'created_at' => '2026-06-12 00:00:00'],
        )->execute();

        $storage = new DbNonceStorage(db: $this->db, clock: $this->fixedClock());
        $deleted = $storage->deleteOlderThan(threshold: new DateTimeImmutable('2026-06-10 00:00:00'));

        $this->assertSame(1, $deleted);
        $this->assertFalse($storage->has(nonce: 'old'));
        $this->assertTrue($storage->has(nonce: 'new'));
    }

    #[Test]
    public function savesAndLoadsDelivery(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $delivery = $this->delivery();

        $storage->save(delivery: $delivery);

        $loaded = $storage->getById(id: $delivery->getId());
        $this->assertNotNull($loaded);
        $this->assertSame($delivery->getId(), $loaded->getId());
        $this->assertSame('order.created', $loaded->getEventType());
        $this->assertSame('https://partner.example/webhook', $loaded->getEndpointUrl());
        $this->assertSame(WebhookDeliveryStatus::Pending, $loaded->getStatus());
        $this->assertSame(0, $loaded->getAttempts());
        $this->assertNull($loaded->getLastAttemptAt());
        $this->assertNull($loaded->getLastError());
    }

    #[Test]
    public function findPendingReturnsOnlyPendingDeliveries(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $pending = $this->delivery(id: 'pending-delivery');
        $delivered = $this->delivery(id: 'delivered-delivery');

        $storage->save(delivery: $pending);
        $storage->save(delivery: $delivered->withStatus(status: WebhookDeliveryStatus::Delivered));

        $result = $storage->findPending(limit: 10);

        $this->assertCount(1, $result);
        $this->assertSame('pending-delivery', $result[0]->getId());
    }

    #[Test]
    public function markDeliveredUpdatesStatusFromPending(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $delivery = $this->delivery();

        $storage->save(delivery: $delivery);
        $storage->markDelivered(delivery: $delivery);

        $loaded = $storage->getById(id: $delivery->getId());
        $this->assertNotNull($loaded);
        $this->assertSame(WebhookDeliveryStatus::Delivered, $loaded->getStatus());
    }

    #[Test]
    public function markFailedUpdatesStatusFromPending(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $delivery = $this->delivery();

        $storage->save(delivery: $delivery);
        $storage->markFailed(delivery: $delivery);

        $loaded = $storage->getById(id: $delivery->getId());
        $this->assertNotNull($loaded);
        $this->assertSame(WebhookDeliveryStatus::Failed, $loaded->getStatus());
    }

    #[Test]
    public function markDeliveredIgnoresAlreadyProcessedDelivery(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $delivery = $this->delivery();

        $storage->save(delivery: $delivery->withStatus(status: WebhookDeliveryStatus::Failed));
        $storage->markDelivered(delivery: $delivery);

        $loaded = $storage->getById(id: $delivery->getId());
        $this->assertNotNull($loaded);
        $this->assertSame(WebhookDeliveryStatus::Failed, $loaded->getStatus());
    }

    #[Test]
    public function markFailedIgnoresAlreadyProcessedDelivery(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $delivery = $this->delivery();

        $storage->save(delivery: $delivery->withStatus(status: WebhookDeliveryStatus::Delivered));
        $storage->markFailed(delivery: $delivery);

        $loaded = $storage->getById(id: $delivery->getId());
        $this->assertNotNull($loaded);
        $this->assertSame(WebhookDeliveryStatus::Delivered, $loaded->getStatus());
    }

    #[Test]
    public function markDeliveredOnlyAffectsSpecifiedDelivery(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $storage->save(delivery: $this->delivery(id: 'd1', createdAt: '2026-06-12 08:00:00'));
        $storage->save(delivery: $this->delivery(id: 'd2', createdAt: '2026-06-12 09:00:00'));

        $storage->markDelivered(delivery: $this->delivery(id: 'd1'));

        $this->assertSame(WebhookDeliveryStatus::Delivered, $storage->getById(id: 'd1')?->getStatus());
        $this->assertSame(WebhookDeliveryStatus::Pending, $storage->getById(id: 'd2')?->getStatus());
    }

    #[Test]
    public function markFailedOnlyAffectsSpecifiedDelivery(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $storage->save(delivery: $this->delivery(id: 'd1', createdAt: '2026-06-12 08:00:00'));
        $storage->save(delivery: $this->delivery(id: 'd2', createdAt: '2026-06-12 09:00:00'));

        $storage->markFailed(delivery: $this->delivery(id: 'd1'));

        $this->assertSame(WebhookDeliveryStatus::Failed, $storage->getById(id: 'd1')?->getStatus());
        $this->assertSame(WebhookDeliveryStatus::Pending, $storage->getById(id: 'd2')?->getStatus());
    }

    #[Test]
    public function findPendingOrdersByCreatedAtAscending(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $storage->save(delivery: $this->delivery(id: 'later', createdAt: '2026-06-12 12:00:00'));
        $storage->save(delivery: $this->delivery(id: 'earlier', createdAt: '2026-06-12 08:00:00'));
        $storage->save(delivery: $this->delivery(id: 'middle', createdAt: '2026-06-12 10:00:00'));

        $result = $storage->findPending(limit: 10);

        $this->assertCount(3, $result);
        $this->assertSame('earlier', $result[0]->getId());
        $this->assertSame('middle', $result[1]->getId());
        $this->assertSame('later', $result[2]->getId());
    }

    #[Test]
    public function findPendingRespectsLimit(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $storage->save(delivery: $this->delivery(id: 'd1', createdAt: '2026-06-12 08:00:00'));
        $storage->save(delivery: $this->delivery(id: 'd2', createdAt: '2026-06-12 09:00:00'));
        $storage->save(delivery: $this->delivery(id: 'd3', createdAt: '2026-06-12 10:00:00'));

        $result = $storage->findPending(limit: 2);

        $this->assertCount(2, $result);
        $this->assertSame('d1', $result[0]->getId());
        $this->assertSame('d2', $result[1]->getId());
    }

    #[Test]
    public function getByIdReturnsCorrectDeliveryAmongMultiple(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $storage->save(delivery: $this->delivery(id: 'first'));
        $storage->save(delivery: $this->delivery(id: 'second'));

        $loaded = $storage->getById(id: 'second');

        $this->assertNotNull($loaded);
        $this->assertSame('second', $loaded->getId());
    }

    #[Test]
    public function getByIdReturnsNullForMissingId(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);

        $this->assertNull($storage->getById(id: 'nonexistent'));
    }

    #[Test]
    public function savesAndLoadsDeliveryWithAttempts(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $delivery = $this->delivery()->withAttempt(
            at: new DateTimeImmutable('2026-06-12 11:00:00'),
            error: 'connection refused',
        );

        $storage->save(delivery: $delivery);

        $loaded = $storage->getById(id: $delivery->getId());
        $this->assertNotNull($loaded);
        $this->assertSame(1, $loaded->getAttempts());
        $this->assertSame('connection refused', $loaded->getLastError());
        $this->assertNotNull($loaded->getLastAttemptAt());
    }

    #[Test]
    public function findPendingRespectsDefaultLimitOfHundred(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);

        for ($i = 1; $i <= 101; $i++) {
            $day = 12 + intdiv($i - 1, 24);
            $hour = ($i - 1) % 24;
            $storage->save(delivery: $this->delivery(
                id: sprintf('d%03d', $i),
                createdAt: sprintf('2026-06-%02d %02d:00:00', $day, $hour),
            ));
        }

        $result = $storage->findPending();

        $this->assertCount(100, $result);
    }

    #[Test]
    public function findPendingThrowsWhenRequiredFieldIsNull(): void
    {
        $this->db->createCommand(sql: 'CREATE TABLE wd_null_field (id TEXT PRIMARY KEY, event_id TEXT, event_type TEXT NOT NULL, endpoint_url TEXT NOT NULL, status TEXT NOT NULL, created_at TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, last_attempt_at TEXT, last_error TEXT)')->execute();
        $this->db->createCommand(sql: "INSERT INTO wd_null_field VALUES ('d1', NULL, 'type', 'https://example.com', 'pending', '2026-06-12 10:00:00', 0, NULL, NULL)")->execute();

        $storage = new DbWebhookDeliveryStorage(db: $this->db, table: 'wd_null_field');

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Invalid webhook delivery row field "event_id"');

        $storage->findPending(limit: 10);
    }

    #[Test]
    public function nullableStringThrowsOnNonStringValue(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $method = new \ReflectionMethod(DbWebhookDeliveryStorage::class, 'nullableString');

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Invalid nullable string field');

        $method->invoke($storage, 42);
    }

    #[Test]
    public function nullableDateTimeThrowsOnNonStringValue(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $method = new \ReflectionMethod(DbWebhookDeliveryStorage::class, 'nullableDateTime');

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Invalid nullable datetime field');

        $method->invoke($storage, 42);
    }

    #[Test]
    public function getAttemptsDefaultsToZeroWhenNullInDatabase(): void
    {
        $this->db->createCommand(sql: 'CREATE TABLE wd_null_attempts (id TEXT PRIMARY KEY, event_id TEXT NOT NULL, event_type TEXT NOT NULL, endpoint_url TEXT NOT NULL, status TEXT NOT NULL, created_at TEXT NOT NULL, attempts INTEGER, last_attempt_at TEXT, last_error TEXT)')->execute();
        $this->db->createCommand(sql: "INSERT INTO wd_null_attempts VALUES ('d1', 'e1', 'type', 'https://example.com', 'pending', '2026-06-12 10:00:00', NULL, NULL, NULL)")->execute();

        $storage = new DbWebhookDeliveryStorage(db: $this->db, table: 'wd_null_attempts');

        $loaded = $storage->getById(id: 'd1');
        $this->assertNotNull($loaded);
        $this->assertSame(0, $loaded->getAttempts());
    }

    #[Test]
    public function findPendingThrowsOnInvalidLastAttemptAtFormat(): void
    {
        $this->db->createCommand(sql: "INSERT INTO webhook_deliveries (id, event_id, event_type, endpoint_url, status, created_at, attempts, last_attempt_at) VALUES ('d1', 'event-1', 'type', 'url', 'pending', '2026-06-12 10:00:00', 0, 'not-a-date')")->execute();

        $storage = new DbWebhookDeliveryStorage(db: $this->db);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Invalid datetime value: not-a-date');

        $storage->findPending(limit: 10);
    }

    #[Test]
    public function findPendingThrowsOnInvalidCreatedAtFormat(): void
    {
        $this->db->createCommand(sql: "INSERT INTO webhook_deliveries (id, event_id, event_type, endpoint_url, status, created_at, attempts) VALUES ('d1', 'event-1', 'type', 'url', 'pending', 'not-a-date', 0)")->execute();

        $storage = new DbWebhookDeliveryStorage(db: $this->db);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Invalid datetime value: not-a-date');

        $storage->findPending(limit: 10);
    }

    private function createTables(): void
    {
        $this->db->createCommand(sql: 'CREATE TABLE webhook_deliveries (id VARCHAR(32) PRIMARY KEY, event_id VARCHAR(255) NOT NULL, event_type VARCHAR(255) NOT NULL, endpoint_url TEXT NOT NULL, status VARCHAR(32) NOT NULL, created_at VARCHAR(30) NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, last_attempt_at VARCHAR(30), last_error TEXT)')->execute();
        $this->db->createCommand(sql: 'CREATE TABLE webhook_nonces (nonce VARCHAR(255) PRIMARY KEY, created_at VARCHAR(30) NOT NULL)')->execute();
    }

    private function delivery(string $id = 'delivery-1', string $createdAt = '2026-06-12 10:00:00'): WebhookDelivery
    {
        $event = new WebhookEvent(
            id: 'event-1',
            type: 'order.created',
            payload: '{"orderId":42}',
            occurredAt: new DateTimeImmutable('2026-06-12 10:00:00'),
        );
        $endpoint = new WebhookEndpoint(url: 'https://partner.example/webhook', secret: 'whsec_test');
        $delivery = WebhookDelivery::create(
            event: $event,
            endpoint: $endpoint,
            createdAt: new DateTimeImmutable($createdAt),
        );

        return new WebhookDelivery(
            id: $id,
            eventId: $delivery->getEventId(),
            eventType: $delivery->getEventType(),
            endpointUrl: $delivery->getEndpointUrl(),
            status: $delivery->getStatus(),
            createdAt: $delivery->getCreatedAt(),
        );
    }

    private function fixedClock(): ClockInterface
    {
        return new StaticClock(new DateTimeImmutable('2026-06-12 10:00:00'));
    }
}
