<?php

declare(strict_types=1);

use cieplik206\BirRegon\BirClient;
use cieplik206\BirRegon\BirClientInterface;
use cieplik206\BirRegon\BirRegonService;
use cieplik206\BirRegon\BirRegonServiceProvider;
use cieplik206\BirRegon\Contracts\BirGatewayInterface;
use cieplik206\BirRegon\Contracts\BirSoapTransportInterface;
use cieplik206\BirRegon\Enums\Environment;
use cieplik206\BirRegon\Enums\IdentifierValidationMode;
use cieplik206\BirRegon\Exceptions\BirAuthenticationException;
use cieplik206\BirRegon\Exceptions\BirValidationException;
use cieplik206\BirRegon\Facades\BirRegon as BirRegonFacade;
use cieplik206\BirRegon\Gateway\NativeBirGateway;
use cieplik206\BirRegon\Protocol\SoapEnvelopeBuilder;
use cieplik206\BirRegon\Protocol\SoapResponseDecoder;
use cieplik206\BirRegon\RateLimit\CacheBirRequestLimiter;
use cieplik206\BirRegon\RateLimit\UnlimitedBirRequestLimiter;
use cieplik206\BirRegon\Transport\NativeSoapTransport;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

it('merges the complete default package configuration', function (): void {
    expect(config('bir-regon'))
        ->toMatchArray([
            'api_key' => '',
            'sandbox_api_key' => 'abcde12345abcde12345',
            'connection_timeout' => 10,
            'request_timeout' => 30,
            'max_response_bytes' => 10_000_000,
            'user_agent' => 'laravel-bir-regon/2',
            'identifier_validation' => 'format',
            'proxy' => [
                'url' => null,
                'username' => null,
                'password' => null,
            ],
            'rate_limit' => [
                'enabled' => true,
                'store' => null,
                'prefix' => 'bir-regon:rate-limit',
            ],
        ]);
});

it('registers the native dependency graph as scoped services', function (): void {
    $container = app();
    $transport = $container->make(BirSoapTransportInterface::class);
    $gateway = $container->make(BirGatewayInterface::class);
    $client = $container->make(BirClientInterface::class);
    $service = $container->make(BirRegonService::class);

    expect($transport)
        ->toBeInstanceOf(NativeSoapTransport::class)
        ->toBe($container->make(BirSoapTransportInterface::class))
        ->and($gateway)
        ->toBeInstanceOf(NativeBirGateway::class)
        ->toBe($container->make(BirGatewayInterface::class))
        ->and($client)
        ->toBeInstanceOf(BirClient::class)
        ->toBe($container->make(BirClientInterface::class))
        ->and($service)
        ->toBe($container->make(BirRegonService::class))
        ->and(providerTestProperty($gateway, 'transport'))->toBe($transport)
        ->and(providerTestProperty($transport, 'requestLimiter'))
        ->toBeInstanceOf(CacheBirRequestLimiter::class)
        ->and(providerTestProperty($client, 'gateway'))->toBe($gateway)
        ->and(providerTestProperty($client, 'identifierValidationMode'))
        ->toBe(IdentifierValidationMode::FormatOnly)
        ->and(providerTestProperty($service, 'client'))->toBe($client);

    $container->forgetScopedInstances();

    expect($container->make(BirSoapTransportInterface::class))->not->toBe($transport)
        ->and($container->make(BirGatewayInterface::class))->not->toBe($gateway)
        ->and($container->make(BirClientInterface::class))->not->toBe($client)
        ->and($container->make(BirRegonService::class))->not->toBe($service);
});

it('resolves production and sandbox clients without authenticating eagerly', function (): void {
    config()->set('bir-regon.api_key', '');
    config()->set('bir-regon.sandbox_api_key', '');

    $service = app(BirRegonService::class);
    $productionClient = providerTestProperty($service, 'client');
    $sandboxClient = providerTestProperty($service, 'sandboxClient');

    expect($productionClient)->toBeInstanceOf(BirClient::class)
        ->and($sandboxClient)->toBeInstanceOf(BirClient::class);
});

