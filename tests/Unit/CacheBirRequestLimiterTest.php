<?php

declare(strict_types=1);

use cieplik206\BirRegon\Enums\Environment;
use cieplik206\BirRegon\Exceptions\BirRateLimitException;
use cieplik206\BirRegon\Protocol\BirOperation;
use cieplik206\BirRegon\Protocol\SearchCriteria;
use cieplik206\BirRegon\RateLimit\CacheBirRequestLimiter;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Cache\TaggedCache;
use Illuminate\Cache\TagSet;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Redis\RedisManager;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

it('shares an atomic request budget and paces short second-level debt', function (): void {
    $apiKey = 'APIKEYSENTINEL123456';
    $prefix = 'bir-test-shared';
    $store = new ArrayStore;
    $cache = new Repository($store);
    $now = new DateTimeImmutable('2026-08-23 08:00:00', new DateTimeZone('Europe/Warsaw'));
    $clock = static function () use (&$now): DateTimeImmutable {
        return $now;
    };
    $sleeps = [];
    $lockWasAvailableDuringSleep = false;
    $stateKey = $prefix.':prod:'.hash('sha256', $apiKey).':state';
    $sleeper = static function (float $seconds) use (
        &$now,
        &$sleeps,
        &$lockWasAvailableDuringSleep,
        $stateKey,
        $store,
    ): void {
        $sleeps[] = $seconds;
        $lock = $store->lock($stateKey.':lock', 5);
        $lockWasAvailableDuringSleep = $lock->get();

        if ($lockWasAvailableDuringSleep) {
            $lock->release();
        }

        $now = rateLimitTimeAt((float) $now->format('U.u') + $seconds);
    };
    $first = new CacheBirRequestLimiter(
        $cache,
        Environment::Production,
        $apiKey,
        $prefix,
        $clock,
        $sleeper,
    );
    $second = new CacheBirRequestLimiter(
        $cache,
        Environment::Production,
        $apiKey,
        $prefix,
        $clock,
        $sleeper,
    );

    $first->acquire(BirOperation::Login);
    $second->acquire(BirOperation::GetValue);
    $first->acquire(BirOperation::Logout);

    $second->acquire(BirOperation::FullReport);

    expect($sleeps)->toHaveCount(3)
        ->and($sleeps[0])->toBeGreaterThan(0.33)
        ->and($sleeps[0])->toBeLessThan(0.34)
        ->and($sleeps[1])->toBeGreaterThan(0.33)
        ->and($sleeps[1])->toBeLessThan(0.34)
        ->and($sleeps[2])->toBeGreaterThan(0.33)
        ->and($sleeps[2])->toBeLessThan(0.34)
        ->and($lockWasAvailableDuringSleep)->toBeTrue();
});

it('paces every individual SOAP operation at the exact daytime spacing', function (
    BirOperation $operation,
    ?SearchCriteria $criteria,
): void {
    $cache = new Repository(new ArrayStore);
    $now = new DateTimeImmutable('2026-08-23 08:00:00', new DateTimeZone('Europe/Warsaw'));
    $sleeps = [];
    $limiter = new CacheBirRequestLimiter(
        $cache,
        Environment::Production,
        'APIKEYSENTINEL123456',
        'bir-test-operation-'.$operation->name,
        static function () use (&$now): DateTimeImmutable {
            return $now;
        },
        static function (float $seconds) use (&$now, &$sleeps): void {
            $sleeps[] = $seconds;
            $now = rateLimitTimeAt((float) $now->format('U.u') + $seconds);
        },
    );
    $parameters = $criteria === null ? [] : ['criteria' => $criteria];

    foreach (range(1, 4) as $_) {
        $limiter->acquire($operation, $parameters);
    }

    expect($sleeps)->toHaveCount(3)
        ->and($sleeps[0])->toBeGreaterThan(0.33)
        ->and($sleeps[0])->toBeLessThan(0.34)
        ->and($sleeps[1])->toBeGreaterThan(0.33)
        ->and($sleeps[1])->toBeLessThan(0.34)
        ->and($sleeps[2])->toBeGreaterThan(0.33)
        ->and($sleeps[2])->toBeLessThan(0.34);
})->with([
    'login' => [BirOperation::Login, null],
    'logout' => [BirOperation::Logout, null],
    'search' => [BirOperation::Search, SearchCriteria::krs('0000123456')],
    'full report' => [BirOperation::FullReport, null],
    'bulk report' => [BirOperation::BulkReport, null],
    'GetValue' => [BirOperation::GetValue, null],
]);

