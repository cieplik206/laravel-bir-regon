<?php

declare(strict_types=1);

use cieplik206\BirRegon\BirRegonService;
use cieplik206\BirRegon\Data\BulkReportData;
use cieplik206\BirRegon\Data\CompanyData;
use cieplik206\BirRegon\Data\DiagnosticsData;
use cieplik206\BirRegon\Data\FullCompanyReportData;
use cieplik206\BirRegon\Data\ServiceStatusData;
use cieplik206\BirRegon\Enums\BulkReportType;
use cieplik206\BirRegon\Enums\Environment;
use cieplik206\BirRegon\Enums\ReportType;
use cieplik206\BirRegon\Exceptions\BirException;
use cieplik206\BirRegon\Facades\BirRegon;
use cieplik206\BirRegon\Tests\Support\StubBirClient;
use Illuminate\Support\Collection;

it('searches for a company by NIP', function (): void {
    $company = makeCompanyData();
    $client = new StubBirClient(company: $company);
    $service = new BirRegonService($client);

    $result = $service->forNip('1234567890')->search();

    expect($result)->toBe($company)
        ->and($client->calls)->toBe([['searchByNip', '1234567890']]);
});

it('uses a selected environment when searching by REGON', function (): void {
    $company = makeCompanyData(['regon' => '123456789']);
    $scopedClient = new StubBirClient(company: $company);
    $client = new StubBirClient(scoped: $scopedClient);
    $service = new BirRegonService($client);

    $result = $service->forRegon('123456789')->inDev()->get();

    expect($result)->toBe($company)
        ->and($client->lastEnvironment)->toBe(Environment::Development)
        ->and($scopedClient->calls)->toBe([['searchByRegon', '123456789']]);
});

it('fetches a full report after searching by NIP', function (): void {
    $company = makeCompanyData(['regon' => '111222333']);
    $report = new FullCompanyReportData($company, [['status' => 'ok']]);
    $client = new StubBirClient(company: $company, report: $report);
    $service = new BirRegonService($client);

    $result = $service->forNip('1234567890')
        ->reportType(ReportType::Organization)
        ->getFullReport();

    expect($result)->toBe($report)
        ->and($client->calls)->toBe([
            ['searchByNip', '1234567890'],
            ['getFullReport', '111222333', ReportType::Organization],
        ]);
});

it('requires a report type before fetching a full report', function (): void {
    $service = new BirRegonService(new StubBirClient);

    expect(fn () => $service->forRegon('123456789')->getFullReport())
        ->toThrow(BirException::class, 'Report type is required');
});

it('exposes the fluent API through the facade', function (): void {
    $company = makeCompanyData();
    $client = new StubBirClient(company: $company);

    BirRegon::swap(new BirRegonService($client));

    $result = BirRegon::forNip('1234567890')->search();

    expect($result)->toBe($company)
        ->and($client->calls)->toBe([['searchByNip', '1234567890']]);
});

it('supports all fluent batch search variants', function (): void {
    $company = makeCompanyData();
    $client = new StubBirClient(companies: [$company]);
    $service = new BirRegonService($client);

    $byNips = $service->forNips(['1234567890'])->get();
    $byKrsNumbers = $service->forKrsNumbers(['0000123456'])->search();
    $byRegons9 = $service->forRegons9(['123456789'])->get();
    $byRegons14 = $service->forRegons14(['12345678901234'])->get();

    expect($byNips)->toBeInstanceOf(Collection::class)->toHaveCount(1)
        ->and($byKrsNumbers)->toHaveCount(1)
        ->and($byRegons9)->toHaveCount(1)
        ->and($byRegons14)->toHaveCount(1)
        ->and($client->calls)->toBe([
            ['searchByNips', ['1234567890']],
            ['searchByKrsNumbers', ['0000123456']],
            ['searchByRegons9', ['123456789']],
            ['searchByRegons14', ['12345678901234']],
        ]);
});

it('fetches a bulk report through a fluent date builder', function (): void {
    $date = new DateTimeImmutable('2026-08-22');
    $reportType = BulkReportType::NewLegalEntitiesAndNaturalPersons;
    $report = new BulkReportData($date, $reportType, ['row']);
    $client = new StubBirClient(bulkReport: $report);
    $service = new BirRegonService($client);

    $result = $service->forDate($date)
        ->reportType($reportType)
        ->get();

    expect($result)->toBe($report)
        ->and($client->calls)->toBe([['getBulkReport', $date, $reportType]]);
});

it('requires a report type before fetching a bulk report', function (): void {
    $service = new BirRegonService(new StubBirClient);

    expect(fn () => $service->forDate(new DateTimeImmutable)->get())
        ->toThrow(BirException::class, 'Report type is required');
});

it('provides service status, data status and diagnostics through fluent builders', function (): void {
    $serviceStatus = new ServiceStatusData(1, 'Dostępny');
    $dataStatus = new DateTimeImmutable('2026-08-22');
    $diagnostics = new DiagnosticsData(0, 'Brak błędu', 1);
    $client = new StubBirClient(
        serviceStatus: $serviceStatus,
        dataStatus: $dataStatus,
        diagnostics: $diagnostics,
    );
    $service = new BirRegonService($client);

    expect($service->service()->get())->toBe($serviceStatus)
        ->and($service->service()->dataStatus())->toBe($dataStatus)
        ->and($service->diagnostics()->get())->toBe($diagnostics)
        ->and($serviceStatus->isAvailable())->toBeTrue()
        ->and($client->calls)->toBe([
            ['getServiceStatus'],
            ['getDataStatus'],
            ['getDiagnostics'],
        ]);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function makeCompanyData(array $overrides = []): CompanyData
{
    $data = array_merge([
        'regon' => '123456789',
        'nip' => '1234567890',
        'name' => 'Test Company',
        'city' => null,
        'postalCode' => null,
        'street' => null,
        'buildingNumber' => null,
        'apartmentNumber' => null,
        'province' => null,
        'district' => null,
        'commune' => null,
        'type' => null,
        'regon14' => null,
        'nipStatus' => null,
        'silo' => 0,
        'activityEndDate' => null,
        'postCity' => null,
    ], $overrides);

    return CompanyData::from($data);
}