it('names the selected environment variable when an API key is missing', function (
    bool $sandbox,
    string $environmentVariable,
): void {
    config()->set('bir-regon.api_key', '');
    config()->set('bir-regon.sandbox_api_key', '');

    $service = app(BirRegonService::class);
    $selected = $sandbox ? $service->sandbox() : $service;

    expect(fn () => $selected->forNip('0123456789')->get())
        ->toThrow(
            BirAuthenticationException::class,
            'BIR API key is not configured. Set '.$environmentVariable.' in your .env file.',
        );
})->with([
    'production' => [false, 'BIR_API_KEY'],
    'sandbox' => [true, 'BIR_SANDBOX_API_KEY'],
]);

it('allows the Laravel cache-backed limiter to be disabled explicitly', function (): void {
    config()->set('bir-regon.rate_limit.enabled', false);

    $transport = app(BirSoapTransportInterface::class);

    expect($transport)->toBeInstanceOf(NativeSoapTransport::class)
        ->and(providerTestProperty($transport, 'requestLimiter'))
        ->toBeInstanceOf(UnlimitedBirRequestLimiter::class);
});

it('passes one explicit HTTP proxy configuration to both native environments', function (): void {
    $proxyUrl = 'https://proxy.example.test:8443';
    $proxyUsername = 'proxy-user';
    $proxyPassword = 'PROXY-PASSWORD-SENTINEL';
    config()->set('bir-regon.proxy', [
        'url' => $proxyUrl,
        'username' => $proxyUsername,
        'password' => $proxyPassword,
    ]);

    $productionTransport = app(BirSoapTransportInterface::class);
    $service = app(BirRegonService::class);
    $sandboxClient = providerTestProperty($service, 'sandboxClient');

    if (! $productionTransport instanceof NativeSoapTransport || ! $sandboxClient instanceof BirClient) {
        throw new LogicException('The provider did not construct both native environments.');
    }

    $sandboxGateway = providerTestProperty($sandboxClient, 'gateway');

    if (! $sandboxGateway instanceof NativeBirGateway) {
        throw new LogicException('The provider did not construct the sandbox gateway.');
    }

    $sandboxTransport = providerTestProperty($sandboxGateway, 'transport');

    if (! $sandboxTransport instanceof NativeSoapTransport) {
        throw new LogicException('The provider did not construct the sandbox transport.');
    }

    foreach ([$productionTransport, $sandboxTransport] as $transport) {
        $sender = providerTestProperty($transport, 'httpSender');

        if (! is_object($sender)) {
            throw new LogicException('The native transport did not contain an HTTP sender.');
        }

        $proxy = providerTestProperty($sender, 'proxy');

        if (! is_object($proxy)) {
            throw new LogicException('The native HTTP sender did not contain proxy configuration.');
        }

        expect(providerTestProperty($proxy, 'url'))->toBe($proxyUrl)
            ->and(providerTestProperty($proxy, 'username'))->toBe($proxyUsername)
            ->and(providerTestProperty($proxy, 'password'))->toBe($proxyPassword);
    }
});

it('fails closed for invalid explicit HTTP proxy configuration', function (
    mixed $url,
    mixed $username,
    mixed $password,
    string $message,
): void {
    config()->set('bir-regon.proxy', [
        'url' => $url,
        'username' => $username,
        'password' => $password,
    ]);

    expect(fn () => app(BirSoapTransportInterface::class))
        ->toThrow(LogicException::class, $message);
})->with([
    'credentials without URL' => [
        null,
        'proxy-user',
        'proxy-password',
        'BIR proxy credentials require a proxy URL.',
    ],
    'unsupported scheme' => [
        'ftp://proxy.example.test:21',
        null,
        null,
        'BIR proxy URL must use the http or https scheme.',
    ],
    'credentials embedded in URL' => [
        'https://user:password@proxy.example.test:8443',
        null,
        null,
        'BIR proxy credentials must use the separate username and password settings.',
    ],
    'incomplete credentials' => [
        'https://proxy.example.test:8443',
        'proxy-user',
        null,
        'BIR proxy username and password must be configured together.',
    ],
    'non-string URL' => [
        ['https://proxy.example.test:8443'],
        null,
        null,
        'BIR proxy URL must be a string or null.',
    ],
    'non-string username' => [
        'https://proxy.example.test:8443',
        ['proxy-user'],
        null,
        'BIR proxy username must be a string or null.',
    ],
    'non-string password' => [
        'https://proxy.example.test:8443',
        null,
        false,
        'BIR proxy password must be a string or null.',
    ],
]);

