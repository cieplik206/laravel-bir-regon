<?php

declare(strict_types=1);

use cieplik206\BirRegon\Enums\Environment;
use cieplik206\BirRegon\Protocol\BirOperation;
use cieplik206\BirRegon\Protocol\RawTransportResult;
use cieplik206\BirRegon\RateLimit\UnlimitedBirRequestLimiter;
use cieplik206\BirRegon\Transport\BirHttpSenderInterface;
use cieplik206\BirRegon\Transport\CurlOptionSetterInterface;
use cieplik206\BirRegon\Transport\CurlProxyConfiguration;
use cieplik206\BirRegon\Transport\NativeSoapTransport;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

it('normalizes an entirely empty explicit proxy configuration to disabled', function (): void {
    expect(CurlProxyConfiguration::from(null, null, null))->toBeNull()
        ->and(CurlProxyConfiguration::from('', '', ''))->toBeNull();
});

it('validates explicit proxy URLs and credentials without echoing their values', function (
    ?string $url,
    ?string $username,
    ?string $password,
    string $message,
): void {
    expect(fn () => CurlProxyConfiguration::from($url, $username, $password))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'credentials without URL' => [
        null,
        'proxy-user',
        'proxy-password',
        'BIR proxy credentials require a proxy URL.',
    ],
    'unsupported scheme' => [
        'socks5://proxy.example.test:1080',
        null,
        null,
        'BIR proxy URL must use the http or https scheme.',
    ],
    'URL userinfo' => [
        'https://proxy-user:proxy-password@proxy.example.test:8443',
        null,
        null,
        'BIR proxy credentials must use the separate username and password settings.',
    ],
    'URL path' => [
        'https://proxy.example.test:8443/path',
        null,
        null,
        'BIR proxy URL must contain only a scheme, host, and optional port.',
    ],
    'URL query' => [
        'https://proxy.example.test:8443/?route=gus',
        null,
        null,
        'BIR proxy URL must contain only a scheme, host, and optional port.',
    ],
    'zero port' => [
        'https://proxy.example.test:0',
        null,
        null,
        'BIR proxy URL must contain a valid port.',
    ],
    'URL whitespace' => [
        ' https://proxy.example.test:8443',
        null,
        null,
        'BIR proxy URL is invalid.',
    ],
    'only username' => [
        'https://proxy.example.test:8443',
        'proxy-user',
        null,
        'BIR proxy username and password must be configured together.',
    ],
    'credentials over cleartext HTTP' => [
        'http://proxy.example.test:8080',
        'proxy-user',
        'proxy-password',
        'BIR proxy credentials require an HTTPS proxy URL.',
    ],
    'credential whitespace' => [
        'https://proxy.example.test:8443',
        ' proxy-user',
        'proxy-password',
        'BIR proxy credentials contain invalid whitespace or control characters.',
    ],
]);

it('applies a mandatory CONNECT tunnel, target-independent proxy routing, TLS verification, and separate credentials', function (): void {
    $proxy = CurlProxyConfiguration::from(
        'https://proxy.example.test:8443',
        'proxy-user',
        'proxy-password',
    );

    if (! $proxy instanceof CurlProxyConfiguration) {
        throw new LogicException('The valid proxy configuration was disabled.');
    }

    $handle = curl_init();

    if (! $handle instanceof CurlHandle) {
        throw new RuntimeException('Unable to initialize cURL for the proxy test.');
    }

    $setter = new RecordingProxyCurlOptionSetter;

    expect($proxy->apply($handle, $setter))->toBeTrue()
        ->and($setter->options)->toMatchArray([
            CURLOPT_PROXY => 'https://proxy.example.test:8443',
            CURLOPT_HTTPPROXYTUNNEL => true,
            CURLOPT_NOPROXY => '',
            CURLOPT_PROXY_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY_SSL_VERIFYPEER => true,
            CURLOPT_SUPPRESS_CONNECT_HEADERS => true,
            CURLOPT_PROXYAUTH => CURLAUTH_ANY,
            CURLOPT_PROXYUSERNAME => 'proxy-user',
            CURLOPT_PROXYPASSWORD => 'proxy-password',
        ]);
});

it('does not configure proxy authentication when credentials are absent', function (): void {
    $proxy = CurlProxyConfiguration::from('http://proxy.internal:8080');

    if (! $proxy instanceof CurlProxyConfiguration) {
        throw new LogicException('The valid proxy configuration was disabled.');
    }

    $handle = curl_init();

    if (! $handle instanceof CurlHandle) {
        throw new RuntimeException('Unable to initialize cURL for the proxy test.');
    }

    $setter = new RecordingProxyCurlOptionSetter;

    expect($proxy->apply($handle, $setter))->toBeTrue()
        ->and($setter->options)->not->toHaveKeys([
            CURLOPT_PROXYAUTH,
            CURLOPT_PROXYUSERNAME,
            CURLOPT_PROXYPASSWORD,
        ]);
});

