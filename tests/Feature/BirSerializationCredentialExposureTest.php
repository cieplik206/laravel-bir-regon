<?php

declare(strict_types=1);

use cieplik206\BirRegon\BirBatchSearchBuilder;
use cieplik206\BirRegon\BirBulkReportBuilder;
use cieplik206\BirRegon\BirClient;
use cieplik206\BirRegon\BirDiagnosticsBuilder;
use cieplik206\BirRegon\BirRegonService;
use cieplik206\BirRegon\BirSearchBuilder;
use cieplik206\BirRegon\BirServiceBuilder;
use cieplik206\BirRegon\Enums\Environment;
use cieplik206\BirRegon\Gateway\BirSessionState;
use cieplik206\BirRegon\Gateway\NativeBirGateway;
use cieplik206\BirRegon\Protocol\BirOperation;
use cieplik206\BirRegon\Protocol\GetValueParameter;
use cieplik206\BirRegon\Protocol\SoapEnvelopeBuilder;
use cieplik206\BirRegon\RateLimit\UnlimitedBirRequestLimiter;
use cieplik206\BirRegon\Tests\Support\FakeBirGateway;
use cieplik206\BirRegon\Transport\NativeSoapTransport;
use Illuminate\Contracts\Queue\ShouldQueue;

$originalSerializationExceptionIgnoreArgs = ini_get('zend.exception_ignore_args');

beforeAll(function (): void {
    if (
        ini_set('zend.exception_ignore_args', '0') === false
        || ini_get('zend.exception_ignore_args') !== '0'
    ) {
        throw new RuntimeException('Unable to enable exception arguments for the serialization exposure tests.');
    }
});

afterAll(function () use ($originalSerializationExceptionIgnoreArgs): void {
    if (is_string($originalSerializationExceptionIgnoreArgs)) {
        ini_set('zend.exception_ignore_args', $originalSerializationExceptionIgnoreArgs);
    }
});

final class BirBuilderQueueJobFixture implements ShouldQueue
{
    public function __construct(public readonly BirSearchBuilder $builder) {}
}

it('restores every security-sensitive BIR object as a credential-free tombstone', function (): void {
    $apiKey = 'APIKEYSENTINEL123456';
    $sessionId = 'SIDSESSION1234567890';
    $transport = new NativeSoapTransport(
        apiKey: $apiKey,
        requestLimiter: new UnlimitedBirRequestLimiter,
        environment: Environment::Sandbox,
    );
    $transport->useSession($sessionId);
    $envelopeBuilder = new SoapEnvelopeBuilder($apiKey);
    $envelopeBuilder->useSession($sessionId);
    $requestXml = $envelopeBuilder->build(
        BirOperation::Login,
        [],
        Environment::Sandbox->endpoint(),
    );
    $session = new BirSessionState;
    $session->start($sessionId);
    $gateway = new NativeBirGateway($transport, session: $session);
    $client = new BirClient($gateway);
    $service = new BirRegonService($client);
    $subjects = [
        $transport,
        $envelopeBuilder,
        $session,
        $gateway,
        $client,
        $service,
        $service->forNip('1234567890'),
        $service->forNips(['1234567890']),
        $service->forDate(new DateTimeImmutable('2026-08-22')),
        $service->service(),
        $service->diagnostics(),
    ];

    expect($requestXml)->toBeString()->toContain($apiKey);

    foreach ($subjects as $subject) {
        $serialized = serialize($subject);
        $restored = unserialize($serialized);

        if (! is_object($restored)) {
            throw new LogicException('Expected the serialized BIR object to restore as a tombstone.');
        }

        $usageFailure = restoredSerializationUsageFailure($restored);
        $restoredValues = array_values((array) $restored);

        expect(str_contains($serialized, $apiKey))->toBeFalse()
            ->and(str_contains($serialized, $sessionId))->toBeFalse()
            ->and(str_contains($serialized, (string) $requestXml))->toBeFalse();
        expect($restored::class)->toBe($subject::class);
        expect(in_array($apiKey, $restoredValues, true))->toBeFalse()
            ->and(in_array($sessionId, $restoredValues, true))->toBeFalse()
            ->and(in_array((string) $requestXml, $restoredValues, true))->toBeFalse();
        expect($usageFailure)->toBeInstanceOf(LogicException::class)
            ->and($usageFailure->getMessage())
            ->toBe(sprintf('Serialization of %s is not supported.', $subject::class));

        expectSerializationThrowableToExclude(
            $usageFailure,
            [$apiKey, $sessionId, (string) $requestXml],
        );
    }
});

