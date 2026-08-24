<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Transport;

use cieplik206\BirRegon\Concerns\PreventsSerialization;
use CurlHandle;
use InvalidArgumentException;
use SensitiveParameterValue;

/** @internal */
final class CurlProxyConfiguration
{
    use PreventsSerialization;

    private readonly SensitiveParameterValue $url;

    private readonly SensitiveParameterValue $username;

    private readonly SensitiveParameterValue $password;

    private function __construct(
        #[\SensitiveParameter] string $url,
        #[\SensitiveParameter] ?string $username,
        #[\SensitiveParameter] ?string $password,
    ) {
        $this->url = new SensitiveParameterValue($url);
        $this->username = new SensitiveParameterValue($username);
        $this->password = new SensitiveParameterValue($password);
    }

    public static function from(
        #[\SensitiveParameter] ?string $url,
        #[\SensitiveParameter] ?string $username = null,
        #[\SensitiveParameter] ?string $password = null,
    ): ?self {
        $url = self::nullIfEmpty($url);
        $username = self::nullIfEmpty($username);
        $password = self::nullIfEmpty($password);

        if ($url === null) {
            if ($username !== null || $password !== null) {
                throw new InvalidArgumentException('BIR proxy credentials require a proxy URL.');
            }

            return null;
        }

        self::validateUrl($url);

        if (($username === null) !== ($password === null)) {
            throw new InvalidArgumentException(
                'BIR proxy username and password must be configured together.',
            );
        }

        if (
            ($username !== null && preg_match('/[\x00-\x1F\x7F]/', $username) === 1)
            || ($password !== null && preg_match('/[\x00-\x1F\x7F]/', $password) === 1)
            || ($username !== null && trim($username) !== $username)
            || ($password !== null && trim($password) !== $password)
        ) {
            throw new InvalidArgumentException(
                'BIR proxy credentials contain invalid whitespace or control characters.',
            );
        }

        return new self($url, $username, $password);
    }

    public function apply(
        CurlHandle $handle,
        CurlOptionSetterInterface $optionSetter,
    ): bool {
        $this->ensureNotRestoredFromSerialization();
        $url = $this->stringValue($this->url);
        $username = $this->nullableStringValue($this->username);
        $password = $this->nullableStringValue($this->password);

        if (
            ! $optionSetter->set($handle, CURLOPT_PROXY, $url)
            || ! $optionSetter->set($handle, CURLOPT_HTTPPROXYTUNNEL, true)
            || ! $optionSetter->set($handle, CURLOPT_NOPROXY, '')
            || ! $optionSetter->set($handle, CURLOPT_PROXY_SSL_VERIFYHOST, 2)
            || ! $optionSetter->set($handle, CURLOPT_PROXY_SSL_VERIFYPEER, true)
            || ! $optionSetter->set($handle, CURLOPT_SUPPRESS_CONNECT_HEADERS, true)
        ) {
            return false;
        }

        if ($username === null || $password === null) {
            return true;
        }

        return $optionSetter->set($handle, CURLOPT_PROXYAUTH, CURLAUTH_ANY)
            && $optionSetter->set($handle, CURLOPT_PROXYUSERNAME, $username)
            && $optionSetter->set($handle, CURLOPT_PROXYPASSWORD, $password);
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        if ($this->wasRestoredFromSerialization()) {
            return [
                'url' => '[UNAVAILABLE]',
                'username' => '[UNAVAILABLE]',
                'password' => '[UNAVAILABLE]',
            ];
        }

        return [
            'url' => '[REDACTED]',
            'username' => $this->nullableStringValue($this->username) === null
                ? '[NONE]'
                : '[REDACTED]',
            'password' => $this->nullableStringValue($this->password) === null
                ? '[NONE]'
                : '[REDACTED]',
        ];
    }

    private static function validateUrl(#[\SensitiveParameter] string $url): void
    {
        if (preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            throw new InvalidArgumentException('BIR proxy URL is invalid.');
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'])) {
            throw new InvalidArgumentException('BIR proxy URL is invalid.');
        }

        if (! in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
            throw new InvalidArgumentException(
                'BIR proxy URL must use the http or https scheme.',
            );
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException(
                'BIR proxy credentials must use the separate username and password settings.',
            );
        }

        if (self::hasInvalidPort($parts)) {
            throw new InvalidArgumentException('BIR proxy URL must contain a valid port.');
        }

        $path = $parts['path'] ?? '';

        if (
            ! isset($parts['host'])
            || $parts['host'] === ''
            || ! in_array($path, ['', '/'], true)
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new InvalidArgumentException(
                'BIR proxy URL must contain only a scheme, host, and optional port.',
            );
        }
    }

    private static function nullIfEmpty(?string $value): ?string
    {
        return $value === null || $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $parts */
    private static function hasInvalidPort(array $parts): bool
    {
        $port = $parts['port'] ?? null;

        return $port !== null
            && (! is_int($port) || $port < 1 || $port > 65_535);
    }

    private function stringValue(SensitiveParameterValue $value): string
    {
        $rawValue = $value->getValue();

        if (! is_string($rawValue)) {
            throw new \LogicException('The BIR proxy configuration is unavailable.');
        }

        return $rawValue;
    }

    private function nullableStringValue(SensitiveParameterValue $value): ?string
    {
        $rawValue = $value->getValue();

        if ($rawValue !== null && ! is_string($rawValue)) {
            throw new \LogicException('The BIR proxy configuration is unavailable.');
        }

        return $rawValue;
    }
}
