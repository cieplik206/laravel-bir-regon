<?php

declare(strict_types=1);

use cieplik206\BirRegon\Enums\SoapFaultCode;
use cieplik206\BirRegon\Protocol\BirOperation;
use cieplik206\BirRegon\Protocol\SoapResponseDecoder;
use cieplik206\BirRegon\Protocol\TransportFailureType;

function soapFixture(string $path): string
{
    $contents = file_get_contents(__DIR__.'/../Fixtures/Gus/'.$path);

    if (! is_string($contents)) {
        throw new RuntimeException("Unable to read the GUS SOAP fixture: {$path}");
    }

    return $contents;
}

function mimeFixtureBody(string $path): string
{
    $entity = soapFixture($path);
    $separator = str_contains($entity, "\r\n\r\n") ? "\r\n\r\n" : "\n\n";
    $position = strpos($entity, $separator);

    if ($position === false) {
        throw new RuntimeException("The GUS MIME fixture has no top-level header separator: {$path}");
    }

    return substr($entity, $position + strlen($separator));
}

function soapResponse(
    BirOperation $operation,
    string $result,
    string $resultAttributes = '',
): string {
    $namespace = $operation->namespace();
    $response = $operation->value.'Response';
    $resultElement = $operation->resultElement();

    return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"
            xmlns:a="http://www.w3.org/2005/08/addressing"
            xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
          <s:Header>
            <a:Action s:mustUnderstand="1">{$operation->responseAction()}</a:Action>
          </s:Header>
          <s:Body>
            <{$response} xmlns="{$namespace}">
              <{$resultElement}{$resultAttributes}>{$result}</{$resultElement}>
            </{$response}>
          </s:Body>
        </s:Envelope>
        XML;
}

function utf16LeSoapDocument(string $xml): string
{
    $encoded = '';

    foreach (str_split($xml) as $byte) {
        $encoded .= $byte."\0";
    }

    return "\xFF\xFE".$encoded;
}

it('decodes plain SOAP response fixtures', function (
    BirOperation $operation,
    string $fixture,
    string $expectedResult,
): void {
    $response = (new SoapResponseDecoder)->decode(soapFixture($fixture), $operation);

    expect($response->successful)->toBeTrue()
        ->and($response->failureType)->toBeNull()
        ->and($response->resultWasNil)->toBeFalse()
        ->and($response->result())->toContain($expectedResult);
})->with([
    'login' => [BirOperation::Login, 'soap/login-success.xml', 'fixtureSession000001'],
    'GetValue' => [BirOperation::GetValue, 'soap/get-value-active.xml', '1'],
    'search' => [BirOperation::Search, 'soap/search.xml', '<Regon>012345678</Regon>'],
    'full report' => [BirOperation::FullReport, 'soap/full.xml', '<praw_regon9>012345678</praw_regon9>'],
    'bulk report' => [BirOperation::BulkReport, 'soap/bulk.xml', '<regon>987654321</regon>'],
]);

it('decodes plain SOAP without depending on an XML declaration', function (): void {
    $fixture = preg_replace('/^<\?xml[^>]+>\s*/', '', soapFixture('soap/login-success.xml'));

    expect($fixture)->toBeString();
    $response = (new SoapResponseDecoder)->decode((string) $fixture, BirOperation::Login);

    expect($response->successful)->toBeTrue()
        ->and($response->result())->toBe('fixtureSession000001');
});

it('decodes plain SOAP with a UTF-8 byte order mark', function (): void {
    $response = (new SoapResponseDecoder)->decode(
        "\xEF\xBB\xBF".soapFixture('soap/login-success.xml'),
        BirOperation::Login,
    );

    expect($response->successful)->toBeTrue()
        ->and($response->result())->toBe('fixtureSession000001');
});

it('decodes plain SOAP with a legal comment before the Envelope', function (): void {
    $fixture = preg_replace('/^<\?xml[^>]+>\s*/', '', soapFixture('soap/login-success.xml'));

    expect($fixture)->toBeString();
    $response = (new SoapResponseDecoder)->decode(
        "<!-- legal SOAP prolog comment -->\n".(string) $fixture,
        BirOperation::Login,
    );

    expect($response->successful)->toBeTrue()
        ->and($response->result())->toBe('fixtureSession000001');
});

