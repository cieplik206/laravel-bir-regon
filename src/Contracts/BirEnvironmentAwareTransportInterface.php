<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Contracts;

use cieplik206\BirRegon\Enums\Environment;

/** @internal */
interface BirEnvironmentAwareTransportInterface
{
    public function environment(): Environment;
}