it('rejects a non-array HTTP proxy configuration instead of silently bypassing it', function (): void {
    config()->set('bir-regon.proxy', false);

    expect(fn () => app(BirSoapTransportInterface::class))
        ->toThrow(LogicException::class, 'BIR proxy configuration must be an array.');
});

it('builds isolated production and sandbox native transports from configuration', function (): void {
    $productionKey = str_repeat('p', 20);
    $sandboxKey = str_repeat('s', 20);

    config()->set('bir-regon.api_key', $productionKey);
    config()->set('bir-regon.sandbox_api_key', $sandboxKey);
    config()->set('bir-regon.connection_timeout', 4);
    config()->set('bir-regon.request_timeout', 19);
    config()->set('bir-regon.max_response_bytes', 123_456);
    config()->set('bir-regon.user_agent', 'bir-regon-provider-test/1');
    config()->set('bir-regon.identifier_validation', 'checksum');

    $productionTransport = app(BirSoapTransportInterface::class);
    $service = app(BirRegonService::class);
    $productionClient = providerTestProperty($service, 'client');
    $sandboxClient = providerTestProperty($service, 'sandboxClient');

    if (! $productionClient instanceof BirClient || ! $sandboxClient instanceof BirClient) {
        throw new LogicException('The provider did not construct both native clients.');
    }

    $productionGateway = providerTestProperty($productionClient, 'gateway');
    $sandboxGateway = providerTestProperty($sandboxClient, 'gateway');

    if (! $productionGateway instanceof NativeBirGateway || ! $sandboxGateway instanceof NativeBirGateway) {
        throw new LogicException('The provider did not construct both native gateways.');
    }

    $sandboxTransport = providerTestProperty($sandboxGateway, 'transport');

    if (
        ! $productionTransport instanceof NativeSoapTransport
        || ! $sandboxTransport instanceof NativeSoapTransport
    ) {
        throw new LogicException('The provider did not construct both native transports.');
    }

    expect($productionClient)->toBe(app(BirClientInterface::class))
        ->and($sandboxClient)->not->toBe($productionClient)
        ->and($sandboxGateway)->not->toBe($productionGateway)
        ->and($sandboxTransport)->not->toBe($productionTransport)
        ->and(providerTestProperty($productionClient, 'identifierValidationMode'))
        ->toBe(IdentifierValidationMode::FormatAndChecksum)
        ->and(providerTestProperty($sandboxClient, 'identifierValidationMode'))
        ->toBe(IdentifierValidationMode::FormatAndChecksum)
        ->and(providerTestTransportConfiguration($productionTransport))->toBe([
            'apiKey' => $productionKey,
            'environment' => Environment::Production,
            'endpoint' => Environment::Production->endpoint(),
            'connectionTimeout' => 4,
            'requestTimeout' => 19,
            'maxResponseBytes' => 123_456,
            'userAgent' => 'bir-regon-provider-test/1',
            'sessionId' => null,
        ])
        ->and(providerTestTransportConfiguration($sandboxTransport))->toBe([
            'apiKey' => $sandboxKey,
            'environment' => Environment::Sandbox,
            'endpoint' => Environment::Sandbox->endpoint(),
            'connectionTimeout' => 4,
            'requestTimeout' => 19,
            'maxResponseBytes' => 123_456,
            'userAgent' => 'bir-regon-provider-test/1',
            'sessionId' => null,
        ])
        ->and($productionTransport->isAuthenticationConfigured())->toBeTrue()
        ->and($sandboxTransport->isAuthenticationConfigured())->toBeTrue()
        ->and(providerTestProperty($service->sandbox(), 'client'))->toBe($sandboxClient);
});

it('fails closed for an invalid identifier validation configuration', function (
    mixed $value,
    string $message,
): void {
    config()->set('bir-regon.identifier_validation', $value);

    expect(fn () => app(BirClientInterface::class))
        ->toThrow(LogicException::class, $message);
})->with([
    'unknown string' => [
        'strict',
        'BIR identifier validation mode must be "format" or "checksum".',
    ],
    'boolean' => [true, 'BIR identifier validation mode must be a string.'],
    'array' => [['checksum'], 'BIR identifier validation mode must be a string.'],
]);

