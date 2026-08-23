<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\RateLimit;

use cieplik206\BirRegon\Concerns\PreventsSerialization;
use cieplik206\BirRegon\Contracts\BirRateLimitScopeInterface;
use cieplik206\BirRegon\Contracts\BirRequestLimiterInterface;
use cieplik206\BirRegon\Enums\Environment;
use cieplik206\BirRegon\Exceptions\BirRateLimitException;
use cieplik206\BirRegon\Protocol\BirOperation;
use cieplik206\BirRegon\Protocol\SearchCriteria;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Cache\Lock;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use SensitiveParameterValue;
use Throwable;

final class CacheBirRequestLimiter implements BirRateLimitScopeInterface, BirRequestLimiterInterface
{
    use PreventsSerialization;

    private const DEFAULT_PREFIX = 'bir-regon:rate-limit';

    private const LOCK_WAIT_SECONDS = 1;

    private const LOCK_TTL_SECONDS = 30;

    private const MAX_FIXED_WINDOW_SECONDS = 3_600;

    private const MAX_PACING_SECONDS = 1.0;

    private const MAX_SCOPED_PACING_SECONDS = 7.0;

    private const MAX_UNIX_TIMESTAMP = 253_402_300_799.0;

    private const STATE_TTL_SECONDS = 7_200;

    private const TIME_ZONE = 'Europe/Warsaw';

    private const TIME_EPSILON = 0.000_001;

    private readonly string $stateKey;

    private readonly SensitiveParameterValue $cache;

    private readonly ?SensitiveParameterValue $clock;

    private readonly ?SensitiveParameterValue $sleeper;

    private int $rateLimitScopeDepth = 0;

    private bool $scopeHasReservedRequest = false;

    /**
     * @param  null|Closure(): DateTimeImmutable  $clock
     * @param  null|Closure(float): void  $sleeper
     */
    public function __construct(
        #[\SensitiveParameter] Repository $cache,
        Environment $environment,
        #[\SensitiveParameter] string $apiKey,
        string $prefix = self::DEFAULT_PREFIX,
        #[\SensitiveParameter] ?Closure $clock = null,
        #[\SensitiveParameter] ?Closure $sleeper = null,
    ) {
        $this->cache = new SensitiveParameterValue($cache);
        $prefix = preg_match('/^[A-Za-z0-9:_-]{1,100}$/D', $prefix) === 1
            ? $prefix
            : self::DEFAULT_PREFIX;
        $credentialFingerprint = hash('sha256', $apiKey);

        $this->stateKey = implode(':', [
            $prefix,
            $environment->value,
            $credentialFingerprint,
            'state',
        ]);
        $this->clock = $clock === null ? null : new SensitiveParameterValue($clock);
        $this->sleeper = $sleeper === null ? null : new SensitiveParameterValue($sleeper);
    }

    public function acquire(BirOperation $operation, array $parameters = []): void
    {
        $this->ensureNotRestoredFromSerialization();

        $cost = $this->requestCost($operation, $parameters);

        $pacedFor = 0.0;

        while (true) {
            $decision = $this->reserveOrDelay($cost);
            $delay = $decision['delay'];

            if ($delay <= self::TIME_EPSILON) {
                if ($this->rateLimitScopeDepth > 0) {
                    $this->scopeHasReservedRequest = true;
                }

                return;
            }

            $maximumPacing = $this->rateLimitScopeDepth > 0
                && $this->scopeHasReservedRequest
                ? self::MAX_SCOPED_PACING_SECONDS
                : self::MAX_PACING_SECONDS;

            if (
                ! $decision['paceable']
                || $pacedFor + $delay > $maximumPacing + self::TIME_EPSILON
            ) {
                throw BirRateLimitException::quotaExceeded((int) ceil($delay));
            }

            try {
                $this->sleep($delay);
            } catch (Throwable) {
                throw BirRateLimitException::limiterUnavailable();
            }

            $pacedFor += $delay;
        }
    }

    public function beginRateLimitScope(): void
    {
        $this->ensureNotRestoredFromSerialization();

        if ($this->rateLimitScopeDepth === 0) {
            $this->scopeHasReservedRequest = false;
        }

        $this->rateLimitScopeDepth++;
    }

    public function endRateLimitScope(): void
    {
        $this->ensureNotRestoredFromSerialization();

        if ($this->rateLimitScopeDepth < 1) {
            throw BirRateLimitException::limiterUnavailable();
        }

        $this->rateLimitScopeDepth--;

        if ($this->rateLimitScopeDepth === 0) {
            $this->scopeHasReservedRequest = false;
        }
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return [
            'cache' => '[HIDDEN]',
            'identity' => '[HASHED]',
        ];
    }

    private function now(): DateTimeImmutable
    {
        $clock = $this->clock();
        $now = $clock === null
            ? new DateTimeImmutable('now', new DateTimeZone(self::TIME_ZONE))
            : $clock();

        return $now->setTimezone(new DateTimeZone(self::TIME_ZONE));
    }

    private function sleep(float $seconds): void
    {
        $sleeper = $this->sleeper();

        if ($sleeper !== null) {
            $sleeper($seconds);

            return;
        }

        usleep(max(1, (int) ceil($seconds * 1_000_000)));
    }

