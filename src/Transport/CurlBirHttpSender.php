<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Transport;

use cieplik206\BirRegon\Enums\Environment;
use cieplik206\BirRegon\Protocol\BirOperation;
use cieplik206\BirRegon\Protocol\RawTransportResult;
use CurlHandle;
use InvalidArgumentException;
use SensitiveParameterValue;
use Throwable;

/** @internal */
final readonly class CurlBirHttpSender implements BirHttpSenderInterface
{
    private const MAX_CONNECTION_TIMEOUT_SECONDS = 60;

    private const MAX_REQUEST_TIMEOUT_SECONDS = 300;

    private const MAX_RESPONSE_BYTES = 50_000_000;

    private const MIN_CONNECTION_TIMEOUT_SECONDS = 1;

    private const MIN_REQUEST_TIMEOUT_SECONDS = 1;

    private const MIN_RESPONSE_BYTES = 1;

    private CurlExecutorInterface $executor;

    private CurlHandle|false $handle;

    private int $maxResponseBytes;

    private SensitiveParameterValue $optionSetter;

    private SensitiveParameterValue $proxy;

    public function __construct(
        private Environment $environment,
        private int $connectionTimeout,
        private int $requestTimeout,
        int $maxResponseBytes,
        private string $userAgent,
        ?CurlExecutorInterface $executor = null,
        #[\SensitiveParameter] ?CurlProxyConfiguration $proxy = null,
        ?CurlOptionSetterInterface $optionSetter = null,
    ) {
        self::validateLimits($connectionTimeout, $requestTimeout, $maxResponseBytes);

        $this->maxResponseBytes = $maxResponseBytes;
        $this->executor = $executor ?? new NativeCurlExecutor;
        $this->optionSetter = new SensitiveParameterValue(
            $optionSetter ?? new NativeCurlOptionSetter,
        );
        $this->proxy = new SensitiveParameterValue($proxy);
        $this->handle = curl_init();
    }

    public function send(
        BirOperation $operation,
        #[\SensitiveParameter] string $soapEnvelope,
        #[\SensitiveParameter] ?string $sessionId,
    ): RawTransportResult {
        try {
            $handle = $this->handle;

            if (! $handle instanceof CurlHandle) {
                return RawTransportResult::failure();
            }

            // curl_reset removes request state but intentionally retains the
            // handle's connection, DNS, and TLS session caches for reuse.
            curl_reset($handle);

            try {
                $buffer = new CurlResponseBuffer($this->maxResponseBytes);
                $safeOptions = [
                    CURLOPT_CONNECTTIMEOUT_MS => $this->connectionTimeout * 1000,
                    CURLOPT_ENCODING => 'identity',
                    CURLOPT_FAILONERROR => false,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_HEADER => false,
                    CURLOPT_HEADERFUNCTION => $buffer->writeHeader(...),
                    CURLOPT_HTTP_CONTENT_DECODING => false,
                    CURLOPT_HTTP_TRANSFER_DECODING => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_MAXREDIRS => 0,
                    CURLOPT_NOSIGNAL => true,
                    CURLOPT_POST => true,
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                    CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                    CURLOPT_RETURNTRANSFER => false,
                    CURLOPT_PROXY_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                    CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_TIMEOUT_MS => $this->requestTimeout * 1000,
                    CURLOPT_URL => $this->environment->endpoint(),
                    CURLOPT_USERAGENT => $this->userAgent,
                    CURLOPT_WRITEFUNCTION => $buffer->writeBody(...),
                ];

                if (! $this->optionSetter()->setMany($handle, $safeOptions)) {
                    return RawTransportResult::failure();
                }

                $proxy = $this->proxy();

                if ($proxy !== null && ! $proxy->apply($handle, $this->optionSetter())) {
                    return RawTransportResult::failure();
                }

                $headers = [
                    'Accept: application/soap+xml, application/xop+xml, multipart/related',
                    'Accept-Encoding: identity',
                    'Content-Type: application/soap+xml; charset=UTF-8; action="'.$operation->action().'"',
                    'Expect:',
                ];

                if ($operation !== BirOperation::Login && $sessionId !== null) {
                    $headers[] = 'sid: '.$sessionId;
                }

                if (
                    ! $this->executor->execute($handle, $headers, $soapEnvelope)
                    || curl_errno($handle) !== CURLE_OK
                ) {
                    return RawTransportResult::failure();
                }

                return $buffer->result();
            } finally {
                // Drop callbacks, request bodies, and SID headers immediately.
                // libcurl keeps the reusable connection cache on this handle.
                curl_reset($handle);
            }
        } catch (Throwable) {
            return RawTransportResult::failure();
        }
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return [
            'environment' => '[HIDDEN]',
            'proxy' => $this->proxy() === null ? '[NONE]' : '[CONFIGURED]',
            'executor' => '[HIDDEN]',
        ];
    }

    private function proxy(): ?CurlProxyConfiguration
    {
        $proxy = $this->proxy->getValue();

        if ($proxy !== null && ! $proxy instanceof CurlProxyConfiguration) {
            throw new \LogicException('The BIR proxy configuration is unavailable.');
        }

        return $proxy;
    }

    private function optionSetter(): CurlOptionSetterInterface
    {
        $optionSetter = $this->optionSetter->getValue();

        if (! $optionSetter instanceof CurlOptionSetterInterface) {
            throw new \LogicException('The cURL option setter is unavailable.');
        }

        return $optionSetter;
    }

    private static function validateLimits(
        int $connectionTimeout,
        int $requestTimeout,
        int $maxResponseBytes,
    ): void {
        if (
            $connectionTimeout < self::MIN_CONNECTION_TIMEOUT_SECONDS
            || $connectionTimeout > self::MAX_CONNECTION_TIMEOUT_SECONDS
        ) {
            throw new InvalidArgumentException(
                'BIR connection timeout must be between 1 and 60 seconds.',
            );
        }

        if (
            $requestTimeout < self::MIN_REQUEST_TIMEOUT_SECONDS
            || $requestTimeout > self::MAX_REQUEST_TIMEOUT_SECONDS
        ) {
            throw new InvalidArgumentException(
                'BIR request timeout must be between 1 and 300 seconds.',
            );
        }

        if (
            $maxResponseBytes < self::MIN_RESPONSE_BYTES
            || $maxResponseBytes > self::MAX_RESPONSE_BYTES
        ) {
            throw new InvalidArgumentException(
                'BIR maximum response size must be between 1 and 50000000 bytes.',
            );
        }
    }
}
