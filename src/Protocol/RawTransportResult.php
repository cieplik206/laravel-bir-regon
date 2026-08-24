<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Protocol;

use cieplik206\BirRegon\Concerns\PreventsSerialization;
use SensitiveParameterValue;

/** @internal */
final class RawTransportResult
{
    use PreventsSerialization;

    private function __construct(
        public readonly bool $exchangeCompleted,
        public readonly bool $successful,
        private readonly ?SensitiveParameterValue $responseBody,
        public readonly ?string $contentType,
        public readonly ?int $httpStatus,
    ) {}

    public static function success(
        #[\SensitiveParameter] string $body,
        ?string $contentType = null,
    ): self {
        return self::completed($body, $contentType, 200);
    }

    public static function completed(
        #[\SensitiveParameter] string $body,
        ?string $contentType,
        int $httpStatus,
    ): self {
        if ($httpStatus < 100 || $httpStatus > 599) {
            return self::failure();
        }

        return new self(
            true,
            $httpStatus === 200 && is_string($contentType) && $contentType !== '',
            new SensitiveParameterValue($body),
            $contentType,
            $httpStatus,
        );
    }

    public static function failure(): self
    {
        return new self(false, false, null, null, null);
    }

    public function body(): ?string
    {
        $this->ensureNotRestoredFromSerialization();
        $body = $this->responseBody?->getValue();

        return is_string($body) ? $body : null;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        if ($this->wasRestoredFromSerialization()) {
            return [
                'body' => '[UNAVAILABLE]',
                'contentType' => '[UNAVAILABLE]',
                'exchangeCompleted' => '[UNAVAILABLE]',
                'httpStatus' => '[UNAVAILABLE]',
                'successful' => '[UNAVAILABLE]',
            ];
        }

        return [
            'body' => $this->responseBody === null ? '[NONE]' : '[REDACTED]',
            'contentType' => $this->contentType ?? '[NONE]',
            'exchangeCompleted' => $this->exchangeCompleted ? 'yes' : 'no',
            'httpStatus' => $this->httpStatus === null ? '[NONE]' : (string) $this->httpStatus,
            'successful' => $this->successful ? 'yes' : 'no',
        ];
    }
}
