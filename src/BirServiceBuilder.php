<?php

declare(strict_types=1);

namespace cieplik206\BirRegon;

use cieplik206\BirRegon\Data\ServiceStatusData;
use DateTimeImmutable;

class BirServiceBuilder extends BirRequestBuilder
{
    public function get(): ServiceStatusData
    {
        return $this->status();
    }

    public function status(): ServiceStatusData
    {
        return $this->getClient()->getServiceStatus();
    }

    public function dataStatus(): DateTimeImmutable
    {
        return $this->getClient()->getDataStatus();
    }
}
