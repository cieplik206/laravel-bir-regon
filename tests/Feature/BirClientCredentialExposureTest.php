<?php

declare(strict_types=1);

use cieplik206\BirRegon\BirBatchSearchBuilder;
use cieplik206\BirRegon\BirBulkReportBuilder;
use cieplik206\BirRegon\BirClient;
use cieplik206\BirRegon\BirRegonService;
use cieplik206\BirRegon\BirRequestBuilder;
use cieplik206\BirRegon\BirSearchBuilder;
use cieplik206\BirRegon\Contracts\BirSoapTransportInterface;
use cieplik206\BirRegon\Enums\EntityType;
use cieplik206\BirRegon\Enums\Environment;
use cieplik206\BirRegon\Enums\ReportType;
use cieplik206\BirRegon\Enums\Silo;
use cieplik206\BirRegon\Exceptions\BirAuthenticationException;
use cieplik206\BirRegon\Exceptions\BirProtocolException;
use cieplik206\BirRegon\Exceptions\BirRateLimitException;
use cieplik206\BirRegon\Exceptions\BirTransportException;
use cieplik206\BirRegon\Gateway\BirSessionState;
use cieplik206\BirRegon\Gateway\NativeBirGateway;
use cieplik206\BirRegon\Protocol\BirOperation;
use cieplik206\BirRegon\Protocol\GetValueParameter;
use cieplik206\BirRegon\Protocol\SearchCriteria;
use cieplik206\BirRegon\Protocol\SearchResult;
use cieplik206\BirRegon\Protocol\SoapEnvelopeBuilder;
use cieplik206\BirRegon\Protocol\TransportResponse;
use cieplik206\BirRegon\RateLimit\CacheBirRequestLimiter;
use cieplik206\BirRegon\Tests\Support\FakeBirGateway;
use cieplik206\BirRegon\Tests\Support\StubBirClient;
use cieplik206\BirRegon\Transport\NativeSoapTransport;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

$originalExceptionIgnoreArgs = ini_get('zend.exception_ignore_args');

beforeAll(function (): void {
    if (
        ini_set('zend.exception_ignore_args', '0') === false
        || ini_get('zend.exception_ignore_args') !== '0'
    ) {
        throw new RuntimeException('Unable to enable exception arguments for the credential exposure tests.');
    }
});

afterAll(function () use ($originalExceptionIgnoreArgs): void {
    if (is_string($originalExceptionIgnoreArgs)) {
        ini_set('zend.exception_ignore_args', $originalExceptionIgnoreArgs);
    }
});

final class BirCredentialTrapTransportFixture implements BirSoapTransportInterface
{
    private ?string $activeSessionId = null;

    public function __construct(
        #[SensitiveParameter] private readonly string $apiKey,
        #[SensitiveParameter] private readonly string $sessionId,
        #[SensitiveParameter] private readonly string $requestXml,
        private readonly ?BirOperation $failureOperation = null,
        private readonly ?GetValueParameter $failureParameter = null,
        private readonly bool $invalidLoginResponse = false,
        private readonly string $searchResponse = '<root/>',
    ) {}

    public function isAuthenticationConfigured(): bool
    {
        return true;
    }

    public function useSession(#[SensitiveParameter] ?string $sessionId): void
    {
        $this->activeSessionId = $sessionId;
    }

    public function call(BirOperation $operation, array $parameters = []): TransportResponse
    {
        $parameter = $parameters['parameter'] ?? null;

        if (
            $operation === $this->failureOperation
            && ($this->failureParameter === null || $parameter === $this->failureParameter)
        ) {
            throw new RuntimeException(sprintf(
                'Native SOAP failure for key %s, session %s and request %s.',
                $this->apiKey,
                $this->sessionId,
                $this->requestXml,
            ), 91);
        }

        if ($operation === BirOperation::Login) {
            return TransportResponse::success($this->invalidLoginResponse
                ? 'invalid:'.$this->apiKey.':'.$this->requestXml
                : $this->sessionId);
        }

        if ($operation === BirOperation::GetValue) {
            return TransportResponse::success(match ($parameter) {
                GetValueParameter::MessageCode => '0',
                GetValueParameter::Message => 'No error',
                GetValueParameter::SessionStatus => '1',
                GetValueParameter::DataStatus => '23-08-2026',
                GetValueParameter::ServiceStatus => '1',
                GetValueParameter::ServiceMessage => 'Available',
                default => '',
            });
        }

        if ($operation === BirOperation::Search) {
            return TransportResponse::success($this->searchResponse);
        }

        return TransportResponse::success('<root/>');
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return [
            'apiKey' => '[REDACTED]',
            'sessionId' => $this->activeSessionId === null ? '[NONE]' : '[REDACTED]',
            'requestXml' => '[REDACTED]',
        ];
    }
}

final class BirCredentialTrapClientFixture extends StubBirClient
{
    public function __construct(
        #[SensitiveParameter] private readonly string $credential,
    ) {
        parent::__construct();
    }

