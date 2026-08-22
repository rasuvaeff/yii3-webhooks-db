<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WebhooksDb;

use DateTimeImmutable;
use InvalidArgumentException;
use Rasuvaeff\Yii3Webhooks\ClaimingDeliveryStorage;
use Rasuvaeff\Yii3Webhooks\WebhookDelivery;
use Rasuvaeff\Yii3Webhooks\WebhookDeliveryStatus;
use Rasuvaeff\Yii3Webhooks\WebhookDeliveryStorage;
use UnexpectedValueException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

/**
 * Delivery storage on top of `yiisoft/db`.
 *
 * Beyond the `WebhookDeliveryStorage` contract it can hand a delivery to exactly
 * one worker: {@see self::claimReady()} leases the deliveries whose backoff has
 * elapsed and {@see self::releaseClaim()} gives a lease back early. Ownership is
 * a lease, not a status — a claimed delivery stays `Pending` and becomes
 * claimable again once `claimed_at` is older than the lease, so a worker killed
 * mid-flight strands nothing.
 *
 * The `ClaimingDeliveryStorage` clause is what a worker detects with
 * `instanceof` before it takes the claiming path, so it is not decoration: drop
 * it and the core silently falls back to `findPending()`, the very double
 * delivery the lease exists to prevent.
 *
 * @api
 */
