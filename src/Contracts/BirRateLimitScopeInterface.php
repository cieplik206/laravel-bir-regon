<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Contracts;

interface BirRateLimitScopeInterface
{
    public function beginRateLimitScope(): void;

    public function endRateLimitScope(): void;
}
