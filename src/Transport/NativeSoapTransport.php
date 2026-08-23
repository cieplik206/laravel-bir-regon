<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Transport;

use cieplik206\BirRegon\Concerns\PreventsSerialization;
use cieplik206\BirRegon\Contracts\BirRateLimitScopeInterface;
use cieplik206\BirRegon\Contracts\BirRequestLimiterInterface;
use cieplik206\BirRegon\Contracts\BirSoapTransportInterface;
use cieplik206\BirRegon\Enums\Environment;
use cieplik206\BirRegon\Exceptions\BirRateLimitException;
use cieplik206\BirRegon\Protocol\BirOperation;
use cieplik206\BirRegon\Protocol\RawTransportResult;
use cieplik206\BirRegon\Protocol\SoapEnvelopeBuilder;
use cieplik206\BirRegon\Protocol\SoapResponseDecoder;
use cieplik206\BirRegon\Protocol\TransportFailureType;
use cieplik206\BirRegon\Protocol\TransportResponse;
use cieplik206\BirRegon\RateLimit\UnlimitedBirRequestLimiter;
use SensitiveParameterValue;
use Throwable;

final class NativeSoapTransport implements BirRateLimitScopeInterface, BirSoapTransportInterface
{
    use PreventsSerialization;

    private readonly SoapEnvelopeBuilder $envelopeBuilder;

    private readonly SoapResponseDecoder $responseDecoder;

    private readonly bool $authenticationConfigured;

    private readonly string $userAgent;

    private readonly SensitiveParameterValue $httpSender;

    private readonly SensitiveParameterValue $requestLimiter;

    private ?SensitiveParameterValue $sessionId = null;

    public function __construct(
        #[\SensitiveParameter] string $apiKey,
        private readonly Environment $environment = Environment::Production,
        private readonly int $connectionTimeout = 10,
        private readonly int $requestTimeout = 30,
        int $maxResponseBytes = 10_000_000,
        string $userAgent = 'laravel-bir-regon/2',
        #[\SensitiveParameter] ?BirHttpSenderInterface $httpSender = null,
        #[\SensitiveParameter] ?BirRequestLimiterInterface $requestLimiter = null,
    ) {
        $this->envelopeBuilder = new SoapEnvelopeBuilder($apiKey);
        $this->responseDecoder = new SoapResponseDecoder(max(1, $maxResponseBytes));
        $this->authenticationConfigured = preg_match('/^[A-Za-z0-9]{20}$/D', $apiKey) === 1;
        $this->userAgent = preg_match('/^[\x20-\x7E]{1,200}$/D', $userAgent) === 1
            ? $userAgent
            : 'laravel-bir-regon/2';
        $this->httpSender = new SensitiveParameterValue($httpSender ?? new CurlBirHttpSender(
            environment: $environment,
            connectionTimeout: $connectionTimeout,
            requestTimeout: $requestTimeout,
            maxResponseBytes: $maxResponseBytes,
            userAgent: $this->userAgent,
        ));
        $this->requestLimiter = new SensitiveParameterValue(
            $requestLimiter ?? new UnlimitedBirRequestLimiter,
        );
    }

    public function isAuthenticationConfigured(): bool
    {
        $this->ensureNotRestoredFromSerialization();

        return $this->authenticationConfigured;
    }

