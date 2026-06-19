<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3WebhooksDb;

use DateTimeImmutable;
use DateTimeZone;
use UnexpectedValueException;

/**
 * @internal
 */
final class DateTimeSerializer
{
    public const string FORMAT = 'Y-m-d H:i:s';

    private static ?DateTimeZone $utc = null;

    public static function format(DateTimeImmutable $dateTime): string
    {
        return $dateTime->setTimezone(self::utc())->format(self::FORMAT);
    }

    public static function parse(string $value): DateTimeImmutable
    {
        $dt = DateTimeImmutable::createFromFormat(self::FORMAT, $value, self::utc());

        if ($dt === false) {
            throw new UnexpectedValueException('Invalid datetime value: ' . $value);
        }

        return $dt;
    }

    private static function utc(): DateTimeZone
    {
        return self::$utc ??= new DateTimeZone('UTC');
    }
}
