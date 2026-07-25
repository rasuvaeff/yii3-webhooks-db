<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WebhooksDb\Tests;

use InvalidArgumentException;
use Rasuvaeff\Yii3WebhooksDb\WebhookNonceTableName;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(WebhookNonceTableName::class)]
final class WebhookNonceTableNameTest
{
    public function defaultsToTheDocumentedName(): void
    {
        Assert::same((new WebhookNonceTableName())->value, 'webhook_nonces');
        Assert::same((string) new WebhookNonceTableName(), 'webhook_nonces');
    }

    public function acceptsASchemaQualifiedName(): void
    {
        Assert::same((new WebhookNonceTableName('public.webhook_nonces'))->value, 'public.webhook_nonces');
    }

    public function indexBaseFlattensTheSchemaSeparator(): void
    {
        // a dot cannot appear in an index name
        Assert::same((new WebhookNonceTableName('public.webhook_nonces'))->forIndexName(), 'public_webhook_nonces');
        Assert::same((new WebhookNonceTableName('webhook_nonces'))->forIndexName(), 'webhook_nonces');
    }

    #[DataProvider('invalidNamesProvider')]
    public function rejectsAnythingOutsideTheIdentifierWhitelist(string $name): void
    {
        Expect::exception(InvalidArgumentException::class);

        new WebhookNonceTableName($name);
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
        yield 'trailing newline' => ["webhook_nonces\n"];
    }
}
