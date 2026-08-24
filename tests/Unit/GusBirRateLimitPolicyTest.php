<?php

declare(strict_types=1);

use cieplik206\BirRegon\RateLimit\GusBirRateLimitPolicy;
use DateTimeImmutable;
use DateTimeZone;

it('selects the official GUS request limits in Warsaw time', function (
    string $time,
    array $expected,
): void {
    $policy = GusBirRateLimitPolicy::at(new DateTimeImmutable(
        '2026-08-23 '.$time,
        new DateTimeZone('Europe/Warsaw'),
    ));

    expect([$policy->perSecond, $policy->perMinute, $policy->perHour])->toBe($expected);
})->with([
    'night before 06:00' => ['05:59:59', [4, 200, 10_000]],
    'morning from 06:00' => ['06:00:00', [3, 150, 8_000]],
    'morning through 07:59' => ['07:59:59', [3, 150, 8_000]],
    'business hours from 08:00' => ['08:00:00', [3, 120, 6_000]],
    'business hours through 16:59' => ['16:59:59', [3, 120, 6_000]],
    'evening from 17:00' => ['17:00:00', [3, 150, 8_000]],
    'evening through 21:59' => ['21:59:59', [3, 150, 8_000]],
    'night from 22:00' => ['22:00:00', [4, 200, 10_000]],
]);

it('selects the schedule after converting the clock to Warsaw time', function (): void {
    $policy = GusBirRateLimitPolicy::at(new DateTimeImmutable(
        '2026-08-23 06:00:00',
        new DateTimeZone('UTC'),
    ));

    expect([$policy->perSecond, $policy->perMinute, $policy->perHour])
        ->toBe([3, 120, 6_000]);
});
