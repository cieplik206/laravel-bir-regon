<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Tests\Support;

use DateTimeImmutable;
use GusApi\GusApi;
use GusApi\SearchReport;

class FakeGusApi extends GusApi
{
    /** @var array<int, array<int, mixed>> */
    public array $calls = [];

    /**
     * @param  array<int, SearchReport>  $searchReports
     * @param  array<int, array<string, string>>  $fullReportData
     * @param  array<int, string>  $bulkReportData
     */
    public function __construct(
        private readonly array $searchReports = [],
        private readonly array $fullReportData = [],
        private readonly array $bulkReportData = [],
        private readonly int $status = 1,
        private readonly string $serviceMessageValue = 'Dostępny',
        private readonly ?DateTimeImmutable $dataStatusValue = null,
        private readonly int $messageCode = 0,
        private readonly string $message = 'Brak błędu',
        private readonly int $sessionStatus = 1,
    ) {}

    public function login(): bool
    {
        $this->calls[] = ['login'];

        return true;
    }

    public function getByNip(string $nip): array
    {
        $this->calls[] = ['getByNip', $nip];

        return $this->searchReports;
    }

    public function getByRegon(string $regon): array
    {
        $this->calls[] = ['getByRegon', $regon];

        return $this->searchReports;
    }

    public function getByKrs(string $krs): array
    {
        $this->calls[] = ['getByKrs', $krs];

        return $this->searchReports;
    }

    public function getByNips(array $nips): array
    {
        $this->calls[] = ['getByNips', $nips];

        return $this->searchReports;
    }

    public function getByKrses(array $krses): array
    {
        $this->calls[] = ['getByKrses', $krses];

        return $this->searchReports;
    }

    public function getByRegons9(array $regons): array
    {
        $this->calls[] = ['getByRegons9', $regons];

        return $this->searchReports;
    }

    public function getByregons14(array $regons): array
    {
        $this->calls[] = ['getByregons14', $regons];

        return $this->searchReports;
    }

    public function getFullReport(SearchReport $searchReport, string $reportName): array
    {
        $this->calls[] = ['getFullReport', $searchReport, $reportName];

        return $this->fullReportData;
    }

    public function getBulkReport(DateTimeImmutable $date, string $reportName): array
    {
        $this->calls[] = ['getBulkReport', $date, $reportName];

        return $this->bulkReportData;
    }

    public function serviceStatus(): int
    {
        $this->calls[] = ['serviceStatus'];

        return $this->status;
    }

    public function serviceMessage(): string
    {
        $this->calls[] = ['serviceMessage'];

        return $this->serviceMessageValue;
    }

    public function dataStatus(): DateTimeImmutable
    {
        $this->calls[] = ['dataStatus'];

        return $this->dataStatusValue ?? new DateTimeImmutable('2026-08-23');
    }

    public function getMessageCode(): int
    {
        $this->calls[] = ['getMessageCode'];

        return $this->messageCode;
    }

    public function getMessage(): string
    {
        $this->calls[] = ['getMessage'];

        return $this->message;
    }

    public function getSessionStatus(): int
    {
        $this->calls[] = ['getSessionStatus'];

        return $this->sessionStatus;
    }
}
