<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Transport;

use CurlHandle;
use Throwable;

/** @internal */
final class NativeCurlOptionSetter implements CurlOptionSetterInterface
{
    public function setMany(
        CurlHandle $handle,
        #[\SensitiveParameter] array $options,
    ): bool {
        return $this->withoutWarnings(
            static fn (): bool => curl_setopt_array($handle, $options),
        );
    }

    public function set(
        CurlHandle $handle,
        int $option,
        #[\SensitiveParameter] mixed $value,
    ): bool {
        return $this->withoutWarnings(
            static fn (): bool => curl_setopt($handle, $option, $value),
        );
    }

    /** @param callable(): bool $operation */
    private function withoutWarnings(#[\SensitiveParameter] callable $operation): bool
    {
        set_error_handler(static fn (): bool => true);

        try {
            return $operation();
        } catch (Throwable) {
            return false;
        } finally {
            restore_error_handler();
        }
    }
}