final readonly class DbWebhookDeliveryStorage implements WebhookDeliveryStorage, ClaimingDeliveryStorage
{
    /**
     * The columns {@see self::save()} withholds from a row that already exists.
     *
     * `id` is the key, and `status` belongs to `markDelivered()`/`markFailed()`
     * alone — the claim writes the lease columns and leaves the status alone.
     * Writing it back here would let a worker holding a stale `Pending` copy
     * resurrect a delivery somebody else already finished, and send the same
     * webhook twice.
     */
    private const array SAVE_PROTECTED_COLUMNS = ['id', 'status'];

    private const array TERMINAL_STATUSES = [
        WebhookDeliveryStatus::Delivered,
        WebhookDeliveryStatus::Failed,
    ];

    private string $table;

    /**
     * @param non-empty-string $table
     *
     * @throws InvalidArgumentException when the name is not a valid identifier
     */
    public function __construct(
        private ConnectionInterface $db,
        string $table = 'webhook_deliveries',
    ) {
        // validation lives in the value object, so the storage and the bundled
        // migration cannot disagree about what a valid table name is
        $this->table = (new WebhookDeliveryTableName($table))->value;
    }

    #[\Override]
    public function save(WebhookDelivery $delivery): void
    {
        $row = $this->toRow(delivery: $delivery);

        $this->db->createCommand()->upsert(
            table: $this->table,
            insertColumns: $row,
            updateColumns: array_diff_key($row, array_flip(self::SAVE_PROTECTED_COLUMNS)),
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

    /**
     * Atomically leases up to $limit pending deliveries that are ready for
     * another attempt, and returns them.
     *
     * A delivery qualifies when its lease is free — `claimed_at` null, or older
     * than $leaseSeconds — and it is ready: never attempted, out of attempts, or
     * its last attempt is at or before the threshold for its attempt count.
     *
     * An exhausted delivery (`attempts >= $maxAttempts`) is deliberately handed
     * out rather than filtered. It can only ever be marked `Failed`, and the
     * caller can only mark what it was given — filter it and nothing terminates
     * it: it stays `Pending` forever, invisible to an alert watching `Failed`.
     *
     * $readyThresholds comes from `WebhookRetryPolicy::readyThresholds()`; the
     * backoff is the core's business and is never re-derived here. Each key
     * governs every attempt count from itself up to the next key, and the
     * highest one governs everything above it — a map that skips a count still
     * has a rule for it.
     *
     * The lease is stamped with a token unique to this call, so the read-back
     * returns exactly the rows this call won and no transaction is needed: the
     * `UPDATE` re-checks the lease and the status it selected on, and a loser of
     * the race simply updates nothing.
     *
     * Every returned delivery must be moved on — {@see self::markDelivered()},
     * {@see self::markFailed()} or {@see self::releaseClaim()} — or it waits out
     * the whole lease before anyone sees it again. $leaseSeconds must outlive
     * the slowest delivery attempt, or two workers get the same delivery.
     *
     * @param array<int, DateTimeImmutable> $readyThresholds attempt count => the
     *        latest `last_attempt_at` that is ready at $now
     *
     * @return list<WebhookDelivery>
     */
    #[\Override]
    public function claimReady(
        DateTimeImmutable $now,
        array $readyThresholds,
        int $maxAttempts,
        int $leaseSeconds = 300,
        int $limit = 100,
    ): array {
        $leaseExpiry = DateTimeSerializer::format(dateTime: $now->modify('-' . $leaseSeconds . ' seconds'));
        $claimable = $this->claimableCondition(
            readyThresholds: $readyThresholds,
            maxAttempts: $maxAttempts,
            leaseExpiry: $leaseExpiry,
        );

        /** @var list<string> $candidates */
        $candidates = (new Query($this->db))
            ->select('id')
            ->from($this->table)
            ->where(condition: $claimable)
            ->orderBy(columns: ['created_at' => SORT_ASC, 'id' => SORT_ASC])
            ->limit($limit)
            ->column();

        // an idle poll stops after that one SELECT: neither the write lock of a
        // no-op UPDATE nor the extra claimed_by lookup behind it is worth paying
        // on every cycle of a worker with nothing to do
        if ($candidates !== []) {
            $token = bin2hex(random_bytes(16));

            $this->db->createCommand()->update(
                table: $this->table,
                columns: ['claimed_at' => DateTimeSerializer::format(dateTime: $now), 'claimed_by' => $token],
                // the candidate list is a snapshot; the lease and the status are
                // re-checked here, where the row is actually locked, so a worker
                // that lost the race between the two queries stamps nothing
                condition: ['and', ['id' => $candidates], $claimable],
            )->execute();

            /** @var list<array<array-key, mixed>> $rows */
            $rows = (new Query($this->db))
                ->from($this->table)
                ->where(condition: ['claimed_by' => $token])
                ->orderBy(columns: ['created_at' => SORT_ASC, 'id' => SORT_ASC])
                ->all();

            return array_map(
                fn(array $row): WebhookDelivery => $this->fromRow(row: $row),
                $rows,
            );
        }

        return [];
    }

    /**
     * Gives a lease back before it expires, so the delivery is claimable again
     * as soon as its backoff allows instead of after the full lease.
     *
     * Returns true when a lease was actually cleared — false means the delivery
     * is unknown, no longer pending, or was not leased at all.
     */
    #[\Override]
    public function releaseClaim(WebhookDelivery $delivery): bool
    {
        return $this->db->createCommand()->update(
            table: $this->table,
            columns: ['claimed_at' => null, 'claimed_by' => null],
            condition: [
                'and',
                ['id' => $delivery->getId()],
                ['status' => WebhookDeliveryStatus::Pending->value],
                ['not', ['claimed_at' => null]],
            ],
        )->execute() > 0;
    }

    /**
     * Deletes finished deliveries created before $threshold, and returns how
     * many rows went.
     *
     * Nothing else in this package removes a delivery row, so without a periodic
     * call the table grows for as long as the application runs. The default
     * statuses are the terminal ones on purpose: passing `Pending` deletes work
     * that was never done, which is a decision the caller has to make out loud.
     *
     * The `(status, created_at)` index of the bundled migration already serves
     * the query.
     */
    public function deleteOlderThan(DateTimeImmutable $threshold, WebhookDeliveryStatus ...$statuses): int
    {
        $statuses = $statuses === [] ? self::TERMINAL_STATUSES : $statuses;
        $values = [];

        foreach ($statuses as $status) {
            $values[] = $status->value;
        }

        return $this->db->createCommand()->delete(
            table: $this->table,
            condition: [
                'and',
                ['status' => $values],
                ['<', 'created_at', DateTimeSerializer::format(dateTime: $threshold)],
            ],
        )->execute();
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
            'last_attempt_at' => $delivery->getLastAttemptAt() instanceof \DateTimeImmutable
                ? DateTimeSerializer::format(dateTime: $delivery->getLastAttemptAt())
                : null,
            'last_error' => $delivery->getLastError(),
        ];
    }

    /**
     * `status = 'pending'` plus a free lease plus readiness — the predicate the
     * claim selects candidates on and re-checks when it stamps them.
     *
     * @param array<int, DateTimeImmutable> $readyThresholds
     *
     * @return array<int, mixed>
     */
    private function claimableCondition(array $readyThresholds, int $maxAttempts, string $leaseExpiry): array
    {
        $condition = [
            'and',
            ['status' => WebhookDeliveryStatus::Pending->value],
            ['or', ['claimed_at' => null], ['<=', 'claimed_at', $leaseExpiry]],
        ];

        $ready = $this->readyCondition(readyThresholds: $readyThresholds, maxAttempts: $maxAttempts);

        if ($ready !== null) {
            $condition[] = $ready;
        }

        return $condition;
    }

    /**
     * The backoff rule as SQL, or null when the policy has no retry step to wait
     * for and every pending delivery is ready.
     *
     * Every key stands for a range — its own attempt count up to the next key,
     * exclusive — so that the branches partition every attempt count between
     * them and none can fall through. A key is not required to have a successor:
     * `WebhookRetryPolicy::readyThresholds()` numbers them contiguously, but a
     * map built by hand may skip counts, and under an equality test `[1 => …,
     * 3 => …]` left a delivery on its second attempt matching no branch at all —
     * never ready, never exhausted, stuck `Pending` for good.
     *
     * The highest key has no upper bound: the core ends the map where the delay
     * stops growing, so that one threshold stands for every larger attempt
     * count.
     *
     * @param array<int, DateTimeImmutable> $readyThresholds
     *
     * @return array<int, mixed>|null
     */
    private function readyCondition(array $readyThresholds, int $maxAttempts): ?array
    {
        if ($readyThresholds === []) {
            return null;
        }

        // the core hands the map back in ascending order; sorting here means the
        // ranges below are built from neighbours even if a caller builds the map
        // by hand and does not sort it
        ksort($readyThresholds);

        $condition = [
            'or',
            ['last_attempt_at' => null],
            ['<', 'attempts', array_key_first($readyThresholds)],
            // out of attempts: handed out so that something can finally fail it
            ['>=', 'attempts', $maxAttempts],
        ];

        // walking down from the highest key, the count handled by the previous
        // iteration is exactly where this one's range ends
        $upperBound = null;

        foreach (array_reverse($readyThresholds, preserve_keys: true) as $count => $threshold) {
            $branch = [
                'and',
                ['>=', 'attempts', $count],
                ['<=', 'last_attempt_at', DateTimeSerializer::format(dateTime: $threshold)],
            ];

            if ($upperBound !== null) {
                $branch[] = ['<', 'attempts', $upperBound];
            }

            $condition[] = $branch;
            $upperBound = $count;
        }

        return $condition;
    }

    /**
     * @return array<string, int|string|null>
     */
    private function statusColumns(WebhookDelivery $delivery, WebhookDeliveryStatus $status): array
    {
        return [
            'status' => $status->value,
            // the delivery is finished; a lease over it would only keep a
            // terminated row looking busy to whoever reads the table
            'claimed_at' => null,
            'claimed_by' => null,
            'attempts' => $delivery->getAttempts(),
            'last_attempt_at' => $delivery->getLastAttemptAt() instanceof \DateTimeImmutable
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