it('does not expose native client credentials in a queued job payload', function (): void {
    $apiKey = 'APIKEYSENTINEL123456';
    $sessionId = 'SIDSESSION1234567890';
    $transport = new NativeSoapTransport(
        apiKey: $apiKey,
        requestLimiter: new UnlimitedBirRequestLimiter,
        environment: Environment::Sandbox,
    );
    $transport->useSession($sessionId);
    $job = new BirBuilderQueueJobFixture(
        (new BirRegonService(
            new BirClient(new NativeBirGateway($transport)),
        ))->forNip('1234567890'),
    );
    $serialized = serialize($job);
    $restored = unserialize($serialized);

    if (! $restored instanceof BirBuilderQueueJobFixture) {
        throw new LogicException('Expected the serialized job to restore with a tombstone builder.');
    }

    $usageFailure = restoredSerializationUsageFailure($restored->builder);
    $restoredBuilderValues = array_values((array) $restored->builder);

    expect(str_contains($serialized, $apiKey))->toBeFalse()
        ->and(str_contains($serialized, $sessionId))->toBeFalse();
    expect(in_array($apiKey, $restoredBuilderValues, true))->toBeFalse()
        ->and(in_array($sessionId, $restoredBuilderValues, true))->toBeFalse();
    expect($usageFailure)->toBeInstanceOf(LogicException::class);

    expectSerializationThrowableToExclude($usageFailure, [$apiKey, $sessionId]);
});

it('keeps fake-gateway clients serializable only as unusable tombstones', function (): void {
    $client = new BirClient(new FakeBirGateway);
    $serialized = serialize($client);
    $restored = unserialize($serialized);

    expect($restored)->toBeInstanceOf(BirClient::class);

    $usageFailure = restoredSerializationUsageFailure($restored);

    expect($usageFailure)->toBeInstanceOf(LogicException::class)
        ->and($usageFailure->getMessage())
        ->toBe(sprintf('Serialization of %s is not supported.', BirClient::class));
});

it('does not retain a BIR API key when deserializing a legacy client payload', function (): void {
    $apiKey = 'bir-legacy-serialization-security-test-sentinel';
    $serialized = legacySerializedBirClient($apiKey);
    $restored = null;
    $restorationFailure = null;

    try {
        $restored = unserialize($serialized);
    } catch (Throwable $exception) {
        $restorationFailure = $exception;
    }

    expect($serialized)->toContain($apiKey);

    if ($restorationFailure instanceof Throwable) {
        expect(serializationThrowableGraphContainsSensitiveValue($restorationFailure, $apiKey))
            ->toBeFalse();
    }

    expect($restorationFailure)->toBeNull()
        ->and($restored)->toBeInstanceOf(BirClient::class)
        ->and(array_values((array) $restored))->not->toContain($apiKey);

    if (! $restored instanceof BirClient) {
        throw new LogicException('Expected the legacy client to restore as a tombstone.');
    }

    $usageFailure = restoredSerializationUsageFailure($restored);

    expect($usageFailure)->toBeInstanceOf(LogicException::class);
    expectSerializationThrowableToExclude($usageFailure, [$apiKey]);
});

function legacySerializedBirClient(string $apiKey): string
{
    $class = BirClient::class;
    $apiKeyProperty = "\0{$class}\0apiKey";

    return sprintf(
        'O:%d:"%s":1:{s:%d:"%s";s:%d:"%s";}',
        strlen($class),
        $class,
        strlen($apiKeyProperty),
        $apiKeyProperty,
        strlen($apiKey),
        $apiKey,
    );
}

