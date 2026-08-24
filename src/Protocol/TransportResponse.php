<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Protocol;

use cieplik206\BirRegon\Concerns\PreventsSerialization;
use cieplik206\BirRegon\Enums\SoapFaultCode;
use SensitiveParameterValue;

final class TransportResponse
{
    use PreventsSerialization;

    private function __construct(
        public readonly bool $successful,
        private readonly ?SensitiveParameterValue $responseResult,
        public readonly ?TransportFailureType $failureType,
        public readonly bool $resultWasNil,
        public readonly ?SoapFaultCode $soapFaultCode,
    ) {}

    public static function success(
        #[\SensitiveParameter] string $result,
        bool $resultWasNil = false,
    ): self {
        return new self(true, new SensitiveParameterValue($result), null, $resultWasNil, null);
    }

    public static function failure(
        TransportFailureType $type,
        bool $resultWasNil = false,
        ?SoapFaultCode $soapFaultCode = null,
    ): self {
        return new self(false, null, $type, $resultWasNil, $soapFaultCode);
    }

    public function result(): ?string
    {
        $this->ensureNotRestoredFromSerialization();
        $result = $this->responseResult?->getValue();

        return is_string($result) ? $result : null;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        if ($this->wasRestoredFromSerialization()) {
            return [
                'result' => '[UNAVAILABLE]',
                'successful' => '[UNAVAILABLE]',
                'failureType' => '[UNAVAILABLE]',
                'resultWasNil' => '[UNAVAILABLE]',
                'soapFaultCode' => '[UNAVAILABLE]',
            ];
        }

        return [
            'result' => $this->responseResult === null ? '[NONE]' : '[REDACTED]',
            'successful' => $this->successful ? 'yes' : 'no',
            'failureType' => $this->failureType === null ? '[NONE]' : $this->failureType->name,
            'resultWasNil' => $this->resultWasNil ? 'yes' : 'no',
            'soapFaultCode' => $this->soapFaultCode === null
                ? '[NONE]'
                : $this->soapFaultCode->value,
        ];
    }
}