    /** @param array<string, mixed> $parameters */
    private function requestCost(BirOperation $operation, array $parameters): int
    {
        if ($operation !== BirOperation::Search) {
            return 1;
        }

        $criteria = $parameters['criteria'] ?? null;

        return $criteria instanceof SearchCriteria ? $criteria->identifierCount() : 1;
    }

    /** @return array{delay: float, paceable: bool} */
    private function reserveOrDelay(int $cost): array
    {
        try {
            $cache = $this->cache();

            if ($cache::class !== CacheRepository::class) {
                throw BirRateLimitException::limiterUnavailable();
            }

            $store = $cache->getStore();

            if (
                ! AtomicCacheStorePolicy::supports($store)
                || ! $store instanceof LockProvider
            ) {
                throw BirRateLimitException::limiterUnavailable();
            }

            $lock = $store->lock($this->stateKey.':lock', self::LOCK_TTL_SECONDS);

            if (! $lock instanceof Lock) {
                throw BirRateLimitException::limiterUnavailable();
            }

            $acquired = $lock->block(self::LOCK_WAIT_SECONDS);

            if ($acquired !== true) {
                throw BirRateLimitException::limiterUnavailable();
            }

            try {
                if (! $this->lockIsOwned($lock)) {
                    throw BirRateLimitException::limiterUnavailable();
                }

                $result = $this->reserve($this->now(), $cost);

                if (! $this->lockIsOwned($lock)) {
                    throw BirRateLimitException::limiterUnavailable();
                }

                if ($result['state'] !== null) {
                    if (! $cache->put(
                        $this->stateKey,
                        $result['state'],
                        self::STATE_TTL_SECONDS,
                    )) {
                        throw BirRateLimitException::limiterUnavailable();
                    }

                    if (! $this->lockIsOwned($lock)) {
                        throw BirRateLimitException::limiterUnavailable();
                    }
                }
            } finally {
                $lock->release();
            }
        } catch (BirRateLimitException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw BirRateLimitException::limiterUnavailable();
        }

        return [
            'delay' => $result['delay'],
            'paceable' => $result['paceable'],
        ];
    }

    /**
     * @return array{
     *     delay: float,
     *     paceable: bool,
     *     state: null|array{
     *         second: float,
     *         minute_start: int,
     *         minute_used: int,
     *         hour_start: int,
     *         hour_used: int,
     *         last_seen: float
     *     }
     * }
     */
    private function reserve(DateTimeImmutable $now, int $cost): array
    {
        $wallTimestamp = (float) $now->format('U.u');

        if (
            ! is_finite($wallTimestamp)
            || $wallTimestamp <= 0
            || $wallTimestamp > $this->maximumSafeTimestamp()
        ) {
            throw BirRateLimitException::limiterUnavailable();
        }

        $state = $this->readState();
        $rollbackDelay = max(0.0, $state['last_seen'] - $wallTimestamp);
        $timestamp = max($wallTimestamp, $state['last_seen']);
        $policy = GusBirRateLimitPolicy::at($this->dateTimeAt($timestamp));
        $minuteStart = $this->fixedWindowStart($timestamp, 60);
        $hourStart = $this->fixedWindowStart($timestamp, 3_600);
        $minuteUsed = $state['minute_start'] === $minuteStart ? $state['minute_used'] : 0;
        $hourUsed = $state['hour_start'] === $hourStart ? $state['hour_used'] : 0;

        $minuteDelay = $minuteUsed + $cost > $policy->perMinute
            ? $this->fixedWindowRetryAfter($timestamp, $minuteStart, 60)
            : 0;
        $hourDelay = $hourUsed + $cost > $policy->perHour
            ? $this->fixedWindowRetryAfter($timestamp, $hourStart, 3_600)
            : 0;

        $interval = 1 / $policy->perSecond;
        $theoreticalArrival = max($timestamp, $state['second']);

        $allowedAt = $cost <= $policy->perSecond
            ? max(
                $timestamp,
                $state['second'] + (($cost - 1) * $interval),
            )
            : $state['second'];

        $secondDelay = max(0.0, $allowedAt - $timestamp);
        $windowDelay = max($minuteDelay, $hourDelay);
        $delay = $rollbackDelay + max($secondDelay, $windowDelay);

        if ($delay > self::TIME_EPSILON) {
            return [
                'delay' => $delay,
                'paceable' => $windowDelay === 0,
                'state' => null,
            ];
        }

        $nextState = [
            'second' => $theoreticalArrival + ($cost * $interval),
            'minute_start' => $minuteStart,
            'minute_used' => $minuteUsed + $cost,
            'hour_start' => $hourStart,
            'hour_used' => $hourUsed + $cost,
            'last_seen' => $timestamp,
        ];

        return [
            'delay' => 0.0,
            'paceable' => true,
            'state' => $nextState,
        ];
    }