it('keeps production, sandbox, and different API keys isolated', function (): void {
    $cache = new Repository(new ArrayStore);
    $now = new DateTimeImmutable('2026-08-23 08:00:00', new DateTimeZone('Europe/Warsaw'));
    $clock = static function () use (&$now): DateTimeImmutable {
        return $now;
    };
    $production = new CacheBirRequestLimiter(
        $cache,
        Environment::Production,
        'PRODUCTIONKEY1234567',
        'bir-test-isolated',
        $clock,
    );
    $sandbox = new CacheBirRequestLimiter(
        $cache,
        Environment::Sandbox,
        'PRODUCTIONKEY1234567',
        'bir-test-isolated',
        $clock,
    );
    $otherUser = new CacheBirRequestLimiter(
        $cache,
        Environment::Production,
        'OTHERUSERKEY12345678',
        'bir-test-isolated',
        $clock,
    );

    foreach (range(1, 3) as $_) {
        $production->acquire(BirOperation::GetValue);
        $now = $now->modify('+1 second');
    }

    $sandbox->acquire(BirOperation::GetValue);
    $otherUser->acquire(BirOperation::GetValue);

    $productionState = $cache->get(
        'bir-test-isolated:prod:'.hash('sha256', 'PRODUCTIONKEY1234567').':state',
    );
    $sandboxState = $cache->get(
        'bir-test-isolated:dev:'.hash('sha256', 'PRODUCTIONKEY1234567').':state',
    );
    $otherUserState = $cache->get(
        'bir-test-isolated:prod:'.hash('sha256', 'OTHERUSERKEY12345678').':state',
    );

    expect($productionState)->toBeArray()
        ->and($productionState['minute_used'])->toBe(3)
        ->and($sandboxState)->toBeArray()
        ->and($sandboxState['minute_used'])->toBe(1)
        ->and($otherUserState)->toBeArray()
        ->and($otherUserState['minute_used'])->toBe(1);
});

it('paces a cold login before admitting a batch atomically and leaves long second debt', function (): void {
    $cache = new Repository(new ArrayStore);
    $now = new DateTimeImmutable('2026-08-23 08:00:00', new DateTimeZone('Europe/Warsaw'));
    $clock = static function () use (&$now): DateTimeImmutable {
        return $now;
    };
    $sleeps = [];
    $limiter = new CacheBirRequestLimiter(
        $cache,
        Environment::Production,
        'APIKEYSENTINEL123456',
        'bir-test-batch',
        $clock,
        static function (float $seconds) use (&$now, &$sleeps): void {
            $sleeps[] = $seconds;
            $now = rateLimitTimeAt((float) $now->format('U.u') + $seconds);
        },
    );
    $criteria = rateLimitBatchCriteria();

    expect($criteria->identifierCount())->toBe(20);

    $limiter->acquire(BirOperation::Login);
    $limiter->acquire(BirOperation::Search, ['criteria' => $criteria]);

    expect($sleeps)->toHaveCount(1)
        ->and($sleeps[0])->toBeGreaterThan(0.33)
        ->and($sleeps[0])->toBeLessThan(0.34);

    try {
        $limiter->acquire(BirOperation::GetValue);
        throw new RuntimeException('The weighted batch did not create rate-limit debt.');
    } catch (BirRateLimitException $exception) {
        expect($exception->quotaWasExceeded())->toBeTrue()
            ->and($exception->retryAfterSeconds())->toBeBetween(6, 7)
            ->and($sleeps)->toHaveCount(1);
    }

    $now = $now->modify('+7 seconds');
    $limiter->acquire(BirOperation::Login);
});

it('paces a weighted batch after login without exceeding the per-second spacing', function (
    string $time,
    int $identifierCount,
    float $expectedBatchDelay,
    float $expectedDebt,
): void {
    $cache = new Repository(new ArrayStore);
    $now = new DateTimeImmutable($time, new DateTimeZone('Europe/Warsaw'));
    $sleeps = [];
    $limiter = new CacheBirRequestLimiter(
        $cache,
        Environment::Production,
        'APIKEYSENTINEL123456',
        'bir-test-small-batch-'.$identifierCount,
        static function () use (&$now): DateTimeImmutable {
            return $now;
        },
        static function (float $seconds) use (&$now, &$sleeps): void {
            $sleeps[] = $seconds;
            $now = rateLimitTimeAt((float) $now->format('U.u') + $seconds);
        },
    );
    $criteria = rateLimitBatchCriteriaOfSize($identifierCount);

    $limiter->beginRateLimitScope();

    try {
        $limiter->acquire(BirOperation::Login);
        $limiter->acquire(BirOperation::Search, ['criteria' => $criteria]);
        $limiter->acquire(BirOperation::GetValue);
    } finally {
        $limiter->endRateLimitScope();
    }

    expect($sleeps)->toHaveCount(2)
        ->and($sleeps[0])->toBeGreaterThan($expectedBatchDelay - 0.01)
        ->and($sleeps[0])->toBeLessThan($expectedBatchDelay + 0.01)
        ->and($sleeps[1])->toBeGreaterThan($expectedDebt - 0.01)
        ->and($sleeps[1])->toBeLessThan($expectedDebt + 0.01);
})->with([
    'two daytime identifiers' => ['2026-08-23 08:00:00', 2, 2 / 3, 2 / 3],
    'three daytime identifiers' => ['2026-08-23 08:00:00', 3, 1.0, 1.0],
    'four nighttime identifiers' => ['2026-08-23 22:00:00', 4, 1.0, 1.0],
]);