    public function credential(): string
    {
        return $this->credential;
    }
}

it('marks credential-bearing constructor dependencies and identifiers as sensitive', function (): void {
    $sensitiveParameters = [
        BirClient::class => ['gateway'],
        BirRequestBuilder::class => ['client'],
        BirSearchBuilder::class => ['client', 'identifier'],
        BirBatchSearchBuilder::class => ['client', 'identifiers'],
        BirBulkReportBuilder::class => ['client'],
        BirRegonService::class => ['client', 'sandboxClient'],
        NativeBirGateway::class => ['transport', 'recordsDecoder', 'session'],
        NativeSoapTransport::class => ['apiKey', 'httpSender', 'requestLimiter'],
        CacheBirRequestLimiter::class => ['cache', 'apiKey', 'clock', 'sleeper'],
    ];

    foreach ($sensitiveParameters as $class => $parameterNames) {
        foreach ($parameterNames as $parameterName) {
            $parameter = new ReflectionParameter([$class, '__construct'], $parameterName);

            expect($parameter->getAttributes(SensitiveParameter::class))
                ->toHaveCount(1);
        }
    }
});

it('does not expose credentials or request XML when login transport fails', function (): void {
    $apiKey = 'APIKEYSENTINEL123456';
    $sessionId = 'SIDSESSION1234567890';
    $requestXml = '<request>login-xml-security-sentinel</request>';
    $transport = new BirCredentialTrapTransportFixture(
        $apiKey,
        $sessionId,
        $requestXml,
        failureOperation: BirOperation::Login,
    );
    $client = new BirClient(new NativeBirGateway($transport));

    try {
        $client->searchByNip('1234567890');

        throw new LogicException('Expected the login transport failure to cause a BIR exception.');
    } catch (BirTransportException $exception) {
        expect($exception->getMessage())
            ->toBe('Unable to communicate with the GUS BIR service.')
            ->and($exception->getCode())->toBe(0)
            ->and($exception->getPrevious())->toBeNull();

        expectCredentialThrowableToExclude(
            $exception,
            [$apiKey, $sessionId, $requestXml],
        );
    }
});

it('does not retain the credential-bearing client in fluent builder exception arguments', function (): void {
    $apiKey = 'APIKEYSENTINEL123456';
    $sessionId = 'SIDSESSION1234567890';
    $requestXml = '<request>builder-search-xml-security-sentinel</request>';
    $transport = new BirCredentialTrapTransportFixture(
        $apiKey,
        $sessionId,
        $requestXml,
        failureOperation: BirOperation::Search,
    );
    $builder = new BirSearchBuilder(
        new BirClient(new NativeBirGateway($transport)),
        '1234567890',
        BirSearchBuilder::TYPE_NIP,
    );

    try {
        $builder->get();

        throw new LogicException('Expected the builder transport failure to cause a BIR exception.');
    } catch (BirTransportException $exception) {
        expect($exception->getPrevious())->toBeNull();
        expectCredentialThrowableToExclude($exception, [$apiKey, $sessionId, $requestXml]);
    }
});

it('does not expose the API key or rejected login payload through authentication errors', function (): void {
    $apiKey = 'APIKEYSENTINEL123456';
    $sessionId = 'SIDSESSION1234567890';
    $requestXml = '<request>invalid-login-xml-security-sentinel</request>';
    $transport = new BirCredentialTrapTransportFixture(
        $apiKey,
        $sessionId,
        $requestXml,
        invalidLoginResponse: true,
    );
    $client = new BirClient(new NativeBirGateway($transport));

    try {
        $client->searchByNip('1234567890');

        throw new LogicException('Expected the invalid login response to cause an authentication exception.');
    } catch (BirAuthenticationException $exception) {
        expect($exception->getMessage())->toBe('Invalid API key')
            ->and($exception->getCode())->toBe(0)
            ->and($exception->getPrevious())->toBeNull();

        expectCredentialThrowableToExclude(
            $exception,
            [$apiKey, $sessionId, $requestXml],
        );
    }
});

it('does not expose credentials or request XML through authenticated transport errors', function (): void {
    $apiKey = 'APIKEYSENTINEL123456';
    $sessionId = 'SIDSESSION1234567890';
    $requestXml = '<request>search-xml-security-sentinel</request>';
    $transport = new BirCredentialTrapTransportFixture(
        $apiKey,
        $sessionId,
        $requestXml,
        failureOperation: BirOperation::Search,
    );
    $client = new BirClient(new NativeBirGateway($transport));

    try {
        $client->searchByNip('1234567890');

        throw new LogicException('Expected the authenticated transport failure to cause a BIR exception.');
    } catch (BirTransportException $exception) {
        expect($exception->getMessage())
            ->toBe('Unable to communicate with the GUS BIR service.')
            ->and($exception->getCode())->toBe(0)
            ->and($exception->getPrevious())->toBeNull();

        expectCredentialThrowableToExclude(
            $exception,
            [$apiKey, $sessionId, $requestXml],
        );
    }
});

it('does not retain client-bound closures or credentials after diagnostics errors', function (): void {
    $apiKey = 'APIKEYSENTINEL123456';
    $sessionId = 'SIDSESSION1234567890';
    $requestXml = '<request>diagnostics-xml-security-sentinel</request>';
    $transport = new BirCredentialTrapTransportFixture(
        $apiKey,
        $sessionId,
        $requestXml,
        failureOperation: BirOperation::GetValue,
        failureParameter: GetValueParameter::MessageCode,
    );
    $client = new BirClient(new NativeBirGateway($transport));

    try {
        $client->getDiagnostics();

        throw new LogicException('Expected the diagnostics transport failure to cause a BIR exception.');
    } catch (BirTransportException $exception) {
        expect($exception->getMessage())
            ->toBe('Unable to communicate with the GUS BIR service.')
            ->and($exception->getPrevious())->toBeNull()
            ->and(credentialThrowableGraphContainsClientBoundClosure($exception))->toBeFalse();

        expectCredentialThrowableToExclude(
            $exception,
            [$apiKey, $sessionId, $requestXml],
        );
    }
});

it('does not retain client-bound closures or credentials after plural full-report failures', function (): void {
    $apiKey = 'APIKEYSENTINEL123456';
    $sessionId = 'SIDSESSION1234567890';
    $requestXml = '<request>plural-full-report-xml-security-sentinel</request>';
    $searchResponse = file_get_contents(__DIR__.'/../Fixtures/Gus/inner/search-single.xml');

    if (! is_string($searchResponse)) {
        throw new RuntimeException('Unable to read the search result fixture.');
    }

    $transport = new BirCredentialTrapTransportFixture(
        $apiKey,
        $sessionId,
        $requestXml,
        failureOperation: BirOperation::FullReport,
        searchResponse: $searchResponse,
    );
    $client = new BirClient(new NativeBirGateway($transport));

    try {
        $client->getFullReportsByNip('0123456789', ReportType::Organization);

        throw new LogicException('Expected the plural full-report transport failure to cause a BIR exception.');
    } catch (BirTransportException $exception) {
        expect($exception->getMessage())
            ->toBe('Unable to communicate with the GUS BIR service.')
            ->and($exception->getPrevious())->toBeNull()
            ->and(credentialThrowableGraphContainsClientBoundClosure($exception))->toBeFalse();

        expectCredentialThrowableToExclude(
            $exception,
            [$apiKey, $sessionId, $requestXml],
        );
    }
});

it('redacts returned company data from normalization exception traces', function (): void {
    $returnedNip = '9876543210';
    $returnedName = 'FULL-REPORT-NAME-TRACE-SENTINEL';
    $returnedCity = 'FULL-REPORT-CITY-TRACE-SENTINEL';
    $returnedStreet = 'FULL-REPORT-STREET-TRACE-SENTINEL';
    $gateway = new FakeBirGateway(
        searchResults: [new SearchResult(
            regon: '012345678',
            nip: $returnedNip,
            name: $returnedName,
            city: $returnedCity,
            postalCode: '00-001',
            street: $returnedStreet,
            buildingNumber: '1',
            apartmentNumber: null,
            province: 'MAZOWIECKIE',
            district: 'testowy',
            commune: 'Testowa',
            type: EntityType::LegalUnit,
            regon14: null,
            nipStatus: null,
            silo: Silo::LegalUnits,
            activityEndDate: null,
            postCity: $returnedCity,
        )],
        fullReportData: [[
            'praw_nip' => 'not-a-valid-nip',
        ]],
    );
    $client = new BirClient($gateway);

    try {
        $client->getFullReportByNip('0123456789', ReportType::Organization);

        throw new LogicException('Expected invalid normalized report data to cause a protocol exception.');
    } catch (BirProtocolException $exception) {
        expect($exception->getMessage())
            ->toBe('GUS BIR returned an invalid praw_nip field for BIR12OsPrawna.')
            ->and($exception->getPrevious())->toBeNull();

        expectCredentialThrowableToExclude(
            $exception,
            [$returnedNip, $returnedName, $returnedCity, $returnedStreet],
        );
    }
});

it('does not retain the limiter API key in quota exceptions', function (): void {
    $apiKey = 'APIKEYSENTINEL123456';
    $now = new DateTimeImmutable('2026-08-23 08:00:00', new DateTimeZone('Europe/Warsaw'));
    $limiter = new CacheBirRequestLimiter(
        new Repository(new ArrayStore),
        Environment::Production,
        $apiKey,
        'bir-credential-trace',
        static fn (): DateTimeImmutable => $now,
    );
    $criteria = SearchCriteria::krsNumbers(array_map(
        static fn (int $value): string => str_pad((string) $value, 10, '0', STR_PAD_LEFT),
        range(1, SearchCriteria::MAX_BATCH_SIZE),
    ));

    $limiter->acquire(BirOperation::Search, ['criteria' => $criteria]);

    try {
        $limiter->acquire(BirOperation::Login);

        throw new LogicException('Expected weighted batch debt to cause a rate-limit exception.');
    } catch (BirRateLimitException $exception) {
        expect($exception->quotaWasExceeded())->toBeTrue();
        expectCredentialThrowableToExclude($exception, [$apiKey]);
    }
});

it('redacts native transport, envelope, session, and gateway debug output', function (): void {
    $apiKey = 'APIKEYSENTINEL123456';
    $sessionId = 'SIDSESSION1234567890';
    $nativeTransport = new NativeSoapTransport($apiKey, Environment::Sandbox);
    $nativeTransport->useSession($sessionId);
    $envelopeBuilder = new SoapEnvelopeBuilder($apiKey);
    $envelopeBuilder->useSession($sessionId);
    $session = new BirSessionState;
    $session->start($sessionId);
    $gateway = new NativeBirGateway($nativeTransport, session: $session);
    $requestXml = $envelopeBuilder->build(
        BirOperation::Login,
        [],
        Environment::Sandbox->endpoint(),
    );

    expect($requestXml)->toBeString()->toContain($apiKey);

    $debugOutput = print_r([
        $nativeTransport,
        $envelopeBuilder,
        $session,
        $gateway,
    ], true);

    expect($debugOutput)->toContain('[REDACTED]');
    expect(str_contains((string) $debugOutput, $apiKey))->toBeFalse()
        ->and(str_contains((string) $debugOutput, $sessionId))->toBeFalse()
        ->and(str_contains((string) $debugOutput, (string) $requestXml))->toBeFalse();

    $exported = var_export([$nativeTransport, $envelopeBuilder, $session, $gateway], true);
    $dumped = '';
    (new CliDumper)->dump(
        (new VarCloner)->cloneVar([$nativeTransport, $envelopeBuilder, $session, $gateway]),
        static function (string $line) use (&$dumped): void {
            $dumped .= $line;
        },
    );

    foreach ([$exported, $dumped] as $rendered) {
        expect(str_contains($rendered, $apiKey))->toBeFalse()
            ->and(str_contains($rendered, $sessionId))->toBeFalse()
            ->and(str_contains($rendered, (string) $requestXml))->toBeFalse();
    }
});

it('does not expose credentials held by custom collaborators through public object graphs', function (): void {
    $apiKey = 'UPPERGRAPHAPIKEY1234';
    $sessionId = 'UPPERGRAPHSESSION123';
    $requestXml = '<request>upper-graph-xml-security-sentinel</request>';
    $transport = new BirCredentialTrapTransportFixture($apiKey, $sessionId, $requestXml);
    $gateway = new NativeBirGateway($transport);
    $gatewayCredential = 'CUSTOM-GATEWAY-CREDENTIAL-SENTINEL';
    $client = new BirClient(new FakeBirGateway(values: [
        'credential' => $gatewayCredential,
    ]));
    $productionCredential = 'CUSTOM-PRODUCTION-CLIENT-CREDENTIAL-SENTINEL';
    $sandboxCredential = 'CUSTOM-SANDBOX-CLIENT-CREDENTIAL-SENTINEL';
    $service = new BirRegonService(
        new BirCredentialTrapClientFixture($productionCredential),
        new BirCredentialTrapClientFixture($sandboxCredential),
    );
    $objects = [
        $gateway,
        $client,
        $service,
        $service->sandbox(),
        $service->forNip('0123456789'),
        $service->forNips(['0123456789']),
        $service->forDate(new DateTimeImmutable('2026-08-20')),
        $service->service(),
        $service->diagnostics(),
    ];

    $exported = var_export($objects, true);
    $dumped = '';
    (new CliDumper)->dump(
        (new VarCloner)->cloneVar($objects),
        static function (string $line) use (&$dumped): void {
            $dumped .= $line;
        },
    );

    foreach ([$exported, $dumped] as $rendered) {
        foreach ([
            $apiKey,
            $sessionId,
            $requestXml,
            $gatewayCredential,
            $productionCredential,
            $sandboxCredential,
        ] as $credential) {
            expect($rendered)->not->toContain($credential);
        }
    }
});

/** @param list<string> $sensitiveValues */
function expectCredentialThrowableToExclude(Throwable $exception, array $sensitiveValues): void
{
    foreach ($sensitiveValues as $sensitiveValue) {
        expect(renderCredentialThrowableChain($exception))->not->toContain($sensitiveValue)
            ->and(credentialThrowableGraphContainsSensitiveValue($exception, $sensitiveValue))
            ->toBeFalse();
    }

    expect(credentialThrowableGraphContainsClientBoundClosure($exception))->toBeFalse();
}

function renderCredentialThrowableChain(Throwable $exception): string
{
    $rendered = '';

    do {
        $rendered .= (string) $exception;
        $exception = $exception->getPrevious();
    } while ($exception instanceof Throwable);

    return $rendered;
}

function credentialThrowableGraphContainsSensitiveValue(
    Throwable $exception,
    string $sensitiveValue,
): bool {
    return credentialValueContainsSensitiveData(
        $exception,
        $sensitiveValue,
        new SplObjectStorage,
    );
}

/** @param SplObjectStorage<object, null> $visited */
function credentialValueContainsSensitiveData(
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
                credentialValueContainsSensitiveData($key, $sensitiveValue, $visited)
                || credentialValueContainsSensitiveData($item, $sensitiveValue, $visited)
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
        return false;
    }

