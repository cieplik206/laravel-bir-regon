<?php

declare(strict_types=1);

use cieplik206\BirRegon\BirClient;
use cieplik206\BirRegon\BirClientInterface;
use cieplik206\BirRegon\BirRegonService;
use cieplik206\BirRegon\Contracts\BirGatewayInterface;
use cieplik206\BirRegon\Contracts\BirRequestLimiterInterface;
use cieplik206\BirRegon\Contracts\BirSoapTransportInterface;
use cieplik206\BirRegon\Enums\EntityType;
use cieplik206\BirRegon\Enums\Environment;
use cieplik206\BirRegon\Enums\ReportType;
use cieplik206\BirRegon\Enums\Silo;
use cieplik206\BirRegon\Exceptions\BirAmbiguousResultException;
use cieplik206\BirRegon\Exceptions\BirNotFoundException;
use cieplik206\BirRegon\Exceptions\BirRateLimitException;
use cieplik206\BirRegon\Exceptions\BirTransportException;
use cieplik206\BirRegon\Gateway\NativeBirGateway;
use cieplik206\BirRegon\Protocol\BirOperation;
use cieplik206\BirRegon\Protocol\RawTransportResult;
use cieplik206\BirRegon\Protocol\SearchCriteria;
use cieplik206\BirRegon\Protocol\SearchResult;
use cieplik206\BirRegon\Protocol\SoapEnvelopeBuilder;
use cieplik206\BirRegon\Protocol\TransportFailureType;
use cieplik206\BirRegon\Protocol\TransportResponse;
use cieplik206\BirRegon\RateLimit\CacheBirRequestLimiter;
use cieplik206\BirRegon\RateLimit\UnlimitedBirRequestLimiter;
use cieplik206\BirRegon\Tests\Support\FakeBirGateway;
use cieplik206\BirRegon\Tests\Support\QueueBirSoapTransport;
use cieplik206\BirRegon\Tests\Support\StubBirClient;
use cieplik206\BirRegon\Transport\BirHttpSenderInterface;
use cieplik206\BirRegon\Transport\NativeSoapTransport;
use cieplik206\BirRegon\Validation\PolishIdentifierChecksum;

$originalIdentifierPrivacyExceptionIgnoreArgs = ini_get('zend.exception_ignore_args');

beforeAll(function (): void {
    if (
        ini_set('zend.exception_ignore_args', '0') === false
        || ini_get('zend.exception_ignore_args') !== '0'
    ) {
        throw new RuntimeException('Unable to enable exception arguments for identifier privacy tests.');
    }
});

afterAll(function () use ($originalIdentifierPrivacyExceptionIgnoreArgs): void {
    if (is_string($originalIdentifierPrivacyExceptionIgnoreArgs)) {
        ini_set('zend.exception_ignore_args', $originalIdentifierPrivacyExceptionIgnoreArgs);
    }
});