it('admits a cold weighted batch up to the active per-second limit atomically', function (
    string $time,
    int $identifierCount,
    float $expectedDebt,
): void {
    $cache = new Repository(new ArrayStore);
    $now = new DateTimeImmutable($time, new DateTimeZone('Europe/Warsaw'));
    $sleeps = [];
    $limiter = new CacheBirRequestLimiter(
        $cache,
        Environment::Production,
        'APIKEYSENTINEL123456',
        'bir-test-cold-small-batch-'.$identifierCount,
        static function () use (&$now): DateTimeImmutable {
            return $now;
        },
        static function (float $seconds) use (&$now, &$sleeps): void {
            $sleeps[] = $seconds;
            $now = rateLimitTimeAt((float) $now->format('U.u') + $seconds);
        },
    );

    $limiter->beginRateLimitScope();

    try {
        $limiter->acquire(BirOperation::Search, [
            'criteria' => rateLimitBatchCriteriaOfSize($identifierCount),
        ]);

        $limiter->acquire(BirOperation::GetValue);
    } finally {
        $limiter->endRateLimitScope();
    }

    expect($sleeps)->toHaveCount(1)
        ->and($sleeps[0])->toBeGreaterThan($expectedDebt - 0.01)
        ->and($sleeps[0])->toBeLessThan($expectedDebt + 0.01);
})->with([
    'two daytime identifiers' => ['2026-08-23 08:00:00', 2, 2 / 3],
    'three daytime identifiers' => ['2026-08-23 08:00:00', 3, 1.0],
    'four nighttime identifiers' => ['2026-08-23 22:00:00', 4, 1.0],
]);

it('paces the fifth call after the four-call night burst', function (): void {
    $cache = new Repository(new ArrayStore);
    $now = new DateTimeImmutable('2026-08-23 22:00:00', new DateTimeZone('Europe/Warsaw'));
    $sleeps = [];
    $limiter = new CacheBirRequestLimiter(
        $cache,
        Environment::Production,
        'APIKEYSENTINEL123456',
        'bir-test-night',
        static function () use (&$now): DateTimeImmutable {
            return $now;
        },
        static function (float $seconds) use (&$now, &$sleeps): void {
            $sleeps[] = $seconds;
            $now = rateLimitTimeAt((float) $now->format('U.u') + $seconds);
        },
    );

    foreach (range(1, 4) as $_) {
        $limiter->acquire(BirOperation::GetValue);
    }

    $limiter->acquire(BirOperation::GetValue);

    expect($sleeps)->toHaveCount(4)
        ->and($sleeps[0])->toBeGreaterThan(0.24)
        ->and($sleeps[0])->toBeLessThan(0.26)
        ->and($sleeps[1])->toBeGreaterThan(0.24)
        ->and($sleeps[1])->toBeLessThan(0.26)
        ->and($sleeps[2])->toBeGreaterThan(0.24)
        ->and($sleeps[2])->toBeLessThan(0.26)
        ->and($sleeps[3])->toBeGreaterThan(0.24)
        ->and($sleeps[3])->toBeLessThan(0.26);
});

it('never exceeds the fixed Warsaw calendar minute allowance', function (): void {
    $cache = new Repository(new ArrayStore);
    $now = new DateTimeImmutable('2026-08-23 08:00:00', new DateTimeZone('Europe/Warsaw'));
    $limiter = new CacheBirRequestLimiter(
        $cache,
        Environment::Production,
        'APIKEYSENTINEL123456',
        'bir-test-minute-hard-cap',
        static function () use (&$now): DateTimeImmutable {
            return $now;
        },
    );
    $criteria = rateLimitBatchCriteria();

    foreach (range(1, 6) as $batch) {
        $limiter->acquire(BirOperation::Search, ['criteria' => $criteria]);

        if ($batch < 6) {
            $now = $now->modify('+10 seconds');
        }
    }

    $now = $now->modify('+9 seconds');

    try {
        $limiter->acquire(BirOperation::Login);
        throw new RuntimeException('The fixed minute allowance exceeded 120 requests.');
    } catch (BirRateLimitException $exception) {
        expect($exception->quotaWasExceeded())->toBeTrue()
            ->and($exception->retryAfterSeconds())->toBe(1);
    }

    $now = $now->modify('+1 second');
    $limiter->acquire(BirOperation::Login);
});

it('never exceeds the fixed Warsaw calendar hour allowance', function (): void {
    $cache = new Repository(new ArrayStore);
    $now = new DateTimeImmutable('2026-08-23 08:00:00', new DateTimeZone('Europe/Warsaw'));
    $limiter = new CacheBirRequestLimiter(
        $cache,
        Environment::Production,
        'APIKEYSENTINEL123456',
        'bir-test-hour-hard-cap',
        static function () use (&$now): DateTimeImmutable {
            return $now;
        },
    );
    $criteria = rateLimitBatchCriteria();

    foreach (range(1, 300) as $batch) {
        $limiter->acquire(BirOperation::Search, ['criteria' => $criteria]);

        if ($batch < 300) {
            $now = $now->modify('+12 seconds');
        }
    }

    $now = $now->modify('+11 seconds');

    try {
        $limiter->acquire(BirOperation::Login);
        throw new RuntimeException('The fixed hour allowance exceeded 6000 requests.');
    } catch (BirRateLimitException $exception) {
        expect($exception->quotaWasExceeded())->toBeTrue()
            ->and($exception->retryAfterSeconds())->toBe(1);
    }

    $now = $now->modify('+1 second');
    $limiter->acquire(BirOperation::Login);
});

