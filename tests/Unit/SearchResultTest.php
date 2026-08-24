<?php

declare(strict_types=1);

use cieplik206\BirRegon\Enums\EntityType;
use cieplik206\BirRegon\Enums\NipStatus;
use cieplik206\BirRegon\Enums\Silo;
use cieplik206\BirRegon\Protocol\SearchResult;

it('rejects malformed identifiers and undocumented enum values', function (
    array $overrides,
): void {
    $record = array_merge([
        'Regon' => '012345678',
        'Nip' => '0123456789',
        'Nazwa' => 'Fixture company',
        'Typ' => 'P',
        'SilosID' => '6',
    ], $overrides);

    expect(SearchResult::tryFromRecord($record))->toBeNull();
})->with([
    'short NIP' => [['Nip' => '123']],
    'non-numeric NIP' => [['Nip' => '012345678A']],
    'missing entity type' => [['Typ' => '']],
    'lowercase entity type' => [['Typ' => 'p']],
    'unknown entity type' => [['Typ' => 'X']],
    'partially numeric silo' => [['SilosID' => '6garbage']],
    'missing silo' => [['SilosID' => '']],
    'unknown silo' => [['SilosID' => '5']],
    'undocumented NIP status' => [['StatusNip' => 'Aktywny']],
]);

it('accepts the XML Schema integer lexical forms used by SilosID', function (
    string $silo,
): void {
    $result = SearchResult::tryFromRecord([
        'Regon' => '012345678',
        'Nazwa' => 'Fixture company',
        'Typ' => 'P',
        'SilosID' => $silo,
    ]);

    expect($result?->silo)->toBe(Silo::LegalUnits);
})->with([
    'canonical' => ['6'],
    'leading zeroes' => ['006'],
    'explicit plus sign' => ['+6'],
    'collapsed XML whitespace' => [" \t+006\r\n"],
]);

it('maps every documented entity type', function (
    string $raw,
    string $regon,
    string $rawSilo,
    EntityType $expected,
): void {
    $result = SearchResult::tryFromRecord([
        'Regon' => $regon,
        'Nazwa' => 'Fixture company',
        'Typ' => $raw,
        'SilosID' => $rawSilo,
    ]);

    expect($result)->not->toBeNull()
        ->and($result?->type)->toBe($expected);
})->with([
    'legal unit' => ['P', '012345678', '6', EntityType::LegalUnit],
    'natural person' => ['F', '012345678', '1', EntityType::NaturalPerson],
    'legal-unit local unit' => ['LP', '01234567800001', '6', EntityType::LegalUnitLocalUnit],
    'natural person local unit' => ['LF', '01234567800001', '1', EntityType::NaturalPersonLocalUnit],
]);

it('maps every documented silo', function (
    string $raw,
    string $rawType,
    Silo $expected,
): void {
    $result = SearchResult::tryFromRecord([
        'Regon' => '012345678',
        'Nazwa' => 'Fixture company',
        'Typ' => $rawType,
        'SilosID' => $raw,
    ]);

    expect($result)->not->toBeNull()
        ->and($result?->silo)->toBe($expected);
})->with([
    'CEIDG' => ['1', 'F', Silo::Ceidg],
    'agricultural activity' => ['2', 'F', Silo::Agriculture],
    'other activity' => ['3', 'F', Silo::Other],
    'deleted before 2014-11-08' => ['4', 'F', Silo::DeletedBefore20141108],
    'legal units' => ['6', 'P', Silo::LegalUnits],
]);

it('rejects entity types paired with an impossible REGON length or silo', function (
    string $type,
    string $regon,
    string $silo,
): void {
    expect(SearchResult::tryFromRecord([
        'Regon' => $regon,
        'Nazwa' => 'Fixture company',
        'Typ' => $type,
        'SilosID' => $silo,
    ]))->toBeNull();
})->with([
    'P with REGON14' => ['P', '01234567800001', '6'],
    'F with REGON14' => ['F', '01234567800001', '1'],
    'LP with REGON9' => ['LP', '012345678', '6'],
    'LF with REGON9' => ['LF', '012345678', '1'],
    'P with a natural-person silo' => ['P', '012345678', '1'],
    'LP with a natural-person silo' => ['LP', '01234567800001', '4'],
    'F with the legal-entity silo' => ['F', '012345678', '6'],
    'LF with the legal-entity silo' => ['LF', '01234567800001', '6'],
]);

