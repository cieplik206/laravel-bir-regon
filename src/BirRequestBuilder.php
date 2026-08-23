<?php

declare(strict_types=1);

namespace cieplik206\BirRegon;

abstract class BirRequestBuilder
{
    public function __construct(protected readonly BirClientInterface $client) {}
}
