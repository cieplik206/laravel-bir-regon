<?php

declare(strict_types=1);

namespace cieplik206\BirRegon;

use cieplik206\BirRegon\Data\CompanyData;
use cieplik206\BirRegon\Data\FullCompanyReportData;
use cieplik206\BirRegon\Enums\ReportType;
use cieplik206\BirRegon\Exceptions\BirException;

class BirSearchBuilder extends BirRequestBuilder
{
    public const TYPE_NIP = 'NIP';

    public const TYPE_REGON = 'REGON';

    public const TYPE_KRS = 'KRS';

    private ?ReportType $reportType = null;

    public function __construct(
        BirClientInterface $client,
        private readonly string $identifier,
        private readonly string $identifierType,
    ) {
        parent::__construct($client);
    }

    public function reportType(ReportType $reportType): self
    {
        $this->reportType = $reportType;

        return $this;
    }

    public function search(): CompanyData
    {
        return $this->searchWithClient($this->client);
    }

    public function get(): CompanyData
    {
        return $this->search();
    }

    public function getFullReport(): FullCompanyReportData
    {
        if ($this->reportType === null) {
            throw new BirException('Report type is required to fetch full report.');
        }

        return match ($this->identifierType) {
            self::TYPE_NIP => $this->client->getFullReportByNip($this->identifier, $this->reportType),
            self::TYPE_KRS => $this->client->getFullReportByKrs($this->identifier, $this->reportType),
            self::TYPE_REGON => $this->client->getFullReport($this->identifier, $this->reportType),
            default => throw new BirException('Unsupported search identifier type.'),
        };
    }

    private function searchWithClient(BirClientInterface $client): CompanyData
    {
        return match ($this->identifierType) {
            self::TYPE_NIP => $client->searchByNip($this->identifier),
            self::TYPE_REGON => $client->searchByRegon($this->identifier),
            self::TYPE_KRS => $client->searchByKrs($this->identifier),
            default => throw new BirException('Unsupported search identifier type.'),
        };
    }
}
