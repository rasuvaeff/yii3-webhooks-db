<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WebhooksDb\Tests;

use Rasuvaeff\Yii3WebhooksDb\Migration\M260612000000CreateWebhookTables;
use Rasuvaeff\Yii3WebhooksDb\Migration\M260822120000AddDeliveryClaimColumns;
use Rasuvaeff\Yii3WebhooksDb\WebhookDeliveryTableName;
use Rasuvaeff\Yii3WebhooksDb\WebhookNonceTableName;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Exception\NotSupportedException;
use Yiisoft\Db\Migration\Informer\NullMigrationInformer;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Injector\Injector;
use Yiisoft\Test\Support\Container\SimpleContainer;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

/**
 * The claim columns arrive in their own migration because the table-creating one
 * has been released: an installation that already ran it must get the columns
 * added, not the create statement re-run.
 *
 * Like {@see MigrationTableNameTest}, everything goes through `Injector::make()`
 * — the resolver `yiisoft/db-migration` actually uses — so a test cannot pass by
 * configuring the migration in a way the runner never would.
 */
#[Test]
#[Covers(M260822120000AddDeliveryClaimColumns::class)]
final class ClaimColumnsMigrationTest
{
    private ConnectionInterface $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->db = new SqliteConnection(
            driver: new SqliteDriver(dsn: 'sqlite::memory:'),
            schemaCache: new SchemaCache(psrCache: new MemorySimpleCache()),
        );
    }

    public function addsTheClaimColumnsToTheDefaultTable(): void
    {
        $this->migrate(new SimpleContainer([]));

        $deliveries = $this->db->getTableSchema('webhook_deliveries', true);

        Assert::notNull($deliveries);
        // the column list IS the contract with the storage: a column silently
        // missing here surfaces only as a failing query in production
        Assert::same(array_keys($deliveries->getColumns()), [
            'id',
            'event_id',
            'event_type',
            'endpoint_url',
            'status',
            'created_at',
            'attempts',
            'last_attempt_at',
            'last_error',
            'claimed_at',
            'claimed_by',
        ]);
    }

    public function addsTheClaimColumnsToTheConfiguredTable(): void
    {
        $this->migrate(new SimpleContainer([
            WebhookDeliveryTableName::class => new WebhookDeliveryTableName('custom_deliveries'),
            WebhookNonceTableName::class => new WebhookNonceTableName('custom_nonces'),
        ]));

        $deliveries = $this->db->getTableSchema('custom_deliveries', true);

        Assert::notNull($deliveries);
        Assert::notNull($deliveries->getColumn('claimed_at'));
        Assert::notNull($deliveries->getColumn('claimed_by'));
    }

    public function claimColumnsAreNullable(): void
    {
        // a delivery is unclaimed for most of its life, and the claim treats
        // NULL as "free" — a NOT NULL column would need a sentinel value
        $this->migrate(new SimpleContainer([]));

        $deliveries = $this->db->getTableSchema('webhook_deliveries', true);

        Assert::notNull($deliveries);
        Assert::true($deliveries->getColumn('claimed_at')?->isNotNull() !== true);
        Assert::true($deliveries->getColumn('claimed_by')?->isNotNull() !== true);
    }

    /**
     * The claim reads its own rows back by `claimed_by` on every successful
     * poll, against a table nothing prunes by itself — without the index that is
     * a sequential scan of the whole backlog.
     */
    public function indexesClaimedByForTheReadBack(): void
    {
        $this->migrate(new SimpleContainer([]));

        Assert::same($this->indexColumns('idx_webhook_deliveries_claimed_by'), ['claimed_by']);
    }

    /**
     * PostgreSQL index names are unique per schema, not per table, so two
     * installations sharing one schema would collide on a hard-coded name.
     */
    public function theIndexNameFollowsTheTableName(): void
    {
        $this->migrate(new SimpleContainer([
            WebhookDeliveryTableName::class => new WebhookDeliveryTableName('custom_deliveries'),
            WebhookNonceTableName::class => new WebhookNonceTableName('custom_nonces'),
        ]));

        Assert::same($this->indexColumns('idx_custom_deliveries_claimed_by'), ['claimed_by']);
    }

    /**
     * `down()` drops the two columns, which `yiisoft/db-sqlite` cannot do at
     * all — the rollback is a MySQL/PostgreSQL-only path, and this pins the
     * fact so it is not discovered during an incident.
     */
    public function downIsNotSupportedOnSqlite(): void
    {
        $this->migrate(new SimpleContainer([]));

        try {
            $this->make(new SimpleContainer([]))->down($this->builder());
            Assert::fail('Expected NotSupportedException');
        } catch (NotSupportedException $e) {
            Assert::string($e->getMessage())->contains('dropColumn is not supported by SQLite');
        }

        $deliveries = $this->db->getTableSchema('webhook_deliveries', true);

        Assert::notNull($deliveries);
        Assert::notNull($deliveries->getColumn('claimed_at'));
    }

    private function migrate(SimpleContainer $container): void
    {
        $builder = $this->builder();

        /** @var M260612000000CreateWebhookTables $create */
        $create = (new Injector($container))->make(M260612000000CreateWebhookTables::class);
        $create->up($builder);

        $this->make($container)->up($builder);
    }

    private function make(SimpleContainer $container): M260822120000AddDeliveryClaimColumns
    {
        /** @var M260822120000AddDeliveryClaimColumns */
        return (new Injector($container))->make(M260822120000AddDeliveryClaimColumns::class);
    }

    private function builder(): MigrationBuilder
    {
        return new MigrationBuilder($this->db, new NullMigrationInformer());
    }

    /**
     * @return list<string>
     */
    private function indexColumns(string $index): array
    {
        $columns = [];

        /** @var array<array-key, mixed> $row */
        foreach ($this->db->createCommand(sprintf('PRAGMA index_info(%s)', $index))->queryAll() as $row) {
            if (is_array($row) && is_string($row['name'] ?? null)) {
                $columns[] = $row['name'];
            }
        }

        return $columns;
    }
}
