<?php

declare(strict_types=1);

use cieplik206\BirRegon\Enums\BulkReportType;
use cieplik206\BirRegon\Enums\ReportType;
use cieplik206\BirRegon\Protocol\BirOperation;
use cieplik206\BirRegon\Protocol\GetValueParameter;
use cieplik206\BirRegon\Protocol\SearchCriteria;
use cieplik206\BirRegon\Protocol\SoapEnvelopeBuilder;

const TEST_SOAP_NAMESPACE = 'http://www.w3.org/2003/05/soap-envelope';
const TEST_WSA_NAMESPACE = 'http://www.w3.org/2005/08/addressing';
const TEST_PUBLIC_NAMESPACE = 'http://CIS/BIR/PUBL/2014/07';
const TEST_DIAGNOSTICS_NAMESPACE = 'http://CIS/BIR/2014/07';
const TEST_DATA_NAMESPACE = 'http://CIS/BIR/PUBL/2014/07/DataContract';
const TEST_XSI_NAMESPACE = 'http://www.w3.org/2001/XMLSchema-instance';

/** @return array{DOMDocument, DOMXPath} */
function parseBuiltSoapEnvelope(string $xml): array
{
    $document = new DOMDocument;

    if (! $document->loadXML($xml, LIBXML_NONET)) {
        throw new RuntimeException('The SOAP envelope is not valid XML.');
    }

    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('soap', TEST_SOAP_NAMESPACE);
    $xpath->registerNamespace('wsa', TEST_WSA_NAMESPACE);
    $xpath->registerNamespace('pub', TEST_PUBLIC_NAMESPACE);
    $xpath->registerNamespace('bir', TEST_DIAGNOSTICS_NAMESPACE);
    $xpath->registerNamespace('dat', TEST_DATA_NAMESPACE);

    return [$document, $xpath];
}

/** @return DOMNodeList<DOMNameSpaceNode|DOMNode> */
function queryBuiltSoapEnvelope(DOMXPath $xpath, string $expression): DOMNodeList
{
    $nodes = $xpath->query($expression);

    if ($nodes === false) {
        throw new RuntimeException("Invalid SOAP test XPath: {$expression}");
    }

    return $nodes;
}

function builtSoapElement(DOMXPath $xpath, string $expression): DOMElement
{
    $node = queryBuiltSoapEnvelope($xpath, $expression)->item(0);

    if (! $node instanceof DOMElement) {
        throw new RuntimeException("Missing SOAP test element: {$expression}");
    }

    return $node;
}

it('builds a SOAP 1.2 login envelope with exact WS-Addressing headers and escaped text', function (): void {
    $apiKey = 'fixture<&api-key';
    $endpoint = 'https://example.invalid/bir?left=1&right=<two>';
    $builder = new SoapEnvelopeBuilder($apiKey);
    $xml = $builder->build(BirOperation::Login, [], $endpoint);

    expect($xml)->toBeString()
        ->and($xml)->toContain('fixture&lt;&amp;api-key')
        ->and($xml)->toContain('left=1&amp;right=&lt;two&gt;')
        ->and($xml)->not->toContain($apiKey)
        ->and($xml)->not->toContain($endpoint);

    [, $xpath] = parseBuiltSoapEnvelope((string) $xml);
    $action = builtSoapElement($xpath, '/soap:Envelope/soap:Header/wsa:Action');
    $to = builtSoapElement($xpath, '/soap:Envelope/soap:Header/wsa:To');
    $operation = builtSoapElement($xpath, '/soap:Envelope/soap:Body/pub:Zaloguj');
    $parameter = builtSoapElement(
        $xpath,
        '/soap:Envelope/soap:Body/pub:Zaloguj/pub:pKluczUzytkownika',
    );

    expect(queryBuiltSoapEnvelope($xpath, '/soap:Envelope')->length)->toBe(1)
        ->and(queryBuiltSoapEnvelope($xpath, '/soap:Envelope/soap:Header')->length)->toBe(1)
        ->and(queryBuiltSoapEnvelope($xpath, '/soap:Envelope/soap:Body')->length)->toBe(1)
        ->and($operation->localName)->toBe('Zaloguj')
        ->and($action->textContent)->toBe(BirOperation::Login->action())
        ->and($action->getAttributeNS(TEST_SOAP_NAMESPACE, 'mustUnderstand'))->toBe('1')
        ->and($to->textContent)->toBe($endpoint)
        ->and($to->getAttributeNS(TEST_SOAP_NAMESPACE, 'mustUnderstand'))->toBe('1')
        ->and($parameter->textContent)->toBe($apiKey);
});

