<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WebhooksDb;

use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Webhooks\NonceStorage;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Exception\IntegrityException;
use Yiisoft\Db\Query\Query;

/**
 * @api
 */
final readonly class DbNonceStorage implements NonceStorage
{
    private const string TABLE_PATTERN = '/^[A-Za-z_]\w*(\.[A-Za-z_]\w*)?$/';

    /**
     * @param non-empty-string $table
     */
    public function __construct(
        private ConnectionInterface $db,
        private ClockInterface $clock,
        private string $table = 'webhook_nonces',
    ) {
        if (preg_match(self::TABLE_PATTERN, $table) !== 1) {
            throw new InvalidArgumentException('Invalid table name "' . $table . '"');
        }
    }

    #[\Override]
    public function has(string $nonce): bool
    {
        $row = (new Query($this->db))
            ->from($this->table)
            ->where(condition: ['nonce' => $nonce])
            ->one();

        return $row !== null;
    }

    #[\Override]
    public function add(string $nonce): bool
    {
        try {
            $affected = $this->db->createCommand()->insert(
                table: $this->table,
                columns: [
                    'nonce' => $nonce,
                    'created_at' => DateTimeSerializer::format($this->clock->now()),
                ],
            )->execute();

            return $affected > 0;
        } catch (IntegrityException) {
            return false;
        }
    }

    public function deleteOlderThan(\DateTimeImmutable $threshold): int
    {
        return $this->db->createCommand()->delete(
            table: $this->table,
            condition: ['<', 'created_at', DateTimeSerializer::format($threshold)],
        )->execute();
    }
}
