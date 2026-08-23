<?php

declare(strict_types=1);

use cieplik206\BirRegon\BirClient;
use cieplik206\BirRegon\Enums\Environment;
use cieplik206\BirRegon\Exceptions\BirAuthenticationException;
use cieplik206\BirRegon\Exceptions\BirException;
use cieplik206\BirRegon\GusApiFactoryInterface;
use cieplik206\BirRegon\Tests\Support\StubGusApiFactory;
use GusApi\Exception\InvalidUserKeyException;
use GusApi\GusApi;

$originalExceptionIgnoreArgs = ini_get('zend.exception_ignore_args');

beforeAll(static function (): void {
    if (
        ini_set('zend.exception_ignore_args', '0') === false
        || ini_get('zend.exception_ignore_args') !== '0'
    ) {
        throw new RuntimeException('Unable to enable exception arguments for the credential exposure tests.');
    }
});

afterAll(static function () use ($originalExceptionIgnoreArgs): void {
    if (is_string($originalExceptionIgnoreArgs)) {
        ini_set('zend.exception_ignore_args', $originalExceptionIgnoreArgs);
    }
});

it('does not expose the BIR API key through authentication exceptions', function (): void {
    $apiKey = 'bir-key-security-test-sentinel';
    $api = new class($apiKey) extends GusApi
    {
        public function __construct(private readonly string $apiKey) {}

        public function login(): bool
        {
            throw new InvalidUserKeyException("User key '{$this->apiKey}' is invalid");
        }
    };
    $client = new BirClient(new StubGusApiFactory($api), $apiKey);

    try {
        $client->searchByNip('1234567890');

        throw new LogicException('Expected the invalid API key to cause an authentication exception.');
    } catch (BirAuthenticationException $exception) {
        $previous = $exception->getPrevious();

        expect($exception->getMessage())->toBe('Invalid API key')
            ->and($previous)->toBeInstanceOf(BirException::class)
            ->and($previous?->getMessage())->toBe('Invalid API key')
            ->and($previous?->getPrevious())->toBeNull()
            ->and(renderThrowableChain($exception))->not->toContain($apiKey)
            ->and(throwableGraphContainsSensitiveValue($exception, $apiKey))->toBeFalse()
            ->and(throwableGraphContainsBirClientClosure($exception))->toBeFalse();
    }
});

it('does not expose the BIR session ID through transport exceptions', function (): void {
    $sessionId = 'bir-session-security-test-sentinel';
    $api = new class($sessionId) extends GusApi
    {
        public function __construct(private readonly string $sessionId) {}

        public function login(): bool
        {
            $this->setSessionId($this->sessionId);

            return true;
        }

        public function isLogged(): bool
        {
            return true;
        }

        public function getByNip(string $nip): array
        {
            throw new RuntimeException("SOAP request failed for session '{$this->sessionId}'", 73);
        }
    };
    $client = new BirClient(new StubGusApiFactory($api), 'api-key');

    try {
        $client->searchByNip('1234567890');

        throw new LogicException('Expected the transport failure to cause a BIR exception.');
    } catch (BirException $exception) {
        $previous = $exception->getPrevious();

        expect($exception->getMessage())
            ->toBe("GUS API error: SOAP request failed for session '[REDACTED]'")
            ->and($previous)->toBeInstanceOf(BirException::class)
            ->and($previous?->getMessage())
            ->toBe("SOAP request failed for session '[REDACTED]'")
            ->and($previous?->getCode())->toBe(73)
            ->and($previous?->getPrevious())->toBeNull()
            ->and(renderThrowableChain($exception))->not->toContain($sessionId)
            ->and(throwableGraphContainsSensitiveValue($exception, $sessionId))->toBeFalse()
            ->and(throwableGraphContainsBirClientClosure($exception))->toBeFalse();
    }
});

it('does not expose the BIR API key when creating the GUS client fails', function (): void {
    $apiKey = 'bir-factory-key-security-test-sentinel';
    $factory = new class implements GusApiFactoryInterface
    {
        public function make(string $apiKey, Environment $environment): GusApi
        {
            throw new RuntimeException("Factory failed for key '{$apiKey}'", 91);
        }
    };
    $client = new BirClient($factory, $apiKey);

    try {
        $client->searchByNip('1234567890');

        throw new LogicException('Expected the factory failure to cause a BIR exception.');
    } catch (BirException $exception) {
        $previous = $exception->getPrevious();

        expect($exception->getMessage())
            ->toBe("Failed to connect to GUS API: Factory failed for key '[REDACTED]'")
            ->and($previous)->toBeInstanceOf(BirException::class)
            ->and($previous?->getMessage())
            ->toBe("Factory failed for key '[REDACTED]'")
            ->and($previous?->getCode())->toBe(91)
            ->and($previous?->getPrevious())->toBeNull()
            ->and(renderThrowableChain($exception))->not->toContain($apiKey)
            ->and(throwableGraphContainsSensitiveValue($exception, $apiKey))->toBeFalse()
            ->and(throwableGraphContainsBirClientClosure($exception))->toBeFalse();
    }
});

