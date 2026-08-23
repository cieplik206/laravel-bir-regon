<?php

declare(strict_types=1);

namespace cieplik206\BirRegon;

use cieplik206\BirRegon\Data\CompanyData;
use cieplik206\BirRegon\Data\FullCompanyReportData;
use cieplik206\BirRegon\Enums\ReportType;
use cieplik206\BirRegon\Exceptions\BirException;
use Illuminate\Support\Collection;

class BirSearchBuilder extends BirRequestBuilder
{
    public const TYPE_NIP = 'NIP';

    public const TYPE_REGON = 'REGON';

    public const TYPE_KRS = 'KRS';

    private ?ReportType $reportType = null;

    public function __construct(
        #[\SensitiveParameter] BirClientInterface $client,
        #[\SensitiveParameter] private readonly string $identifier,
        private readonly string $identifierType,
    ) {
        parent::__construct($client);
    }

    public function reportType(ReportType $reportType): self
    {
        $this->ensureNotRestoredFromSerialization();

        $this->reportType = $reportType;

        return $this;
    }

    /** @return Collection<int, CompanyData> */
    public function search(): Collection
    {
        return $this->searchWithClient();
    }

    /** @return Collection<int, CompanyData> */
    public function get(): Collection
    {
        return $this->search();
    }

    public function getFullReport(): FullCompanyReportData
    {
        $client = $this->getClient();

        if ($this->reportType === null) {
            throw new BirException('Report type is required to fetch full report.');
        }

        return match ($this->identifierType) {
            self::TYPE_NIP => $client->getFullReportByNip($this->identifier, $this->reportType),
            self::TYPE_KRS => $client->getFullReportByKrs($this->identifier, $this->reportType),
            self::TYPE_REGON => $client->getFullReport($this->identifier, $this->reportType),
            default => throw new BirException('Unsupported search identifier type.'),
        };
    }

    /** @return Collection<int, FullCompanyReportData> */
    public function getFullReports(): Collection
    {
        $client = $this->getClient();

        if ($this->reportType === null) {
            throw new BirException('Report type is required to fetch full reports.');
        }

        $reports = match ($this->identifierType) {
            self::TYPE_NIP => $client->getFullReportsByNip($this->identifier, $this->reportType),
            self::TYPE_KRS => $client->getFullReportsByKrs($this->identifier, $this->reportType),
            self::TYPE_REGON => $client->getFullReports($this->identifier, $this->reportType),
            default => throw new BirException('Unsupported search identifier type.'),
        };

        return new Collection($reports);
    }

    /** @return Collection<int, CompanyData> */
    private function searchWithClient(): Collection
    {
        $client = $this->getClient();

        $companies = match ($this->identifierType) {
            self::TYPE_NIP => $client->searchByNip($this->identifier),
            self::TYPE_REGON => $client->searchByRegon($this->identifier),
            self::TYPE_KRS => $client->searchByKrs($this->identifier),
            default => throw new BirException('Unsupported search identifier type.'),
        };

        return new Collection($companies);
    }
}