it('keeps proxy credentials out of debug, export, and serialized state', function (): void {
    $url = 'https://proxy-security-sentinel.example:8443';
    $username = 'PROXY-USER-SECURITY-SENTINEL';
    $password = 'PROXY-PASSWORD-SECURITY-SENTINEL';
    $proxy = CurlProxyConfiguration::from($url, $username, $password);

    if (! $proxy instanceof CurlProxyConfiguration) {
        throw new LogicException('The valid proxy configuration was disabled.');
    }

    $rendered = print_r($proxy, true).var_export($proxy, true).serialize($proxy);

    expect($rendered)->not->toContain($url)
        ->not->toContain($username)
        ->not->toContain($password)
        ->and($proxy->__debugInfo())->toBe([
            'url' => '[REDACTED]',
            'username' => '[REDACTED]',
            'password' => '[REDACTED]',
        ]);
});

it('keeps proxy credentials out of option-application exception traces', function (): void {
    $originalExceptionIgnoreArgs = ini_get('zend.exception_ignore_args');
    $url = 'https://proxy-trace-sentinel.example:8443';
    $username = 'PROXY-TRACE-USER-SENTINEL';
    $password = 'PROXY-TRACE-PASSWORD-SENTINEL';
    $proxy = CurlProxyConfiguration::from($url, $username, $password);

    if (! $proxy instanceof CurlProxyConfiguration) {
        throw new LogicException('The valid proxy configuration was disabled.');
    }

    $handle = curl_init();

    if (! $handle instanceof CurlHandle) {
        throw new RuntimeException('Unable to initialize cURL for the proxy trace test.');
    }

    $setter = new class implements CurlOptionSetterInterface
    {
        public function setMany(
            CurlHandle $handle,
            #[SensitiveParameter] array $options,
        ): bool {
            unset($handle, $options);

            return true;
        }

        public function set(
            CurlHandle $handle,
            int $option,
            #[SensitiveParameter] mixed $value,
        ): never {
            unset($handle, $option, $value);

            throw new RuntimeException('Deterministic proxy option failure.');
        }
    };

    try {
        if (ini_set('zend.exception_ignore_args', '0') === false) {
            throw new RuntimeException('Unable to enable exception arguments for the proxy trace test.');
        }

        try {
            $proxy->apply($handle, $setter);
            throw new LogicException('The failing proxy option setter was ignored.');
        } catch (RuntimeException $exception) {
            $rendered = (new CliDumper)->dump(
                (new VarCloner)->cloneVar($exception),
                true,
            );

            expect($rendered)->toBeString()
                ->not->toContain($url)
                ->not->toContain($username)
                ->not->toContain($password);
        }
    } finally {
        if (is_string($originalExceptionIgnoreArgs)) {
            ini_set('zend.exception_ignore_args', $originalExceptionIgnoreArgs);
        }
    }
});

it('fails closed when explicit proxy settings accompany a custom HTTP sender', function (): void {
    $sender = new class implements BirHttpSenderInterface
    {
        public function send(
            BirOperation $operation,
            #[SensitiveParameter] string $soapEnvelope,
            #[SensitiveParameter] ?string $sessionId,
        ): RawTransportResult {
            return RawTransportResult::failure();
        }
    };

    expect(fn () => new NativeSoapTransport(
        apiKey: 'APIKEYSENTINEL123456',
        requestLimiter: new UnlimitedBirRequestLimiter,
        environment: Environment::Sandbox,
        httpSender: $sender,
        proxyUrl: 'https://proxy.example.test:8443',
    ))->toThrow(
        LogicException::class,
        'BIR proxy configuration cannot be combined with a custom HTTP sender.',
    );
});

it('rejects authenticated cleartext HTTP proxies passed directly to the native transport', function (): void {
    expect(fn () => new NativeSoapTransport(
        apiKey: 'APIKEYSENTINEL123456',
        requestLimiter: new UnlimitedBirRequestLimiter,
        environment: Environment::Sandbox,
        proxyUrl: 'http://proxy.example.test:8080',
        proxyUsername: 'proxy-user',
        proxyPassword: 'proxy-password',
    ))->toThrow(
        InvalidArgumentException::class,
        'BIR proxy credentials require an HTTPS proxy URL.',
    );
});

final class RecordingProxyCurlOptionSetter implements CurlOptionSetterInterface
{
    /** @var array<int, mixed> */
    public array $options = [];

    public function setMany(
        CurlHandle $handle,
        #[SensitiveParameter] array $options,
    ): bool {
        unset($handle);
        $this->options = $options + $this->options;

        return true;
    }

    public function set(
        CurlHandle $handle,
        int $option,
        #[SensitiveParameter] mixed $value,
    ): bool {
        unset($handle);
        $this->options[$option] = $value;

        return true;
    }
}