function restoredSerializationUsageFailure(object $subject): Throwable
{
    try {
        if ($subject instanceof NativeSoapTransport) {
            $subject->isAuthenticationConfigured();
        } elseif ($subject instanceof SoapEnvelopeBuilder) {
            $subject->build(
                BirOperation::Login,
                [],
                Environment::Sandbox->endpoint(),
            );
        } elseif ($subject instanceof BirSessionState) {
            $subject->hasSession();
        } elseif ($subject instanceof NativeBirGateway) {
            $subject->getValue(GetValueParameter::ServiceStatus);
        } elseif ($subject instanceof BirClient) {
            $subject->getServiceStatus();
        } elseif ($subject instanceof BirRegonService) {
            $subject->service();
        } elseif ($subject instanceof BirSearchBuilder) {
            $subject->get();
        } elseif ($subject instanceof BirBatchSearchBuilder) {
            $subject->get();
        } elseif ($subject instanceof BirBulkReportBuilder) {
            $subject->getBulkReport();
        } elseif ($subject instanceof BirServiceBuilder) {
            $subject->status();
        } elseif ($subject instanceof BirDiagnosticsBuilder) {
            $subject->get();
        }
    } catch (Throwable $exception) {
        return $exception;
    }

    throw new LogicException(sprintf(
        'Expected restored %s to reject use.',
        $subject::class,
    ));
}

/** @param list<string> $sensitiveValues */
function expectSerializationThrowableToExclude(
    Throwable $exception,
    array $sensitiveValues,
): void {
    foreach ($sensitiveValues as $sensitiveValue) {
        expect((string) $exception)->not->toContain($sensitiveValue)
            ->and(serializationThrowableGraphContainsSensitiveValue($exception, $sensitiveValue))
            ->toBeFalse();
    }
}

function serializationThrowableGraphContainsSensitiveValue(
    Throwable $exception,
    string $sensitiveValue,
): bool {
    return serializationValueContainsSensitiveData(
        $exception,
        $sensitiveValue,
        new SplObjectStorage,
    );
}

/** @param SplObjectStorage<object, null> $visited */
function serializationValueContainsSensitiveData(
    mixed $value,
    string $sensitiveValue,
    SplObjectStorage $visited,
): bool {
    if (is_string($value)) {
        return str_contains($value, $sensitiveValue);
    }

    if (is_array($value)) {
        foreach ($value as $key => $item) {
            if (
                serializationValueContainsSensitiveData($key, $sensitiveValue, $visited)
                || serializationValueContainsSensitiveData($item, $sensitiveValue, $visited)
            ) {
                return true;
            }
        }

        return false;
    }

    if (! is_object($value) || $visited->offsetExists($value)) {
        return false;
    }

    $visited->offsetSet($value);

    if ($value instanceof SensitiveParameterValue) {
        return serializationValueContainsSensitiveData(
            $value->getValue(),
            $sensitiveValue,
            $visited,
        );
    }

    if ($value instanceof Throwable) {
        return str_contains($value->getMessage(), $sensitiveValue)
            || serializationValueContainsSensitiveData(
                $value->getTrace(),
                $sensitiveValue,
                $visited,
            )
            || ($value->getPrevious() instanceof Throwable
                && serializationValueContainsSensitiveData(
                    $value->getPrevious(),
                    $sensitiveValue,
                    $visited,
                ));
    }

    if ($value instanceof Closure) {
        $reflection = new ReflectionFunction($value);

        return serializationValueContainsSensitiveData(
            $reflection->getClosureThis(),
            $sensitiveValue,
            $visited,
        ) || serializationValueContainsSensitiveData(
            $reflection->getStaticVariables(),
            $sensitiveValue,
            $visited,
        );
    }

    return serializationValueContainsSensitiveData((array) $value, $sensitiveValue, $visited);
}