it('applies checksum validation through the facade in both environments', function (): void {
    config()->set('bir-regon.identifier_validation', 'checksum');

    expect(fn () => BirRegonFacade::forNip('7740001455')->get())
        ->toThrow(BirValidationException::class, 'NIP checksum is invalid.')
        ->and(fn () => BirRegonFacade::sandbox()->forRegon('610188202')->get())
        ->toThrow(BirValidationException::class, 'REGON checksum is invalid.');
});

it('applies the configured response limit to both SOAP and inner XML decoders', function (): void {
    $configuredLimit = 123_456;

    config()->set('bir-regon.max_response_bytes', $configuredLimit);

    $service = app(BirRegonService::class);
    $productionClient = providerTestProperty($service, 'client');
    $sandboxClient = providerTestProperty($service, 'sandboxClient');

    if (! $productionClient instanceof BirClient || ! $sandboxClient instanceof BirClient) {
        throw new LogicException('The provider did not construct both native clients.');
    }

    foreach ([$productionClient, $sandboxClient] as $client) {
        $gateway = providerTestProperty($client, 'gateway');

        if (! $gateway instanceof NativeBirGateway) {
            throw new LogicException('The provider did not construct a native gateway.');
        }

        $transport = providerTestProperty($gateway, 'transport');

        if (! $transport instanceof NativeSoapTransport) {
            throw new LogicException('The provider did not construct a native transport.');
        }

        $soapDecoder = providerTestProperty($transport, 'responseDecoder');
        $recordsDecoder = providerTestProperty($gateway, 'recordsDecoder');

        expect(providerTestProperty($soapDecoder, 'maxResponseBytes'))->toBe($configuredLimit)
            ->and(providerTestProperty($recordsDecoder, 'maxResponseBytes'))->toBe($configuredLimit);
    }
});

it('accepts bounded integer transport configuration from environment-style strings', function (): void {
    config()->set('bir-regon.connection_timeout', '1');
    config()->set('bir-regon.request_timeout', '300');
    config()->set('bir-regon.max_response_bytes', '50000000');

    $transport = app(BirSoapTransportInterface::class);

    if (! $transport instanceof NativeSoapTransport) {
        throw new LogicException('The provider did not construct the native transport.');
    }

    $decoder = providerTestProperty($transport, 'responseDecoder');

    expect(providerTestProperty($transport, 'connectionTimeout'))->toBe(1)
        ->and(providerTestProperty($transport, 'requestTimeout'))->toBe(300)
        ->and(providerTestProperty($decoder, 'maxResponseBytes'))->toBe(50_000_000);
});

it('fails closed for unsafe transport configuration values', function (
    string $key,
    mixed $value,
    string $message,
): void {
    config()->set('bir-regon.'.$key, $value);

    expect(fn () => app(BirSoapTransportInterface::class))
        ->toThrow(LogicException::class, $message);
})->with([
    'zero connection timeout' => [
        'connection_timeout',
        0,
        'BIR connection timeout must be an integer between 1 and 60 seconds.',
    ],
    'excessive connection timeout' => [
        'connection_timeout',
        61,
        'BIR connection timeout must be an integer between 1 and 60 seconds.',
    ],
    'excessive request timeout' => [
        'request_timeout',
        '301',
        'BIR request timeout must be an integer between 1 and 300 seconds.',
    ],
    'excessive response size' => [
        'max_response_bytes',
        50_000_001,
        'BIR maximum response size must be an integer between 1 and 50000000 bytes.',
    ],
    'non-decimal timeout' => [
        'request_timeout',
        '30.0',
        'BIR request timeout must be an integer between 1 and 300 seconds.',
    ],
    'whitespace-padded response size' => [
        'max_response_bytes',
        ' 10000000 ',
        'BIR maximum response size must be an integer between 1 and 50000000 bytes.',
    ],
    'boolean timeout' => [
        'connection_timeout',
        true,
        'BIR connection timeout must be an integer between 1 and 60 seconds.',
    ],
    'array response size' => [
        'max_response_bytes',
        [10_000_000],
        'BIR maximum response size must be an integer between 1 and 50000000 bytes.',
    ],
]);

