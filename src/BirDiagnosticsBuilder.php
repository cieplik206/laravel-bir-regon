<?php

declare(strict_types=1);

namespace cieplik206\BirRegon;

use cieplik206\BirRegon\Data\DiagnosticsData;

class BirDiagnosticsBuilder extends BirRequestBuilder
{
    public function get(): DiagnosticsData
    {
        return $this->client->getDiagnostics();
    }
}
