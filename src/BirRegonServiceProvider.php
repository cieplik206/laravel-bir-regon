<?php

declare(strict_types=1);

namespace cieplik206\BirRegon;

use cieplik206\BirRegon\Contracts\BirGatewayInterface;
use cieplik206\BirRegon\Contracts\BirRequestLimiterInterface;
use cieplik206\BirRegon\Contracts\BirSoapTransportInterface;
use cieplik206\BirRegon\Enums\Environment;
use cieplik206\BirRegon\Enums\IdentifierValidationMode;
use cieplik206\BirRegon\Gateway\NativeBirGateway;
use cieplik206\BirRegon\Protocol\XmlRecordsDecoder;
use cieplik206\BirRegon\RateLimit\CacheBirRequestLimiter;
use cieplik206\BirRegon\RateLimit\UnlimitedBirRequestLimiter;
use cieplik206\BirRegon\Transport\NativeSoapTransport;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use LogicException;

class BirRegonServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/bir-regon.php',
            'bir-regon',
        );

        $this->app->scoped(
            BirSoapTransportInterface::class,
            static fn (
                #[\SensitiveParameter] Application $app,
                #[\SensitiveParameter] array $parameters = [],
            ): NativeSoapTransport => self::makeTransport(
                $app,
                Environment::Production,
            ),
        );
        $this->app->scoped(
            BirGatewayInterface::class,
            static fn (
                #[\SensitiveParameter] Application $app,
                #[\SensitiveParameter] array $parameters = [],
            ): NativeBirGateway => new NativeBirGateway(
                $app->make(BirSoapTransportInterface::class),
                new XmlRecordsDecoder(self::maxResponseBytes()),
            ),
        );
        $this->app->scoped(
            BirClientInterface::class,
            static fn (
                #[\SensitiveParameter] Application $app,
                #[\SensitiveParameter] array $parameters = [],
            ): BirClient => new BirClient(
                $app->make(BirGatewayInterface::class),
                self::identifierValidationMode(),
            ),
        );
        $this->app->scoped(
            BirRegonService::class,
            static function (
                #[\SensitiveParameter] Application $app,
                #[\SensitiveParameter] array $parameters = [],
            ): BirRegonService {
                $sandboxClient = new BirClient(
                    new NativeBirGateway(
                        self::makeTransport($app, Environment::Sandbox),
                        new XmlRecordsDecoder(self::maxResponseBytes()),
                    ),
                    self::identifierValidationMode(),
                );

                return new BirRegonService(
                    $app->make(BirClientInterface::class),
                    $sandboxClient,
                );
            },
        );
    }

    private static function makeTransport(
        #[\SensitiveParameter] Application $app,
        Environment $environment,
    ): NativeSoapTransport {
        $keyConfig = $environment === Environment::Production ? 'api_key' : 'sandbox_api_key';
        $apiKey = (string) config('bir-regon.'.$keyConfig, '');

        return new NativeSoapTransport(
            apiKey: $apiKey,
            environment: $environment,
            connectionTimeout: (int) config('bir-regon.connection_timeout', 10),
            requestTimeout: (int) config('bir-regon.request_timeout', 30),
            maxResponseBytes: self::maxResponseBytes(),
            userAgent: (string) config('bir-regon.user_agent', 'laravel-bir-regon/2'),
            requestLimiter: self::makeRequestLimiter($app, $environment, $apiKey),
        );
    }

    private static function makeRequestLimiter(
        #[\SensitiveParameter] Application $app,
        Environment $environment,
        #[\SensitiveParameter] string $apiKey,
    ): BirRequestLimiterInterface {
        if (! (bool) config('bir-regon.rate_limit.enabled', true)) {
            return new UnlimitedBirRequestLimiter;
        }

        $store = config('bir-regon.rate_limit.store');
        $store = is_string($store) && $store !== '' ? $store : null;
        $prefix = config('bir-regon.rate_limit.prefix', 'bir-regon:rate-limit');

        return new CacheBirRequestLimiter(
            cache: $app->make(CacheFactory::class)->store($store),
            environment: $environment,
            apiKey: $apiKey,
            prefix: is_string($prefix) ? $prefix : 'bir-regon:rate-limit',
        );
    }

    private static function maxResponseBytes(): int
    {
        return max(1, (int) config('bir-regon.max_response_bytes', 10_000_000));
    }

    private static function identifierValidationMode(): IdentifierValidationMode
    {
        $value = config('bir-regon.identifier_validation', IdentifierValidationMode::FormatOnly->value);

        if (! is_string($value)) {
            throw new LogicException('BIR identifier validation mode must be a string.');
        }

        return IdentifierValidationMode::tryFrom($value)
            ?? throw new LogicException(
                'BIR identifier validation mode must be "format" or "checksum".',
            );
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/bir-regon.php' => config_path('bir-regon.php'),
        ], 'bir-regon-config');
    }
}
