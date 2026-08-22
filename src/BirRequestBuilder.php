<?php

declare(strict_types=1);

namespace cieplik206\BirRegon;

use cieplik206\BirRegon\Enums\Environment;

abstract class BirRequestBuilder
{
    private ?Environment $environment = null;

    public function __construct(protected readonly BirClientInterface $client) {}

    public function inProd(): static
    {
        $this->environment = Environment::Production;

        return $this;
    }

    public function inDev(): static
    {
        $this->environment = Environment::Development;

        return $this;
    }

    protected function resolveClient(): BirClientInterface
    {
        if ($this->environment === null) {
            return $this->client;
        }

        return $this->client->withEnvironment($this->environment);
    }
}