it('distinguishes an empty result from an xsi nil result', function (): void {
    $decoder = new SoapResponseDecoder;
    $empty = $decoder->decode(soapFixture('soap/login-empty.xml'), BirOperation::Login);
    $nil = $decoder->decode(
        soapResponse(BirOperation::Login, '', ' xsi:nil="true"'),
        BirOperation::Login,
    );

    expect($empty->successful)->toBeTrue()
        ->and($empty->result())->toBe('')
        ->and($empty->resultWasNil)->toBeFalse()
        ->and($nil->successful)->toBeTrue()
        ->and($nil->result())->toBe('')
        ->and($nil->resultWasNil)->toBeTrue();
});

it('accepts both XML Schema boolean spellings of an xsi nil result', function (): void {
    $decoder = new SoapResponseDecoder;
    $literal = $decoder->decode(
        soapResponse(BirOperation::Login, '', ' xsi:nil="true"'),
        BirOperation::Login,
    );
    $numeric = $decoder->decode(
        soapResponse(BirOperation::Login, '', ' xsi:nil="1"'),
        BirOperation::Login,
    );

    expect($literal->successful)->toBeTrue()
        ->and($literal->resultWasNil)->toBeTrue()
        ->and($numeric->successful)->toBeTrue()
        ->and($numeric->resultWasNil)->toBeTrue();
});

it('accepts xsi nil for every result element declared nillable in the WSDL', function (
    BirOperation $operation,
): void {
    $response = (new SoapResponseDecoder)->decode(
        soapResponse($operation, '', ' xsi:nil="true"'),
        $operation,
    );

    expect($response->successful)->toBeTrue()
        ->and($response->failureType)->toBeNull()
        ->and($response->resultWasNil)->toBeTrue()
        ->and($response->result())->toBe('');
})->with([
    'login' => [BirOperation::Login],
    'search' => [BirOperation::Search],
    'full report' => [BirOperation::FullReport],
    'bulk report' => [BirOperation::BulkReport],
]);

it('rejects xsi nil for result elements that are not nillable in the WSDL', function (
    BirOperation $operation,
): void {
    $response = (new SoapResponseDecoder)->decode(
        soapResponse($operation, '', ' xsi:nil="true"'),
        $operation,
    );

    expect($response->successful)->toBeFalse()
        ->and($response->failureType)->toBe(TransportFailureType::Protocol)
        ->and($response->resultWasNil)->toBeTrue()
        ->and($response->result())->toBeNull();
})->with([
    'GetValue' => [BirOperation::GetValue],
    'logout' => [BirOperation::Logout],
]);

it('rejects invalid xsi nil values', function (string $nil): void {
    $response = (new SoapResponseDecoder)->decode(
        soapResponse(BirOperation::Login, '', ' xsi:nil="'.$nil.'"'),
        BirOperation::Login,
    );

    expect($response->successful)->toBeFalse()
        ->and($response->failureType)->toBe(TransportFailureType::Protocol);
})->with(['uppercase true' => ['TRUE'], 'word' => ['yes'], 'number' => ['2']]);

it('rejects a nil SOAP result that also contains a value', function (): void {
    $response = (new SoapResponseDecoder)->decode(
        soapResponse(BirOperation::Login, 'must-not-be-accepted', ' xsi:nil="true"'),
        BirOperation::Login,
    );

    expect($response->successful)->toBeFalse()
        ->and($response->failureType)->toBe(TransportFailureType::Protocol);
});

it('rejects nested elements inside a simple SOAP result', function (): void {
    $response = (new SoapResponseDecoder)->decode(
        soapResponse(BirOperation::Login, '<Nested>must-not-be-flattened</Nested>'),
        BirOperation::Login,
    );

    expect($response->successful)->toBeFalse()
        ->and($response->failureType)->toBe(TransportFailureType::Protocol);
});

it('decodes an XML string escaped inside a SOAP result', function (): void {
    $escaped = htmlspecialchars(
        '<root><dane><Nazwa>FIKCYJNA &amp; BEZPIECZNA</Nazwa></dane></root>',
        ENT_XML1 | ENT_QUOTES,
        'UTF-8',
    );
    $response = (new SoapResponseDecoder)->decode(
        soapResponse(BirOperation::Search, $escaped),
        BirOperation::Search,
    );

    expect($response->successful)->toBeTrue()
        ->and($response->result())->toBe(
            '<root><dane><Nazwa>FIKCYJNA &amp; BEZPIECZNA</Nazwa></dane></root>',
        );
});

it('extracts the SOAP root from a standard MIME response body', function (): void {
    $response = (new SoapResponseDecoder)->decode(
        mimeFixtureBody('mime/standard.multipart'),
        BirOperation::Login,
    );

    expect($response->successful)->toBeTrue()
        ->and($response->result())->toBe('fixtureSession000001');
});

