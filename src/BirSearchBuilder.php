<?php

declare(strict_types=1);

namespace cieplik206\BirRegon;

use cieplik206\BirRegon\Data\CompanyData;
use cieplik206\BirRegon\Data\FullCompanyReportData;
use cieplik206\BirRegon\Enums\ReportType;
use cieplik206\BirRegon\Exceptions\BirAmbiguousSearchResultException;
use cieplik206\BirRegon\Exceptions\BirException;
use cieplik206\BirRegon\Exceptions\BirNotFoundException;
use Illuminate\Support\Collection;
use SensitiveParameterValue;

class BirSearchBuilder extends BirRequestBuilder
{
    public const TYPE_NIP = 'NIP';

    public const TYPE_REGON = 'REGON';

    public const TYPE_KRS = 'KRS';

    private readonly SensitiveParameterValue $identifier;

    private ?ReportType $reportType = null;

    public function __construct(
        #[\SensitiveParameter] BirClientInterface $client,
        #[\SensitiveParameter] string $identifier,
        private readonly string $identifierType,
    ) {
        parent::__construct($client);

        $this->identifier = new SensitiveParameterValue($identifier);
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
        return new Collection($this->searchWithClient());
    }

    /** @return Collection<int, CompanyData> */
    public function get(): Collection
    {
        return $this->search();
    }

    /**
     * @throws BirAmbiguousSearchResultException
     * @throws BirNotFoundException
     */
    public function sole(): CompanyData
    {
        $companies = $this->searchWithClient();
        $resultCount = count($companies);

        if ($resultCount === 0) {
            throw new BirNotFoundException($this->identifierType);
        }

        if ($resultCount > 1) {
            throw new BirAmbiguousSearchResultException($this->identifierType, $resultCount);
        }

        return $companies[0];
    }

    public function getFullReport(): FullCompanyReportData
    {
        $client = $this->getClient();

        if ($this->reportType === null) {
            throw new BirException('Report type is required to fetch full report.');
        }

        return match ($this->identifierType) {
            self::TYPE_NIP => $client->getFullReportByNip($this->identifier(), $this->reportType),
            self::TYPE_KRS => $client->getFullReportByKrs($this->identifier(), $this->reportType),
            self::TYPE_REGON => $client->getFullReport($this->identifier(), $this->reportType),
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
            self::TYPE_NIP => $client->getFullReportsByNip($this->identifier(), $this->reportType),
            self::TYPE_KRS => $client->getFullReportsByKrs($this->identifier(), $this->reportType),
            self::TYPE_REGON => $client->getFullReports($this->identifier(), $this->reportType),
            default => throw new BirException('Unsupported search identifier type.'),
        };

        return new Collection($reports);
    }

    /** @return list<CompanyData> */
    private function searchWithClient(): array
    {
        $client = $this->getClient();

        $companies = match ($this->identifierType) {
            self::TYPE_NIP => $client->searchByNip($this->identifier()),
            self::TYPE_REGON => $client->searchByRegon($this->identifier()),
            self::TYPE_KRS => $client->searchByKrs($this->identifier()),
            default => throw new BirException('Unsupported search identifier type.'),
        };

        return $companies;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        if ($this->wasRestoredFromSerialization()) {
            return [
                'client' => '[UNAVAILABLE]',
                'identifier' => '[UNAVAILABLE]',
                'identifierType' => '[UNAVAILABLE]',
                'reportType' => '[UNAVAILABLE]',
            ];
        }

        return [
            'client' => '[HIDDEN]',
            'identifier' => '[REDACTED]',
            'identifierType' => $this->identifierType,
            'reportType' => $this->reportType === null
                ? '[NONE]'
                : $this->reportType->value,
        ];
    }

    private function identifier(): string
    {
        $identifier = $this->identifier->getValue();

        if (! is_string($identifier)) {
            throw new \LogicException('The BIR search identifier is unavailable.');
        }

        return $identifier;
    }
}
