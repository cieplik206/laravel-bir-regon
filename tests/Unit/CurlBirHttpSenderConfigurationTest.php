<?php

declare(strict_types=1);

use cieplik206\BirRegon\Enums\Environment;
use cieplik206\BirRegon\Transport\CurlBirHttpSender;

it('accepts the documented cURL sender limit boundaries', function (): void {
    $minimums = new CurlBirHttpSender(
        environment: Environment::Sandbox,
        connectionTimeout: 1,
        requestTimeout: 1,
        maxResponseBytes: 1,
        userAgent: 'laravel-bir-regon-tests/2',
    );
    $maximums = new CurlBirHttpSender(
        environment: Environment::Sandbox,
        connectionTimeout: 60,
        requestTimeout: 300,
        maxResponseBytes: 50_000_000,
        userAgent: 'laravel-bir-regon-tests/2',
    );

    expect(curlSenderConfigurationValue($minimums, 'connectionTimeout'))->toBe(1)
        ->and(curlSenderConfigurationValue($minimums, 'requestTimeout'))->toBe(1)
        ->and(curlSenderConfigurationValue($minimums, 'maxResponseBytes'))->toBe(1)
        ->and(curlSenderConfigurationValue($maximums, 'connectionTimeout'))->toBe(60)
        ->and(curlSenderConfigurationValue($maximums, 'requestTimeout'))->toBe(300)
        ->and(curlSenderConfigurationValue($maximums, 'maxResponseBytes'))->toBe(50_000_000);
});

function curlSenderConfigurationValue(CurlBirHttpSender $sender, string $property): mixed
{
    return (new ReflectionProperty($sender, $property))->getValue($sender);
}

it('rejects unsafe direct cURL sender limits before any request', function (
    string $setting,
    int $value,
    string $message,
): void {
    $construct = match ($setting) {
        'connection timeout' => fn (): CurlBirHttpSender => new CurlBirHttpSender(
            environment: Environment::Sandbox,
            connectionTimeout: $value,
            requestTimeout: 30,
            maxResponseBytes: 10_000_000,
            userAgent: 'laravel-bir-regon-tests/2',
        ),
        'request timeout' => fn (): CurlBirHttpSender => new CurlBirHttpSender(
            environment: Environment::Sandbox,
            connectionTimeout: 10,
            requestTimeout: $value,
            maxResponseBytes: 10_000_000,
            userAgent: 'laravel-bir-regon-tests/2',
        ),
        'maximum response size' => fn (): CurlBirHttpSender => new CurlBirHttpSender(
            environment: Environment::Sandbox,
            connectionTimeout: 10,
            requestTimeout: 30,
            maxResponseBytes: $value,
            userAgent: 'laravel-bir-regon-tests/2',
        ),
        default => throw new LogicException('Unsupported cURL sender setting fixture.'),
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
