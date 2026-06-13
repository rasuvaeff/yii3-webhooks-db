<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Creates tables used by yii3-webhooks DB storages.
 */
final class M260612000000CreateWebhookTables implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    /**
     * @param non-empty-string $deliveryTable
     * @param non-empty-string $nonceTable
     */
    public function __construct(
        private readonly string $deliveryTable = 'webhook_deliveries',
        private readonly string $nonceTable = 'webhook_nonces',
    ) {}

    #[\Override]
    public function up(MigrationBuilder $b): void
    {
        $b->createTable($this->deliveryTable, [
            'id' => 'string(32) NOT NULL PRIMARY KEY',
            'event_id' => 'string(255) NOT NULL',
            'event_type' => 'string(255) NOT NULL',
            'endpoint_url' => 'text NOT NULL',
            'status' => 'string(32) NOT NULL',
            'created_at' => 'string(30) NOT NULL',
            'attempts' => 'integer NOT NULL DEFAULT 0',
            'last_attempt_at' => 'string(30)',
            'last_error' => 'text',
        ]);
        $b->createIndex($this->deliveryTable, 'idx_webhook_deliveries_status_created', ['status', 'created_at']);
        $b->createIndex($this->deliveryTable, 'idx_webhook_deliveries_event_id', 'event_id');
        $b->createTable($this->nonceTable, [
            'nonce' => 'string(255) NOT NULL PRIMARY KEY',
            'created_at' => 'string(30) NOT NULL',
        ]);
        $b->createIndex($this->nonceTable, 'idx_webhook_nonces_created_at', 'created_at');
    }

    #[\Override]
    public function down(MigrationBuilder $b): void
    {
        $b->dropTable($this->nonceTable);
        $b->dropTable($this->deliveryTable);
    }
}
