<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Transport;

use CurlHandle;

/** @internal */
interface CurlOptionSetterInterface
{
    /** @param array<int, mixed> $options */
    public function setMany(
        CurlHandle $handle,
        #[\SensitiveParameter] array $options,
    ): bool;

    public function set(
        CurlHandle $handle,
        int $option,
        #[\SensitiveParameter] mixed $value,
    ): bool;
}
