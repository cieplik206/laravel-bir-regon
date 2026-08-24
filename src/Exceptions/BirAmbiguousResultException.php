<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Exceptions;

final class BirAmbiguousResultException extends BirException
{
    public readonly string $identifierType;

    public function __construct(
        string $identifierType,
        public readonly int $compatibleTargetCount,
    ) {
        $this->identifierType = in_array($identifierType, ['NIP', 'REGON', 'KRS'], true)
            ? $identifierType
            : 'UNKNOWN';

        parent::__construct(sprintf(
            'GUS BIR returned %d distinct compatible report targets for the %s identifier. Use the plural full-report method to retrieve every result.',
            $compatibleTargetCount,
            $this->identifierType,
        ));
    }
}
