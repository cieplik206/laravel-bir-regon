<?php

declare(strict_types=1);

use cieplik206\BirRegon\BirClient;
use cieplik206\BirRegon\Exceptions\BirAuthenticationException;
use cieplik206\BirRegon\Exceptions\BirException;
use cieplik206\BirRegon\Tests\Support\StubGusApiFactory;
use GusApi\Exception\InvalidUserKeyException;
use GusApi\GusApi;

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
            ->and(renderThrowableChain($exception))->not->toContain($apiKey);
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
            ->and(renderThrowableChain($exception))->not->toContain($sessionId);
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
