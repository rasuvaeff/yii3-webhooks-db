<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WebhooksDb\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rasuvaeff\Yii3WebhooksDb\DateTimeSerializer;
use UnexpectedValueException;

#[CoversClass(DateTimeSerializer::class)]
final class DateTimeSerializerTest extends TestCase
{
    #[Test]
    public function formatsToUtcString(): void
    {
        $dt = new DateTimeImmutable('2026-06-12 15:30:45', new DateTimeZone('UTC'));

        $this->assertSame('2026-06-12 15:30:45', DateTimeSerializer::format($dt));
    }

    #[Test]
    public function formatsConvertingNonUtcTimezone(): void
    {
        $dt = new DateTimeImmutable('2026-06-12 18:30:45', new DateTimeZone('Europe/Moscow'));

        $this->assertSame('2026-06-12 15:30:45', DateTimeSerializer::format($dt));
    }

    #[Test]
    public function formatsWithNegativeOffsetTimezone(): void
    {
        $dt = new DateTimeImmutable('2026-06-12 10:30:45', new DateTimeZone('-05:00'));

        $this->assertSame('2026-06-12 15:30:45', DateTimeSerializer::format($dt));
    }

    #[Test]
    public function parsesValidString(): void
    {
        $dt = DateTimeSerializer::parse('2026-06-12 15:30:45');

        $this->assertSame('2026-06-12 15:30:45', $dt->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $dt->getTimezone()->getName());
    }

    #[Test]
    public function roundTripPreservesValue(): void
    {
        $original = new DateTimeImmutable('2026-01-31 23:59:59', new DateTimeZone('UTC'));

        $formatted = DateTimeSerializer::format($original);
        $parsed = DateTimeSerializer::parse($formatted);

        $this->assertSame($original->getTimestamp(), $parsed->getTimestamp());
    }

    #[Test]
    public function roundTripWithNonUtcTimezone(): void
    {
        $local = new DateTimeImmutable('2026-03-15 12:00:00', new DateTimeZone('Asia/Tokyo'));

        $formatted = DateTimeSerializer::format($local);
        $parsed = DateTimeSerializer::parse($formatted);

        $this->assertSame($local->getTimestamp(), $parsed->getTimestamp());
    }

    #[Test]
    public function formatConstantIsExpectedValue(): void
    {
        $this->assertSame('Y-m-d H:i:s', DateTimeSerializer::FORMAT);
    }

    #[Test]
    public function parseThrowsOnInvalidFormat(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Invalid datetime value: not-a-date');

        DateTimeSerializer::parse('not-a-date');
    }

    #[Test]
    public function parseThrowsOnIso8601Format(): void
    {
        $this->expectException(UnexpectedValueException::class);

        DateTimeSerializer::parse('2026-06-12T15:30:45+00:00');
    }

    #[Test]
    public function parseThrowsOnEmptyString(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Invalid datetime value: ');

        DateTimeSerializer::parse('');
    }

    #[DataProvider('boundaryValueProvider')]
    #[Test]
    public function roundTripBoundaryValues(string $value): void
    {
        $parsed = DateTimeSerializer::parse($value);
        $formatted = DateTimeSerializer::format($parsed);

        $this->assertSame($value, $formatted);
    }

    #[Test]
    public function utcTimezoneIsCachedAcrossCalls(): void
    {
        $prop = new \ReflectionProperty(DateTimeSerializer::class, 'utc');
        $prop->setValue(null, null);

        $dt = new DateTimeImmutable('2026-06-12 10:00:00', new DateTimeZone('UTC'));
        DateTimeSerializer::format($dt);
        $tz1 = $prop->getValue();

        DateTimeSerializer::format($dt);
        $tz2 = $prop->getValue();

        $this->assertSame($tz1, $tz2);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function boundaryValueProvider(): iterable
    {
        yield 'year start' => ['2026-01-01 00:00:00'];
        yield 'year end' => ['2026-12-31 23:59:59'];
        yield 'february 29 leap year' => ['2024-02-29 12:00:00'];
        yield 'midnight' => ['2026-06-12 00:00:00'];
    }
}
