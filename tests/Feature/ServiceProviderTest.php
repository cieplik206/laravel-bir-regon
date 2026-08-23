<?php

declare(strict_types=1);

use cieplik206\BirRegon\BirClient;
use cieplik206\BirRegon\BirClientInterface;
use cieplik206\BirRegon\BirRegonService;
use cieplik206\BirRegon\BirRegonServiceProvider;
use cieplik206\BirRegon\Contracts\BirGatewayInterface;
use cieplik206\BirRegon\Contracts\BirSoapTransportInterface;
use cieplik206\BirRegon\Enums\Environment;
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
        ->and(providerTestProperty($service, 'client'))->toBe($client);

    $container->forgetScopedInstances();

    expect($container->make(BirSoapTransportInterface::class))->not->toBe($transport)
        ->and($container->make(BirGatewayInterface::class))->not->toBe($gateway)
        ->and($container->make(BirClientInterface::class))->not->toBe($client)
        ->and($container->make(BirRegonService::class))->not->toBe($service);
});

it('allows the Laravel cache-backed limiter to be disabled explicitly', function (): void {
    config()->set('bir-regon.rate_limit.enabled', false);

    $transport = app(BirSoapTransportInterface::class);

    expect($transport)->toBeInstanceOf(NativeSoapTransport::class)
        ->and(providerTestProperty($transport, 'requestLimiter'))
        ->toBeInstanceOf(UnlimitedBirRequestLimiter::class);
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