it('redacts the application container when dependency resolution fails', function (): void {
    $originalExceptionIgnoreArgs = ini_get('zend.exception_ignore_args');

    if (ini_set('zend.exception_ignore_args', '0') === false) {
        throw new RuntimeException('Unable to enable exception arguments for the provider trace test.');
    }

    $productionKey = 'PROVIDER-PRODUCTION-KEY-SENTINEL';
    $sandboxKey = 'PROVIDER-SANDBOX-KEY-SENTINEL';
    $overrideSecret = 'PROVIDER-OVERRIDE-TRACE-SENTINEL';

    try {
        config()->set('bir-regon.api_key', $productionKey);
        config()->set('bir-regon.sandbox_api_key', $sandboxKey);

        $container = app();
        $container->forgetScopedInstances();
        $container->instance(CacheFactory::class, new ProviderFailingCacheFactory);

        try {
            $container->make(BirSoapTransportInterface::class, [
                'token' => $overrideSecret,
            ]);
            throw new LogicException('The failing cache factory was ignored.');
        } catch (Throwable $exception) {
            $rendered = (new CliDumper)->dump((new VarCloner)->cloneVar($exception), true);
            $providerFrames = array_values(array_filter(
                $exception->getTrace(),
                static fn (array $frame): bool => ($frame['class'] ?? null) === BirRegonServiceProvider::class
                    && str_starts_with($frame['function'], '{closure:'),
            ));
            $providerFramesDump = (new CliDumper)->dump(
                (new VarCloner)->cloneVar($providerFrames),
                true,
            );

            expect($exception)->toBeInstanceOf(RuntimeException::class)
                ->and($rendered)->toBeString()
                ->not->toContain($productionKey)
                ->not->toContain($sandboxKey)
                ->and($providerFrames)->not->toBeEmpty()
                ->and($providerFramesDump)->toBeString()
                ->not->toContain($overrideSecret);
        }
    } finally {
        if (is_string($originalExceptionIgnoreArgs)) {
            ini_set('zend.exception_ignore_args', $originalExceptionIgnoreArgs);
        }
    }
});

final class ProviderFailingCacheFactory implements CacheFactory
{
    public function store($name = null): never
    {
        throw new RuntimeException('The configured cache store is unavailable.');
    }
}

function providerTestProperty(object $object, string $property): mixed
{
    $value = (new ReflectionProperty($object, $property))->getValue($object);

    return $value instanceof SensitiveParameterValue ? $value->getValue() : $value;
}

/**
 * @return array{
 *     apiKey: string,
 *     environment: Environment,
 *     endpoint: string,
 *     connectionTimeout: int,
 *     requestTimeout: int,
 *     maxResponseBytes: int,
 *     userAgent: string,
 *     sessionId: ?string
 * }
 */
function providerTestTransportConfiguration(NativeSoapTransport $transport): array
{
    $environment = providerTestProperty($transport, 'environment');
    $envelopeBuilder = providerTestProperty($transport, 'envelopeBuilder');
    $responseDecoder = providerTestProperty($transport, 'responseDecoder');
    $connectionTimeout = providerTestProperty($transport, 'connectionTimeout');
    $requestTimeout = providerTestProperty($transport, 'requestTimeout');
    $userAgent = providerTestProperty($transport, 'userAgent');
    $sessionId = providerTestProperty($transport, 'sessionId');

    if ($sessionId instanceof SensitiveParameterValue) {
        $sessionId = $sessionId->getValue();
    }

    if (
        ! $environment instanceof Environment
        || ! $envelopeBuilder instanceof SoapEnvelopeBuilder
        || ! $responseDecoder instanceof SoapResponseDecoder
        || ! is_int($connectionTimeout)
        || ! is_int($requestTimeout)
        || ! is_string($userAgent)
        || (! is_string($sessionId) && $sessionId !== null)
    ) {
        throw new LogicException('The native transport has an unexpected internal structure.');
    }

    $apiKey = providerTestProperty($envelopeBuilder, 'apiKey');
    $maxResponseBytes = providerTestProperty($responseDecoder, 'maxResponseBytes');

    if ($apiKey instanceof SensitiveParameterValue) {
        $apiKey = $apiKey->getValue();
    }

    if (! is_string($apiKey) || ! is_int($maxResponseBytes)) {
        throw new LogicException('The native transport configuration has unexpected types.');
    }

    return [
        'apiKey' => $apiKey,
        'environment' => $environment,
        'endpoint' => $environment->endpoint(),
        'connectionTimeout' => $connectionTimeout,
        'requestTimeout' => $requestTimeout,
        'maxResponseBytes' => $maxResponseBytes,
        'userAgent' => $userAgent,
        'sessionId' => $sessionId,
    ];
}