    /**
     * @return array{
     *     second: float,
     *     minute_start: int,
     *     minute_used: int,
     *     hour_start: int,
     *     hour_used: int,
     *     last_seen: float
     * }
     */
    private function readState(): array
    {
        $state = $this->cache()->get($this->stateKey);

        if ($state === null) {
            return [
                'second' => 0.0,
                'minute_start' => 0,
                'minute_used' => 0,
                'hour_start' => 0,
                'hour_used' => 0,
                'last_seen' => 0.0,
            ];
        }

        if (! is_array($state)) {
            throw BirRateLimitException::limiterUnavailable();
        }

        $validated = [
            'second' => $this->validatedFloat($state, 'second'),
            'minute_start' => $this->validatedInt($state, 'minute_start'),
            'minute_used' => $this->validatedInt($state, 'minute_used'),
            'hour_start' => $this->validatedInt($state, 'hour_start'),
            'hour_used' => $this->validatedInt($state, 'hour_used'),
            'last_seen' => $this->validatedFloat($state, 'last_seen'),
        ];

        $this->assertValidStateSemantics($validated);

        return $validated;
    }

    private function cache(): Repository
    {
        $cache = $this->cache->getValue();

        if (! $cache instanceof Repository) {
            throw BirRateLimitException::limiterUnavailable();
        }

        return $cache;
    }

    /** @return null|Closure(): DateTimeImmutable */
    private function clock(): ?Closure
    {
        $clock = $this->clock?->getValue();

        if ($clock !== null && ! $clock instanceof Closure) {
            throw BirRateLimitException::limiterUnavailable();
        }

        return $clock;
    }

    /** @return null|Closure(float): void */
    private function sleeper(): ?Closure
    {
        $sleeper = $this->sleeper?->getValue();

        if ($sleeper !== null && ! $sleeper instanceof Closure) {
            throw BirRateLimitException::limiterUnavailable();
        }

        return $sleeper;
    }

    /** @param array<array-key, mixed> $state */
    private function validatedFloat(#[\SensitiveParameter] array $state, string $name): float
    {
        $value = $state[$name] ?? null;

        if (! is_int($value) && ! is_float($value)) {
            throw BirRateLimitException::limiterUnavailable();
        }

        $value = (float) $value;

        if (! is_finite($value) || $value < 0) {
            throw BirRateLimitException::limiterUnavailable();
        }

        return $value;
    }

    /** @param array<array-key, mixed> $state */
    private function validatedInt(#[\SensitiveParameter] array $state, string $name): int
    {
        $value = $state[$name] ?? null;

        if (! is_int($value) || $value < 0) {
            throw BirRateLimitException::limiterUnavailable();
        }

        return $value;
    }

    /**
     * @param array{
     *     second: float,
     *     minute_start: int,
     *     minute_used: int,
     *     hour_start: int,
     *     hour_used: int,
     *     last_seen: float
     * } $state
     */
    private function assertValidStateSemantics(#[\SensitiveParameter] array $state): void
    {
        if ($state['last_seen'] <= 0
            || $state['last_seen'] > $this->maximumSafeTimestamp()
            || $state['last_seen'] > $state['second'] + self::TIME_EPSILON
            || $state['minute_used'] < 1
            || $state['hour_used'] < $state['minute_used']) {
            throw BirRateLimitException::limiterUnavailable();
        }

        $policy = GusBirRateLimitPolicy::at($this->dateTimeAt($state['last_seen']));
        $minimumDebt = 1 / $policy->perSecond;
        $maximumDebt = SearchCriteria::MAX_BATCH_SIZE / $policy->perSecond;
        $secondDebt = $state['second'] - $state['last_seen'];

        if ($state['minute_start'] !== $this->fixedWindowStart($state['last_seen'], 60)
            || $state['hour_start'] !== $this->fixedWindowStart($state['last_seen'], 3_600)
            || $secondDebt + self::TIME_EPSILON < $minimumDebt
            || $maximumDebt + self::TIME_EPSILON < $secondDebt
            || $state['minute_used'] > $policy->perMinute
            || $state['hour_used'] > $policy->perHour
        ) {
            throw BirRateLimitException::limiterUnavailable();
        }
    }

    /** @phpstan-impure */
    private function lockIsOwned(Lock $lock): bool
    {
        return $lock->isOwnedByCurrentProcess();
    }

    private function fixedWindowStart(float $timestamp, int $windowSeconds): int
    {
        return intdiv((int) floor($timestamp), $windowSeconds) * $windowSeconds;
    }

    private function maximumSafeTimestamp(): float
    {
        return min(
            self::MAX_UNIX_TIMESTAMP,
            (float) (PHP_INT_MAX - self::MAX_FIXED_WINDOW_SECONDS),
        );
    }

    private function fixedWindowRetryAfter(
        float $timestamp,
        int $windowStart,
        int $windowSeconds,
    ): int {
        return max(1, (int) ceil(($windowStart + $windowSeconds) - $timestamp));
    }

    private function dateTimeAt(float $timestamp): DateTimeImmutable
    {
        $dateTime = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $timestamp));

        if (! $dateTime instanceof DateTimeImmutable) {
            throw BirRateLimitException::limiterUnavailable();
        }

        return $dateTime->setTimezone(new DateTimeZone(self::TIME_ZONE));
    }
}
