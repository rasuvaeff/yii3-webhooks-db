<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WebhooksDb\Tests;

use Rasuvaeff\Yii3WebhooksDb\Migration\M260612000000CreateWebhookTables;
use Rasuvaeff\Yii3WebhooksDb\WebhookDeliveryTableName;
use Rasuvaeff\Yii3WebhooksDb\WebhookNonceTableName;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Migration\Informer\NullMigrationInformer;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Injector\Injector;
use Yiisoft\Test\Support\Container\SimpleContainer;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

/**
 * The migration is created by `yiisoft/db-migration` through `Injector::make()`,
 * not by the container, so a test that instantiates it directly proves nothing
 * about whether configuration actually reaches it. These go through the real
 * resolver.
 */
#[Test]
#[Covers(M260612000000CreateWebhookTables::class)]
final class MigrationTableNameTest
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

    public function containerBoundTableNamesReachTheMigration(): void
    {
        $migration = $this->make(new SimpleContainer([
            WebhookDeliveryTableName::class => new WebhookDeliveryTableName('custom_deliveries'),
            WebhookNonceTableName::class => new WebhookNonceTableName('custom_nonces'),
        ]));

        $migration->up($this->builder());

        Assert::notNull($this->db->getTableSchema('custom_deliveries', true));
        Assert::notNull($this->db->getTableSchema('custom_nonces', true));
        Assert::null($this->db->getTableSchema('webhook_deliveries', true));
        Assert::null($this->db->getTableSchema('webhook_nonces', true));
    }

    public function withoutBindingsTheDefaultNamesAreUsed(): void
    {
        // Injector falls back to the parameter defaults, so the package stays
        // usable with no configuration at all
        $migration = $this->make(new SimpleContainer([]));

        $migration->up($this->builder());

        Assert::notNull($this->db->getTableSchema('webhook_deliveries', true));
        Assert::notNull($this->db->getTableSchema('webhook_nonces', true));
    }

    public function createsTheDocumentedColumnSets(): void
    {
        // the column lists ARE the contract with the storages: a column silently
        // dropped here surfaces only as a failing query in production
        $migration = $this->make(new SimpleContainer([]));

        $migration->up($this->builder());

        $deliveries = $this->db->getTableSchema('webhook_deliveries', true);
        Assert::notNull($deliveries);
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
        ]);

        $nonces = $this->db->getTableSchema('webhook_nonces', true);
        Assert::notNull($nonces);
        Assert::same(array_keys($nonces->getColumns()), ['nonce', 'created_at']);
    }

    public function indexNamesFollowTheTableNames(): void
    {
        // hard-coded index names collide in PostgreSQL, where names are unique
        // per schema rather than per table
        $migration = $this->make(new SimpleContainer([
            WebhookDeliveryTableName::class => new WebhookDeliveryTableName('custom_deliveries'),
            WebhookNonceTableName::class => new WebhookNonceTableName('custom_nonces'),
        ]));

        $migration->up($this->builder());

        $deliveryIndexes = $this->indexNames('custom_deliveries');
        Assert::true(in_array('idx_custom_deliveries_status_created', $deliveryIndexes, true));
        Assert::true(in_array('idx_custom_deliveries_event_id', $deliveryIndexes, true));
        Assert::true(in_array('idx_custom_nonces_created_at', $this->indexNames('custom_nonces'), true));
    }

    public function indexesCoverTheDocumentedColumns(): void
    {
        $migration = $this->make(new SimpleContainer([]));

        $migration->up($this->builder());

        Assert::same($this->indexColumns('idx_webhook_deliveries_status_created'), ['status', 'created_at']);
        Assert::same($this->indexColumns('idx_webhook_deliveries_event_id'), ['event_id']);
        Assert::same($this->indexColumns('idx_webhook_nonces_created_at'), ['created_at']);
    }

    public function downDropsBothConfiguredTables(): void
    {
        $migration = $this->make(new SimpleContainer([
            WebhookDeliveryTableName::class => new WebhookDeliveryTableName('custom_deliveries'),
            WebhookNonceTableName::class => new WebhookNonceTableName('custom_nonces'),
        ]));
        $builder = $this->builder();

        $migration->up($builder);
        $migration->down($builder);

        Assert::null($this->db->getTableSchema('custom_deliveries', true));
        Assert::null($this->db->getTableSchema('custom_nonces', true));
    }

    private function make(SimpleContainer $container): M260612000000CreateWebhookTables
    {
        /** @var M260612000000CreateWebhookTables */
        return (new Injector($container))->make(M260612000000CreateWebhookTables::class);
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

    /**
     * @return list<string>
     */
    private function indexNames(string $table): array
    {
        $names = [];

        /** @var array<array-key, mixed> $row */
        foreach ($this->db->createCommand(sprintf('PRAGMA index_list(%s)', $table))->queryAll() as $row) {
            if (is_array($row) && is_string($row['name'] ?? null)) {
                $names[] = $row['name'];
            }
        }

        return $names;
    }
}
