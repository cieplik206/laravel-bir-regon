<?php

declare(strict_types=1);

use cieplik206\BirRegon\Protocol\XmlRecordsDecoder;

function innerFixture(string $path): string
{
    $contents = file_get_contents(__DIR__.'/../Fixtures/Gus/'.$path);

    if (! is_string($contents)) {
        throw new RuntimeException("Unable to read the GUS inner XML fixture: {$path}");
    }

    return $contents;
}

it('decodes search records without coercing identifiers or empty fields', function (): void {
    $result = (new XmlRecordsDecoder)->decode(innerFixture('inner/search-single.xml'));

    expect($result->successful)->toBeTrue()
        ->and($result->error)->toBeNull()
        ->and($result->records)->toHaveCount(1)
        ->and($result->records[0]['Regon'])->toBe('012345678')
        ->and($result->records[0]['Nip'])->toBe('0123456789')
        ->and($result->records[0]['StatusNip'])->toBe('')
        ->and($result->records[0]['Nazwa'])->toBe('FIKCYJNA SPÓŁKA TESTOWA')
        ->and($result->records[0]['SilosID'])->toBe('6');
});

it('decodes multiple search records in source order', function (): void {
    $result = (new XmlRecordsDecoder)->decode(innerFixture('inner/search-multiple.xml'));

    expect($result->successful)->toBeTrue()
        ->and($result->records)->toHaveCount(2)
        ->and(array_column($result->records, 'Regon'))->toBe(['012345678', '987654321'])
        ->and($result->records[1]['DataZakonczeniaDzialalnosci'])->toBe('2025-12-31');
});

it('decodes full and BIR121 report fields without a fixed schema', function (
    string $fixture,
    string $field,
    string $value,
): void {
    $result = (new XmlRecordsDecoder)->decode(innerFixture($fixture));

    expect($result->successful)->toBeTrue()
        ->and($result->error)->toBeNull()
        ->and($result->records)->toHaveCount(1)
        ->and($result->records[0][$field])->toBe($value);
})->with([
    'full report' => ['inner/full-single.xml', 'praw_nazwa', 'FIKCYJNA SPÓŁKA TESTOWA'],
    'BIR121 local legal unit' => [
        'inner/full-bir121.xml',
        'lokpraw_statusNip',
        'Unieważniony',
    ],
]);

it('decodes every REGON from a bulk report as a string', function (): void {
    $result = (new XmlRecordsDecoder)->decode(innerFixture('inner/bulk-multiple.xml'));

    expect($result->successful)->toBeTrue()
        ->and(array_column($result->records, 'regon'))->toBe([
            '012345678',
            '000000001',
            '987654321',
        ]);
});

it('maps documented GUS errors and suppresses partial records', function (
    string $fixture,
    int $code,
    string $message,
): void {
    $result = (new XmlRecordsDecoder)->decode(innerFixture($fixture));

    expect($result->successful)->toBeTrue()
        ->and($result->records)->toBe([])
        ->and($result->error)->not->toBeNull()
        ->and($result->error?->code)->toBe($code)
        ->and($result->error?->message)->toBe($message);
})->with([
    'search not found' => [
        'inner/search-error-4.xml',
        4,
        'No fixture entity matches the supplied criteria.',
    ],
    'full report error' => [
        'inner/full-error-11.xml',
        11,
        'PKD is unavailable for this historical fixture record.',
    ],
    'bulk report concurrency error' => [
        'inner/bulk-error-101.xml',
        101,
        'The fixture limit for concurrently generated reports was reached.',
    ],
]);

it('decodes escaped field values as text and preserves surrounding whitespace', function (): void {
    $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <root>
          <dane>
            <Nazwa> FIKCYJNA &amp; BEZPIECZNA </Nazwa>
          </dane>
        </root>
        XML;
    $result = (new XmlRecordsDecoder)->decode($xml);

    expect($result->successful)->toBeTrue()
        ->and($result->records[0]['Nazwa'])->toBe(' FIKCYJNA & BEZPIECZNA ');
});

it('treats an empty response as a successful empty record set', function (): void {
    $result = (new XmlRecordsDecoder)->decode('');

    expect($result->successful)->toBeTrue()
        ->and($result->records)->toBe([])
        ->and($result->error)->toBeNull();
});

