<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WebhooksDb\Migration;

use Rasuvaeff\Yii3WebhooksDb\WebhookDeliveryTableName;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Adds the lease columns `DbWebhookDeliveryStorage::claimReady()` needs.
 *
 * `claimed_at` is when the lease was taken and `claimed_by` is the token of the
 * call that took it: the claim stamps both, reads back the rows carrying its own
 * token, and treats a `claimed_at` older than the lease as free. Ownership is
 * therefore expressible without adding a fourth `WebhookDeliveryStatus` — a
 * claimed delivery stays `pending`, so a worker that dies leaves nothing in a
 * state no code recovers from.
 *
 * The table name comes from {@see WebhookDeliveryTableName}, which `config/di.php`
 * builds from params — the same source of truth
 * {@see M260612000000CreateWebhookTables} uses. Register by namespace:
 *
 * ```php
 * MigrationService::class => [
 *     'setSourceNamespaces()' => [['Rasuvaeff\\Yii3WebhooksDb\\Migration']],
 * ],
 * ```
 *
 * `down()` works on MySQL and PostgreSQL only: `yiisoft/db-sqlite` cannot drop a
 * column, so rolling back on SQLite throws `NotSupportedException`.
 *
 * @api
 */
final class M260822120000AddDeliveryClaimColumns implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function __construct(
        private readonly WebhookDeliveryTableName $deliveryTable = new WebhookDeliveryTableName(),
    ) {}

    #[\Override]
    public function up(MigrationBuilder $b): void
    {
        // same width as created_at, which holds the identical 'Y-m-d H:i:s'
        $b->addColumn($this->deliveryTable->value, 'claimed_at', 'string(30)');
        // 32 hex characters, the token one claimReady() call stamps its rows with
        $b->addColumn($this->deliveryTable->value, 'claimed_by', 'string(32)');

        // every successful claim reads its own rows back by token; without this
        // index that is a sequential scan of a table nothing prunes by itself.
        // The candidate SELECT is a different query and keeps using
        // (status, created_at) — it orders by created_at.
        $b->createIndex($this->deliveryTable->value, $this->indexName(), 'claimed_by');
    }

    #[\Override]
    public function down(MigrationBuilder $b): void
    {
        $b->dropIndex($this->deliveryTable->value, $this->indexName());
        $b->dropColumn($this->deliveryTable->value, 'claimed_by');
        $b->dropColumn($this->deliveryTable->value, 'claimed_at');
    }

    /**
     * Index names follow the table's name: in PostgreSQL they are unique per
     * schema, so two installations sharing one schema would collide on a
     * hard-coded name.
     */
    private function indexName(): string
    {
        return sprintf('idx_%s_claimed_by', $this->deliveryTable->forIndexName());
    }
}
