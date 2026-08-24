<?php

declare(strict_types=1);

use cieplik206\BirRegon\BirClient;
use cieplik206\BirRegon\Enums\EntityType;
use cieplik206\BirRegon\Enums\ReportType;
use cieplik206\BirRegon\Enums\Silo;
use cieplik206\BirRegon\Exceptions\BirAmbiguousResultException;
use cieplik206\BirRegon\Exceptions\BirValidationException;
use cieplik206\BirRegon\Protocol\SearchCriteria;
use cieplik206\BirRegon\Protocol\SearchResult;
use cieplik206\BirRegon\Tests\Support\FakeBirGateway;

it('requests a full report for every compatible result', function (
    Closure $operation,
    SearchCriteria $criteria,
): void {
    $ceidg = makeFullReportMultiplicitySearchResult(
        regon: '123456789',
        silo: Silo::Ceidg,
        name: 'CEIDG activity',
    );
    $agriculture = makeFullReportMultiplicitySearchResult(
        regon: '987654321',
        silo: Silo::Agriculture,
        name: 'Agricultural activity',
    );
    $incompatible = makeFullReportMultiplicitySearchResult(
        regon: '111222333',
        type: EntityType::LegalUnit,
        silo: Silo::LegalUnits,
        name: 'Legal entity',
    );
    $gateway = (new FakeBirGateway(searchResults: [$ceidg, $incompatible, $agriculture]))
        ->queueFullReport([['Nazwa' => 'CEIDG activity']])
        ->queueFullReport([['Nazwa' => 'Agricultural activity']]);
    $client = new BirClient($gateway);

    $reports = $operation($client);

    expect($reports)->toHaveCount(2)
        ->and(array_map(
            static fn ($report): string => $report->basicData->regon,
            $reports,
        ))->toBe(['123456789', '987654321'])
        ->and($reports[0]->reportData)->toBe([['Nazwa' => 'CEIDG activity']])
        ->and($reports[1]->reportData)->toBe([['Nazwa' => 'Agricultural activity']])
        ->and($gateway->calls)->toEqual([
            ['search', $criteria],
            ['fullReport', '123456789', ReportType::NaturalPerson],
            ['fullReport', '987654321', ReportType::NaturalPerson],
        ]);
})->with([
    'NIP' => [
        static fn (BirClient $client): array => $client->getFullReportsByNip(
            '1234567890',
            ReportType::NaturalPerson,
        ),
        SearchCriteria::nip('1234567890'),
    ],
    'KRS' => [
        static fn (BirClient $client): array => $client->getFullReportsByKrs(
            '0000123456',
            ReportType::NaturalPerson,
        ),
        SearchCriteria::krs('0000123456'),
    ],
    'REGON' => [
        static fn (BirClient $client): array => $client->getFullReports(
            '123456789',
            ReportType::NaturalPerson,
        ),
        SearchCriteria::regon('123456789'),
    ],
]);

it('requests the general natural-person report for a historical silo result', function (): void {
    $result = makeFullReportMultiplicitySearchResult(
        regon: '771504670',
        silo: Silo::DeletedBefore20141108,
        name: 'Historical activity',
    );
    $reportData = [[
        'fiz_regon9' => '771504670',
        'fiz_dzialalnoscSkreslonaDo20141108' => '1',
    ]];
    $gateway = (new FakeBirGateway(searchResults: [$result]))
        ->queueFullReport($reportData);
    $client = new BirClient($gateway);

    $report = $client->getFullReport('771504670', ReportType::NaturalPerson);

    expect($report->basicData->regon)->toBe('771504670')
        ->and($report->reportData)->toBe($reportData)
        ->and($gateway->calls)->toEqual([
            ['search', SearchCriteria::regon('771504670')],
            ['fullReport', '771504670', ReportType::NaturalPerson],
        ]);
});