it('does not reset fixed counters when the wall clock moves backwards', function (): void {
    $cache = new Repository(new ArrayStore);
    $now = new DateTimeImmutable('2026-08-23 08:00:10', new DateTimeZone('Europe/Warsaw'));
    $limiter = new CacheBirRequestLimiter(
        $cache,
        Environment::Production,
        'APIKEYSENTINEL123456',
        'bir-test-backwards-clock',
        static function () use (&$now): DateTimeImmutable {
            return $now;
        },
    );

    $limiter->acquire(BirOperation::Login);
    $now = $now->modify('-2 seconds');

    try {
        $limiter->acquire(BirOperation::GetValue);
        throw new RuntimeException('A backwards wall clock reset the limiter.');
    } catch (BirRateLimitException $exception) {
        expect($exception->quotaWasExceeded())->toBeTrue()
            ->and($exception->retryAfterSeconds())->toBe(3);
    }
});

it('uses distinct epoch windows for the repeated Warsaw DST hour', function (): void {
    $apiKey = 'APIKEYSENTINEL123456';
    $prefix = 'bir-test-dst-fallback';
    $cache = new Repository(new ArrayStore);
    $now = new DateTimeImmutable('2026-10-25T02:00:00+02:00');
    $limiter = new CacheBirRequestLimiter(
        $cache,
        Environment::Production,
        $apiKey,
        $prefix,
        static function () use (&$now): DateTimeImmutable {
            return $now;
        },
    );
    $stateKey = $prefix.':prod:'.hash('sha256', $apiKey).':state';

    $limiter->acquire(BirOperation::Login);
    $firstState = $cache->get($stateKey);
    $now = new DateTimeImmutable('2026-10-25T02:00:00+01:00');
    $limiter->acquire(BirOperation::Login);
    $secondState = $cache->get($stateKey);

    expect($firstState)->toBeArray()
        ->and($secondState)->toBeArray()
        ->and($secondState['hour_start'] - $firstState['hour_start'])->toBe(3_600)
        ->and($secondState['hour_used'])->toBe(1);
});

it('advances to the next epoch window across the skipped Warsaw DST hour', function (): void {
    $apiKey = 'APIKEYSENTINEL123456';
    $prefix = 'bir-test-dst-spring-forward';
    $cache = new Repository(new ArrayStore);
    $now = new DateTimeImmutable('2026-03-29T01:59:59+01:00');
    $limiter = new CacheBirRequestLimiter(
        $cache,
        Environment::Production,
        $apiKey,
        $prefix,
        static function () use (&$now): DateTimeImmutable {
            return $now;
        },
    );
    $stateKey = $prefix.':prod:'.hash('sha256', $apiKey).':state';

    $limiter->acquire(BirOperation::Login);
    $firstState = $cache->get($stateKey);
    $now = new DateTimeImmutable('2026-03-29T03:00:00+02:00');
    $limiter->acquire(BirOperation::Login);
    $secondState = $cache->get($stateKey);

    expect($firstState)->toBeArray()
        ->and($secondState)->toBeArray()
        ->and($secondState['hour_start'] - $firstState['hour_start'])->toBe(3_600)
        ->and($secondState['hour_used'])->toBe(1);
});

it('reports the longest retry when minute hour and second budgets are exhausted together', function (): void {
    $apiKey = 'APIKEYSENTINEL123456';
    $prefix = 'bir-test-combined-retry';
    $cache = new Repository(new ArrayStore);
    $now = new DateTimeImmutable('2026-08-23 08:30:59', new DateTimeZone('Europe/Warsaw'));
    $timestamp = (float) $now->format('U.u');
    $stateKey = $prefix.':prod:'.hash('sha256', $apiKey).':state';
    $cache->put($stateKey, [
        'second' => $timestamp + (20 / 3),
        'minute_start' => intdiv((int) $timestamp, 60) * 60,
        'minute_used' => 120,
        'hour_start' => intdiv((int) $timestamp, 3_600) * 3_600,
        'hour_used' => 6_000,
        'last_seen' => $timestamp,
    ], 7_200);
    $limiter = new CacheBirRequestLimiter(
        $cache,
        Environment::Production,
        $apiKey,
        $prefix,
        static fn (): DateTimeImmutable => $now,
    );

    try {
        $limiter->acquire(BirOperation::Login);
        throw new RuntimeException('The limiter ignored exhausted combined budgets.');
    } catch (BirRateLimitException $exception) {
        expect($exception->quotaWasExceeded())->toBeTrue()
            ->and($exception->retryAfterSeconds())->toBe(1_741);
    }
});

it('does not hide batch second debt behind a nearer minute boundary', function (): void {
    $apiKey = 'APIKEYSENTINEL123456';
    $prefix = 'bir-test-minute-and-second-retry';
    $cache = new Repository(new ArrayStore);
    $now = new DateTimeImmutable('2026-08-23 08:00:59', new DateTimeZone('Europe/Warsaw'));
    $timestamp = (float) $now->format('U.u');
    $stateKey = $prefix.':prod:'.hash('sha256', $apiKey).':state';
    $cache->put($stateKey, [
        'second' => $timestamp + (20 / 3),
        'minute_start' => intdiv((int) $timestamp, 60) * 60,
        'minute_used' => 120,
        'hour_start' => intdiv((int) $timestamp, 3_600) * 3_600,
        'hour_used' => 120,
        'last_seen' => $timestamp,
    ], 7_200);
    $limiter = new CacheBirRequestLimiter(
        $cache,
        Environment::Production,
        $apiKey,
        $prefix,
        static fn (): DateTimeImmutable => $now,
    );

    try {
        $limiter->acquire(BirOperation::GetValue);
        throw new RuntimeException('The minute boundary hid longer batch debt.');
    } catch (BirRateLimitException $exception) {
        expect($exception->quotaWasExceeded())->toBeTrue()
            ->and($exception->retryAfterSeconds())->toBe(7);
    }
});

