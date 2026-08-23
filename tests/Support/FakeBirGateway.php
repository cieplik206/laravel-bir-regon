<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Tests\Support;

use cieplik206\BirRegon\Contracts\BirGatewayInterface;
use cieplik206\BirRegon\Enums\BulkReportType;
use cieplik206\BirRegon\Enums\ReportType;
use cieplik206\BirRegon\Protocol\DiagnosticsSnapshot;
use cieplik206\BirRegon\Protocol\GetValueParameter;
use cieplik206\BirRegon\Protocol\SearchCriteria;
use cieplik206\BirRegon\Protocol\SearchResult;
use DateTimeImmutable;
use Throwable;

final class FakeBirGateway implements BirGatewayInterface
{
    /** @var list<array<int, mixed>> */
    public array $calls = [];

    /** @var list<list<SearchResult>|Throwable> */
    private array $searchQueue = [];

    /** @var list<list<array<string, string>>|Throwable> */
    private array $fullReportQueue = [];

    /** @var list<list<string>|Throwable> */
    private array $bulkReportQueue = [];

    /** @var list<string|Throwable> */
    private array $valueQueue = [];

    /**
     * @param  list<SearchResult>  $searchResults
     * @param  list<array<string, string>>  $fullReportData
     * @param  list<string>  $bulkReportData
     * @param  array<string, string>  $values
     */
    public function __construct(
        private readonly array $searchResults = [],
        private readonly array $fullReportData = [],
        private readonly array $bulkReportData = [],
        private readonly array $values = [],
        private readonly bool $logoutResult = true,
    ) {}

    /** @param list<SearchResult>|Throwable $result */
    public function queueSearch(array|Throwable $result): self
    {
        $this->searchQueue[] = $result;

        return $this;
    }

    /** @param list<array<string, string>>|Throwable $result */
    public function queueFullReport(array|Throwable $result): self
    {
        $this->fullReportQueue[] = $result;

        return $this;
    }

    /** @param list<string>|Throwable $result */
    public function queueBulkReport(array|Throwable $result): self
    {
        $this->bulkReportQueue[] = $result;

        return $this;
    }

    public function queueValue(string|Throwable $result): self
    {
        $this->valueQueue[] = $result;

        return $this;
    }

    public function search(SearchCriteria $criteria): array
    {
        $this->calls[] = ['search', $criteria];
        $result = array_shift($this->searchQueue) ?? $this->searchResults;

        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }

    public function fullReport(string $regon, ReportType $reportType): array
    {
        $this->calls[] = ['fullReport', $regon, $reportType];
        $result = array_shift($this->fullReportQueue) ?? $this->fullReportData;

        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }

    public function bulkReport(DateTimeImmutable $date, BulkReportType $reportType): array
    {
        $this->calls[] = ['bulkReport', $date, $reportType];
        $result = array_shift($this->bulkReportQueue) ?? $this->bulkReportData;

        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }

    public function getValue(GetValueParameter $parameter): string
    {
        $this->calls[] = ['getValue', $parameter];
        $result = array_shift($this->valueQueue) ?? ($this->values[$parameter->value] ?? '');

        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }

    public function diagnostics(): DiagnosticsSnapshot
    {
        $this->calls[] = ['diagnostics'];
        $rawMessageCode = $this->values[GetValueParameter::MessageCode->value] ?? '';
        $rawSessionStatus = $this->values[GetValueParameter::SessionStatus->value] ?? '';

        return new DiagnosticsSnapshot(
            messageCode: ctype_digit($rawMessageCode) ? (int) $rawMessageCode : -1,
            message: $this->values[GetValueParameter::Message->value] ?? '',
            sessionStatus: preg_match('/^[01]$/D', $rawSessionStatus) === 1
                ? (int) $rawSessionStatus
                : -1,
        );
    }

    public function logout(): bool
    {
        $this->calls[] = ['logout'];

        return $this->logoutResult;
    }
}