it('decodes the historically indented single-part MIME framing used by GUS', function (
    ?string $httpContentType,
): void {
    $boundary = 'uuid:fixture-boundary+id=8624';
    $indent = '        ';
    $soap = soapFixture('soap/login-success.xml');
    $mime = "\r\n--{$boundary}\r\n"
        .$indent."Content-ID: <http://tempuri.org/0>\r\n"
        .$indent."Content-Transfer-Encoding: 8bit\r\n"
        .$indent.'Content-Type: application/xop+xml; charset=utf-8; type="application/soap+xml"'."\r\n\r\n"
        .$soap."\r\n"
        .$indent."--{$boundary}--\r\n";

    $response = (new SoapResponseDecoder)->decode(
        $mime,
        BirOperation::Login,
        $httpContentType,
    );

    expect($response->successful)->toBeTrue()
        ->and($response->result())->toBe('fixtureSession000001');
})->with([
    'inferred boundary' => [null],
    'HTTP content type' => [
        'multipart/related; boundary="uuid:fixture-boundary+id=8624"; '
        .'type="application/xop+xml"; start="<http://tempuri.org/0>"',
    ],
]);

it('rejects ambiguous variants of the historical indented MIME framing', function (
    string $variant,
): void {
    $boundary = 'uuid:fixture-boundary+id=8624';
    $indent = '        ';
    $closing = $indent."--{$boundary}--\r\n";
    $mime = "\r\n--{$boundary}\r\n"
        .$indent."Content-ID: <http://tempuri.org/0>\r\n"
        .$indent."Content-Transfer-Encoding: 8bit\r\n"
        .$indent.'Content-Type: application/xop+xml; type="application/soap+xml"'."\r\n\r\n"
        .soapFixture('soap/login-success.xml')."\r\n"
        .$closing;

    $mime = match ($variant) {
        'duplicate closing delimiter' => $mime.$closing,
        'mixed header indentation' => str_replace(
            $indent.'Content-Transfer-Encoding',
            '    Content-Transfer-Encoding',
            $mime,
        ),
        default => throw new InvalidArgumentException('Unknown historical MIME test variant.'),
    };
    $response = (new SoapResponseDecoder)->decode(
        $mime,
        BirOperation::Login,
        'multipart/related; boundary="'.$boundary.'"; type="application/xop+xml"',
    );

    expect($response->successful)->toBeFalse()
        ->and($response->failureType)->toBe(TransportFailureType::Protocol);
})->with([
    'duplicate closing delimiter' => ['duplicate closing delimiter'],
    'mixed header indentation' => ['mixed header indentation'],
]);

it('uses the MIME start parameter to select a non-first SOAP root', function (): void {
    $response = (new SoapResponseDecoder)->decode(
        soapFixture('mime/root-not-first.multipart'),
        BirOperation::Login,
    );

    expect($response->successful)->toBeTrue()
        ->and($response->result())->toBe('fixtureSession000001');
});

it('accepts every RFC 2046 boundary character allowed in a quoted parameter', function (
    string $boundary,
): void {
    $soap = soapFixture('soap/login-success.xml');
    $mime = "--{$boundary}\r\n"
        ."Content-Type: application/soap+xml\r\n"
        ."Content-Transfer-Encoding: 8bit\r\n\r\n"
        .$soap."\r\n"
        ."--{$boundary}--\r\n";
    $response = (new SoapResponseDecoder)->decode(
        $mime,
        BirOperation::Login,
        'multipart/related; boundary="'.$boundary.'"; type="application/soap+xml"',
    );

    expect($response->successful)->toBeTrue()
        ->and($response->result())->toBe('fixtureSession000001');
})->with([
    'slash' => ['safe/boundary'],
    'internal space' => ['safe boundary'],
]);

it('treats the first MIME part as the root when no start parameter is available', function (): void {
    $response = (new SoapResponseDecoder)->decode(
        mimeFixtureBody('mime/root-not-first.multipart'),
        BirOperation::Login,
    );

    expect($response->successful)->toBeFalse()
        ->and($response->failureType)->toBe(TransportFailureType::Protocol);
});

it('rejects MIME boundary prefix smuggling inside a non-SOAP root part', function (): void {
    $soap = soapFixture('soap/login-success.xml');
    $mime = <<<MIME
        --fixture-boundary
        Content-Type: text/plain
        Content-Transfer-Encoding: 8bit

        harmless root payload
        --fixture-boundary-smuggled
        Content-Type: application/soap+xml
        Content-Transfer-Encoding: 8bit

        {$soap}
        --fixture-boundary--
        MIME;
    $response = (new SoapResponseDecoder)->decode($mime, BirOperation::Login);

    expect($response->successful)->toBeFalse()
        ->and($response->failureType)->toBe(TransportFailureType::Protocol);
});

