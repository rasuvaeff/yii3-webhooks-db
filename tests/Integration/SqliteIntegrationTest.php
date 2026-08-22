<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WebhooksDb\Tests\Integration;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Webhooks\WebhookDelivery;
use Rasuvaeff\Yii3Webhooks\WebhookDeliveryStatus;
use Rasuvaeff\Yii3Webhooks\WebhookEndpoint;
use Rasuvaeff\Yii3Webhooks\WebhookEvent;
use Rasuvaeff\Yii3Webhooks\WebhookRetryPolicy;
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
    private const string NOW = '2026-06-12 10:00:00';

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

    // ── claimReady() ─────────────────────────────────────────────────────────

    /**
     * The thresholds a fixed 30-second policy produces at $now: attempt counts
     * of one and above are ready once their last attempt is that old.
     *
     * Built by hand rather than through `WebhookRetryPolicy::readyThresholds()`
     * so that the maps below can also take shapes the core never produces — a
     * skipped attempt count above all — which is exactly where the readiness
     * rule used to lose a delivery. `claimReadyAgreesWithTheCoreRetryPolicy()`
     * pins the real thing.
     *
     * @return array<int, DateTimeImmutable>
     */
    private function thresholds(DateTimeImmutable $now, int $seconds = 30): array
    {
        return [1 => $now->modify('-' . $seconds . ' seconds')];
    }

    /**
     * @param array<int, DateTimeImmutable>|null $thresholds
     *
     * @return list<string>
     */
    private function claim(
        DbWebhookDeliveryStorage $storage,
        ?DateTimeImmutable $now = null,
        ?array $thresholds = null,
        int $maxAttempts = 3,
        int $leaseSeconds = 300,
        int $limit = 100,
    ): array {
        $at = $now ?? new DateTimeImmutable(self::NOW);

        $claimed = $storage->claimReady(
            now: $at,
            readyThresholds: $thresholds ?? $this->thresholds($at),
            maxAttempts: $maxAttempts,
            leaseSeconds: $leaseSeconds,
            limit: $limit,
        );

        return array_map(static fn(WebhookDelivery $d): string => $d->getId(), $claimed);
    }

    private function attempted(string $id, int $attempts, int $agoSeconds, string $createdAt = '2026-06-12 08:00:00'): WebhookDelivery
    {
        $delivery = $this->delivery(id: $id, createdAt: $createdAt);
        $at = (new DateTimeImmutable(self::NOW))->modify('-' . $agoSeconds . ' seconds');

        for ($i = 0; $i < $attempts; $i++) {
            $delivery = $delivery->withAttempt(at: $at, error: 'HTTP 503');
        }

        return $delivery;
    }

    public function claimReadyLeasesEachDeliveryToASingleWorker(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $storage->save(delivery: $this->delivery(id: 'd1', createdAt: '2026-06-12 08:00:00'));
        $storage->save(delivery: $this->delivery(id: 'd2', createdAt: '2026-06-12 09:00:00'));

        Assert::same($this->claim($storage), ['d1', 'd2']);
        // a second worker polling the same table gets nothing
        Assert::same($this->claim($storage), []);
        // findPending, by contrast, still hands both rows to everyone
        Assert::count($storage->findPending(), 2);
    }

    /**
     * Head-of-line blocking: a backlog of deliveries waiting out their backoff
     * fills every findPending() batch and the ready ones behind them are never
     * fetched. The claim filters by readiness before applying the limit.
     */
    public function claimReadySkipsBackingOffDeliveriesInsteadOfBlockingBehindThem(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);

        for ($i = 1; $i <= 5; $i++) {
            $storage->save(delivery: $this->attempted(
                id: 'backing-off-' . $i,
                attempts: 1,
                agoSeconds: 1,
                createdAt: sprintf('2026-06-12 08:00:0%d', $i),
            ));
        }

        $storage->save(delivery: $this->delivery(id: 'ready', createdAt: '2026-06-12 09:00:00'));

        Assert::count($storage->findPending(limit: 5), 5);
        Assert::same($this->claim($storage, limit: 5), ['ready']);
    }

    public function claimReadyIncludesADeliveryExactlyAtItsThreshold(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $storage->save(delivery: $this->attempted(id: 'd1', attempts: 1, agoSeconds: 30));

        Assert::same($this->claim($storage), ['d1']);
    }

    public function claimReadySkipsADeliveryOneSecondShortOfItsThreshold(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $storage->save(delivery: $this->attempted(id: 'd1', attempts: 1, agoSeconds: 29));

        Assert::same($this->claim($storage), []);
    }

    /**
     * The highest threshold key stands for every larger attempt count — the core
     * ends the map where the delay stops growing.
     */
    public function claimReadyAppliesTheHighestThresholdToLargerAttemptCounts(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $storage->save(delivery: $this->attempted(id: 'short', attempts: 2, agoSeconds: 29));
        $storage->save(delivery: $this->attempted(id: 'elapsed', attempts: 2, agoSeconds: 31, createdAt: '2026-06-12 09:00:00'));

        Assert::same($this->claim($storage), ['elapsed']);
    }

    /**
     * An exhausted delivery must still be handed out: the caller can only mark
     * what it was given, so filtering it here means nothing ever fails it.
     */
    public function claimReadyReturnsExhaustedDelivery(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $storage->save(delivery: $this->attempted(id: 'd1', attempts: 3, agoSeconds: 0));

        Assert::same($this->claim($storage), ['d1']);
    }

    /**
     * An empty threshold map means the policy has no retry step to wait for, so
     * readiness drops out of the query entirely. A delivery that has been
     * attempted, is not out of attempts and has no threshold to clear is exactly
     * the case that tells "no readiness filter" apart from "a filter nothing
     * satisfies".
     */
    public function claimReadyWithoutThresholdsClaimsEveryPendingDelivery(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $storage->save(delivery: $this->attempted(id: 'd1', attempts: 1, agoSeconds: 0));

        Assert::same($this->claim($storage, thresholds: [], maxAttempts: 3), ['d1']);
    }

    /**
     * A caller building the map by hand is not required to sort it — the highest
     * attempt count has to be found, not assumed to be last.
     */
    public function claimReadyAcceptsThresholdsInAnyKeyOrder(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $storage->save(delivery: $this->attempted(id: 'd1', attempts: 2, agoSeconds: 45));
        $now = new DateTimeImmutable(self::NOW);

        // attempt 1 waits 30 seconds, attempt 2 and above wait 60 — unsorted
        $thresholds = [2 => $now->modify('-60 seconds'), 1 => $now->modify('-30 seconds')];

        Assert::same($this->claim($storage, thresholds: $thresholds), []);
    }

    /**
     * A hand-built map may skip an attempt count — the docblock invites one, and
     * only `readyThresholds()` numbers them contiguously. Each key governs the
     * counts up to the next one, so `[1 => …, 3 => …]` still has a rule for a
     * delivery on its second attempt. Under the equality test this replaces,
     * that delivery matched no branch at all and was never handed out again.
     */
    public function claimReadyUsesTheNearestLowerThresholdForACountBetweenKeys(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $storage->save(delivery: $this->attempted(id: 'att-1', attempts: 1, agoSeconds: 45));
        $storage->save(delivery: $this->attempted(id: 'att-2', attempts: 2, agoSeconds: 45, createdAt: '2026-06-12 09:00:00'));
        $now = new DateTimeImmutable(self::NOW);

        // attempt 1 and 2 wait 30 seconds, attempt 3 and above wait 90
        $thresholds = [1 => $now->modify('-30 seconds'), 3 => $now->modify('-90 seconds')];

        Assert::same($this->claim($storage, thresholds: $thresholds, maxAttempts: 5), ['att-1', 'att-2']);
    }

    /**
     * The other end of that range: a count that has a key of its own must not
     * borrow the shorter wait of a lower one.
     */
    public function claimReadyDoesNotApplyALowerThresholdToAHigherAttemptCount(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $storage->save(delivery: $this->attempted(id: 'att-3', attempts: 3, agoSeconds: 45));
        $now = new DateTimeImmutable(self::NOW);

        $thresholds = [1 => $now->modify('-30 seconds'), 3 => $now->modify('-90 seconds')];

        // 45 seconds clears the 30-second threshold of key 1 but not the
        // 90-second one that actually governs a third attempt
        Assert::same($this->claim($storage, thresholds: $thresholds, maxAttempts: 5), []);
    }

    /**
     * The map this backend is built for comes from the core, not from a test
     * helper: the shape `WebhookRetryPolicy::readyThresholds()` produces has to
     * select the same deliveries the policy's own `isReadyForRetry()` accepts.
     */
    public function claimReadyAgreesWithTheCoreRetryPolicy(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $policy = WebhookRetryPolicy::exponential(maxAttempts: 5, baseSeconds: 30, cap: 240);
        $now = new DateTimeImmutable(self::NOW);

        // 30, 60, 120, 240 seconds of backoff for attempts one to four
        $waiting = $this->attempted(id: 'waiting', attempts: 2, agoSeconds: 59);
        $ready = $this->attempted(id: 'ready', attempts: 2, agoSeconds: 60, createdAt: '2026-06-12 09:00:00');

        $storage->save(delivery: $waiting);
        $storage->save(delivery: $ready);

        Assert::false($policy->isReadyForRetry(delivery: $waiting, now: $now));
        Assert::true($policy->isReadyForRetry(delivery: $ready, now: $now));
        Assert::same(
            $this->claim(
                $storage,
                now: $now,
                thresholds: $policy->readyThresholds($now),
                maxAttempts: $policy->getMaxAttempts(),
            ),
            ['ready'],
        );
    }

    /**
     * `attempts` and `last_attempt_at` are separate columns and a row can carry
     * a count with no timestamp — the delivery constructor allows exactly that.
     * Such a row has no threshold to compare against and must not be stranded.
     */
    public function claimReadyClaimsADeliveryWithAttemptsButNoAttemptTime(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $storage->save(delivery: new WebhookDelivery(
            id: 'd1',
            eventId: 'event-1',
            eventType: 'order.created',
            endpointUrl: 'https://partner.example/webhook',
            status: WebhookDeliveryStatus::Pending,
            createdAt: new DateTimeImmutable('2026-06-12 08:00:00'),
            attempts: 2,
        ));

        Assert::same($this->claim($storage), ['d1']);
    }

    /**
     * The queue is ordered by `created_at`, not by id: the oldest delivery is
     * the one a limited batch must take.
     */
    public function claimReadyTakesTheOldestDeliveriesFirst(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        // id order is the reverse of creation order, so an ordering that fell
        // back to the primary key would pick the other one
        $storage->save(delivery: $this->delivery(id: 'z-oldest', createdAt: '2026-06-12 08:00:00'));
        $storage->save(delivery: $this->delivery(id: 'a-newest', createdAt: '2026-06-12 09:00:00'));

        Assert::same($this->claim($storage, limit: 1), ['z-oldest']);
    }

    public function claimReadyReturnsTheBatchInQueueOrder(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $storage->save(delivery: $this->delivery(id: 'z-oldest', createdAt: '2026-06-12 08:00:00'));
        $storage->save(delivery: $this->delivery(id: 'a-newest', createdAt: '2026-06-12 09:00:00'));

        Assert::same($this->claim($storage), ['z-oldest', 'a-newest']);
    }

    public function claimReadySkipsTerminalDeliveries(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $storage->save(delivery: $this->delivery(id: 'd1'));
        $storage->markFailed(delivery: $this->delivery(id: 'd1'));

        Assert::same($this->claim($storage), []);
    }

    public function claimReadyReturnsEmptyWhenTheTableIsEmpty(): void
    {
        Assert::same($this->claim(new DbWebhookDeliveryStorage(db: $this->db)), []);
    }

    public function claimReadyRespectsLimit(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);

        for ($i = 1; $i <= 3; $i++) {
            $storage->save(delivery: $this->delivery(id: 'd' . $i, createdAt: sprintf('2026-06-12 0%d:00:00', $i)));
        }

        Assert::same($this->claim($storage, limit: 2), ['d1', 'd2']);
    }

    public function claimReadyHandsOutADeliveryAgainOnceTheLeaseExpires(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $storage->save(delivery: $this->delivery(id: 'd1'));
        $now = new DateTimeImmutable(self::NOW);

        Assert::same($this->claim($storage, now: $now), ['d1']);
        Assert::same($this->claim($storage, now: $now->modify('+299 seconds')), []);
        Assert::same($this->claim($storage, now: $now->modify('+300 seconds')), ['d1']);
    }

    public function claimReadyStampsTheLeaseOnTheClaimedRow(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $storage->save(delivery: $this->delivery(id: 'd1'));

        $this->claim($storage);

        $row = $this->db->createCommand(sql: "SELECT claimed_at, claimed_by FROM webhook_deliveries WHERE id = 'd1'")->queryOne();

        Assert::notNull($row);
        Assert::same($row['claimed_at'], self::NOW);
        Assert::same(\strlen((string) $row['claimed_by']), 32);
    }

    // ── releaseClaim() ───────────────────────────────────────────────────────

    public function releaseClaimMakesTheDeliveryClaimableAgain(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $delivery = $this->delivery(id: 'd1');
        $storage->save(delivery: $delivery);
        $this->claim($storage);

        Assert::true($storage->releaseClaim(delivery: $delivery));
        Assert::same($this->claim($storage), ['d1']);
    }

    public function releaseClaimReturnsFalseWhenNothingWasLeased(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $delivery = $this->delivery(id: 'd1');
        $storage->save(delivery: $delivery);

        Assert::false($storage->releaseClaim(delivery: $delivery));
    }

    public function releaseClaimReturnsFalseForAnUnknownDelivery(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);

        Assert::false($storage->releaseClaim(delivery: $this->delivery(id: 'nonexistent')));
    }

    public function releaseClaimReturnsFalseForATerminalDelivery(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $delivery = $this->delivery(id: 'd1');
        $storage->save(delivery: $delivery);
        $this->claim($storage);
        $storage->markDelivered(delivery: $delivery);

        Assert::false($storage->releaseClaim(delivery: $delivery));
    }

    public function releaseClaimOnlyAffectsTheGivenDelivery(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $storage->save(delivery: $this->delivery(id: 'd1', createdAt: '2026-06-12 08:00:00'));
        $storage->save(delivery: $this->delivery(id: 'd2', createdAt: '2026-06-12 09:00:00'));
        $this->claim($storage);

        Assert::true($storage->releaseClaim(delivery: $this->delivery(id: 'd1')));
        // d2 is still leased, so a second worker sees only the released one
        Assert::same($this->claim($storage), ['d1']);
    }

    /**
     * A lease left behind on a row that was finished outside the storage — a
     * hand-written `UPDATE` during an incident, a crash halfway through an
     * older version — must not be releasable back into the queue. `mark*` clears
     * the lease on the rows it wins, so the status check is what covers the rest.
     */
    public function releaseClaimRefusesAStaleLeaseOnAFinishedDelivery(): void
    {
        $this->db->createCommand(sql: "INSERT INTO webhook_deliveries (id, event_id, event_type, endpoint_url, status, created_at, attempts, claimed_at, claimed_by) VALUES ('d1', 'event-1', 'order.created', 'https://partner.example/webhook', 'delivered', '2026-06-12 08:00:00', 1, '2026-06-12 09:00:00', 'stale-token')")->execute();

        $storage = new DbWebhookDeliveryStorage(db: $this->db);

        Assert::false($storage->releaseClaim(delivery: $this->delivery(id: 'd1')));

        $row = $this->db->createCommand(sql: "SELECT claimed_by FROM webhook_deliveries WHERE id = 'd1'")->queryOne();

        Assert::notNull($row);
        Assert::same($row['claimed_by'], 'stale-token');
    }

    public function markDeliveredClearsTheLease(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $delivery = $this->delivery(id: 'd1');
        $storage->save(delivery: $delivery);
        $this->claim($storage);
        $storage->markDelivered(delivery: $delivery);

        $row = $this->db->createCommand(sql: "SELECT claimed_at, claimed_by FROM webhook_deliveries WHERE id = 'd1'")->queryOne();

        Assert::notNull($row);
        Assert::null($row['claimed_at']);
        Assert::null($row['claimed_by']);
    }

    public function markFailedClearsTheLease(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $delivery = $this->delivery(id: 'd1');
        $storage->save(delivery: $delivery);
        $this->claim($storage);
        $storage->markFailed(delivery: $delivery);

        $row = $this->db->createCommand(sql: "SELECT claimed_at, claimed_by FROM webhook_deliveries WHERE id = 'd1'")->queryOne();

        Assert::notNull($row);
        Assert::null($row['claimed_at']);
        Assert::null($row['claimed_by']);
    }

    // ── save() must not resurrect ────────────────────────────────────────────

    /**
     * The lost-update half of the double-delivery bug: worker A delivered and
     * marked the row, worker B still holds the stale pending copy and records
     * its own failed attempt. The upsert used to overwrite every column,
     * `status` included, and the delivered webhook went out again.
     */
    public function saveDoesNotResurrectAFinishedDelivery(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $stale = $this->delivery(id: 'd1');

        $storage->save(delivery: $stale);
        $storage->markDelivered(delivery: $stale);
        $storage->save(delivery: $stale->withAttempt(at: new DateTimeImmutable(self::NOW), error: 'HTTP 502'));

        $loaded = $storage->getById(id: 'd1');

        Assert::notNull($loaded);
        Assert::same($loaded->getStatus(), WebhookDeliveryStatus::Delivered);
        // the attempt state is still written — only the status is protected
        Assert::same($loaded->getAttempts(), 1);
        Assert::same($loaded->getLastError(), 'HTTP 502');
        Assert::same($storage->findPending(), []);
    }

    public function saveKeepsTheStatusOfANewDelivery(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $storage->save(delivery: $this->delivery(id: 'd1')->withStatus(status: WebhookDeliveryStatus::Failed));

        Assert::same($storage->getById(id: 'd1')?->getStatus(), WebhookDeliveryStatus::Failed);
    }

    // ── deleteOlderThan() ────────────────────────────────────────────────────

    public function deleteOlderThanRemovesFinishedDeliveriesOnly(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $storage->save(delivery: $this->delivery(id: 'old-pending', createdAt: '2026-06-01 00:00:00'));
        $storage->save(delivery: $this->delivery(id: 'old-delivered', createdAt: '2026-06-01 00:00:00'));
        $storage->markDelivered(delivery: $this->delivery(id: 'old-delivered'));
        $storage->save(delivery: $this->delivery(id: 'old-failed', createdAt: '2026-06-01 00:00:00'));
        $storage->markFailed(delivery: $this->delivery(id: 'old-failed'));
        $storage->save(delivery: $this->delivery(id: 'recent-delivered', createdAt: '2026-06-20 00:00:00'));
        $storage->markDelivered(delivery: $this->delivery(id: 'recent-delivered'));

        $deleted = $storage->deleteOlderThan(threshold: new DateTimeImmutable('2026-06-10 00:00:00'));

        Assert::same($deleted, 2);
        Assert::notNull($storage->getById(id: 'old-pending'));
        Assert::null($storage->getById(id: 'old-delivered'));
        Assert::null($storage->getById(id: 'old-failed'));
        Assert::notNull($storage->getById(id: 'recent-delivered'));
    }

    public function deleteOlderThanAcceptsExplicitStatuses(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $storage->save(delivery: $this->delivery(id: 'old-pending', createdAt: '2026-06-01 00:00:00'));
        $storage->save(delivery: $this->delivery(id: 'old-failed', createdAt: '2026-06-01 00:00:00'));
        $storage->markFailed(delivery: $this->delivery(id: 'old-failed'));

        $deleted = $storage->deleteOlderThan(
            new DateTimeImmutable('2026-06-10 00:00:00'),
            WebhookDeliveryStatus::Pending,
        );

        Assert::same($deleted, 1);
        Assert::null($storage->getById(id: 'old-pending'));
        Assert::notNull($storage->getById(id: 'old-failed'));
    }

    public function deleteOlderThanExcludesTheThresholdItself(): void
    {
        $storage = new DbWebhookDeliveryStorage(db: $this->db);
        $storage->save(delivery: $this->delivery(id: 'd1', createdAt: '2026-06-10 00:00:00'));
        $storage->markDelivered(delivery: $this->delivery(id: 'd1'));

        Assert::same($storage->deleteOlderThan(threshold: new DateTimeImmutable('2026-06-10 00:00:00')), 0);
    }

    private function createTables(): void
    {
        $this->db->createCommand(sql: 'CREATE TABLE webhook_deliveries (id VARCHAR(32) PRIMARY KEY, event_id VARCHAR(255) NOT NULL, event_type VARCHAR(255) NOT NULL, endpoint_url TEXT NOT NULL, status VARCHAR(32) NOT NULL, created_at VARCHAR(30) NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, last_attempt_at VARCHAR(30), last_error TEXT, claimed_at VARCHAR(30), claimed_by VARCHAR(32))')->execute();
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
