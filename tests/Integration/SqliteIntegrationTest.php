<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WebhooksDb\Tests\Integration;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Webhooks\WebhookDelivery;
use Rasuvaeff\Yii3Webhooks\WebhookDeliveryStatus;
use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;
use Rasuvaeff\Yii3Webhooks\WebhookEvent;
use Rasuvaeff\Yii3WebhooksDb\DbNonceStorage;
use Rasuvaeff\Yii3WebhooksDb\DbWebhookDeliveryStorage;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use UnexpectedValueException;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\Clock\StaticClock;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

#[Test]
#[Covers(DbWebhookDeliveryStorage::class)]
#[Covers(DbNonceStorage::class)]
final class SqliteIntegrationTest
{
    private ConnectionInterface $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $driver = new SqliteDriver(dsn: 'sqlite::memory:');
        $schemaCache = new SchemaCache(psrCache: new MemorySimpleCache());
        $this->db = new SqliteConnection(driver: $driver, schemaCache: $schemaCache);
        $this->db->open();
        $this->createTables();
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->db->close();
    }

    public function nonceAddIsAtomicAndRejectsDuplicate(): void
    {
        $storage = new DbNonceStorage(db: $this->db, clock: $this->fixedClock());

        Assert::true($storage->add(nonce: 'nonce-1'));
        Assert::false($storage->add(nonce: 'nonce-1'));
        Assert::true($storage->has(nonce: 'nonce-1'));
    }

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

        Assert::same($deleted, 1);
        Assert::false($storage->has(nonce: 'old'));
        Assert::true($storage->has(nonce: 'new'));
    }

    public function savesAndLoadsDelivery(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $delivery = $this->delivery();

        $storage->save(delivery: $delivery);

        $loaded = $storage->getById(id: $delivery->getId());
        Assert::notNull($loaded);
        Assert::same($loaded->getId(), $delivery->getId());
        Assert::same($loaded->getEventType(), 'order.created');
        Assert::same($loaded->getEndpointUrl(), 'https://partner.example/webhook');
        Assert::same($loaded->getStatus(), WebhookDeliveryStatus::Pending);
        Assert::same($loaded->getAttempts(), 0);
        Assert::null($loaded->getLastAttemptAt());
        Assert::null($loaded->getLastError());
    }

    public function findPendingReturnsOnlyPendingDeliveries(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $pending = $this->delivery(id: 'pending-delivery');
        $delivered = $this->delivery(id: 'delivered-delivery');

        $storage->save(delivery: $pending);
        $storage->save(delivery: $delivered->withStatus(status: WebhookDeliveryStatus::Delivered));

        $result = $storage->findPending(limit: 10);

        Assert::count($result, 1);
        Assert::same($result[0]->getId(), 'pending-delivery');
    }

    public function markDeliveredUpdatesStatusFromPending(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $delivery = $this->delivery();

        $storage->save(delivery: $delivery);
        $storage->markDelivered(delivery: $delivery);

        $loaded = $storage->getById(id: $delivery->getId());
        Assert::notNull($loaded);
        Assert::same($loaded->getStatus(), WebhookDeliveryStatus::Delivered);
    }

    public function markDeliveredPersistsLastAttemptAt(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $delivery = $this->delivery();
        $attempted = $delivery->withAttempt(
            at: new DateTimeImmutable('2026-06-12 11:30:00'),
        );

        $storage->save(delivery: $delivery);
        $storage->markDelivered(delivery: $attempted);

        $loaded = $storage->getById(id: $delivery->getId());
        Assert::notNull($loaded);
        Assert::notNull($loaded->getLastAttemptAt());
        Assert::same($loaded->getLastAttemptAt()->format('Y-m-d H:i:s'), '2026-06-12 11:30:00');
    }

    public function markFailedUpdatesStatusFromPending(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $delivery = $this->delivery();

        $storage->save(delivery: $delivery);
        $storage->markFailed(delivery: $delivery);

        $loaded = $storage->getById(id: $delivery->getId());
        Assert::notNull($loaded);
        Assert::same($loaded->getStatus(), WebhookDeliveryStatus::Failed);
    }

    public function markDeliveredIgnoresAlreadyProcessedDelivery(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $delivery = $this->delivery();

        $storage->save(delivery: $delivery->withStatus(status: WebhookDeliveryStatus::Failed));
        $storage->markDelivered(delivery: $delivery);

        $loaded = $storage->getById(id: $delivery->getId());
        Assert::notNull($loaded);
        Assert::same($loaded->getStatus(), WebhookDeliveryStatus::Failed);
    }

    public function markFailedIgnoresAlreadyProcessedDelivery(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $delivery = $this->delivery();

        $storage->save(delivery: $delivery->withStatus(status: WebhookDeliveryStatus::Delivered));
        $storage->markFailed(delivery: $delivery);

        $loaded = $storage->getById(id: $delivery->getId());
        Assert::notNull($loaded);
        Assert::same($loaded->getStatus(), WebhookDeliveryStatus::Delivered);
    }

    public function markDeliveredOnlyAffectsSpecifiedDelivery(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $storage->save(delivery: $this->delivery(id: 'd1', createdAt: '2026-06-12 08:00:00'));
        $storage->save(delivery: $this->delivery(id: 'd2', createdAt: '2026-06-12 09:00:00'));

        $storage->markDelivered(delivery: $this->delivery(id: 'd1'));

        Assert::same($storage->getById(id: 'd1')?->getStatus(), WebhookDeliveryStatus::Delivered);
        Assert::same($storage->getById(id: 'd2')?->getStatus(), WebhookDeliveryStatus::Pending);
    }

    public function markFailedOnlyAffectsSpecifiedDelivery(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $storage->save(delivery: $this->delivery(id: 'd1', createdAt: '2026-06-12 08:00:00'));
        $storage->save(delivery: $this->delivery(id: 'd2', createdAt: '2026-06-12 09:00:00'));

        $storage->markFailed(delivery: $this->delivery(id: 'd1'));

        Assert::same($storage->getById(id: 'd1')?->getStatus(), WebhookDeliveryStatus::Failed);
        Assert::same($storage->getById(id: 'd2')?->getStatus(), WebhookDeliveryStatus::Pending);
    }

    public function findPendingOrdersByCreatedAtAscending(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $storage->save(delivery: $this->delivery(id: 'later', createdAt: '2026-06-12 12:00:00'));
        $storage->save(delivery: $this->delivery(id: 'earlier', createdAt: '2026-06-12 08:00:00'));
        $storage->save(delivery: $this->delivery(id: 'middle', createdAt: '2026-06-12 10:00:00'));

        $result = $storage->findPending(limit: 10);

        Assert::count($result, 3);
        Assert::same($result[0]->getId(), 'earlier');
        Assert::same($result[1]->getId(), 'middle');
        Assert::same($result[2]->getId(), 'later');
    }

    public function findPendingRespectsLimit(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $storage->save(delivery: $this->delivery(id: 'd1', createdAt: '2026-06-12 08:00:00'));
        $storage->save(delivery: $this->delivery(id: 'd2', createdAt: '2026-06-12 09:00:00'));
        $storage->save(delivery: $this->delivery(id: 'd3', createdAt: '2026-06-12 10:00:00'));

        $result = $storage->findPending(limit: 2);

        Assert::count($result, 2);
        Assert::same($result[0]->getId(), 'd1');
        Assert::same($result[1]->getId(), 'd2');
    }

    public function getByIdReturnsCorrectDeliveryAmongMultiple(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $storage->save(delivery: $this->delivery(id: 'first'));
        $storage->save(delivery: $this->delivery(id: 'second'));

        $loaded = $storage->getById(id: 'second');

        Assert::notNull($loaded);
        Assert::same($loaded->getId(), 'second');
    }

    public function getByIdReturnsNullForMissingId(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);

        Assert::null($storage->getById(id: 'nonexistent'));
    }

    public function savesAndLoadsDeliveryWithAttempts(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $delivery = $this->delivery()->withAttempt(
            at: new DateTimeImmutable('2026-06-12 11:00:00'),
            error: 'connection refused',
        );

        $storage->save(delivery: $delivery);

        $loaded = $storage->getById(id: $delivery->getId());
        Assert::notNull($loaded);
        Assert::same($loaded->getAttempts(), 1);
        Assert::same($loaded->getLastError(), 'connection refused');
        Assert::notNull($loaded->getLastAttemptAt());
    }

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

        Assert::count($result, 100);
    }

    public function findPendingThrowsWhenRequiredFieldIsNull(): void
    {
        $this->db->createCommand(sql: 'CREATE TABLE wd_null_field (id TEXT PRIMARY KEY, event_id TEXT, event_type TEXT NOT NULL, endpoint_url TEXT NOT NULL, status TEXT NOT NULL, created_at TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, last_attempt_at TEXT, last_error TEXT)')->execute();
        $this->db->createCommand(sql: "INSERT INTO wd_null_field VALUES ('d1', NULL, 'type', 'https://example.com', 'pending', '2026-06-12 10:00:00', 0, NULL, NULL)")->execute();

        $storage = new DbWebhookDeliveryStorage(db: $this->db, table: 'wd_null_field');

        try {
            $storage->findPending(limit: 10);
            Assert::fail('Expected UnexpectedValueException');
        } catch (UnexpectedValueException $e) {
            Assert::string($e->getMessage())->contains('Invalid webhook delivery row field "event_id"');
        }
    }

    public function nullableStringThrowsOnNonStringValue(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $method = new \ReflectionMethod(DbWebhookDeliveryStorage::class, 'nullableString');

        try {
            $method->invoke($storage, 42);
            Assert::fail('Expected UnexpectedValueException');
        } catch (UnexpectedValueException $e) {
            Assert::string($e->getMessage())->contains('Invalid nullable string field');
        }
    }

    public function nullableDateTimeThrowsOnNonStringValue(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $method = new \ReflectionMethod(DbWebhookDeliveryStorage::class, 'nullableDateTime');

        try {
            $method->invoke($storage, 42);
            Assert::fail('Expected UnexpectedValueException');
        } catch (UnexpectedValueException $e) {
            Assert::string($e->getMessage())->contains('Invalid nullable datetime field');
        }
    }

    public function getAttemptsDefaultsToZeroWhenNullInDatabase(): void
    {
        $this->db->createCommand(sql: 'CREATE TABLE wd_null_attempts (id TEXT PRIMARY KEY, event_id TEXT NOT NULL, event_type TEXT NOT NULL, endpoint_url TEXT NOT NULL, status TEXT NOT NULL, created_at TEXT NOT NULL, attempts INTEGER, last_attempt_at TEXT, last_error TEXT)')->execute();
        $this->db->createCommand(sql: "INSERT INTO wd_null_attempts VALUES ('d1', 'e1', 'type', 'https://example.com', 'pending', '2026-06-12 10:00:00', NULL, NULL, NULL)")->execute();

        $storage = new DbWebhookDeliveryStorage(db: $this->db, table: 'wd_null_attempts');

        $loaded = $storage->getById(id: 'd1');
        Assert::notNull($loaded);
        Assert::same($loaded->getAttempts(), 0);
    }

    public function findPendingThrowsOnInvalidLastAttemptAtFormat(): void
    {
        $this->db->createCommand(sql: "INSERT INTO webhook_deliveries (id, event_id, event_type, endpoint_url, status, created_at, attempts, last_attempt_at) VALUES ('d1', 'event-1', 'type', 'url', 'pending', '2026-06-12 10:00:00', 0, 'not-a-date')")->execute();

        $storage = new DbWebhookDeliveryStorage(db: $this->db);

        try {
            $storage->findPending(limit: 10);
            Assert::fail('Expected UnexpectedValueException');
        } catch (UnexpectedValueException $e) {
            Assert::string($e->getMessage())->contains('Invalid datetime value: not-a-date');
        }
    }

    public function findPendingThrowsOnInvalidCreatedAtFormat(): void
    {
        $this->db->createCommand(sql: "INSERT INTO webhook_deliveries (id, event_id, event_type, endpoint_url, status, created_at, attempts) VALUES ('d1', 'event-1', 'type', 'url', 'pending', 'not-a-date', 0)")->execute();

        $storage = new DbWebhookDeliveryStorage(db: $this->db);

        try {
            $storage->findPending(limit: 10);
            Assert::fail('Expected UnexpectedValueException');
        } catch (UnexpectedValueException $e) {
            Assert::string($e->getMessage())->contains('Invalid datetime value: not-a-date');
        }
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
