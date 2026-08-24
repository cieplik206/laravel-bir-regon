<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Transport;

use CurlHandle;

/** @internal */
interface CurlExecutorInterface
{
    /**
     * @param  list<string>  $headers
     */
    public function execute(
        CurlHandle $handle,
        #[\SensitiveParameter] array $headers,
        #[\SensitiveParameter] string $body,
    ): bool;
}
