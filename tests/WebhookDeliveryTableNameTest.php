<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WebhooksDb\Tests;

use InvalidArgumentException;
use Rasuvaeff\Yii3WebhooksDb\WebhookDeliveryTableName;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(WebhookDeliveryTableName::class)]
final class WebhookDeliveryTableNameTest
{
    public function defaultsToTheDocumentedName(): void
    {
        Assert::same((new WebhookDeliveryTableName())->value, 'webhook_deliveries');
        Assert::same((string) new WebhookDeliveryTableName(), 'webhook_deliveries');
    }

    public function acceptsASchemaQualifiedName(): void
    {
        Assert::same((new WebhookDeliveryTableName('public.webhook_deliveries'))->value, 'public.webhook_deliveries');
    }

    public function indexBaseFlattensTheSchemaSeparator(): void
    {
        // a dot cannot appear in an index name
        Assert::same((new WebhookDeliveryTableName('public.webhook_deliveries'))->forIndexName(), 'public_webhook_deliveries');
        Assert::same((new WebhookDeliveryTableName('webhook_deliveries'))->forIndexName(), 'webhook_deliveries');
    }

    #[DataProvider('invalidNamesProvider')]
    public function rejectsAnythingOutsideTheIdentifierWhitelist(string $name): void
    {
        Expect::exception(InvalidArgumentException::class);

        new WebhookDeliveryTableName($name);
    }

    public static function invalidNamesProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'starts with digit' => ['1table'];
        yield 'space' => ['my table'];
        yield 'semicolon injection' => ['t; DROP TABLE users'];
        yield 'dash' => ['my-table'];
        yield 'two dots' => ['a.b.c'];
        // PCRE's $ also matches before a trailing newline — the pattern is
        // anchored with \z so this is rejected
        yield 'trailing newline' => ["webhook_deliveries\n"];
    }
}
