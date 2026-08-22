<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WebhooksDb\Tests;

use Yiisoft\Db\Command\CommandInterface;
use Yiisoft\Db\Query\QueryInterface;

/**
 * @internal
 */
final class FakeCommand implements CommandInterface
{
    /** @var array{table: string|null, columns: array|QueryInterface}|null */
    public ?array $insertCapture = null;

    /** @var array{table: string|null, condition: mixed}|null */
    public ?array $deleteCapture = null;

    public function __construct(
        private readonly int $executeResult = 1,
    ) {}

    public function insert(string $table, array|QueryInterface $columns): static
    {
        $this->insertCapture = ['table' => $table, 'columns' => $columns];

        return $this;
    }

    public function delete(string $table, array|string $condition = '', array $params = []): static
    {
        $this->deleteCapture = ['table' => $table, 'condition' => $condition];

        return $this;
    }

    public function execute(): int
    {
        return $this->executeResult;
    }

    public function addCheck(string $table, string $name, string $expression): static
    {
        return $this;
    }

    public function addColumn(string $table, string $column, mixed $type): static
    {
        return $this;
    }

    public function addCommentOnColumn(string $table, string $column, string $comment): static
    {
        return $this;
    }

    public function addCommentOnTable(string $table, string $comment): static
    {
        return $this;
    }

    public function addDefaultValue(string $table, string $name, string $column, mixed $value): static
    {
        return $this;
    }

    public function addForeignKey(string $table, string $name, array|string $columns, string $referenceTable, array|string $referenceColumns, ?string $delete = null, ?string $update = null): static
    {
        return $this;
    }

    public function addPrimaryKey(string $table, string $name, array|string $columns): static
    {
        return $this;
    }

    public function addUnique(string $table, string $name, array|string $columns): static
    {
        return $this;
    }

    public function alterColumn(string $table, string $column, mixed $type): static
    {
        return $this;
    }

    public function bindParam(int|string $name, mixed &$value, ?int $dataType = null, ?int $length = null, mixed $driverOptions = null): static
    {
        return $this;
    }

    public function bindValue(int|string $name, mixed $value, ?int $dataType = null): static
    {
        return $this;
    }

    public function bindValues(array $values): static
    {
        return $this;
    }

    public function cancel(): void {}

    public function checkIntegrity(string $schema, string $table, bool $check = true): static
    {
        return $this;
    }

    public function createIndex(string $table, string $name, array|string $columns, ?string $indexType = null, ?string $indexMethod = null): static
    {
        return $this;
    }

    public function createTable(string $table, array $columns, ?string $options = null): static
    {
        return $this;
    }

    public function createView(string $viewName, QueryInterface|string $subQuery): static
    {
        return $this;
    }

    public function dropCheck(string $table, string $name): static
    {
        return $this;
    }

    public function dropColumn(string $table, string $column): static
    {
        return $this;
    }

    public function dropCommentFromColumn(string $table, string $column): static
    {
        return $this;
    }

    public function dropCommentFromTable(string $table): static
    {
        return $this;
    }

    public function dropDefaultValue(string $table, string $name): static
    {
        return $this;
    }

    public function dropForeignKey(string $table, string $name): static
    {
        return $this;
    }

    public function dropIndex(string $table, string $name): static
    {
        return $this;
    }

    public function dropPrimaryKey(string $table, string $name): static
    {
        return $this;
    }

    public function dropTable(string $table, bool $ifExists = false, bool $cascade = false): static
    {
        return $this;
    }

    public function dropUnique(string $table, string $name): static
    {
        return $this;
    }

    public function dropView(string $viewName): static
    {
        return $this;
    }

    public function getParams(bool $asValues = true): array
    {
        return [];
    }

    public function getRawSql(): string
    {
        return '';
    }

    public function getSql(): string
    {
        return '';
    }

    public function insertBatch(string $table, iterable $rows, array $columns = []): static
    {
        return $this;
    }

    public function insertReturningPks(string $table, array|QueryInterface $columns): array
    {
        return [];
    }

    public function prepare(?bool $forRead = null): void {}

    public function query(): \Yiisoft\Db\Query\DataReaderInterface
    {
        throw new \Yiisoft\Db\Exception\NotSupportedException('Not implemented in fake');
    }

    public function queryAll(): array
    {
        return [];
    }

    public function queryColumn(): array
    {
        return [];
    }

    public function queryOne(): ?array
    {
        return null;
    }

    public function queryScalar(): bool|string|int|float|null
    {
        return null;
    }

    public function renameColumn(string $table, string $oldName, string $newName): static
    {
        return $this;
    }

    public function renameTable(string $table, string $newName): static
    {
        return $this;
    }

    public function resetSequence(string $table, int|string|null $value = null): static
    {
        return $this;
    }

    public function setRawSql(string $sql): static
    {
        return $this;
    }

    public function setRetryHandler(?\Closure $handler): static
    {
        return $this;
    }

    public function setSql(string $sql): static
    {
        return $this;
    }

    public function showDatabases(): array
    {
        return [];
    }

    public function truncateTable(string $table): static
    {
        return $this;
    }

    public function update(string $table, array $columns, array|\Yiisoft\Db\Expression\ExpressionInterface|string $condition = '', array|\Yiisoft\Db\Expression\ExpressionInterface|string|null $from = null, array $params = []): static
    {
        return $this;
    }

    public function upsert(string $table, array|QueryInterface $insertColumns, array|bool $updateColumns = true): static
    {
        return $this;
    }

    public function upsertReturning(string $table, array|QueryInterface $insertColumns, array|bool $updateColumns = true, ?array $returnColumns = null): array
    {
        return [];
    }

    public function upsertReturningPks(string $table, array|QueryInterface $insertColumns, array|bool $updateColumns = true): array
    {
        return [];
    }

    public function withDbTypecasting(bool $dbTypecasting = true): static
    {
        return $this;
    }

    public function withPhpTypecasting(bool $phpTypecasting = true): static
    {
        return $this;
    }

    public function withTypecasting(bool $typecasting = true): static
    {
        return $this;
    }
}
