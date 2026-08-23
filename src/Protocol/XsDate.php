<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Protocol;

use DateTimeImmutable;
use DateTimeZone;

/** @internal */
final class XsDate
{
    private const PATTERN = '/^(?<year>(?!0000)\d{4})-(?<month>\d{2})-(?<day>\d{2})(?:Z|[+-](?:(?:0\d|1[0-3]):[0-5]\d|14:00))?$/D';

    public static function normalize(string $value): string
    {
        $normalized = preg_replace('/[\x20\x09\x0D\x0A]+/', ' ', $value);

        return trim($normalized ?? $value, ' ');
    }

    public static function isValid(?string $value): bool
    {
        if ($value === null) {
            return true;
        }

        $value = self::normalize($value);

        if (preg_match(self::PATTERN, $value, $parts) !== 1) {
            return false;
        }

        return checkdate(
            (int) $parts['month'],
            (int) $parts['day'],
            (int) $parts['year'],
        );
    }

    public static function toDateTimeImmutable(
        string $value,
        DateTimeZone $defaultTimezone,
    ): ?DateTimeImmutable {
        $value = self::normalize($value);

        if (! self::isValid($value)) {
            return null;
        }

        return new DateTimeImmutable($value, $defaultTimezone);
    }
}