it('maps documented NIP statuses and treats an empty or missing status as none', function (
    ?string $raw,
    ?NipStatus $expected,
): void {
    $record = [
        'Regon' => '012345678',
        'Nazwa' => 'Fixture company',
        'Typ' => 'P',
        'SilosID' => '6',
    ];

    if ($raw !== null) {
        $record['StatusNip'] = $raw;
    }

    $result = SearchResult::tryFromRecord($record);

    expect($result)->not->toBeNull()
        ->and($result?->nipStatus)->toBe($expected);
})->with([
    'missing' => [null, null],
    'empty' => ['', null],
    'revoked' => ['Uchylony', NipStatus::Revoked],
    'invalidated' => ['Unieważniony', NipStatus::Invalidated],
]);

it('accepts a missing optional NIP and activity end date', function (): void {
    $result = SearchResult::tryFromRecord([
        'Regon' => '012345678',
        'Nazwa' => 'Fixture company',
        'Typ' => 'P',
        'SilosID' => '6',
    ]);

    expect($result)->not->toBeNull()
        ->and($result?->nip)->toBeNull()
        ->and($result?->regon14)->toBeNull()
        ->and($result?->activityEndDate)->toBeNull();
});

it('exposes REGON14 only when GUS returned an actual 14-digit identifier', function (): void {
    $result = SearchResult::tryFromRecord([
        'Regon' => '01234567800001',
        'Nazwa' => 'Fixture local unit',
        'Typ' => 'LP',
        'SilosID' => '6',
    ]);

    expect($result)->not->toBeNull()
        ->and($result?->regon)->toBe('01234567800001')
        ->and($result?->regon14)->toBe('01234567800001');
});

it('accepts supported GUS xs:date lexical forms and preserves the normalized value', function (
    string $date,
    ?string $expected = null,
): void {
    $result = SearchResult::tryFromRecord([
        'Regon' => '012345678',
        'Nazwa' => 'Fixture company',
        'Typ' => 'F',
        'SilosID' => '1',
        'DataZakonczeniaDzialalnosci' => $date,
    ]);

    expect($result)->not->toBeNull()
        ->and($result?->activityEndDate)->toBe($expected ?? $date);
})->with([
    'date only' => ['2024-02-29'],
    'UTC designator' => ['2025-02-01Z'],
    'positive offset' => ['2025-02-01+05:30'],
    'negative offset' => ['2025-02-01-13:59'],
    'maximum positive offset' => ['2025-02-01+14:00'],
    'maximum negative offset' => ['2025-02-01-14:00'],
    'surrounding XML whitespace' => [
        " \t\r\n2025-02-01+05:30\r\n ",
        '2025-02-01+05:30',
    ],
]);

it('rejects malformed or impossible activity end dates', function (string $date): void {
    expect(SearchResult::tryFromRecord([
        'Regon' => '012345678',
        'Nazwa' => 'Fixture company',
        'Typ' => 'F',
        'SilosID' => '1',
        'DataZakonczeniaDzialalnosci' => $date,
    ]))->toBeNull();
})->with([
    'non leap day' => ['2025-02-29'],
    'zero month' => ['2025-00-10'],
    'zero year' => ['0000-01-01'],
    'un-padded month' => ['2025-2-01'],
    'date and time' => ['2025-02-01T00:00:00'],
    'lowercase UTC designator' => ['2025-02-01z'],
    'offset without a colon' => ['2025-02-01+0100'],
    'offset above the maximum' => ['2025-02-01+15:00'],
    'minutes beyond the maximum' => ['2025-02-01+13:60'],
    'minutes on the maximum hour' => ['2025-02-01-14:01'],
    'internal XML whitespace' => ["2025-\t02-01"],
]);