it('rejects duplicate or ambiguous MIME content type headers', function (string $contentHeaders): void {
    $soap = soapFixture('soap/login-success.xml');
    $mime = <<<MIME
        --fixture-boundary
        {$contentHeaders}
        Content-Transfer-Encoding: 8bit

        {$soap}
        --fixture-boundary--
        MIME;
    $response = (new SoapResponseDecoder)->decode($mime, BirOperation::Login);

    expect($response->successful)->toBeFalse()
        ->and($response->failureType)->toBe(TransportFailureType::Protocol);
})->with([
    'duplicate header' => ["Content-Type: text/plain\nContent-Type: application/soap+xml"],
    'media type prefix' => ['Content-Type: application/soap+xml-evil'],
    'unrelated XOP parameter' => [
        'Content-Type: application/xop+xml; note="application/soap+xml"',
    ],
]);

it('accepts irrelevant MIME extension headers with every legal token character', function (
    string $headerName,
): void {
    $soap = soapFixture('soap/login-success.xml');
    $mime = <<<MIME
        --fixture-boundary
        Content-Type: application/soap+xml
        {$headerName}: harmless
        Content-Transfer-Encoding: 8bit

        {$soap}
        --fixture-boundary--
        MIME;
    $response = (new SoapResponseDecoder)->decode($mime, BirOperation::Login);

    expect($response->successful)->toBeTrue()
        ->and($response->result())->toBe('fixtureSession000001');
})->with([
    'underscore' => ['X_Test'],
    'punctuation' => ['X!Trace~Value'],
]);

it('rejects a UTF-16 DTD hidden in a base64 MIME SOAP part', function (): void {
    $operation = BirOperation::Login;
    $namespace = $operation->namespace();
    $action = $operation->responseAction();
    $utf16Soap = utf16LeSoapDocument(<<<XML
        <?xml version="1.0" encoding="UTF-16"?>
        <!DOCTYPE s:Envelope [<!ENTITY injected "entity-expanded-session">]>
        <s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"
            xmlns:a="http://www.w3.org/2005/08/addressing">
          <s:Header><a:Action>{$action}</a:Action></s:Header>
          <s:Body>
            <ZalogujResponse xmlns="{$namespace}">
              <ZalogujResult>&injected;</ZalogujResult>
            </ZalogujResponse>
          </s:Body>
        </s:Envelope>
        XML);
    $payload = chunk_split(base64_encode($utf16Soap), 76, "\r\n");
    $mime = "--fixture-boundary\r\n"
        ."Content-Type: application/soap+xml\r\n"
        ."Content-Transfer-Encoding: base64\r\n\r\n"
        .$payload
        ."--fixture-boundary--\r\n";
    $response = (new SoapResponseDecoder)->decode($mime, $operation);

    expect($response->successful)->toBeFalse()
        ->and($response->failureType)->toBe(TransportFailureType::Protocol)
        ->and($response->result())->toBeNull();
});

it('rejects a base64 MIME SOAP part whose UTF-16 encoding is detectable only from its BOM', function (): void {
    $soap = preg_replace('/^<\?xml[^>]+>\s*/', '', soapFixture('soap/login-success.xml'));

    if (! is_string($soap)) {
        throw new RuntimeException('Unable to prepare the UTF-16 SOAP fixture.');
    }

    $payload = chunk_split(base64_encode(utf16LeSoapDocument($soap)), 76, "\r\n");
    $mime = "--fixture-boundary\r\n"
        ."Content-Type: application/soap+xml\r\n"
        ."Content-Transfer-Encoding: base64\r\n\r\n"
        .$payload
        ."--fixture-boundary--\r\n";
    $response = (new SoapResponseDecoder)->decode($mime, BirOperation::Login);

    expect($response->successful)->toBeFalse()
        ->and($response->failureType)->toBe(TransportFailureType::Protocol)
        ->and($response->result())->toBeNull();
});

it('rejects multiple SOAP MIME parts', function (): void {
    $soap = soapFixture('soap/login-success.xml');
    $mime = <<<MIME
        --fixture-boundary
        Content-Type: application/xop+xml; type="application/soap+xml"
        Content-Transfer-Encoding: 8bit

        {$soap}
        --fixture-boundary
        Content-Type: application/soap+xml
        Content-Transfer-Encoding: 8bit

        {$soap}
        --fixture-boundary--
        MIME;
    $response = (new SoapResponseDecoder)->decode($mime, BirOperation::Login);

    expect($response->successful)->toBeFalse()
        ->and($response->failureType)->toBe(TransportFailureType::Protocol);
});

