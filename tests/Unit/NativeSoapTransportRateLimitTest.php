<?php

declare(strict_types=1);

use cieplik206\BirRegon\Contracts\BirRateLimitScopeInterface;
use cieplik206\BirRegon\Contracts\BirRequestLimiterInterface;
use cieplik206\BirRegon\Enums\Environment;
use cieplik206\BirRegon\Exceptions\BirRateLimitException;
use cieplik206\BirRegon\Protocol\BirOperation;
use cieplik206\BirRegon\Protocol\RawTransportResult;
use cieplik206\BirRegon\Protocol\TransportFailureType;
use cieplik206\BirRegon\RateLimit\UnlimitedBirRequestLimiter;
use cieplik206\BirRegon\Transport\BirHttpSenderInterface;
use cieplik206\BirRegon\Transport\NativeSoapTransport;

it('reserves quota after local validation and before network I/O', function (): void {
    $body = file_get_contents(__DIR__.'/../Fixtures/Gus/soap/login-success.xml');

    if (! is_string($body)) {
        throw new RuntimeException('Unable to read the login fixture.');
    }

    $limiter = new NativeTransportRecordingLimiter;
    $sender = new NativeTransportRecordingSender($body);
    $transport = new NativeSoapTransport(
        apiKey: 'APIKEYSENTINEL123456',
        environment: Environment::Sandbox,
        httpSender: $sender,
        requestLimiter: $limiter,
    );

    $invalid = $transport->call(BirOperation::Search);

    expect($invalid->successful)->toBeFalse()
        ->and($limiter->calls)->toBe([])
        ->and($sender->calls)->toBe([]);

    $valid = $transport->call(BirOperation::Login);

    expect($valid->successful)->toBeTrue()
        ->and($limiter->calls)->toBe([[BirOperation::Login, []]])
        ->and($sender->calls)->toBe([BirOperation::Login]);
});

it('propagates a local rate-limit exception without opening a connection', function (): void {
    $body = file_get_contents(__DIR__.'/../Fixtures/Gus/soap/login-success.xml');

    if (! is_string($body)) {
        throw new RuntimeException('Unable to read the login fixture.');
    }

    $sender = new NativeTransportRecordingSender($body);
    $transport = new NativeSoapTransport(
        apiKey: 'APIKEYSENTINEL123456',
        environment: Environment::Sandbox,
        httpSender: $sender,
        requestLimiter: new NativeTransportRejectingLimiter,
    );

    try {
        $transport->call(BirOperation::Login);
        throw new RuntimeException('The rate-limit exception was swallowed by the transport.');
    } catch (BirRateLimitException $exception) {
        expect($exception->retryAfterSeconds())->toBe(9)
            ->and($exception->quotaWasExceeded())->toBeTrue()
            ->and($sender->calls)->toBe([]);
    }
});

it('maps an unexpected limiter failure to an unavailable limiter exception', function (): void {
    $transport = new NativeSoapTransport(
        apiKey: 'APIKEYSENTINEL123456',
        environment: Environment::Sandbox,
        httpSender: new NativeTransportRecordingSender('unused'),
        requestLimiter: new NativeTransportThrowingLimiter,
    );

    try {
        $transport->call(BirOperation::Login);
        throw new RuntimeException('The limiter failure was swallowed.');
    } catch (BirRateLimitException $exception) {
        expect($exception->quotaWasExceeded())->toBeFalse()
            ->and($exception->retryAfterSeconds())->toBe(1);
    }
});

it('maps an unexpected limiter scope failure to an unavailable limiter exception', function (): void {
    $transport = new NativeSoapTransport(
        apiKey: 'APIKEYSENTINEL123456',
        environment: Environment::Sandbox,
        httpSender: new NativeTransportRecordingSender('unused'),
        requestLimiter: new NativeTransportThrowingScopeLimiter,
    );

    try {
        $transport->beginRateLimitScope();
        throw new RuntimeException('The limiter scope failure was swallowed.');
    } catch (BirRateLimitException $exception) {
        expect($exception->quotaWasExceeded())->toBeFalse();
    }
});

it('maps an unexpected sender failure to a transport response', function (): void {
    $transport = new NativeSoapTransport(
        apiKey: 'APIKEYSENTINEL123456',
        requestLimiter: new UnlimitedBirRequestLimiter,
        environment: Environment::Sandbox,
        httpSender: new NativeTransportThrowingSender,
    );

    $response = $transport->call(BirOperation::Login);

    expect($response->successful)->toBeFalse()
        ->and($response->failureType)->toBe(TransportFailureType::Transport);
});

it('keeps malformed decoded SOAP classified as a protocol response', function (): void {
    $transport = new NativeSoapTransport(
        apiKey: 'APIKEYSENTINEL123456',
        requestLimiter: new UnlimitedBirRequestLimiter,
        environment: Environment::Sandbox,
        httpSender: new NativeTransportRecordingSender('<not-soap/>'),
    );

    $response = $transport->call(BirOperation::Login);

    expect($response->successful)->toBeFalse()
        ->and($response->failureType)->toBe(TransportFailureType::Protocol);
});

final class NativeTransportRecordingLimiter implements BirRequestLimiterInterface
{
    /** @var list<array{BirOperation, array<string, mixed>}> */
    public array $calls = [];

    public function acquire(
        BirOperation $operation,
        #[SensitiveParameter] array $parameters = [],
    ): void {
        $this->calls[] = [$operation, $parameters];
    }
}

final class NativeTransportRejectingLimiter implements BirRequestLimiterInterface
{
    public function acquire(
        BirOperation $operation,
        #[SensitiveParameter] array $parameters = [],
    ): void {
        throw BirRateLimitException::quotaExceeded(9);
    }
}

final class NativeTransportThrowingLimiter implements BirRequestLimiterInterface
{
    public function acquire(
        BirOperation $operation,
        #[SensitiveParameter] array $parameters = [],
    ): void {
        throw new RuntimeException('Cache backend unavailable.');
    }
}

final class NativeTransportThrowingScopeLimiter implements BirRateLimitScopeInterface, BirRequestLimiterInterface
{
    public function acquire(
        BirOperation $operation,
        #[SensitiveParameter] array $parameters = [],
    ): void {}

    public function beginRateLimitScope(): void
    {
        throw new RuntimeException('Scope backend unavailable.');
    }

    public function endRateLimitScope(): void {}
}

final class NativeTransportThrowingSender implements BirHttpSenderInterface
{
    public function send(
        BirOperation $operation,
        #[SensitiveParameter] string $soapEnvelope,
        #[SensitiveParameter] ?string $sessionId,
    ): RawTransportResult {
        throw new RuntimeException('Connection timed out.');
    }
}

final class NativeTransportRecordingSender implements BirHttpSenderInterface
{
    /** @var list<BirOperation> */
    public array $calls = [];

    public function __construct(private readonly string $body) {}

    public function send(
        BirOperation $operation,
        #[SensitiveParameter] string $soapEnvelope,
        #[SensitiveParameter] ?string $sessionId,
    ): RawTransportResult {
        $this->calls[] = $operation;

        return RawTransportResult::success($this->body, 'application/soap+xml; charset=UTF-8');
    }
}