it('rejects ambiguous singular full reports without making a report request', function (
    Closure $operation,
    SearchCriteria $criteria,
    string $identifierType,
): void {
    $gateway = new FakeBirGateway(searchResults: [
        makeFullReportMultiplicitySearchResult('123456789', Silo::Ceidg),
        makeFullReportMultiplicitySearchResult('987654321', Silo::Agriculture),
    ]);
    $client = new BirClient($gateway);
    $exception = null;

    try {
        $operation($client);
    } catch (BirAmbiguousResultException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(BirAmbiguousResultException::class)
        ->and($exception->identifierType)->toBe($identifierType)
        ->and($exception->compatibleTargetCount)->toBe(2)
        ->and(array_key_exists('identifier', get_object_vars($exception)))->toBeFalse()
        ->and($exception->getMessage())->toBe(sprintf(
            'GUS BIR returned 2 distinct compatible report targets for the %s identifier. Use the plural full-report method to retrieve every result.',
            $identifierType,
        ))
        ->and($gateway->calls)->toEqual([
            ['search', $criteria],
        ]);
})->with([
    'NIP' => [
        static fn (BirClient $client) => $client->getFullReportByNip(
            '1234567890',
            ReportType::NaturalPerson,
        ),
        SearchCriteria::nip('1234567890'),
        'NIP',
    ],
    'KRS' => [
        static fn (BirClient $client) => $client->getFullReportByKrs(
            '0000123456',
            ReportType::NaturalPerson,
        ),
        SearchCriteria::krs('0000123456'),
        'KRS',
    ],
    'REGON' => [
        static fn (BirClient $client) => $client->getFullReport(
            '123456789',
            ReportType::NaturalPerson,
        ),
        SearchCriteria::regon('123456789'),
        'REGON',
    ],
]);

it('rejects a plural full report when no result is compatible', function (): void {
    $gateway = new FakeBirGateway(searchResults: [
        makeFullReportMultiplicitySearchResult(
            regon: '123456789',
            type: EntityType::LegalUnit,
            silo: Silo::LegalUnits,
        ),
    ]);
    $client = new BirClient($gateway);

    expect(fn () => $client->getFullReportsByNip(
        '1234567890',
        ReportType::NaturalPerson,
    ))
        ->toThrow(BirValidationException::class, 'is not compatible')
        ->and($gateway->calls)->toEqual([
            ['search', SearchCriteria::nip('1234567890')],
        ]);
});

it('requests a shared full report only once for duplicate compatible REGON records', function (): void {
    $gateway = (new FakeBirGateway(searchResults: [
        makeFullReportMultiplicitySearchResult('123456789', Silo::Ceidg, name: 'CEIDG activity'),
        makeFullReportMultiplicitySearchResult(
            '123456789',
            Silo::Agriculture,
            name: 'Agricultural activity',
        ),
    ]))->queueFullReport([['Nazwa' => 'Shared report']]);
    $client = new BirClient($gateway);

    $reports = $client->getFullReportsByNip('1234567890', ReportType::NaturalPerson);

    expect($reports)->toHaveCount(1)
        ->and($reports[0]->basicData->name)->toBe('CEIDG activity')
        ->and($reports[0]->reportData)->toBe([['Nazwa' => 'Shared report']])
        ->and($gateway->calls)->toEqual([
            ['search', SearchCriteria::nip('1234567890')],
            ['fullReport', '123456789', ReportType::NaturalPerson],
        ]);
});

it('allows a singular full report for duplicate compatible records sharing one REGON', function (): void {
    $gateway = (new FakeBirGateway(searchResults: [
        makeFullReportMultiplicitySearchResult('123456789', Silo::Ceidg, name: 'CEIDG activity'),
        makeFullReportMultiplicitySearchResult(
            '123456789',
            Silo::Agriculture,
            name: 'Agricultural activity',
        ),
    ]))->queueFullReport([['Nazwa' => 'Shared report']]);
    $client = new BirClient($gateway);

    $report = $client->getFullReportByNip('1234567890', ReportType::NaturalPerson);

    expect($report->basicData->name)->toBe('CEIDG activity')
        ->and($report->reportData)->toBe([['Nazwa' => 'Shared report']])
        ->and($gateway->calls)->toEqual([
            ['search', SearchCriteria::nip('1234567890')],
            ['fullReport', '123456789', ReportType::NaturalPerson],
        ]);
});

it('reports distinct targets after deduplicating ambiguous compatible records', function (): void {
    $gateway = new FakeBirGateway(searchResults: [
        makeFullReportMultiplicitySearchResult('123456789', Silo::Ceidg),
        makeFullReportMultiplicitySearchResult('123456789', Silo::Agriculture),
        makeFullReportMultiplicitySearchResult('987654321', Silo::Other),
    ]);
    $client = new BirClient($gateway);
    $exception = null;

    try {
        $client->getFullReportByNip('1234567890', ReportType::NaturalPerson);
    } catch (BirAmbiguousResultException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(BirAmbiguousResultException::class)
        ->and($exception->compatibleTargetCount)->toBe(2)
        ->and($exception->getMessage())->toContain('2 distinct compatible report targets')
        ->and($gateway->calls)->toEqual([
            ['search', SearchCriteria::nip('1234567890')],
        ]);
});

it('uses the complete REGON14 when requesting a local-unit full report', function (
    EntityType $entityType,
    Silo $silo,
    ReportType $reportType,
    string $regon14,
): void {
    $gateway = (new FakeBirGateway(searchResults: [
        makeFullReportMultiplicitySearchResult(
            regon: $regon14,
            silo: $silo,
            type: $entityType,
            name: 'Local unit',
        ),
    ]))->queueFullReport([['Nazwa' => 'Local unit']]);
    $client = new BirClient($gateway);

    $report = $client->getFullReportByNip('1234567890', $reportType);

    expect($report->basicData->regon)->toBe($regon14)
        ->and($report->basicData->regon14)->toBe($regon14)
        ->and($report->reportType)->toBe($reportType)
        ->and($gateway->calls)->toEqual([
            ['search', SearchCriteria::nip('1234567890')],
            ['fullReport', $regon14, $reportType],
        ]);
})->with([
    'LF natural-person local unit' => [
        EntityType::NaturalPersonLocalUnit,
        Silo::Ceidg,
        ReportType::NaturalPersonLocal,
        '01234567800001',
    ],
    'LP legal-unit local unit using BIR121' => [
        EntityType::LegalUnitLocalUnit,
        Silo::LegalUnits,
        ReportType::OrganizationLocalWithNip,
        '01234567800002',
    ],
]);

function makeFullReportMultiplicitySearchResult(
    string $regon,
    Silo $silo,
    EntityType $type = EntityType::NaturalPerson,
    string $name = 'Test activity',
): SearchResult {
    return new SearchResult(
        regon: $regon,
        nip: '1234567890',
        name: $name,
        city: 'Warszawa',
        postalCode: '00-001',
        street: 'Testowa',
        buildingNumber: '1',
        apartmentNumber: null,
        province: 'MAZOWIECKIE',
        district: 'Warszawa',
        commune: 'Warszawa',
        type: $type,
        regon14: strlen($regon) === 14 ? $regon : null,
        nipStatus: null,
        silo: $silo,
        activityEndDate: null,
        postCity: 'Warszawa',
    );
}
