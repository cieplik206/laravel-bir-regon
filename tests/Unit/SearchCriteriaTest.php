<?php

declare(strict_types=1);

use cieplik206\BirRegon\Exceptions\BirValidationException;
use cieplik206\BirRegon\Protocol\SearchCriteria;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

it('keeps the exact WSDL search field order', function (): void {
    expect(SearchCriteria::WSDL_FIELD_ORDER)->toBe([
        'Krs',
        'Krsy',
        'Nip',
        'Nipy',
        'Regon',
        'Regony14zn',
        'Regony9zn',
    ]);
});

it('creates each mutually exclusive search criterion', function (
    SearchCriteria $criteria,
    string $field,
    string $value,
): void {
    expect($criteria->field)->toBe($field)
        ->and($criteria->value)->toBe($value)
        ->and(SearchCriteria::WSDL_FIELD_ORDER)->toContain($field);
})->with([
    'KRS' => [SearchCriteria::krs('0000123456'), 'Krs', '0000123456'],
    'KRS batch' => [SearchCriteria::krsNumbers(['0000123456', '0000654321']), 'Krsy', '0000123456,0000654321'],
    'NIP' => [SearchCriteria::nip('0012345678'), 'Nip', '0012345678'],
    'NIP batch' => [SearchCriteria::nips(['0012345678', '0098765432']), 'Nipy', '0012345678,0098765432'],
    'REGON' => [SearchCriteria::regon('012345678'), 'Regon', '012345678'],
    'REGON14' => [SearchCriteria::regon('01234567800001'), 'Regon', '01234567800001'],
    'REGON14 batch' => [SearchCriteria::regons14(['01234567800001', '01234567800002']), 'Regony14zn', '01234567800001,01234567800002'],
    'REGON9 batch' => [SearchCriteria::regons9(['012345678', '000000001']), 'Regony9zn', '012345678,000000001'],
]);

it('accepts the maximum batch size without losing leading zeroes', function (): void {
    $identifiers = array_map(
        static fn (int $index): string => str_pad((string) $index, 10, '0', STR_PAD_LEFT),
        range(1, SearchCriteria::MAX_BATCH_SIZE),
    );

    $criteria = SearchCriteria::nips($identifiers);

    expect(explode(',', $criteria->value))->toBe($identifiers)
        ->and(explode(',', $criteria->value))->toHaveCount(SearchCriteria::MAX_BATCH_SIZE);
});

it('reports the number of identifiers charged by GUS', function (
    SearchCriteria $criteria,
    int $expected,
): void {
    expect($criteria->identifierCount())->toBe($expected);
})->with([
    'KRS' => [SearchCriteria::krs('0000123456'), 1],
    'KRS batch' => [SearchCriteria::krsNumbers(['0000123456', '0000654321']), 2],
    'NIP' => [SearchCriteria::nip('0012345678'), 1],
    'NIP batch' => [SearchCriteria::nips(['0012345678', '0098765432']), 2],
    'REGON9' => [SearchCriteria::regon('012345678'), 1],
    'REGON14' => [SearchCriteria::regon('01234567800001'), 1],
    'REGON9 batch' => [SearchCriteria::regons9(['012345678', '000000001']), 2],
    'REGON14 batch' => [SearchCriteria::regons14(['01234567800001', '01234567800002']), 2],
]);

it('keeps a single identifier out of criteria dumps, exports, and serialized state', function (
    SearchCriteria $criteria,
    string $identifier,
): void {
    expect($criteria->value)->toBe($identifier);

    expectSearchCriteriaViewsToExclude($criteria, [$identifier]);
})->with([
    'NIP' => [SearchCriteria::nip('9182736450'), '9182736450'],
    'KRS' => [SearchCriteria::krs('0000987654'), '0000987654'],
    'REGON' => [SearchCriteria::regon('987654321'), '987654321'],
]);

