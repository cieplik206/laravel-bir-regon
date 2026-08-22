<?php

declare(strict_types=1);

namespace cieplik206\BirRegon;

use cieplik206\BirRegon\Enums\Environment;
use GusApi\GusApi;

interface GusApiFactoryInterface
{
    public function make(string $apiKey, Environment $environment): GusApi;
}
