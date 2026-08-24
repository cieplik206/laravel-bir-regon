<?php

declare(strict_types=1);

use cieplik206\BirRegon\RateLimit\UnlimitedBirRequestLimiter;
use cieplik206\BirRegon\Transport\NativeSoapTransport;

it('requires an explicit request limiter for direct construction', function (): void {
    $constructor = new ReflectionMethod(NativeSoapTransport::class, '__construct');
    $requestLimiter = $constructor->getParameters()[1];

    expect($requestLimiter->getName())->toBe('requestLimiter')
        ->and($requestLimiter->isOptional())->toBeFalse()
        ->and(fn () => (new ReflectionClass(NativeSoapTransport::class))->newInstanceArgs([
            'APIKEYSENTINEL123456',
        ]))->toThrow(ArgumentCountError::class);
});

it('rejects unsafe direct transport limits before any request', function (
    string $setting,
    int $value,
    string $message,
): void {
    $construct = match ($setting) {
        'connection timeout' => fn (): NativeSoapTransport => new NativeSoapTransport(
            apiKey: 'APIKEYSENTINEL123456',
            requestLimiter: new UnlimitedBirRequestLimiter,
            connectionTimeout: $value,
        ),
        'request timeout' => fn (): NativeSoapTransport => new NativeSoapTransport(
            apiKey: 'APIKEYSENTINEL123456',
            requestLimiter: new UnlimitedBirRequestLimiter,
            requestTimeout: $value,
        ),
        'maximum response size' => fn (): NativeSoapTransport => new NativeSoapTransport(
            apiKey: 'APIKEYSENTINEL123456',
            requestLimiter: new UnlimitedBirRequestLimiter,
            maxResponseBytes: $value,
        ),
        default => throw new LogicException('Unsupported transport setting fixture.'),
    };

    expect($construct)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'connection timeout below range' => [
        'connection timeout',
        0,
        'BIR connection timeout must be between 1 and 60 seconds.',
    ],
    'connection timeout above range' => [
        'connection timeout',
        61,
        'BIR connection timeout must be between 1 and 60 seconds.',
    ],
    'request timeout below range' => [
        'request timeout',
        0,
        'BIR request timeout must be between 1 and 300 seconds.',
    ],
    'request timeout above range' => [
        'request timeout',
        301,
        'BIR request timeout must be between 1 and 300 seconds.',
    ],
    'response size below range' => [
        'maximum response size',
        0,
        'BIR maximum response size must be between 1 and 50000000 bytes.',
    ],
    'response size above range' => [
        'maximum response size',
        50_000_001,
        'BIR maximum response size must be between 1 and 50000000 bytes.',
    ],
]);
