<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\RateLimit;

use DateTimeImmutable;
use DateTimeZone;

final readonly class GusBirRateLimitPolicy
{
    private const TIME_ZONE = 'Europe/Warsaw';

    private function __construct(
        public int $perSecond,
        public int $perMinute,
        public int $perHour,
    ) {}

    public static function at(DateTimeImmutable $now): self
    {
        $hour = (int) $now
            ->setTimezone(new DateTimeZone(self::TIME_ZONE))
            ->format('G');

        if ($hour >= 8 && $hour <= 16) {
            return new self(3, 120, 6_000);
        }

        if (($hour >= 6 && $hour <= 7) || ($hour >= 17 && $hour <= 21)) {
            return new self(3, 150, 8_000);
        }

        return new self(4, 200, 10_000);
    }
}
