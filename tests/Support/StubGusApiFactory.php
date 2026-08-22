<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Tests\Support;

use cieplik206\BirRegon\Enums\Environment;
use cieplik206\BirRegon\GusApiFactoryInterface;
use GusApi\GusApi;

class StubGusApiFactory implements GusApiFactoryInterface
{
    /** @var array<int, array{string, Environment}> */
    public array $calls = [];

    public function __construct(private readonly GusApi $api) {}

    public function make(string $apiKey, Environment $environment): GusApi
    {
        $this->calls[] = [$apiKey, $environment];

        return $this->api;
    }
}