it('adds backwards-clock recovery to the remaining second debt', function (): void {
    $apiKey = 'APIKEYSENTINEL123456';
    $prefix = 'bir-test-rollback-debt';
    $cache = new Repository(new ArrayStore);
    $lastSeen = new DateTimeImmutable('2026-08-23 08:00:59', new DateTimeZone('Europe/Warsaw'));
    $lastTimestamp = (float) $lastSeen->format('U.u');
    $now = $lastSeen->modify('-2 seconds');
    $stateKey = $prefix.':prod:'.hash('sha256', $apiKey).':state';
    $cache->put($stateKey, [
        'second' => $lastTimestamp + (20 / 3),
        'minute_start' => intdiv((int) $lastTimestamp, 60) * 60,
        'minute_used' => 20,
        'hour_start' => intdiv((int) $lastTimestamp, 3_600) * 3_600,
        'hour_used' => 20,
        'last_seen' => $lastTimestamp,
    ], 7_200);
    $limiter = new CacheBirRequestLimiter(
        $cache,
        Environment::Production,
        $apiKey,
        $prefix,
        static fn (): DateTimeImmutable => $now,
    );

    try {
        $limiter->acquire(BirOperation::GetValue);
        throw new RuntimeException('The backwards-clock retry ignored second debt.');
    } catch (BirRateLimitException $exception) {
        expect($exception->quotaWasExceeded())->toBeTrue()
            ->and($exception->retryAfterSeconds())->toBe(9);
    }
});

it('fails closed for semantically impossible cached state', function (array $changes): void {
    $apiKey = 'APIKEYSENTINEL123456';
    $prefix = 'bir-test-invalid-state-'.md5(serialize($changes));
    $cache = new Repository(new ArrayStore);
    $now = new DateTimeImmutable('2026-08-23 08:00:10', new DateTimeZone('Europe/Warsaw'));
    $timestamp = (float) $now->format('U.u');
    $stateKey = $prefix.':prod:'.hash('sha256', $apiKey).':state';
    $state = array_replace([
        'second' => $timestamp + (1 / 3),
        'minute_start' => intdiv((int) $timestamp, 60) * 60,
        'minute_used' => 1,
        'hour_start' => intdiv((int) $timestamp, 3_600) * 3_600,
        'hour_used' => 1,
        'last_seen' => $timestamp,
    ], $changes);
    $cache->put($stateKey, $state, 7_200);
    $limiter = new CacheBirRequestLimiter(
        $cache,
        Environment::Production,
        $apiKey,
        $prefix,
        static fn (): DateTimeImmutable => $now,
    );

    try {
        $limiter->acquire(BirOperation::Login);
        throw new RuntimeException('The limiter accepted impossible cached state.');
    } catch (BirRateLimitException $exception) {
        expect($exception->quotaWasExceeded())->toBeFalse();
    }
})->with([
    'second before last observation' => [['second' => 1.0]],
    'misaligned minute' => [['minute_start' => 1]],
    'misaligned hour' => [['hour_start' => 1]],
    'empty active minute' => [['minute_used' => 0]],
    'minute exceeds hour' => [['minute_used' => 2]],
    'minute exceeds policy' => [['minute_used' => 121, 'hour_used' => 121]],
    'hour exceeds policy' => [['hour_used' => 6_001]],
    'unbounded second debt' => [['second' => 1e308]],
]);

it('redacts corrupted cache state from exception traces', function (): void {
    $originalExceptionIgnoreArgs = ini_get('zend.exception_ignore_args');

    if (ini_set('zend.exception_ignore_args', '0') === false) {
        throw new RuntimeException('Unable to enable exception arguments for the cache-state trace test.');
    }

    $sentinel = 'CORRUPTED-CACHE-STATE-SENTINEL';
    $apiKey = 'APIKEYSENTINEL123456';
    $prefix = 'bir-test-invalid-state-trace';
    $cache = new Repository(new ArrayStore);
    $now = new DateTimeImmutable('2026-08-23 08:00:10', new DateTimeZone('Europe/Warsaw'));
    $timestamp = (float) $now->format('U.u');
    $stateKey = $prefix.':prod:'.hash('sha256', $apiKey).':state';
    $cache->put($stateKey, [
        'second' => $sentinel,
        'minute_start' => intdiv((int) $timestamp, 60) * 60,
        'minute_used' => 1,
        'hour_start' => intdiv((int) $timestamp, 3_600) * 3_600,
        'hour_used' => 1,
        'last_seen' => $timestamp,
    ], 7_200);
    $limiter = new CacheBirRequestLimiter(
        $cache,
        Environment::Production,
        $apiKey,
        $prefix,
        static fn (): DateTimeImmutable => $now,
    );

    try {
        try {
            $limiter->acquire(BirOperation::Login);
            throw new LogicException('The limiter accepted corrupted cache state.');
        } catch (BirRateLimitException $exception) {
            $rendered = (new CliDumper)->dump((new VarCloner)->cloneVar($exception), true);

            expect($exception->quotaWasExceeded())->toBeFalse()
                ->and($rendered)->toBeString()
                ->not->toContain($sentinel);
        }
    } finally {
        if (is_string($originalExceptionIgnoreArgs)) {
            ini_set('zend.exception_ignore_args', $originalExceptionIgnoreArgs);
        }
    }
});

