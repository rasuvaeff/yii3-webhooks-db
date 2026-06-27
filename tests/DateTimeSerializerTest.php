<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WebhooksDb\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Rasuvaeff\Yii3WebhooksDb\DateTimeSerializer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;
use UnexpectedValueException;

#[Test]
#[Covers(DateTimeSerializer::class)]
final class DateTimeSerializerTest
{
    public function formatsToUtcString(): void
    {
        $dt = new DateTimeImmutable('2026-06-12 15:30:45', new DateTimeZone('UTC'));

        Assert::same(DateTimeSerializer::format($dt), '2026-06-12 15:30:45');
    }

    public function formatsConvertingNonUtcTimezone(): void
    {
        $dt = new DateTimeImmutable('2026-06-12 18:30:45', new DateTimeZone('Europe/Moscow'));

        Assert::same(DateTimeSerializer::format($dt), '2026-06-12 15:30:45');
    }

    public function formatsWithNegativeOffsetTimezone(): void
    {
        $dt = new DateTimeImmutable('2026-06-12 10:30:45', new DateTimeZone('-05:00'));

        Assert::same(DateTimeSerializer::format($dt), '2026-06-12 15:30:45');
    }

    public function parsesValidString(): void
    {
        $dt = DateTimeSerializer::parse('2026-06-12 15:30:45');

        Assert::same($dt->format('Y-m-d H:i:s'), '2026-06-12 15:30:45');
        Assert::same($dt->getTimezone()->getName(), 'UTC');
    }

    public function roundTripPreservesValue(): void
    {
        $original = new DateTimeImmutable('2026-01-31 23:59:59', new DateTimeZone('UTC'));

        $formatted = DateTimeSerializer::format($original);
        $parsed = DateTimeSerializer::parse($formatted);

        Assert::same($parsed->getTimestamp(), $original->getTimestamp());
    }

    public function roundTripWithNonUtcTimezone(): void
    {
        $local = new DateTimeImmutable('2026-03-15 12:00:00', new DateTimeZone('Asia/Tokyo'));

        $formatted = DateTimeSerializer::format($local);
        $parsed = DateTimeSerializer::parse($formatted);

        Assert::same($parsed->getTimestamp(), $local->getTimestamp());
    }

    public function formatConstantIsExpectedValue(): void
    {
        Assert::same(DateTimeSerializer::FORMAT, 'Y-m-d H:i:s');
    }

    public function parseThrowsOnInvalidFormat(): void
    {
        try {
            DateTimeSerializer::parse('not-a-date');
            Assert::fail('Expected UnexpectedValueException');
        } catch (UnexpectedValueException $e) {
            Assert::string($e->getMessage())->contains('Invalid datetime value: not-a-date');
        }
    }

    public function parseThrowsOnIso8601Format(): void
    {
        Expect::exception(UnexpectedValueException::class);

        DateTimeSerializer::parse('2026-06-12T15:30:45+00:00');
    }

    public function parseThrowsOnInvalidCalendarDate(): void
    {
        try {
            DateTimeSerializer::parse('2026-02-31 10:00:00');
            Assert::fail('Expected UnexpectedValueException');
        } catch (UnexpectedValueException $e) {
            Assert::string($e->getMessage())->contains('Invalid datetime value: 2026-02-31 10:00:00');
        }
    }

    public function parseThrowsOnEmptyString(): void
    {
        try {
            DateTimeSerializer::parse('');
            Assert::fail('Expected UnexpectedValueException');
        } catch (UnexpectedValueException $e) {
            Assert::string($e->getMessage())->contains('Invalid datetime value: ');
        }
    }

    #[DataProvider('boundaryValueProvider')]
    public function roundTripBoundaryValues(string $value): void
    {
        $parsed = DateTimeSerializer::parse($value);
        $formatted = DateTimeSerializer::format($parsed);

        Assert::same($formatted, $value);
    }

    public function utcTimezoneIsCachedAcrossCalls(): void
    {
        $prop = new \ReflectionProperty(DateTimeSerializer::class, 'utc');
        $prop->setValue(null, null);

        $dt = new DateTimeImmutable('2026-06-12 10:00:00', new DateTimeZone('UTC'));
        DateTimeSerializer::format($dt);
        $tz1 = $prop->getValue();

        DateTimeSerializer::format($dt);
        $tz2 = $prop->getValue();

        Assert::same($tz2, $tz1);
    }

    public static function boundaryValueProvider(): iterable
    {
        yield 'year start' => ['2026-01-01 00:00:00'];
        yield 'year end' => ['2026-12-31 23:59:59'];
        yield 'february 29 leap year' => ['2024-02-29 12:00:00'];
        yield 'midnight' => ['2026-06-12 00:00:00'];
    }
}