it('keeps a maximum batch out of criteria dumps, exports, and serialized state', function (
    string $type,
): void {
    $identifiers = match ($type) {
        'NIP', 'KRS' => array_map(
            static fn (int $value): string => (string) (1_100_000_000 + $value),
            range(1, SearchCriteria::MAX_BATCH_SIZE),
        ),
        'REGON9' => array_map(
            static fn (int $value): string => (string) (200_000_000 + $value),
            range(1, SearchCriteria::MAX_BATCH_SIZE),
        ),
        'REGON14' => array_map(
            static fn (int $value): string => (string) (20_000_000_000_000 + $value),
            range(1, SearchCriteria::MAX_BATCH_SIZE),
        ),
        default => throw new LogicException('Unsupported test criterion type.'),
    };
    $criteria = match ($type) {
        'NIP' => SearchCriteria::nips($identifiers),
        'KRS' => SearchCriteria::krsNumbers($identifiers),
        'REGON9' => SearchCriteria::regons9($identifiers),
        'REGON14' => SearchCriteria::regons14($identifiers),
    };

    expect(explode(',', $criteria->value))->toBe($identifiers);

    expectSearchCriteriaViewsToExclude($criteria, $identifiers);
})->with([
    'NIP batch' => ['NIP'],
    'KRS batch' => ['KRS'],
    'REGON9 batch' => ['REGON9'],
    'REGON14 batch' => ['REGON14'],
]);

it('rejects malformed single identifiers', function (Closure $create): void {
    expect($create)->toThrow(BirValidationException::class);
})->with([
    'short NIP' => [static fn (): SearchCriteria => SearchCriteria::nip('123456789')],
    'non-numeric NIP' => [static fn (): SearchCriteria => SearchCriteria::nip('123456789A')],
    'short KRS' => [static fn (): SearchCriteria => SearchCriteria::krs('123456789')],
    'non-numeric KRS' => [static fn (): SearchCriteria => SearchCriteria::krs('A123456789')],
    'short REGON' => [static fn (): SearchCriteria => SearchCriteria::regon('12345678')],
    'long REGON' => [static fn (): SearchCriteria => SearchCriteria::regon('1234567890')],
    'non-numeric REGON' => [static fn (): SearchCriteria => SearchCriteria::regon('12345678A')],
]);

it('rejects empty, oversized, or malformed batches', function (Closure $create): void {
    expect($create)->toThrow(BirValidationException::class);
})->with([
    'empty batch' => [static fn (): SearchCriteria => SearchCriteria::nips([])],
    'oversized batch' => [
        static fn (): SearchCriteria => SearchCriteria::krsNumbers(
            array_fill(0, SearchCriteria::MAX_BATCH_SIZE + 1, '0000123456'),
        ),
    ],
    'malformed member' => [
        static fn (): SearchCriteria => SearchCriteria::regons9(['012345678', '12345678A']),
    ],
    'wrong REGON batch kind' => [
        static fn (): SearchCriteria => SearchCriteria::regons14(['012345678']),
    ],
]);

/** @param list<string> $identifiers */
function expectSearchCriteriaViewsToExclude(
    SearchCriteria $criteria,
    array $identifiers,
): void {
    ob_start();
    var_dump($criteria);
    $nativeDump = ob_get_clean();
    $symfonyDump = '';
    (new CliDumper)->dump(
        (new VarCloner)->cloneVar($criteria),
        static function (string $line) use (&$symfonyDump): void {
            $symfonyDump .= $line;
        },
    );
    $serialized = serialize($criteria);
    $restored = unserialize($serialized);
    $exportTombstone = SearchCriteria::__set_state([
        'field' => $criteria->field,
        'value' => $criteria->value,
    ]);

    foreach ([
        print_r($criteria, true),
        is_string($nativeDump) ? $nativeDump : '',
        var_export($criteria, true),
        $symfonyDump,
        $serialized,
        print_r($restored, true),
        var_export($exportTombstone, true),
    ] as $rendered) {
        foreach ($identifiers as $identifier) {
            expect($rendered)->not->toContain($identifier);
        }
    }

    expect($restored)->toBeInstanceOf(SearchCriteria::class)
        ->and(fn () => $restored->value)
        ->toThrow(
            LogicException::class,
            sprintf('Serialization of %s is not supported.', SearchCriteria::class),
        )
        ->and(fn () => $exportTombstone->value)
        ->toThrow(
            LogicException::class,
            sprintf('Serialization of %s is not supported.', SearchCriteria::class),
        );
}