it('fails closed when cached second debt is below one weighted unit', function (): void {
    $apiKey = 'APIKEYSENTINEL123456';
    $prefix = 'bir-test-insufficient-second-debt';
    $cache = new Repository(new ArrayStore);
    $now = new DateTimeImmutable('2026-08-23 08:00:10', new DateTimeZone('Europe/Warsaw'));
    $timestamp = (float) $now->format('U.u');
    $stateKey = $prefix.':prod:'.hash('sha256', $apiKey).':state';
    $cache->put($stateKey, [
        'second' => $timestamp + 0.1,
        'minute_start' => intdiv((int) $timestamp, 60) * 60,
        'minute_used' => 1,
        'hour_start' => intdiv((int) $timestamp, 3_600) * 3_600,
        'hour_used' => 1,
        'last_seen' => $timestamp,
    ], 7_200);
    $limiter = new CacheBirRequestLimiter(
        $cache,
        Environment::Production,
        $apiKey,
        $prefix,
        static fn (): DateTimeImmutable => $now,
    );

    try {
        $limiter->acquire(BirOperation::Login);
        throw new RuntimeException('The limiter accepted insufficient second debt.');
    } catch (BirRateLimitException $exception) {
        expect($exception->quotaWasExceeded())->toBeFalse();
    }
});

it('fails closed for tagged repositories that namespace state separately from locks', function (): void {
    $store = new ArrayStore;
    $apiKey = 'APIKEYSENTINEL123456';
    $prefix = 'bir-test-tagged-cache';
    $now = new DateTimeImmutable('2026-08-23 08:00:00', new DateTimeZone('Europe/Warsaw'));
    $limiters = [
        new CacheBirRequestLimiter(
            new TaggedCache($store, new TagSet($store, ['first-budget'])),
            Environment::Production,
            $apiKey,
            $prefix,
            static fn (): DateTimeImmutable => $now,
        ),
        new CacheBirRequestLimiter(
            new TaggedCache($store, new TagSet($store, ['second-budget'])),
            Environment::Production,
            $apiKey,
            $prefix,
            static fn (): DateTimeImmutable => $now,
        ),
    ];

    foreach ($limiters as $limiter) {
        try {
            $limiter->acquire(BirOperation::Login);
            throw new RuntimeException('The limiter accepted a tagged cache repository.');
        } catch (BirRateLimitException $exception) {
            expect($exception->quotaWasExceeded())->toBeFalse();
        }
    }

    $stateKey = $prefix.':prod:'.hash('sha256', $apiKey).':state';

    expect($store->get($stateKey))->toBeNull();
});

it('fails closed before fixed-window timestamp arithmetic can overflow', function (): void {
    $maximumSafeTimestamp = min(
        253_402_300_799.0,
        (float) (PHP_INT_MAX - 3_600),
    );
    $now = rateLimitTimeAt($maximumSafeTimestamp + 1);
    $limiter = new CacheBirRequestLimiter(
        new Repository(new ArrayStore),
        Environment::Production,
        'APIKEYSENTINEL123456',
        'bir-test-timestamp-overflow',
        static fn (): DateTimeImmutable => $now,
    );

    try {
        $limiter->acquire(BirOperation::Login);
        throw new RuntimeException('The limiter accepted an unsafe fixed-window timestamp.');
    } catch (BirRateLimitException $exception) {
        expect($exception->quotaWasExceeded())->toBeFalse();
    }
});

it('stores only a credential fingerprint in cache keys', function (): void {
    $apiKey = 'APIKEYSENTINEL123456';
    $prefix = 'bir-test-fingerprint';
    $cache = new Repository(new ArrayStore);
    $now = new DateTimeImmutable('2026-08-23 08:00:00', new DateTimeZone('Europe/Warsaw'));
    $limiter = new CacheBirRequestLimiter(
        $cache,
        Environment::Production,
        $apiKey,
        $prefix,
        static fn (): DateTimeImmutable => $now,
    );

    $limiter->acquire(BirOperation::Login);

    $hashedKey = $prefix.':prod:'.hash('sha256', $apiKey).':state';

    expect($cache->get($hashedKey))->toBeArray()
        ->and($cache->get($prefix.':prod:'.$apiKey.':state'))->toBeNull()
        ->and(serialize($cache->get($hashedKey)))->not->toContain($apiKey)
        ->and($limiter->__debugInfo())->toBe([
            'cache' => '[HIDDEN]',
            'identity' => '[HASHED]',
        ]);
});