it('does not retain client-bound closures for authenticated operations outside searches', function (): void {
    $sessionId = 'bir-data-status-session-security-test-sentinel';
    $api = new class($sessionId) extends GusApi
    {
        public function __construct(private readonly string $sessionId) {}

        public function login(): bool
        {
            $this->setSessionId($this->sessionId);

            return true;
        }

        public function isLogged(): bool
        {
            return true;
        }

        public function dataStatus(): DateTimeImmutable
        {
            throw new RuntimeException("Data status failed for session '{$this->sessionId}'", 84);
        }
    };
    $client = new BirClient(new StubGusApiFactory($api), 'api-key');

    try {
        $client->getDataStatus();

        throw new LogicException('Expected the data-status failure to cause a BIR exception.');
    } catch (BirException $exception) {
        $previous = $exception->getPrevious();

        expect($exception->getMessage())
            ->toBe("GUS API error: Data status failed for session '[REDACTED]'")
            ->and($previous)->toBeInstanceOf(BirException::class)
            ->and($previous?->getMessage())
            ->toBe("Data status failed for session '[REDACTED]'")
            ->and($previous?->getCode())->toBe(84)
            ->and($previous?->getPrevious())->toBeNull()
            ->and(renderThrowableChain($exception))->not->toContain($sessionId)
            ->and(throwableGraphContainsSensitiveValue($exception, $sessionId))->toBeFalse()
            ->and(throwableGraphContainsBirClientClosure($exception))->toBeFalse();
    }
});

function renderThrowableChain(Throwable $exception): string
{
    $rendered = '';

    do {
        $rendered .= (string) $exception;
        $exception = $exception->getPrevious();
    } while ($exception instanceof Throwable);

    return $rendered;
}

function throwableGraphContainsSensitiveValue(
    Throwable $exception,
    string $sensitiveValue,
): bool {
    return valueContainsSensitiveData(
        $exception,
        $sensitiveValue,
        new SplObjectStorage,
    );
}

function throwableGraphContainsBirClientClosure(Throwable $exception): bool
{
    return valueContainsBirClientClosure(
        $exception,
        new SplObjectStorage,
    );
}

/** @param SplObjectStorage<object, null> $visited */
function valueContainsSensitiveData(
    mixed $value,
    string $sensitiveValue,
    SplObjectStorage $visited,
): bool {
    if (is_string($value)) {
        return str_contains($value, $sensitiveValue);
    }

    if (is_array($value)) {
        foreach ($value as $item) {
            if (valueContainsSensitiveData($item, $sensitiveValue, $visited)) {
                return true;
            }
        }

        return false;
    }

    if ($value instanceof SensitiveParameterValue) {
        return valueContainsSensitiveData(
            $value->getValue(),
            $sensitiveValue,
            $visited,
        );
    }

    if (! $value instanceof Throwable || $visited->offsetExists($value)) {
        return false;
    }

    $visited->offsetSet($value);

    return str_contains($value->getMessage(), $sensitiveValue)
        || valueContainsSensitiveData($value->getTrace(), $sensitiveValue, $visited)
        || ($value->getPrevious() instanceof Throwable
            && valueContainsSensitiveData($value->getPrevious(), $sensitiveValue, $visited));
}

/** @param SplObjectStorage<object, null> $visited */
function valueContainsBirClientClosure(
    mixed $value,
    SplObjectStorage $visited,
): bool {
    if (is_array($value)) {
        foreach ($value as $item) {
            if (valueContainsBirClientClosure($item, $visited)) {
                return true;
            }
        }

        return false;
    }

    if ($value instanceof SensitiveParameterValue) {
        return valueContainsBirClientClosure($value->getValue(), $visited);
    }

    if ($value instanceof BirClient) {
        return true;
    }

    if ($value instanceof Closure) {
        if ($visited->offsetExists($value)) {
            return false;
        }

        $visited->offsetSet($value);
        $reflection = new ReflectionFunction($value);

        return $reflection->getClosureThis() instanceof BirClient
            || valueContainsBirClientClosure($reflection->getStaticVariables(), $visited);
    }

    if (! $value instanceof Throwable || $visited->offsetExists($value)) {
        return false;
    }

    $visited->offsetSet($value);

    return valueContainsBirClientClosure($value->getTrace(), $visited)
        || ($value->getPrevious() instanceof Throwable
            && valueContainsBirClientClosure($value->getPrevious(), $visited));
}
