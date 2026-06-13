<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WebhooksDb\Tests\Integration;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Webhooks\NonceStorage;
use Rasuvaeff\Yii3Webhooks\WebhookDeliveryStorage;
use Rasuvaeff\Yii3WebhooksDb\DbNonceStorage;
use Rasuvaeff\Yii3WebhooksDb\DbWebhookDeliveryStorage;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\Clock\StaticClock;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

#[CoversNothing]
final class ConfigWiringTest extends TestCase
{
    #[Test]
    public function bindsOnlySwappableStorageKeys(): void
    {
        $this->assertSame(
            [WebhookDeliveryStorage::class, NonceStorage::class],
            array_keys($this->loadDb([])),
        );
    }

    #[Test]
    public function factoriesBuildDbStorages(): void
    {
        $definitions = $this->loadDb([
            'rasuvaeff/yii3-webhooks-db' => [
                'deliveryTable' => 'custom_deliveries',
                'nonceTable' => 'custom_nonces',
            ],
        ]);

        $deliveryFactory = $definitions[WebhookDeliveryStorage::class];
        $nonceFactory = $definitions[NonceStorage::class];
        $this->assertIsCallable($deliveryFactory);
        $this->assertIsCallable($nonceFactory);

        $this->assertInstanceOf(DbWebhookDeliveryStorage::class, $deliveryFactory($this->sqlite()));
        $this->assertInstanceOf(DbNonceStorage::class, $nonceFactory($this->sqlite(), $this->fixedClock()));
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function loadDb(array $params): array
    {
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