it('rejects a SOAP fault without exposing its detail as a result', function (): void {
    $response = (new SoapResponseDecoder)->decode(
        soapFixture('soap/fault.xml'),
        BirOperation::Login,
        'application/soap+xml',
        500,
    );

    expect($response->successful)->toBeFalse()
        ->and($response->failureType)->toBe(TransportFailureType::Protocol)
        ->and($response->soapFaultCode)->toBe(SoapFaultCode::Receiver)
        ->and($response->result())->toBeNull();
});

it('accepts top-level XOP when it explicitly declares a SOAP media type', function (): void {
    $response = (new SoapResponseDecoder)->decode(
        soapFixture('soap/login-success.xml'),
        BirOperation::Login,
        'application/xop+xml; charset=UTF-8; type="application/soap+xml"',
        200,
    );

    expect($response->successful)->toBeTrue()
        ->and($response->result())->toBe('fixtureSession000001');
});

it('rejects a response with a missing result element', function (): void {
    $xml = str_replace(
        '<ZalogujResult>fixtureSession000001</ZalogujResult>',
        '',
        soapFixture('soap/login-success.xml'),
    );
    $response = (new SoapResponseDecoder)->decode($xml, BirOperation::Login);

    expect($response->successful)->toBeFalse()
        ->and($response->failureType)->toBe(TransportFailureType::Protocol);
});

it('rejects additional element payloads beside the expected SOAP response and result', function (
    string $xml,
): void {
    $response = (new SoapResponseDecoder)->decode($xml, BirOperation::Login);

    expect($response->successful)->toBeFalse()
        ->and($response->failureType)->toBe(TransportFailureType::Protocol);
})->with([
    'extra Body operation' => [str_replace(
        '</s:Body>',
        '<Unexpected xmlns="urn:unexpected" /></s:Body>',
        soapFixture('soap/login-success.xml'),
    )],
    'extra response field' => [str_replace(
        '</ZalogujResponse>',
        '<Unexpected>value</Unexpected></ZalogujResponse>',
        soapFixture('soap/login-success.xml'),
    )],
]);

it('rejects a mismatched WS-Addressing response action', function (): void {
    $xml = str_replace(
        BirOperation::Login->responseAction(),
        BirOperation::Search->responseAction(),
        soapFixture('soap/login-success.xml'),
    );
    $response = (new SoapResponseDecoder)->decode($xml, BirOperation::Login);

    expect($response->successful)->toBeFalse()
        ->and($response->failureType)->toBe(TransportFailureType::Protocol);
});

it('rejects a missing WS-Addressing response action', function (): void {
    $xml = preg_replace(
        '~\s*<s:Header>.*?</s:Header>~s',
        '',
        soapFixture('soap/login-success.xml'),
    );

    expect($xml)->toBeString();
    $response = (new SoapResponseDecoder)->decode((string) $xml, BirOperation::Login);

    expect($response->successful)->toBeFalse()
        ->and($response->failureType)->toBe(TransportFailureType::Protocol);
});

it('rejects DOCTYPE and XOP include responses', function (): void {
    $doctype = str_replace(
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE s:Envelope [<!ENTITY xxe SYSTEM "file:///invalid">]>',
        soapFixture('soap/login-success.xml'),
    );
    $xop = str_replace(
        '<ZalogujResult>fixtureSession000001</ZalogujResult>',
        '<ZalogujResult><xop:Include xmlns:xop="http://www.w3.org/2004/08/xop/include" href="cid:secret" /></ZalogujResult>',
        soapFixture('soap/login-success.xml'),
    );
    $decoder = new SoapResponseDecoder;

    expect($decoder->decode($doctype, BirOperation::Login)->successful)->toBeFalse()
        ->and($decoder->decode($xop, BirOperation::Login)->successful)->toBeFalse();
});

it('enforces the configured raw response size limit', function (): void {
    $fixture = soapFixture('soap/login-success.xml');
    $tooSmall = new SoapResponseDecoder(strlen($fixture) - 1);
    $exact = new SoapResponseDecoder(strlen($fixture));

    expect($tooSmall->decode($fixture, BirOperation::Login)->successful)->toBeFalse()
        ->and($exact->decode($fixture, BirOperation::Login)->successful)->toBeTrue();
});