it('rejects XXE and every document containing a DOCTYPE', function (): void {
    $result = (new XmlRecordsDecoder)->decode(innerFixture('security/xxe.xml'));

    expect($result->successful)->toBeFalse()
        ->and($result->records)->toBe([])
        ->and($result->error)->toBeNull();
});

it('rejects a UTF-16 DTD before reading expanded field text', function (): void {
    $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-16"?>
        <!DOCTYPE root [<!ENTITY injected "expanded-inner-value">]>
        <root><dane><Nazwa>&injected;</Nazwa></dane></root>
        XML;
    $encoded = '';

    foreach (str_split($xml) as $byte) {
        $encoded .= $byte."\0";
    }

    $result = (new XmlRecordsDecoder)->decode("\xFF\xFE".$encoded);

    expect($result->successful)->toBeFalse()
        ->and($result->records)->toBe([])
        ->and($result->error)->toBeNull();
});

it('rejects UTF-16 input detected only from its byte order mark', function (): void {
    $xml = '<root><dane><Regon>012345678</Regon></dane></root>';
    $encoded = '';

    foreach (str_split($xml) as $byte) {
        $encoded .= $byte."\0";
    }

    $result = (new XmlRecordsDecoder)->decode("\xFF\xFE".$encoded);

    expect($result->successful)->toBeFalse()
        ->and($result->records)->toBe([])
        ->and($result->error)->toBeNull();
});

it('rejects foreign namespaces, nested field elements, and unexpected root children', function (
    string $xml,
): void {
    $result = (new XmlRecordsDecoder)->decode($xml);

    expect($result->successful)->toBeFalse()
        ->and($result->records)->toBe([])
        ->and($result->error)->toBeNull();
})->with([
    'namespaced document' => [
        '<evil:root xmlns:evil="urn:evil"><evil:dane><evil:Regon>012345678</evil:Regon></evil:dane></evil:root>',
    ],
    'namespaced field' => [
        '<root xmlns:evil="urn:evil"><dane><evil:Regon>012345678</evil:Regon></dane></root>',
    ],
    'nested field' => [
        '<root xmlns:evil="urn:evil"><dane><Nazwa><evil:value>flattened</evil:value></Nazwa></dane></root>',
    ],
    'unexpected root child' => [
        '<root><metadata>not-a-record</metadata><dane><Regon>012345678</Regon></dane></root>',
    ],
]);

it('rejects malformed XML, an unexpected root, and duplicate fields', function (
    string $xml,
): void {
    $result = (new XmlRecordsDecoder)->decode($xml);

    expect($result->successful)->toBeFalse()
        ->and($result->records)->toBe([])
        ->and($result->error)->toBeNull();
})->with([
    'malformed document' => ['<root><dane></root>'],
    'unexpected root' => ['<response><dane><Regon>012345678</Regon></dane></response>'],
    'duplicate field' => [
        '<root><dane><Regon>012345678</Regon><Regon>987654321</Regon></dane></root>',
    ],
]);

it('turns an invalid non-positive or non-numeric GUS error code into a safe generic error', function (
    string $rawCode,
): void {
    $xml = '<root><dane><ErrorCode>'.$rawCode.'</ErrorCode><ErrorMessageEn>Unsafe detail</ErrorMessageEn></dane></root>';
    $result = (new XmlRecordsDecoder)->decode($xml);

    expect($result->successful)->toBeTrue()
        ->and($result->records)->toBe([])
        ->and($result->error?->code)->toBe(-1)
        ->and($result->error?->message)->toBe('GUS BIR returned an invalid error response.');
})->with(['zero' => ['0'], 'non-numeric' => ['4x']]);

it('enforces the configured inner XML size limit', function (): void {
    $fixture = innerFixture('inner/search-single.xml');
    $tooSmall = new XmlRecordsDecoder(strlen($fixture) - 1);
    $exact = new XmlRecordsDecoder(strlen($fixture));

    expect($tooSmall->decode($fixture)->successful)->toBeFalse()
        ->and($exact->decode($fixture)->successful)->toBeTrue();
});
