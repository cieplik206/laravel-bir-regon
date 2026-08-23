<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Exceptions;

final class BirAmbiguousResultException extends BirException
{
    public function __construct(
        public readonly string $identifier,
        public readonly string $identifierType,
        public readonly int $compatibleTargetCount,
    ) {
        parent::__construct(sprintf(
            'GUS BIR returned %d distinct compatible report targets for %s: %s. Use the plural full-report method to retrieve every result.',
            $compatibleTargetCount,
            $identifierType,
            $identifier,
        ));
    }
}
