<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Transport;

use cieplik206\BirRegon\Protocol\BirOperation;
use cieplik206\BirRegon\Protocol\RawTransportResult;

/** @internal */
interface BirHttpSenderInterface
{
    public function send(
        BirOperation $operation,
        #[\SensitiveParameter] string $soapEnvelope,
        #[\SensitiveParameter] ?string $sessionId,
    ): RawTransportResult;
}
