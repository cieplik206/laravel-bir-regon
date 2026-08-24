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
            requestLimiter: self::makeRequestLimiter($app, $environment, $apiKey),
            environment: $environment,
            connectionTimeout: self::connectionTimeout(),
            requestTimeout: self::requestTimeout(),
            maxResponseBytes: self::maxResponseBytes(),
            userAgent: (string) config('bir-regon.user_agent', 'laravel-bir-regon/2'),
            proxyUrl: self::nullableProxyConfig('url', 'URL'),
            proxyUsername: self::nullableProxyConfig('username', 'username'),
            proxyPassword: self::nullableProxyConfig('password', 'password'),
        );
    }

    private static function nullableProxyConfig(string $key, string $label): ?string
    {
        $proxy = config('bir-regon.proxy', []);

        if (! is_array($proxy)) {
            throw new LogicException('BIR proxy configuration must be an array.');
        }

        $value = $proxy[$key] ?? null;

        if ($value === null || is_string($value)) {
            return $value;
        }

        throw new LogicException('BIR proxy '.$label.' must be a string or null.');
    }

    private static function makeRequestLimiter(
        #[\SensitiveParameter] Application $app,
        Environment $environment,
        #[\SensitiveParameter] string $apiKey,
    ): BirRequestLimiterInterface {
        if (! self::rateLimitEnabled()) {
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

    private static function rateLimitEnabled(): bool
    {
        $value = config('bir-regon.rate_limit.enabled', true);

        if (! is_bool($value)) {
            throw new LogicException('BIR rate limiting enabled setting must be a boolean.');
        }

        return $value;
    }

    private static function maxResponseBytes(): int
    {
        return self::boundedIntegerConfig(
            key: 'max_response_bytes',
            default: 10_000_000,
            minimum: NativeSoapTransport::MIN_RESPONSE_BYTES,
            maximum: NativeSoapTransport::MAX_RESPONSE_BYTES,
            label: 'maximum response size',
            unit: 'bytes',
        );
    }

    private static function connectionTimeout(): int
    {
        return self::boundedIntegerConfig(
            key: 'connection_timeout',
            default: 10,
            minimum: NativeSoapTransport::MIN_CONNECTION_TIMEOUT_SECONDS,
            maximum: NativeSoapTransport::MAX_CONNECTION_TIMEOUT_SECONDS,
            label: 'connection timeout',
            unit: 'seconds',
        );
    }

    private static function requestTimeout(): int
    {
        return self::boundedIntegerConfig(
            key: 'request_timeout',
            default: 30,
            minimum: NativeSoapTransport::MIN_REQUEST_TIMEOUT_SECONDS,
            maximum: NativeSoapTransport::MAX_REQUEST_TIMEOUT_SECONDS,
            label: 'request timeout',
            unit: 'seconds',
        );
    }

    private static function boundedIntegerConfig(
        string $key,
        int $default,
        int $minimum,
        int $maximum,
        string $label,
        string $unit,
    ): int {
        $value = config('bir-regon.'.$key, $default);
        $validated = is_int($value) ? $value : false;

        if (is_string($value) && preg_match('/^[1-9]\d*$/D', $value) === 1) {
            $validated = filter_var($value, FILTER_VALIDATE_INT, [
                'options' => [
                    'min_range' => $minimum,
                    'max_range' => $maximum,
                ],
            ]);
        }

        if (
            ! is_int($validated)
            || $validated < $minimum
            || $validated > $maximum
        ) {
            throw new LogicException(sprintf(
                'BIR %s must be an integer between %d and %d %s.',
                $label,
                $minimum,
                $maximum,
                $unit,
            ));
        }

        return $validated;
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
