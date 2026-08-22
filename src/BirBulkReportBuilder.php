<?php

declare(strict_types=1);

namespace cieplik206\BirRegon;

use cieplik206\BirRegon\Data\BulkReportData;
use cieplik206\BirRegon\Enums\BulkReportType;
use cieplik206\BirRegon\Exceptions\BirException;
use DateTimeImmutable;

class BirBulkReportBuilder extends BirRequestBuilder
{
    private ?BulkReportType $reportType = null;

    public function __construct(
        BirClientInterface $client,
        private readonly DateTimeImmutable $date,
    ) {
        parent::__construct($client);
    }

    public function reportType(BulkReportType $reportType): self
    {
        $this->reportType = $reportType;

        return $this;
    }

    public function get(): BulkReportData
    {
        return $this->getBulkReport();
    }

    public function getBulkReport(): BulkReportData
    {
        if ($this->reportType === null) {
            throw new BirException('Report type is required to fetch bulk report.');
        }

        return $this->resolveClient()->getBulkReport($this->date, $this->reportType);
    }
}
