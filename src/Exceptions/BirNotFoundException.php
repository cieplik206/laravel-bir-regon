<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Exceptions;

class BirNotFoundException extends BirException
{
    public function __construct(
        string $identifierType = 'NIP',
    ) {
        $identifierType = in_array(
            $identifierType,
            ['NIP', 'REGON', 'KRS', 'REGON9', 'REGON14'],
            true,
        )
            ? $identifierType
            : 'UNKNOWN';

        parent::__construct("Nie znaleziono podmiotu dla identyfikatora typu {$identifierType}.");
    }
}
