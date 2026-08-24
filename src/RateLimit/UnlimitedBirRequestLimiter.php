<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\RateLimit;

use cieplik206\BirRegon\Contracts\BirRateLimitScopeInterface;
use cieplik206\BirRegon\Contracts\BirRequestLimiterInterface;
use cieplik206\BirRegon\Protocol\BirOperation;

final class UnlimitedBirRequestLimiter implements BirRateLimitScopeInterface, BirRequestLimiterInterface
{
    public function acquire(
        BirOperation $operation,
        #[\SensitiveParameter] array $parameters = [],
    ): void {}

    public function beginRateLimitScope(): void {}

    public function endRateLimitScope(): void {}
}
