<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WebhooksDb\Benchmarks;

use DateTimeImmutable;
use Rasuvaeff\Yii3WebhooksDb\DateTimeSerializer;
use Testo\Bench;

final class AdapterBench
{
    #[Bench(
        callables: [
            'parse' => [self::class, 'parseDateTime'],
        ],
        calls: 1_000,
        iterations: 10,
    )]
    public static function formatDateTime(): string
    {
        return DateTimeSerializer::format(dateTime: new DateTimeImmutable('2024-06-15 14:32:10'));
    }

    public static function parseDateTime(): DateTimeImmutable
    {
        return DateTimeSerializer::parse(value: '2024-06-15 14:32:10');
    }
}
