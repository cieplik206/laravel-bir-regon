<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Exceptions;

class BirReportException extends BirException
{
    public function __construct(
        public readonly int $gusCode,
        string $message = 'GUS BIR rejected the requested report.',
    ) {
        parent::__construct($message, $gusCode);
    }
}
