<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Transport;

use cieplik206\BirRegon\Enums\Environment;
use cieplik206\BirRegon\Protocol\BirOperation;
use cieplik206\BirRegon\Protocol\RawTransportResult;
use CurlHandle;
use Throwable;

/** @internal */
final readonly class CurlBirHttpSender implements BirHttpSenderInterface
{
    private CurlExecutorInterface $executor;

    private CurlHandle|false $handle;

    private int $maxResponseBytes;

    public function __construct(
        private Environment $environment,
        private int $connectionTimeout,
        private int $requestTimeout,
        int $maxResponseBytes,
        private string $userAgent,
        ?CurlExecutorInterface $executor = null,
    ) {
        $this->maxResponseBytes = max(1, $maxResponseBytes);
        $this->executor = $executor ?? new NativeCurlExecutor;
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
                    CURLOPT_CONNECTTIMEOUT_MS => max(1, $this->connectionTimeout) * 1000,
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
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_TIMEOUT_MS => max(1, $this->requestTimeout) * 1000,
                    CURLOPT_URL => $this->environment->endpoint(),
                    CURLOPT_USERAGENT => $this->userAgent,
                    CURLOPT_WRITEFUNCTION => $buffer->writeBody(...),
                ];

                if (! curl_setopt_array($handle, $safeOptions)) {
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
}