it('keeps missing identifiers of every supported type out of exception messages and traces', function (): void {
    $client = new BirClient(new FakeBirGateway);
    $cases = [
        [
            '1234567890',
            'NIP',
            static fn (BirClient $client, #[SensitiveParameter] string $identifier): array => $client->searchByNip($identifier),
        ],
        [
            '0987654321',
            'KRS',
            static fn (BirClient $client, #[SensitiveParameter] string $identifier): array => $client->searchByKrs($identifier),
        ],
        [
            '123456789',
            'REGON9',
            static fn (BirClient $client, #[SensitiveParameter] string $identifier): array => $client->searchByRegons9([$identifier]),
        ],
        [
            '12345678901234',
            'REGON14',
            static fn (BirClient $client, #[SensitiveParameter] string $identifier): array => $client->searchByRegons14([$identifier]),
        ],
    ];

    foreach ($cases as [$identifier, $identifierType, $search]) {
        try {
            $search($client, $identifier);
            throw new LogicException('The empty search did not throw a not-found exception.');
        } catch (BirNotFoundException $exception) {
            expect($exception->getMessage())
                ->toBe("Nie znaleziono podmiotu dla identyfikatora typu {$identifierType}.")
                ->not->toContain($identifier)
                ->and(identifierPrivacyThrowableContains($exception, $identifier))->toBeFalse();
        }
    }
});

it('keeps every missing batch identifier out of the exception message and trace', function (): void {
    $identifiers = array_map(
        static fn (int $value): string => str_pad((string) $value, 10, '0', STR_PAD_LEFT),
        range(1, SearchCriteria::MAX_BATCH_SIZE),
    );
    $client = new BirClient(new FakeBirGateway);

    try {
        $client->searchByNips($identifiers);
        throw new LogicException('The empty batch search did not throw a not-found exception.');
    } catch (BirNotFoundException $exception) {
        expect($exception->getMessage())
            ->toBe('Nie znaleziono podmiotu dla identyfikatora typu NIP.');

        foreach ($identifiers as $identifier) {
            expect($exception->getMessage())->not->toContain($identifier)
                ->and(identifierPrivacyThrowableContains($exception, $identifier))->toBeFalse();
        }
    }
});

it('does not expose the submitted identifier through an ambiguous result exception', function (): void {
    $identifier = '1234567890';
    $gateway = new FakeBirGateway(searchResults: [
        identifierPrivacySearchResult('123456789', Silo::Ceidg),
        identifierPrivacySearchResult('987654321', Silo::Agriculture),
    ]);
    $client = new BirClient($gateway);

    try {
        $client->getFullReportByNip($identifier, ReportType::NaturalPerson);
        throw new LogicException('The ambiguous search did not throw an exception.');
    } catch (BirAmbiguousResultException $exception) {
        expect($exception->getMessage())
            ->toBe('GUS BIR returned 2 distinct compatible report targets for the NIP identifier. Use the plural full-report method to retrieve every result.')
            ->not->toContain($identifier)
            ->and(array_key_exists('identifier', get_object_vars($exception)))->toBeFalse()
            ->and($exception->identifierType)->toBe('NIP')
            ->and($exception->compatibleTargetCount)->toBe(2)
            ->and(identifierPrivacyThrowableContains($exception, $identifier))->toBeFalse();
    }
});

it('keeps an identifier out of a translated transport exception graph', function (): void {
    $identifier = '1234567890';
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success('A1234567890123456789'),
        TransportResponse::failure(TransportFailureType::Transport),
    );
    $client = new BirClient(new NativeBirGateway($transport));

    try {
        $client->searchByNip($identifier);
        throw new LogicException('The failed transport did not throw an exception.');
    } catch (BirTransportException $exception) {
        expect(identifierPrivacyThrowableContains($exception, $identifier))->toBeFalse();
    }
});

it('keeps a maximum identifier batch out of a propagated rate-limit exception graph', function (): void {
    $identifiers = array_map(
        static fn (int $value): string => str_pad((string) $value, 10, '0', STR_PAD_LEFT),
        range(1, SearchCriteria::MAX_BATCH_SIZE),
    );
    $loginResponse = file_get_contents(__DIR__.'/../Fixtures/Gus/soap/login-success.xml');

    if (! is_string($loginResponse)) {
        throw new RuntimeException('Unable to read the login response fixture.');
    }

    $limiter = new class implements BirRequestLimiterInterface
    {
        /** @var list<BirOperation> */
        public array $operations = [];

        public function acquire(
            BirOperation $operation,
            #[SensitiveParameter] array $parameters = [],
        ): void {
            $this->operations[] = $operation;

            if ($operation === BirOperation::Search) {
                throw BirRateLimitException::quotaExceeded(7);
            }
        }
    };
    $sender = new class($loginResponse) implements BirHttpSenderInterface
    {
        /** @var list<BirOperation> */
        public array $operations = [];

        public function __construct(private readonly string $loginResponse) {}

        public function send(
            BirOperation $operation,
            #[SensitiveParameter] string $soapEnvelope,
            #[SensitiveParameter] ?string $sessionId,
        ): RawTransportResult {
            $this->operations[] = $operation;

            if ($operation !== BirOperation::Login) {
                throw new LogicException('Only the login request may reach the HTTP sender.');
            }

            return RawTransportResult::success(
                $this->loginResponse,
                'application/soap+xml; charset=UTF-8',
            );
        }
    };
    $client = new BirClient(new NativeBirGateway(new NativeSoapTransport(
        apiKey: 'APIKEYSENTINEL123456',
        requestLimiter: $limiter,
        environment: Environment::Sandbox,
        httpSender: $sender,
    )));

    try {
        $client->searchByNips($identifiers);
        throw new LogicException('The maximum batch search did not reach the rate limiter.');
    } catch (BirRateLimitException $exception) {
        expect($exception->retryAfterSeconds())->toBe(7)
            ->and($limiter->operations)->toBe([BirOperation::Login, BirOperation::Search])
            ->and($sender->operations)->toBe([BirOperation::Login]);

        foreach ($identifiers as $identifier) {
            expect(identifierPrivacyThrowableContains($exception, $identifier))->toBeFalse();
        }
    }
});

it('keeps identifiers out of validation exception traces', function (): void {
    $invalidCriteriaIdentifier = '123456789012345678';
    $invalidChecksumIdentifier = '1234567890';

    foreach ([
        static fn () => SearchCriteria::nip($invalidCriteriaIdentifier),
        static fn () => PolishIdentifierChecksum::assertValidNip($invalidChecksumIdentifier),
    ] as $operation) {
        try {
            $operation();
            throw new LogicException('Invalid identifier input did not throw an exception.');
        } catch (Throwable $exception) {
            expect(identifierPrivacyThrowableContains($exception, $invalidCriteriaIdentifier))->toBeFalse()
                ->and(identifierPrivacyThrowableContains($exception, $invalidChecksumIdentifier))->toBeFalse();
        }
    }
});

it('marks every public identifier entry point as sensitive', function (): void {
    $parameters = [
        [BirClientInterface::class, 'searchByNip', 'nip'],
        [BirClientInterface::class, 'searchByRegon', 'regon'],
        [BirClientInterface::class, 'searchByKrs', 'krs'],
        [BirClientInterface::class, 'searchByNips', 'nips'],
        [BirClientInterface::class, 'searchByKrsNumbers', 'krsNumbers'],
        [BirClientInterface::class, 'searchByRegons9', 'regons'],
        [BirClientInterface::class, 'searchByRegons14', 'regons'],
        [BirClientInterface::class, 'getFullReportByNip', 'nip'],
        [BirClientInterface::class, 'getFullReportsByNip', 'nip'],
        [BirClientInterface::class, 'getFullReportByKrs', 'krs'],
        [BirClientInterface::class, 'getFullReportsByKrs', 'krs'],
        [BirClientInterface::class, 'getFullReport', 'regon'],
        [BirClientInterface::class, 'getFullReports', 'regon'],
        [BirClient::class, 'searchByNip', 'nip'],
        [BirClient::class, 'searchByRegon', 'regon'],
        [BirClient::class, 'searchByKrs', 'krs'],
        [BirClient::class, 'searchByNips', 'nips'],
        [BirClient::class, 'searchByKrsNumbers', 'krsNumbers'],
        [BirClient::class, 'searchByRegons9', 'regons'],
        [BirClient::class, 'searchByRegons14', 'regons'],
        [BirClient::class, 'getFullReportByNip', 'nip'],
        [BirClient::class, 'getFullReportsByNip', 'nip'],
        [BirClient::class, 'getFullReportByKrs', 'krs'],
        [BirClient::class, 'getFullReportsByKrs', 'krs'],
        [BirClient::class, 'getFullReport', 'regon'],
        [BirClient::class, 'getFullReports', 'regon'],
        [BirRegonService::class, 'forNip', 'nip'],
        [BirRegonService::class, 'forRegon', 'regon'],
        [BirRegonService::class, 'forKrs', 'krs'],
        [BirRegonService::class, 'forNips', 'nips'],
        [BirRegonService::class, 'forKrsNumbers', 'krsNumbers'],
        [BirRegonService::class, 'forRegons9', 'regons'],
        [BirRegonService::class, 'forRegons14', 'regons'],
        [SearchCriteria::class, 'nip', 'nip'],
        [SearchCriteria::class, 'regon', 'regon'],
        [SearchCriteria::class, 'krs', 'krs'],
        [SearchCriteria::class, 'nips', 'nips'],
        [SearchCriteria::class, 'krsNumbers', 'krsNumbers'],
        [SearchCriteria::class, 'regons9', 'regons'],
        [SearchCriteria::class, 'regons14', 'regons'],
        [PolishIdentifierChecksum::class, 'isValidNip', 'nip'],
        [PolishIdentifierChecksum::class, 'isValidRegon', 'regon'],
        [PolishIdentifierChecksum::class, 'isValidRegon9', 'regon'],
        [PolishIdentifierChecksum::class, 'isValidRegon14', 'regon'],
        [PolishIdentifierChecksum::class, 'assertValidNip', 'nip'],
        [PolishIdentifierChecksum::class, 'assertValidRegon', 'regon'],
        [PolishIdentifierChecksum::class, 'assertValidRegon9', 'regon'],
        [PolishIdentifierChecksum::class, 'assertValidRegon14', 'regon'],
        [BirGatewayInterface::class, 'search', 'criteria'],
        [BirGatewayInterface::class, 'fullReport', 'regon'],
        [NativeBirGateway::class, 'search', 'criteria'],
        [NativeBirGateway::class, 'fullReport', 'regon'],
        [BirSoapTransportInterface::class, 'call', 'parameters'],
        [NativeSoapTransport::class, 'call', 'parameters'],
        [BirRequestLimiterInterface::class, 'acquire', 'parameters'],
        [CacheBirRequestLimiter::class, 'acquire', 'parameters'],
        [UnlimitedBirRequestLimiter::class, 'acquire', 'parameters'],
        [SoapEnvelopeBuilder::class, 'build', 'parameters'],
        [SoapEnvelopeBuilder::class, 'appendOperationParameters', 'parameters'],
        [SoapEnvelopeBuilder::class, 'appendSearchCriteria', 'criteria'],
        [SoapEnvelopeBuilder::class, 'appendFullReportParameters', 'parameters'],
        [SoapEnvelopeBuilder::class, 'appendBulkReportParameters', 'parameters'],
        [FakeBirGateway::class, 'search', 'criteria'],
        [FakeBirGateway::class, 'fullReport', 'regon'],
        [QueueBirSoapTransport::class, 'call', 'parameters'],
        [StubBirClient::class, 'searchByNip', 'nip'],
        [StubBirClient::class, 'searchByRegon', 'regon'],
        [StubBirClient::class, 'searchByKrs', 'krs'],
        [StubBirClient::class, 'searchByNips', 'nips'],
        [StubBirClient::class, 'searchByKrsNumbers', 'krsNumbers'],
        [StubBirClient::class, 'searchByRegons9', 'regons'],
        [StubBirClient::class, 'searchByRegons14', 'regons'],
        [StubBirClient::class, 'getFullReportByNip', 'nip'],
        [StubBirClient::class, 'getFullReportsByNip', 'nip'],
        [StubBirClient::class, 'getFullReportByKrs', 'krs'],
        [StubBirClient::class, 'getFullReportsByKrs', 'krs'],
        [StubBirClient::class, 'getFullReport', 'regon'],
        [StubBirClient::class, 'getFullReports', 'regon'],
    ];

    foreach ($parameters as [$class, $method, $parameter]) {
        expect((new ReflectionParameter([$class, $method], $parameter))
            ->getAttributes(SensitiveParameter::class))
            ->toHaveCount(1, $class.'::'.$method.'($'.$parameter.')');
    }
});

function identifierPrivacySearchResult(string $regon, Silo $silo): SearchResult
{
    return new SearchResult(
        regon: $regon,
        nip: null,
        name: 'Identifier privacy fixture',
        city: null,
        postalCode: null,
        street: null,
        buildingNumber: null,
        apartmentNumber: null,
        province: null,
        district: null,
        commune: null,
        type: EntityType::NaturalPerson,
        regon14: null,
        nipStatus: null,
        silo: $silo,
        activityEndDate: null,
        postCity: null,
    );
}

function identifierPrivacyThrowableContains(Throwable $exception, string $identifier): bool
{
    return identifierPrivacyThrowableContainsWithVisited(
        $exception,
        $identifier,
        new SplObjectStorage,
    );
}

/** @param SplObjectStorage<object, null> $visited */
function identifierPrivacyThrowableContainsWithVisited(
    Throwable $exception,
    string $identifier,
    SplObjectStorage $visited,
): bool {
    if ($visited->offsetExists($exception)) {
        return false;
    }

    $visited->attach($exception);

    if (str_contains($exception->getMessage(), $identifier)) {
        return true;
    }

    foreach ($exception->getTrace() as $frame) {
        $class = $frame['class'] ?? null;

        if (
            is_string($class)
            && str_starts_with($class, 'cieplik206\\BirRegon\\')
            && identifierPrivacyValueContains($frame['args'] ?? [], $identifier, $visited)
        ) {
            return true;
        }
    }

    foreach ((array) $exception as $property => $value) {
        $separator = strrpos($property, "\0");
        $propertyName = $separator === false ? $property : substr($property, $separator + 1);

        if (in_array($propertyName, [
            'message',
            'string',
            'code',
            'file',
            'line',
            'trace',
            'previous',
        ], true)) {
            continue;
        }

        if (identifierPrivacyValueContains($value, $identifier, $visited)) {
            return true;
        }
    }

    return $exception->getPrevious() instanceof Throwable
        && identifierPrivacyThrowableContainsWithVisited(
            $exception->getPrevious(),
            $identifier,
            $visited,
        );
}

/** @param SplObjectStorage<object, null> $visited */
function identifierPrivacyValueContains(
    mixed $value,
    string $identifier,
    SplObjectStorage $visited,
): bool {
    if (is_string($value)) {
        return str_contains($value, $identifier);
    }

    if (is_array($value)) {
        foreach ($value as $key => $item) {
            if (
                identifierPrivacyValueContains($key, $identifier, $visited)
                || identifierPrivacyValueContains($item, $identifier, $visited)
            ) {
                return true;
            }
        }

        return false;
    }

    if (! is_object($value)) {
        return false;
    }

    if ($value instanceof SensitiveParameterValue) {
        return false;
    }

    if ($value instanceof Throwable) {
        return identifierPrivacyThrowableContainsWithVisited($value, $identifier, $visited);
    }

    if ($visited->offsetExists($value)) {
        return false;
    }

    $visited->attach($value);

    return identifierPrivacyValueContains((array) $value, $identifier, $visited);
}
