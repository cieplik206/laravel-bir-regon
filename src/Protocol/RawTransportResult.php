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
        public readonly bool $successful,
        private readonly ?SensitiveParameterValue $responseBody,
        public readonly ?string $contentType,
    ) {}

    public static function success(
        #[\SensitiveParameter] string $body,
        ?string $contentType = null,
    ): self {
        return new self(true, new SensitiveParameterValue($body), $contentType);
    }

    public static function failure(): self
    {
        return new self(false, null, null);
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
                'successful' => '[UNAVAILABLE]',
            ];
        }

        return [
            'body' => $this->responseBody === null ? '[NONE]' : '[REDACTED]',
            'contentType' => $this->contentType ?? '[NONE]',
            'successful' => $this->successful ? 'yes' : 'no',
        ];
    }
}