    public function useSession(#[\SensitiveParameter] ?string $sessionId): void
    {
        $this->ensureNotRestoredFromSerialization();
        $this->sessionId = $sessionId === null ? null : new SensitiveParameterValue($sessionId);
        $this->envelopeBuilder->useSession($sessionId);
    }

    public function call(BirOperation $operation, array $parameters = []): TransportResponse
    {
        $this->ensureNotRestoredFromSerialization();
        $sessionId = $this->sessionId();

        if (
            $sessionId !== null
            && preg_match('/^[A-Za-z0-9]{20}$/D', $sessionId) !== 1
        ) {
            return TransportResponse::failure(TransportFailureType::Protocol);
        }

        try {
            $request = $this->envelopeBuilder->build(
                $operation,
                $parameters,
                $this->environment->endpoint(),
            );

            if ($request === null) {
                return TransportResponse::failure(TransportFailureType::Protocol);
            }
        } catch (Throwable) {
            return TransportResponse::failure(TransportFailureType::Protocol);
        }

        try {
            $this->requestLimiter()->acquire($operation, $parameters);
        } catch (BirRateLimitException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw BirRateLimitException::limiterUnavailable();
        }

        try {
            $rawResponse = $this->httpSender()->send($operation, $request, $sessionId);
        } catch (Throwable) {
            return TransportResponse::failure(TransportFailureType::Transport);
        }

        try {
            return $this->decodeRawResponse($rawResponse, $operation);
        } catch (Throwable) {
            return TransportResponse::failure(TransportFailureType::Protocol);
        }
    }

    public function beginRateLimitScope(): void
    {
        $this->ensureNotRestoredFromSerialization();

        try {
            $requestLimiter = $this->requestLimiter();

            if ($requestLimiter instanceof BirRateLimitScopeInterface) {
                $requestLimiter->beginRateLimitScope();
            }
        } catch (BirRateLimitException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw BirRateLimitException::limiterUnavailable();
        }
    }

    public function endRateLimitScope(): void
    {
        $this->ensureNotRestoredFromSerialization();

        try {
            $requestLimiter = $this->requestLimiter();

            if ($requestLimiter instanceof BirRateLimitScopeInterface) {
                $requestLimiter->endRateLimitScope();
            }
        } catch (BirRateLimitException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw BirRateLimitException::limiterUnavailable();
        }
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return [
            'apiKey' => '[REDACTED]',
            'sessionId' => $this->sessionId === null ? '[NONE]' : '[REDACTED]',
            'environment' => '[HIDDEN]',
        ];
    }

    private function sessionId(): ?string
    {
        $sessionId = $this->sessionId?->getValue();

        return is_string($sessionId) ? $sessionId : null;
    }

    private function httpSender(): BirHttpSenderInterface
    {
        $httpSender = $this->httpSender->getValue();

        if (! $httpSender instanceof BirHttpSenderInterface) {
            throw new \LogicException('The BIR HTTP sender is unavailable.');
        }

        return $httpSender;
    }

    private function requestLimiter(): BirRequestLimiterInterface
    {
        $requestLimiter = $this->requestLimiter->getValue();

        if (! $requestLimiter instanceof BirRequestLimiterInterface) {
            throw BirRateLimitException::limiterUnavailable();
        }

        return $requestLimiter;
    }

    private function decodeRawResponse(
        RawTransportResult $rawResponse,
        BirOperation $operation,
    ): TransportResponse {
        if (! $rawResponse->exchangeCompleted || $rawResponse->httpStatus === null) {
            return TransportResponse::failure(TransportFailureType::Transport);
        }

        $body = $rawResponse->body();
        $contentType = $rawResponse->contentType;

        if ($body === null) {
            return TransportResponse::failure(TransportFailureType::Transport);
        }

        if ($rawResponse->httpStatus === 200) {
            if (! $this->responseDecoder->supportsHttpContentType($contentType)) {
                return TransportResponse::failure(TransportFailureType::Protocol);
            }

            return $this->responseDecoder->decode(
                $body,
                $operation,
                $contentType,
                $rawResponse->httpStatus,
            );
        }

        if (
            ! in_array($rawResponse->httpStatus, [400, 500], true)
            || $body === ''
            || ! $this->responseDecoder->supportsHttpContentType($contentType)
        ) {
            return TransportResponse::failure(TransportFailureType::Transport);
        }

        return $this->responseDecoder->decode(
            $body,
            $operation,
            $contentType,
            $rawResponse->httpStatus,
        );
    }
}