    if ($value instanceof Throwable) {
        return str_contains($value->getMessage(), $sensitiveValue)
            || credentialValueContainsSensitiveData($value->getTrace(), $sensitiveValue, $visited)
            || ($value->getPrevious() instanceof Throwable
                && credentialValueContainsSensitiveData(
                    $value->getPrevious(),
                    $sensitiveValue,
                    $visited,
                ));
    }

    if ($value instanceof Closure) {
        $reflection = new ReflectionFunction($value);

        return credentialValueContainsSensitiveData(
            $reflection->getClosureThis(),
            $sensitiveValue,
            $visited,
        ) || credentialValueContainsSensitiveData(
            $reflection->getStaticVariables(),
            $sensitiveValue,
            $visited,
        );
    }

    return credentialValueContainsSensitiveData((array) $value, $sensitiveValue, $visited);
}

function credentialThrowableGraphContainsClientBoundClosure(Throwable $exception): bool
{
    return credentialValueContainsClientBoundClosure(
        $exception,
        new SplObjectStorage,
    );
}

/** @param SplObjectStorage<object, null> $visited */
function credentialValueContainsClientBoundClosure(
    mixed $value,
    SplObjectStorage $visited,
): bool {
    if (is_array($value)) {
        foreach ($value as $item) {
            if (credentialValueContainsClientBoundClosure($item, $visited)) {
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
        return credentialValueContainsClientBoundClosure($value->getValue(), $visited);
    }

    if ($value instanceof Closure) {
        $reflection = new ReflectionFunction($value);

        return $reflection->getClosureThis() instanceof BirClient
            || credentialValueContainsClientBoundClosure(
                $reflection->getStaticVariables(),
                $visited,
            );
    }

    if ($value instanceof Throwable) {
        return credentialValueContainsClientBoundClosure($value->getTrace(), $visited)
            || ($value->getPrevious() instanceof Throwable
                && credentialValueContainsClientBoundClosure($value->getPrevious(), $visited));
    }

    return credentialValueContainsClientBoundClosure((array) $value, $visited);
}
