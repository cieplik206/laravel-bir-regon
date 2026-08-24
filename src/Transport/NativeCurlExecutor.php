<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Transport;

use CurlHandle;
use Throwable;

/** @internal */
final class NativeCurlExecutor implements CurlExecutorInterface
{
    /**
     * @param  list<string>  $headers
     */
    public function execute(
        CurlHandle $handle,
        #[\SensitiveParameter] array $headers,
        #[\SensitiveParameter] string $body,
    ): bool {
        set_error_handler(static fn (): bool => true);

        try {
            return curl_setopt($handle, CURLOPT_HTTPHEADER, $headers)
                && curl_setopt($handle, CURLOPT_POSTFIELDS, $body)
                && curl_exec($handle) === true;
        } catch (Throwable) {
            return false;
        } finally {
            restore_error_handler();
        }
    }
}
