<?php

declare(strict_types=1);

use cieplik206\BirRegon\BirRegonService;
use cieplik206\BirRegon\Data\BulkReportData;
use cieplik206\BirRegon\Data\CompanyData;
use cieplik206\BirRegon\Data\DiagnosticsData;
use cieplik206\BirRegon\Data\FullCompanyReportData;
use cieplik206\BirRegon\Data\ServiceStatusData;
use cieplik206\BirRegon\Enums\BulkReportType;
use cieplik206\BirRegon\Enums\EntityType;
use cieplik206\BirRegon\Enums\ReportType;
use cieplik206\BirRegon\Enums\Silo;
use cieplik206\BirRegon\Exceptions\BirException;
use cieplik206\BirRegon\Facades\BirRegon;
use cieplik206\BirRegon\Normalization\FullReportNormalizer;
use cieplik206\BirRegon\Tests\Support\StubBirClient;
use Illuminate\Support\Collection;

it('searches for a company by NIP', function (): void {
    $company = makeCompanyData();
    $client = new StubBirClient(company: $company);
    $service = new BirRegonService($client);

    $result = $service->forNip('1234567890')->search();

    expect($result)->toHaveCount(1)
        ->and($result->sole())->toBe($company)
        ->and($client->calls)->toBe([['searchByNip', '1234567890']]);
});

it('returns every company for each singular identifier builder', function (): void {
    $firstCompany = makeCompanyData([
        'regon' => '123456789',
        'name' => 'CEIDG activity',
        'silo' => Silo::Ceidg,
    ]);
    $secondCompany = makeCompanyData([
        'regon' => '987654321',
        'name' => 'Agricultural activity',
        'silo' => Silo::Agriculture,
    ]);
    $client = new StubBirClient(companies: [$firstCompany, $secondCompany]);
    $service = new BirRegonService($client);

    $byNip = $service->forNip('1234567890')->get();
    $byRegon = $service->forRegon('123456789')->search();
    $byKrs = $service->forKrs('0000123456')->get();

    expect($byNip)->toHaveCount(2)
        ->and($byRegon)->toHaveCount(2)
        ->and($byKrs)->toHaveCount(2)
        ->and($byNip->pluck('regon')->all())->toBe(['123456789', '987654321'])
        ->and($byRegon->pluck('regon')->all())->toBe(['123456789', '987654321'])
        ->and($byKrs->pluck('regon')->all())->toBe(['123456789', '987654321'])
        ->and($client->calls)->toBe([
            ['searchByNip', '1234567890'],
            ['searchByRegon', '123456789'],
            ['searchByKrs', '0000123456'],
        ]);
});

it('routes sandbox queries through the stable sandbox client', function (): void {
    $company = makeCompanyData(['regon' => '123456789']);
    $productionClient = new StubBirClient;
    $sandboxClient = new StubBirClient(company: $company);
    $service = new BirRegonService($productionClient, $sandboxClient);
    $sandbox = $service->sandbox();

    $firstResult = $sandbox->forRegon('123456789')->get();
    $secondResult = $sandbox->forRegon('123456789')->get();

    expect($firstResult)->toHaveCount(1)
        ->and($firstResult->sole())->toBe($company)
        ->and($secondResult)->toHaveCount(1)
        ->and($secondResult->sole())->toBe($company)
        ->and($service->sandbox())->toBe($sandbox)
        ->and($sandbox->sandbox())->toBe($sandbox)
        ->and($productionClient->calls)->toBe([])
        ->and($sandboxClient->calls)->toBe([
            ['searchByRegon', '123456789'],
            ['searchByRegon', '123456789'],
        ]);
});

it('fetches a full report after searching by NIP', function (): void {
    $company = makeCompanyData(['regon' => '111222333']);
    $reportData = [['praw_regon9' => $company->regon]];
    $report = new FullCompanyReportData(
        $company,
        ReportType::Organization,
        $reportData,
        (new FullReportNormalizer)->normalize(ReportType::Organization, $reportData),
    );
    $client = new StubBirClient(company: $company, report: $report);
    $service = new BirRegonService($client);

    $result = $service->forNip('1234567890')
        ->reportType(ReportType::Organization)
        ->getFullReport();

    expect($result)->toBe($report)
        ->and($client->calls)->toBe([
            ['getFullReportByNip', '1234567890', ReportType::Organization],
        ]);
});