it('builds every search criterion with all seven fields in WSDL order and one non-nil value', function (
    SearchCriteria $criteria,
): void {
    $builder = new SoapEnvelopeBuilder('fixture-api-key');
    $xml = $builder->build(
        BirOperation::Search,
        ['criteria' => $criteria],
        'https://example.invalid/bir',
    );

    expect($xml)->toBeString();
    [, $xpath] = parseBuiltSoapEnvelope((string) $xml);
    $containers = queryBuiltSoapEnvelope(
        $xpath,
        '/soap:Envelope/soap:Body/pub:DaneSzukajPodmioty/pub:pParametryWyszukiwania',
    );
    $fields = queryBuiltSoapEnvelope(
        $xpath,
        '/soap:Envelope/soap:Body/pub:DaneSzukajPodmioty/pub:pParametryWyszukiwania/dat:*',
    );

    expect($containers->length)->toBe(1)
        ->and($fields->length)->toBe(7);

    $actualOrder = [];
    $nonNilValues = [];
    $nilCount = 0;

    foreach ($fields as $field) {
        if (! $field instanceof DOMElement) {
            throw new RuntimeException('Expected the search field to be a DOM element.');
        }

        $actualOrder[] = $field->localName;

        if (strtolower($field->getAttributeNS(TEST_XSI_NAMESPACE, 'nil')) === 'true') {
            $nilCount++;
        } else {
            $nonNilValues[$field->localName] = $field->textContent;
        }
    }

    expect($actualOrder)->toBe(SearchCriteria::WSDL_FIELD_ORDER)
        ->and($nilCount)->toBe(6)
        ->and($nonNilValues)->toBe([$criteria->field => $criteria->value]);
})->with([
    'KRS' => [SearchCriteria::krs('0000123456')],
    'KRS batch' => [SearchCriteria::krsNumbers(['0000123456', '0000654321'])],
    'NIP' => [SearchCriteria::nip('0012345678')],
    'NIP batch' => [SearchCriteria::nips(['0012345678', '0098765432'])],
    'REGON' => [SearchCriteria::regon('012345678')],
    'REGON14 batch' => [SearchCriteria::regons14(['01234567800001', '01234567800002'])],
    'REGON9 batch' => [SearchCriteria::regons9(['012345678', '000000001'])],
]);

it('builds GetValue in its separate diagnostics namespace', function (): void {
    $builder = new SoapEnvelopeBuilder('fixture-api-key');
    $xml = $builder->build(
        BirOperation::GetValue,
        ['parameter' => GetValueParameter::SessionStatus],
        'https://example.invalid/bir',
    );

    expect($xml)->toBeString();
    [, $xpath] = parseBuiltSoapEnvelope((string) $xml);

    expect(queryBuiltSoapEnvelope($xpath, '/soap:Envelope/soap:Body/bir:GetValue')->length)->toBe(1)
        ->and(queryBuiltSoapEnvelope($xpath, '/soap:Envelope/soap:Body/pub:GetValue')->length)->toBe(0)
        ->and($xpath->evaluate('string(/soap:Envelope/soap:Body/bir:GetValue/bir:pNazwaParametru)'))
        ->toBe('StatusSesji')
        ->and($xpath->evaluate('string(/soap:Envelope/soap:Header/wsa:Action)'))
        ->toBe(BirOperation::GetValue->action());
});

it('builds full report parameters in documented order', function (): void {
    $builder = new SoapEnvelopeBuilder('fixture-api-key');
    $xml = $builder->build(BirOperation::FullReport, [
        'regon' => '012345678',
        'reportType' => ReportType::Organization,
    ], 'https://example.invalid/bir');

    expect($xml)->toBeString();
    [, $xpath] = parseBuiltSoapEnvelope((string) $xml);
    $parameters = queryBuiltSoapEnvelope(
        $xpath,
        '/soap:Envelope/soap:Body/pub:DanePobierzPelnyRaport/pub:*',
    );
    $names = [];
    $values = [];

    foreach ($parameters as $parameter) {
        if ($parameter instanceof DOMElement) {
            $names[] = $parameter->localName;
            $values[] = $parameter->textContent;
        }
    }

    expect($names)->toBe(['pRegon', 'pNazwaRaportu'])
        ->and($values)->toBe(['012345678', ReportType::Organization->value]);
});

it('builds bulk report parameters in documented order', function (): void {
    $builder = new SoapEnvelopeBuilder('fixture-api-key');
    $xml = $builder->build(BirOperation::BulkReport, [
        'date' => '2026-08-22',
        'reportType' => BulkReportType::NewLegalEntitiesAndNaturalPersons,
    ], 'https://example.invalid/bir');

    expect($xml)->toBeString();
    [, $xpath] = parseBuiltSoapEnvelope((string) $xml);
    $parameters = queryBuiltSoapEnvelope(
        $xpath,
        '/soap:Envelope/soap:Body/pub:DanePobierzRaportZbiorczy/pub:*',
    );
    $names = [];
    $values = [];

    foreach ($parameters as $parameter) {
        if ($parameter instanceof DOMElement) {
            $names[] = $parameter->localName;
            $values[] = $parameter->textContent;
        }
    }

    expect($names)->toBe(['pDataRaportu', 'pNazwaRaportu'])
        ->and($values)->toBe([
            '2026-08-22',
            BulkReportType::NewLegalEntitiesAndNaturalPersons->value,
        ]);
});

it('uses the current session in the logout body without exposing it through debug info', function (): void {
    $builder = new SoapEnvelopeBuilder('fixture-api-key');
    $builder->useSession('fixtureSession000001');
    $xml = $builder->build(BirOperation::Logout, [], 'https://example.invalid/bir');

    expect($xml)->toBeString();
    [, $xpath] = parseBuiltSoapEnvelope((string) $xml);

    expect($xpath->evaluate('string(/soap:Envelope/soap:Body/pub:Wyloguj/pub:pIdentyfikatorSesji)'))
        ->toBe('fixtureSession000001')
        ->and($builder->__debugInfo())->toBe([
            'apiKey' => '[REDACTED]',
            'sessionId' => '[REDACTED]',
        ]);
});
