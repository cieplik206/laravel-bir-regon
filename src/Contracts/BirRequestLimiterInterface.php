<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Contracts;

use cieplik206\BirRegon\Protocol\BirOperation;

interface BirRequestLimiterInterface
{
    /** @param array<string, mixed> $parameters */
    public function acquire(
        BirOperation $operation,
        #[\SensitiveParameter] array $parameters = [],
    ): void;
}
