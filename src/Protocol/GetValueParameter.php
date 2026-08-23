<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Protocol;

enum GetValueParameter: string
{
    case DataStatus = 'StanDanych';
    case MessageCode = 'KomunikatKod';
    case Message = 'KomunikatTresc';
    case SessionStatus = 'StatusSesji';
    case ServiceStatus = 'StatusUslugi';
    case ServiceMessage = 'KomunikatUslugi';

    public function requiresSession(): bool
    {
        return ! in_array($this, [self::ServiceStatus, self::ServiceMessage], true);
    }
}
