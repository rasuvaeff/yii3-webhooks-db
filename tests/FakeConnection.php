<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WebhooksDb\Tests;

use Closure;
use Yiisoft\Db\Command\CommandInterface;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Connection\ServerInfoInterface;
use Yiisoft\Db\Expression\ExpressionInterface;
use Yiisoft\Db\Query\BatchQueryResultInterface;
use Yiisoft\Db\Query\QueryInterface;
use Yiisoft\Db\QueryBuilder\QueryBuilderInterface;
use Yiisoft\Db\Schema\Column\ColumnFactoryInterface;
use Yiisoft\Db\Schema\QuoterInterface;
use Yiisoft\Db\Schema\SchemaInterface;
use Yiisoft\Db\Schema\TableSchemaInterface;
use Yiisoft\Db\Transaction\TransactionInterface;

/**
 * @internal
 */
final class FakeConnection implements ConnectionInterface
{
    private CommandInterface $command;

    public function __construct(CommandInterface $command)
    {
        $this->command = $command;
    }

    public function createCommand(?string $sql = null, array $params = []): CommandInterface
    {
        return $this->command;
    }

    public function beginTransaction(?string $isolationLevel = null): TransactionInterface
    {
        throw new \Yiisoft\Db\Exception\NotSupportedException('Not implemented in fake');
    }

    public function createBatchQueryResult(QueryInterface $query): BatchQueryResultInterface
    {
        throw new \Yiisoft\Db\Exception\NotSupportedException('Not implemented in fake');
    }

    public function createQuery(): QueryInterface
    {
        throw new \Yiisoft\Db\Exception\NotSupportedException('Not implemented in fake');
    }

    public function createTransaction(): TransactionInterface
    {
        throw new \Yiisoft\Db\Exception\NotSupportedException('Not implemented in fake');
    }

    public function close(): void {}

    public function getColumnBuilderClass(): string
    {
        return '';
    }

    public function getColumnFactory(): ColumnFactoryInterface
    {
        throw new \Yiisoft\Db\Exception\NotSupportedException('Not implemented in fake');
    }

    public function getDriverName(): string
    {
        return 'sqlite';
    }

    public function getLastInsertId(?string $sequenceName = null): string
    {
        return '';
    }

    public function getQueryBuilder(): QueryBuilderInterface
    {
        throw new \Yiisoft\Db\Exception\NotSupportedException('Not implemented in fake');
    }

    public function getQuoter(): QuoterInterface
    {
        throw new \Yiisoft\Db\Exception\NotSupportedException('Not implemented in fake');
    }

    public function getSchema(): SchemaInterface
    {
        throw new \Yiisoft\Db\Exception\NotSupportedException('Not implemented in fake');
    }

    public function getServerInfo(): ServerInfoInterface
    {
        throw new \Yiisoft\Db\Exception\NotSupportedException('Not implemented in fake');
    }

    public function getTablePrefix(): string
    {
        return '';
    }

    public function getTableSchema(string $name, bool $refresh = false): ?TableSchemaInterface
    {
        return null;
    }

    public function getTransaction(): ?TransactionInterface
    {
        return null;
    }

    public function isActive(): bool
    {
        return false;
    }

    public function isSavepointEnabled(): bool
    {
        return false;
    }

    public function open(): void {}

    public function quoteValue(mixed $value): mixed
    {
        return $value;
    }

    public function setEnableSavepoint(bool $value): void {}

    public function select(
        array|bool|float|int|string|ExpressionInterface $columns = [],
        ?string $option = null,
    ): QueryInterface {
        throw new \Yiisoft\Db\Exception\NotSupportedException('Not implemented in fake');
    }

    public function setTablePrefix(string $value): void {}

    public function transaction(Closure $closure, ?string $isolationLevel = null): mixed
    {
        throw new \Yiisoft\Db\Exception\NotSupportedException('Not implemented in fake');
    }
}
