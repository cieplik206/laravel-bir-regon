<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Exceptions;

class BirNotFoundException extends BirException
{
    public function __construct(string $identifier, string $type = 'NIP')
    {
        parent::__construct("Nie znaleziono firmy dla {$type}: {$identifier}");
    }
}
