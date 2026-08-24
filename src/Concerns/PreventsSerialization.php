<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Concerns;

use LogicException;

trait PreventsSerialization
{
    private bool $restoredFromSerialization = false;

    /** @return array<never, never> */
    final public function __serialize(): array
    {
        // Throwing here would retain the original object in serialize()'s
        // exception trace when zend.exception_ignore_args is disabled.
        return [];
    }

    /** @param array<array-key, mixed> $data */
    final public function __unserialize(array $data): void
    {
        // Do not throw here. Legacy payloads can contain credentials, and
        // unserialize() would retain its raw input in the exception trace.
        $this->restoredFromSerialization = true;
    }

    final protected function ensureNotRestoredFromSerialization(): void
    {
        if ($this->restoredFromSerialization) {
            throw new LogicException(sprintf(
                'Serialization of %s is not supported.',
                static::class,
            ));
        }
    }

    final protected function wasRestoredFromSerialization(): bool
    {
        return $this->restoredFromSerialization;
    }
}