it('fetches every full report through each singular identifier builder', function (
    Closure $builder,
    array $expectedCall,
): void {
    $firstCompany = makeCompanyData(['regon' => '111222333']);
    $secondCompany = makeCompanyData(['regon' => '444555666']);
    $firstData = [['praw_regon9' => $firstCompany->regon]];
    $secondData = [['praw_regon9' => $secondCompany->regon]];
    $firstReport = new FullCompanyReportData(
        $firstCompany,
        ReportType::Organization,
        $firstData,
        (new FullReportNormalizer)->normalize(ReportType::Organization, $firstData),
    );
    $secondReport = new FullCompanyReportData(
        $secondCompany,
        ReportType::Organization,
        $secondData,
        (new FullReportNormalizer)->normalize(ReportType::Organization, $secondData),
    );
    $client = new StubBirClient(reports: [$firstReport, $secondReport]);
    $service = new BirRegonService($client);

    $reports = $builder($service)
        ->reportType(ReportType::Organization)
        ->getFullReports();

    expect($reports)->toBeInstanceOf(Collection::class)->toHaveCount(2)
        ->and($reports->all())->toBe([$firstReport, $secondReport])
        ->and($client->calls)->toBe([$expectedCall]);
})->with([
    'NIP' => [
        static fn (BirRegonService $service) => $service->forNip('1234567890'),
        ['getFullReportsByNip', '1234567890', ReportType::Organization],
    ],
    'KRS' => [
        static fn (BirRegonService $service) => $service->forKrs('0000123456'),
        ['getFullReportsByKrs', '0000123456', ReportType::Organization],
    ],
    'REGON' => [
        static fn (BirRegonService $service) => $service->forRegon('123456789'),
        ['getFullReports', '123456789', ReportType::Organization],
    ],
]);

it('requires a report type before fetching a full report', function (): void {
    $service = new BirRegonService(new StubBirClient);

    expect(fn () => $service->forRegon('123456789')->getFullReport())
        ->toThrow(BirException::class, 'Report type is required');
});

it('requires a report type before fetching plural full reports', function (): void {
    $service = new BirRegonService(new StubBirClient);

    expect(fn () => $service->forRegon('123456789')->getFullReports())
        ->toThrow(BirException::class, 'Report type is required');
});

it('exposes the fluent API through the facade', function (): void {
    $company = makeCompanyData();
    $client = new StubBirClient(company: $company);

    BirRegon::swap(new BirRegonService($client));

    $result = BirRegon::forNip('1234567890')->search();
    $loggedOut = BirRegon::logout();
    $facadeDoc = (new ReflectionClass(BirRegon::class))->getDocComment();

    expect($result)->toHaveCount(1)
        ->and($result->sole())->toBe($company)
        ->and($loggedOut)->toBeTrue()
        ->and($facadeDoc)->toBeString()->toContain('@method static bool logout()')
        ->and($client->calls)->toBe([
            ['searchByNip', '1234567890'],
            ['logout'],
        ]);
});

it('supports all fluent batch search variants', function (): void {
    $company = makeCompanyData();
    $client = new StubBirClient(companies: [$company]);
    $service = new BirRegonService($client);

    $byNips = $service->forNips(['1234567890'])->get();
    $byKrsNumbers = $service->forKrsNumbers(['0000123456'])->search();
    $byRegons9 = $service->forRegons9(['123456789'])->get();
    $byRegons14 = $service->forRegons14(['12345678901234'])->get();

    expect($byNips)->toHaveCount(1)
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
        'type' => EntityType::LegalUnit,
        'regon14' => null,
        'nipStatus' => null,
        'silo' => Silo::LegalUnits,
        'activityEndDate' => null,
        'postCity' => null,
    ], $overrides);

    return CompanyData::from($data);
}