it('translates sleeper failures without retaining their message or previous exception', function (): void {
    $secret = 'SLEEPER-SECRET-SENTINEL';
    $now = new DateTimeImmutable('2026-08-23 08:00:00', new DateTimeZone('Europe/Warsaw'));
    $limiter = new CacheBirRequestLimiter(
        new Repository(new ArrayStore),
        Environment::Production,
        'APIKEYSENTINEL123456',
        'bir-test-sleeper-failure',
        static fn (): DateTimeImmutable => $now,
        static function (float $seconds) use ($secret): never {
            throw new RuntimeException($secret);
        },
    );

    $limiter->acquire(BirOperation::Login);

    try {
        $limiter->acquire(BirOperation::Login);
        throw new RuntimeException('The failing sleeper was ignored.');
    } catch (BirRateLimitException $exception) {
        expect($exception->quotaWasExceeded())->toBeFalse()
            ->and($exception->getPrevious())->toBeNull()
            ->and($exception->getMessage())->not->toContain($secret);
    }
});

it('does not expose cache backend credentials through object exporters', function (): void {
    $password = 'REDIS-PASSWORD-SENTINEL';
    $callbackSecret = 'CALLBACK-CREDENTIAL-SENTINEL';
    $application = Mockery::mock(Application::class);

    if (! $application instanceof Application) {
        throw new RuntimeException('The Redis manager fixture requires a Laravel application.');
    }

    $redis = new RedisManager($application, 'phpredis', [
        'default' => [
            'host' => '127.0.0.1',
            'password' => $password,
        ],
    ]);
    $limiter = new CacheBirRequestLimiter(
        new Repository(new RedisStore($redis)),
        Environment::Production,
        'APIKEYSENTINEL123456',
        clock: static function () use ($callbackSecret): DateTimeImmutable {
            $date = str_replace($callbackSecret, $callbackSecret, '2026-08-23 08:00:00');

            return new DateTimeImmutable($date, new DateTimeZone('Europe/Warsaw'));
        },
        sleeper: static function (float $seconds) use ($callbackSecret): void {
            if ($seconds < -strlen($callbackSecret)) {
                throw new RuntimeException('The callback fixture received an invalid delay.');
            }
        },
    );

    $exported = var_export($limiter, true);
    $dumped = (new CliDumper)->dump((new VarCloner)->cloneVar($limiter), true);

    expect($exported)->not->toContain($password)
        ->not->toContain($callbackSecret)
        ->and($dumped)->toBeString()
        ->not->toContain($password)
        ->not->toContain($callbackSecret);
});

it('samples the clock after lock contention before selecting the active window', function (): void {
    $apiKey = 'APIKEYSENTINEL123456';
    $prefix = 'bir-test-lock-window-boundary';
    $redis = new ControllableRateLimitRedisClient;
    $store = new RedisStore(new ControllableRateLimitRedisFactory($redis));
    $cache = new Repository($store);
    $now = new DateTimeImmutable('2026-08-23 08:00:00', new DateTimeZone('Europe/Warsaw'));
    $limiter = new CacheBirRequestLimiter(
        $cache,
        Environment::Production,
        $apiKey,
        $prefix,
        static function () use (&$now): DateTimeImmutable {
            return $now;
        },
    );
    $criteria = rateLimitBatchCriteria();

    foreach (range(1, 6) as $batch) {
        $limiter->acquire(BirOperation::Search, ['criteria' => $criteria]);

        if ($batch < 6) {
            $now = $now->modify('+10 seconds');
        }
    }

    $now = $now->modify('+9 seconds');
    $redis->onFirstLockContention = static function () use (&$now): void {
        $now = $now->modify('+1 second');
    };

    $limiter->acquire(BirOperation::Login);

    $stateKey = $prefix.':prod:'.hash('sha256', $apiKey).':state';
    $state = $cache->get($stateKey);

    expect($now->format('H:i:s'))->toBe('08:01:00')
        ->and($state)->toBeArray()
        ->and($state['minute_start'])->toBe((int) $now->format('U'))
        ->and($state['minute_used'])->toBe(1);
});

it('fails closed without writing after losing lock ownership', function (): void {
    $apiKey = 'APIKEYSENTINEL123456';
    $prefix = 'bir-test-lost-lock';
    $redis = new ControllableRateLimitRedisClient;
    $redis->loseOwnershipOnNextStateRead = true;
    $store = new RedisStore(new ControllableRateLimitRedisFactory($redis));
    $cache = new Repository($store);
    $now = new DateTimeImmutable('2026-08-23 08:00:00', new DateTimeZone('Europe/Warsaw'));
    $limiter = new CacheBirRequestLimiter(
        $cache,
        Environment::Production,
        $apiKey,
        $prefix,
        static fn (): DateTimeImmutable => $now,
    );

    try {
        $limiter->acquire(BirOperation::Login);
        throw new RuntimeException('The limiter wrote state after losing its lock.');
    } catch (BirRateLimitException $exception) {
        $stateKey = $prefix.':prod:'.hash('sha256', $apiKey).':state';

        expect($exception->quotaWasExceeded())->toBeFalse()
            ->and($cache->get($stateKey))->toBeNull()
            ->and($redis->putCalls)->toBe(0)
            ->and($redis->lastLockSeconds)->toBe(30);
    }
});

