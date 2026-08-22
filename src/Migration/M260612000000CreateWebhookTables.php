<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WebhooksDb\Migration;

use Rasuvaeff\Yii3WebhooksDb\WebhookDeliveryTableName;
use Rasuvaeff\Yii3WebhooksDb\WebhookNonceTableName;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Creates tables used by yii3-webhooks DB storages.
 *
 * The names come from {@see WebhookDeliveryTableName} and
 * {@see WebhookNonceTableName}, which `config/di.php` builds from params — one
 * source of truth for the migration and the storages alike. Register the
 * migration by namespace:
 *
 * ```php
 * MigrationService::class => [
 *     'setSourceNamespaces()' => [['Rasuvaeff\\Yii3WebhooksDb\\Migration']],
 * ],
 * ```
 *
 * @api
 */
final readonly class M260612000000CreateWebhookTables implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function __construct(
        private WebhookDeliveryTableName $deliveryTable = new WebhookDeliveryTableName(),
        private WebhookNonceTableName $nonceTable = new WebhookNonceTableName(),
    ) {}

    #[\Override]
    public function up(MigrationBuilder $b): void
    {
        $b->createTable($this->deliveryTable->value, [
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
        // index names follow the table name: in PostgreSQL they are unique per
        // schema, so two installations sharing one schema would collide on a
        // hard-coded name
        $deliveryIndex = $this->deliveryTable->forIndexName();

        $b->createIndex($this->deliveryTable->value, sprintf('idx_%s_status_created', $deliveryIndex), ['status', 'created_at']);
        $b->createIndex($this->deliveryTable->value, sprintf('idx_%s_event_id', $deliveryIndex), 'event_id');
        $b->createTable($this->nonceTable->value, [
            'nonce' => 'string(255) NOT NULL PRIMARY KEY',
            'created_at' => 'string(30) NOT NULL',
        ]);
        $b->createIndex(
            $this->nonceTable->value,
            sprintf('idx_%s_created_at', $this->nonceTable->forIndexName()),
            'created_at',
        );
    }

    #[\Override]
    public function down(MigrationBuilder $b): void
    {
        $b->dropTable($this->nonceTable->value);
        $b->dropTable($this->deliveryTable->value);
    }
}
