<?php

declare(strict_types=1);

namespace cieplik206\BirRegon;

use cieplik206\BirRegon\Concerns\PreventsSerialization;
use SensitiveParameterValue;

abstract class BirRequestBuilder
{
    use PreventsSerialization;

    protected readonly SensitiveParameterValue $client;

    public function __construct(
        #[\SensitiveParameter] BirClientInterface $client,
    ) {
        $this->client = new SensitiveParameterValue($client);
    }

    final protected function getClient(): BirClientInterface
    {
        $this->ensureNotRestoredFromSerialization();

        $client = $this->client->getValue();

        if (! $client instanceof BirClientInterface) {
            throw new \LogicException('The BIR client is unavailable.');
        }

        return $client;
    }
}