it('waits briefly for transient distributed lock contention', function (): void {
    $redis = new ControllableRateLimitRedisClient;
    $redis->onFirstLockContention = static function (): void {};
    $store = new RedisStore(new ControllableRateLimitRedisFactory($redis));
    $cache = new Repository($store);
    $now = new DateTimeImmutable('2026-08-23 08:00:00', new DateTimeZone('Europe/Warsaw'));
    $limiter = new CacheBirRequestLimiter(
        $cache,
        Environment::Production,
        'APIKEYSENTINEL123456',
        'bir-test-transient-lock',
        static fn (): DateTimeImmutable => $now,
    );

    $limiter->acquire(BirOperation::Login);

    expect($redis->lockAttempts)->toBe(2);
});

it('fails closed after bounded waiting when the distributed lock stays busy', function (): void {
    $apiKey = 'APIKEYSENTINEL123456';
    $prefix = 'bir-test-lock';
    $store = new ArrayStore;
    $cache = new Repository($store);
    $now = new DateTimeImmutable('2026-08-23 08:00:00', new DateTimeZone('Europe/Warsaw'));
    $limiter = new CacheBirRequestLimiter(
        $cache,
        Environment::Production,
        $apiKey,
        $prefix,
        static fn (): DateTimeImmutable => $now,
    );
    $stateKey = $prefix.':prod:'.hash('sha256', $apiKey).':state';
    $lock = $store->lock($stateKey.':lock', 5);

    expect($lock->get())->toBeTrue();

    try {
        $limiter->acquire(BirOperation::Login);
        throw new RuntimeException('The limiter ignored lock contention.');
    } catch (BirRateLimitException $exception) {
        expect($exception->quotaWasExceeded())->toBeFalse()
            ->and($exception->retryAfterSeconds())->toBe(1);
    } finally {
        $lock->release();
    }
});

function rateLimitBatchCriteria(): SearchCriteria
{
    return rateLimitBatchCriteriaOfSize(SearchCriteria::MAX_BATCH_SIZE);
}

function rateLimitBatchCriteriaOfSize(int $identifierCount): SearchCriteria
{
    $identifiers = array_map(
        static fn (int $value): string => str_pad((string) $value, 10, '0', STR_PAD_LEFT),
        range(1, $identifierCount),
    );

    return SearchCriteria::krsNumbers($identifiers);
}

function rateLimitTimeAt(float $timestamp): DateTimeImmutable
{
    $time = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $timestamp));

    if (! $time instanceof DateTimeImmutable) {
        throw new RuntimeException('Unable to advance the rate-limit test clock.');
    }

    return $time->setTimezone(new DateTimeZone('Europe/Warsaw'));
}

final readonly class ControllableRateLimitRedisFactory implements RedisFactory
{
    private RedisConnection $connection;

    public function __construct(ControllableRateLimitRedisClient $client)
    {
        $this->connection = new ControllableRateLimitRedisConnection($client);
    }

    public function connection($name = null): RedisConnection
    {
        return $this->connection;
    }
}

final class ControllableRateLimitRedisConnection extends RedisConnection
{
    public function __construct(private readonly ControllableRateLimitRedisClient $fakeClient) {}

    /** @param array<int, string>|string $channels */
    public function createSubscription($channels, Closure $callback, $method = 'subscribe'): void {}

    /** @param array<int, mixed> $parameters */
    public function command($method, array $parameters = []): mixed
    {
        if (! method_exists($this->fakeClient, $method)) {
            throw new RuntimeException('Unsupported Redis command in the rate-limit fixture.');
        }

        return $this->fakeClient->{$method}(...$parameters);
    }
}

final class ControllableRateLimitRedisClient
{
    /** @var array<string, string> */
    private array $values = [];

    public int $lockAttempts = 0;

    public int $lastLockSeconds = 0;

    public int $putCalls = 0;

    public bool $loseOwnershipOnNextStateRead = false;

    public ?Closure $onFirstLockContention = null;

    public function set(
        string $key,
        string $value,
        string $expiration,
        int $seconds,
        string $condition,
    ): bool {
        $this->lockAttempts++;
        $this->lastLockSeconds = $seconds;

        if ($this->onFirstLockContention !== null) {
            $onFirstLockContention = $this->onFirstLockContention;
            $this->onFirstLockContention = null;
            $onFirstLockContention();

            return false;
        }

        if (isset($this->values[$key])) {
            return false;
        }

        $this->values[$key] = $value;

        return $expiration === 'EX' && $condition === 'NX';
    }

    public function get(string $key): ?string
    {
        $value = $this->values[$key] ?? null;

        if ($this->loseOwnershipOnNextStateRead && ! str_ends_with($key, ':lock')) {
            $this->loseOwnershipOnNextStateRead = false;

            foreach (array_keys($this->values) as $storedKey) {
                if (str_ends_with($storedKey, ':lock')) {
                    unset($this->values[$storedKey]);
                }
            }
        }

        return $value;
    }

    public function setex(string $key, int $seconds, string $value): bool
    {
        $this->putCalls++;
        $this->values[$key] = $value;

        return $seconds > 0;
    }

    public function eval(string $script, int $keyCount, string $key, string $owner): int
    {
        if (($this->values[$key] ?? null) !== $owner) {
            return 0;
        }

        unset($this->values[$key]);

        return $script !== '' && $keyCount === 1 ? 1 : 0;
    }

    public function del(string $key): int
    {
        $deleted = isset($this->values[$key]);
        unset($this->values[$key]);

        return $deleted ? 1 : 0;
    }
}
