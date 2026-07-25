<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WebhooksDb\Tests\Integration;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Webhooks\NonceStorage;
use Rasuvaeff\Yii3Webhooks\WebhookDeliveryStorage;
use Rasuvaeff\Yii3WebhooksDb\DbNonceStorage;
use Rasuvaeff\Yii3WebhooksDb\DbWebhookDeliveryStorage;
use Rasuvaeff\Yii3WebhooksDb\WebhookDeliveryTableName;
use Rasuvaeff\Yii3WebhooksDb\WebhookNonceTableName;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\Clock\StaticClock;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

#[Test]
#[CoversNothing]
final class ConfigWiringTest
{
    public function bindsOnlyItsOwnKeys(): void
    {
        // the two table-name types are this package's own; the core binds
        // neither them nor the storage interfaces, so there is nothing for
        // yiisoft/config to call a duplicate
        Assert::same(
            array_keys($this->loadDb(params: [])),
            [
                WebhookDeliveryTableName::class,
                WebhookNonceTableName::class,
                WebhookDeliveryStorage::class,
                NonceStorage::class,
            ],
        );
    }

    public function tableNameFactoriesApplyTheSharedPrefix(): void
    {
        $definitions = $this->loadDb(params: [
            'rasuvaeff/yii3-webhooks-db' => [
                'deliveryTable' => 'custom_deliveries',
                'nonceTable' => 'custom_nonces',
                'table_prefix' => 'rsv_',
            ],
        ]);

        Assert::same($this->tableName($definitions, WebhookDeliveryTableName::class), 'rsv_custom_deliveries');
        Assert::same($this->tableName($definitions, WebhookNonceTableName::class), 'rsv_custom_nonces');
    }

    public function factoriesBuildDbStorages(): void
    {
        $definitions = $this->loadDb(params: [
            'rasuvaeff/yii3-webhooks-db' => [
                'deliveryTable' => 'custom_deliveries',
                'nonceTable' => 'custom_nonces',
            ],
        ]);

        $deliveryFactory = $definitions[WebhookDeliveryStorage::class];
        $nonceFactory = $definitions[NonceStorage::class];
        Assert::true(is_callable($deliveryFactory));
        Assert::true(is_callable($nonceFactory));

        $deliveryTable = $definitions[WebhookDeliveryTableName::class];
        $nonceTable = $definitions[WebhookNonceTableName::class];
        Assert::true(is_callable($deliveryTable));
        Assert::true(is_callable($nonceTable));

        Assert::instanceOf($deliveryFactory($this->sqlite(), $deliveryTable()), DbWebhookDeliveryStorage::class);
        Assert::instanceOf(
            $nonceFactory($this->sqlite(), $this->fixedClock(), $nonceTable()),
            DbNonceStorage::class,
        );
    }

    /**
     * @param array<string, mixed> $definitions
     */
    private function tableName(array $definitions, string $key): string
    {
        $factory = $definitions[$key];
        Assert::true(is_callable($factory));

        /** @var WebhookDeliveryTableName|WebhookNonceTableName $table */
        $table = $factory();

        return $table->value;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function loadDb(array $params): array
    {
        $params = array_replace([], $params);

        return require dirname(__DIR__, 2) . '/config/di.php';
    }

    private function sqlite(): ConnectionInterface
    {
        $driver = new SqliteDriver(dsn: 'sqlite::memory:');

        return new SqliteConnection(driver: $driver, schemaCache: new SchemaCache(psrCache: new MemorySimpleCache()));
    }

    private function fixedClock(): ClockInterface
    {
        return new StaticClock(new DateTimeImmutable('2026-06-12 10:00:00'));
    }
}
