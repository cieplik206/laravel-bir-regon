<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Contracts;

use cieplik206\BirRegon\Protocol\BirOperation;
use cieplik206\BirRegon\Protocol\TransportResponse;

interface BirSoapTransportInterface
{
    public function isAuthenticationConfigured(): bool;

    public function useSession(#[\SensitiveParameter] ?string $sessionId): void;

    /** @param array<string, mixed> $parameters */
    public function call(
        BirOperation $operation,
        #[\SensitiveParameter] array $parameters = [],
    ): TransportResponse;
}
