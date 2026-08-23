<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Enums;

enum SoapFaultCode: string
{
    case Sender = 'Sender';
    case Receiver = 'Receiver';
    case MustUnderstand = 'MustUnderstand';
    case VersionMismatch = 'VersionMismatch';
    case DataEncodingUnknown = 'DataEncodingUnknown';

    public function expectedHttpStatus(): int
    {
        return $this === self::Sender ? 400 : 500;
    }
}
