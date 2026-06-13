<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WebhooksDb;

use DateTimeImmutable;
use Rasuvaeff\Yii3Webhooks\WebhookDelivery;
use Rasuvaeff\Yii3Webhooks\WebhookDeliveryStatus;
use Rasuvaeff\Yii3Webhooks\WebhookDeliveryStorage;
use UnexpectedValueException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

/**
 * @api
 */
final readonly class DbWebhookDeliveryStorage implements WebhookDeliveryStorage
{
    /**
     * @param non-empty-string $table
     */
    public function __construct(
        private ConnectionInterface $db,
        private string $table = 'webhook_deliveries',
    ) {}

    #[\Override]
    public function save(WebhookDelivery $delivery): void
    {
        $this->db->createCommand()->upsert(
            table: $this->table,
            insertColumns: $this->toRow(delivery: $delivery),
        )->execute();
    }

    #[\Override]
    public function findPending(int $limit = 100): array
    {
        /** @var list<array<array-key, mixed>> $rows */
        $rows = (new Query($this->db))
            ->from($this->table)
            ->where(condition: ['status' => WebhookDeliveryStatus::Pending->value])
            ->orderBy(columns: ['created_at' => SORT_ASC, 'id' => SORT_ASC])
            ->limit($limit)
            ->all();

        return array_map(
            fn(array $row): WebhookDelivery => $this->fromRow(row: $row),
            $rows,
        );
    }

    #[\Override]
    public function markDelivered(WebhookDelivery $delivery): void
    {
        $this->db->createCommand()->update(
            table: $this->table,
            columns: $this->statusColumns(delivery: $delivery, status: WebhookDeliveryStatus::Delivered),
            condition: ['id' => $delivery->getId(), 'status' => WebhookDeliveryStatus::Pending->value],
        )->execute();
    }

    #[\Override]
    public function markFailed(WebhookDelivery $delivery): void
    {
        $this->db->createCommand()->update(
            table: $this->table,
            columns: $this->statusColumns(delivery: $delivery, status: WebhookDeliveryStatus::Failed),
            condition: ['id' => $delivery->getId(), 'status' => WebhookDeliveryStatus::Pending->value],
        )->execute();
    }

    #[\Override]
    public function getById(string $id): ?WebhookDelivery
    {
        $row = (new Query($this->db))
            ->from($this->table)
            ->where(condition: ['id' => $id])
            ->one();

        if ($row === null) {
            return null;
        }

        /** @var array<array-key, mixed> $row */
        return $this->fromRow(row: $row);
    }

    /**
     * @return array<string, int|string|null>
     */
    private function toRow(WebhookDelivery $delivery): array
    {
        return [
            'id' => $delivery->getId(),
            'event_id' => $delivery->getEventId(),
            'event_type' => $delivery->getEventType(),
            'endpoint_url' => $delivery->getEndpointUrl(),
            'status' => $delivery->getStatus()->value,
            'created_at' => DateTimeSerializer::format(dateTime: $delivery->getCreatedAt()),
            'attempts' => $delivery->getAttempts(),
            'last_attempt_at' => $delivery->getLastAttemptAt() !== null
                ? DateTimeSerializer::format(dateTime: $delivery->getLastAttemptAt())
                : null,
            'last_error' => $delivery->getLastError(),
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private function statusColumns(WebhookDelivery $delivery, WebhookDeliveryStatus $status): array
    {
        return [
            'status' => $status->value,
            'attempts' => $delivery->getAttempts(),
            'last_attempt_at' => $delivery->getLastAttemptAt() !== null
                ? DateTimeSerializer::format(dateTime: $delivery->getLastAttemptAt())
                : null,
            'last_error' => $delivery->getLastError(),
        ];
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function fromRow(array $row): WebhookDelivery
    {
        return new WebhookDelivery(
            id: $this->stringValue(row: $row, key: 'id'),
            eventId: $this->stringValue(row: $row, key: 'event_id'),
            eventType: $this->stringValue(row: $row, key: 'event_type'),
            endpointUrl: $this->stringValue(row: $row, key: 'endpoint_url'),
            status: WebhookDeliveryStatus::from($this->stringValue(row: $row, key: 'status')),
            createdAt: DateTimeSerializer::parse(value: $this->stringValue(row: $row, key: 'created_at')),
            attempts: (int) ($row['attempts'] ?? 0),
            lastAttemptAt: $this->nullableDateTime(value: $row['last_attempt_at'] ?? null),
            lastError: $this->nullableString(value: $row['last_error'] ?? null),
        );
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function stringValue(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        if (!\is_string($value)) {
            throw new UnexpectedValueException('Invalid webhook delivery row field "' . $key . '"');
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!\is_string($value)) {
            throw new UnexpectedValueException('Invalid nullable string field');
        }

        return $value;
    }

    private function nullableDateTime(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if (!\is_string($value)) {
            throw new UnexpectedValueException('Invalid nullable datetime field');
        }

        return DateTimeSerializer::parse(value: $value);
    }
}
