<?php

declare(strict_types=1);

use cieplik206\BirRegon\Transport\CurlResponseBuffer;

/**
 * @param  list<string>  $headers
 * @return array{CurlResponseBuffer, CurlHandle}
 */
function preparedCurlResponseBuffer(int $maxResponseBytes, array $headers = []): array
{
    $handle = curl_init();

    if (! $handle instanceof CurlHandle) {
        throw new RuntimeException('Unable to create a cURL handle for the response buffer test.');
    }

    $buffer = new CurlResponseBuffer($maxResponseBytes);
    $buffer->writeHeader($handle, "HTTP/1.1 200 OK\r\n");

    foreach ($headers as $header) {
        $buffer->writeHeader($handle, $header."\r\n");
    }

    $buffer->writeHeader($handle, "\r\n");

    return [$buffer, $handle];
}

it('accepts a response body exactly at the configured byte limit', function (): void {
    [$buffer, $handle] = preparedCurlResponseBuffer(4, [
        'Content-Type: application/soap+xml; charset=UTF-8',
        'Content-Length: 4',
    ]);

    expect($buffer->writeBody($handle, 'ABCD'))->toBe(4);

    $result = $buffer->result();

    expect($result->exchangeCompleted)->toBeTrue()
        ->and($result->httpStatus)->toBe(200)
        ->and($result->successful)->toBeTrue()
        ->and($result->body())->toBe('ABCD')
        ->and($result->contentType)->toBe('application/soap+xml; charset=UTF-8');
});

it('rejects a response body one byte above the configured limit', function (): void {
    [$buffer, $handle] = preparedCurlResponseBuffer(4, [
        'Content-Type: application/soap+xml',
    ]);

    expect($buffer->writeBody($handle, 'ABCD'))->toBe(4)
        ->and($buffer->writeBody($handle, 'E'))->toBe(0)
        ->and($buffer->result()->successful)->toBeFalse();
});

it('rejects a declared content length one byte above the configured limit', function (): void {
    $handle = curl_init();

    if (! $handle instanceof CurlHandle) {
        throw new RuntimeException('Unable to create a cURL handle for the response buffer test.');
    }

    $buffer = new CurlResponseBuffer(4);

    expect($buffer->writeHeader($handle, "HTTP/1.1 200 OK\r\n"))->toBeGreaterThan(0)
        ->and($buffer->writeHeader($handle, "Content-Type: application/soap+xml\r\n"))->toBeGreaterThan(0)
        ->and($buffer->writeHeader($handle, "Content-Length: 5\r\n"))->toBe(0)
        ->and($buffer->result()->successful)->toBeFalse();
});

it('rejects duplicate protected response headers', function (string $first, string $duplicate): void {
    $handle = curl_init();

    if (! $handle instanceof CurlHandle) {
        throw new RuntimeException('Unable to create a cURL handle for the response buffer test.');
    }

    $buffer = new CurlResponseBuffer(100);

    $buffer->writeHeader($handle, "HTTP/1.1 200 OK\r\n");

    expect($buffer->writeHeader($handle, $first."\r\n"))->toBeGreaterThan(0)
        ->and($buffer->writeHeader($handle, $duplicate."\r\n"))->toBe(0)
        ->and($buffer->result()->successful)->toBeFalse();
})->with([
    'content type' => ['Content-Type: application/soap+xml', 'content-type: application/xop+xml'],
    'content length' => ['Content-Length: 4', 'content-length: 4'],
    'content encoding' => ['Content-Encoding: identity', 'content-encoding: identity'],
    'transfer encoding' => ['Transfer-Encoding: chunked', 'transfer-encoding: chunked'],
]);

it('rejects ambiguous content length and transfer encoding combinations', function (array $headers): void {
    $handle = curl_init();

    if (! $handle instanceof CurlHandle) {
        throw new RuntimeException('Unable to create a cURL handle for the response buffer test.');
    }

    $buffer = new CurlResponseBuffer(100);

    $buffer->writeHeader($handle, "HTTP/1.1 200 OK\r\n");
    $buffer->writeHeader($handle, $headers[0]."\r\n");

    expect($buffer->writeHeader($handle, $headers[1]."\r\n"))->toBe(0)
        ->and($buffer->result()->successful)->toBeFalse();
})->with([
    'length then transfer encoding' => [[
        'Content-Length: 4',
        'Transfer-Encoding: chunked',
    ]],
    'transfer encoding then length' => [[
        'Transfer-Encoding: chunked',
        'Content-Length: 4',
    ]],
]);

it('preserves a completed non-success HTTP exchange for safe classification', function (
    string $status,
    int $expectedStatus,
): void {
    $handle = curl_init();

    if (! $handle instanceof CurlHandle) {
        throw new RuntimeException('Unable to create a cURL handle for the response buffer test.');
    }

    $buffer = new CurlResponseBuffer(100);

    $buffer->writeHeader($handle, $status."\r\n");
    $buffer->writeHeader($handle, "Content-Type: application/soap+xml\r\n");
    $buffer->writeHeader($handle, "\r\n");
    $buffer->writeBody($handle, 'SOAP');

    $result = $buffer->result();

    expect($result->exchangeCompleted)->toBeTrue()
        ->and($result->httpStatus)->toBe($expectedStatus)
        ->and($result->successful)->toBeFalse()
        ->and($result->body())->toBe('SOAP')
        ->and($result->contentType)->toBe('application/soap+xml');
})->with([
    'informational' => ['HTTP/1.1 101 Switching Protocols', 101],
    'redirect' => ['HTTP/1.1 302 Found', 302],
    'client error' => ['HTTP/1.1 400 Bad Request', 400],
    'server error' => ['HTTP/1.1 500 Internal Server Error', 500],
]);

it('assembles a large response from small chunks without changing its contents', function (): void {
    $chunk = str_repeat('A', 1024);
    $chunkCount = 2048;
    $bodyBytes = strlen($chunk) * $chunkCount;
    [$buffer, $handle] = preparedCurlResponseBuffer($bodyBytes, [
        'Content-Type: application/soap+xml',
        'Content-Length: '.$bodyBytes,
    ]);

    foreach (range(1, $chunkCount) as $_) {
        expect($buffer->writeBody($handle, $chunk))->toBe(strlen($chunk));
    }

    $result = $buffer->result();

    expect($result->exchangeCompleted)->toBeTrue()
        ->and($result->body())->toBe(str_repeat($chunk, $chunkCount));
});

it('accepts only an identity content encoding', function (string $encoding, bool $successful): void {
    [$buffer, $handle] = preparedCurlResponseBuffer(100, [
        'Content-Type: application/soap+xml',
        'Content-Encoding: '.$encoding,
    ]);

    $buffer->writeBody($handle, 'SOAP');

    expect($buffer->result()->successful)->toBe($successful);
})->with([
    'identity' => ['identity', true],
    'case-insensitive identity' => ['IDENTITY', true],
    'gzip' => ['gzip', false],
    'deflate' => ['deflate', false],
    'multiple encodings' => ['identity, gzip', false],
]);

it('requires a non-empty HTTP content type and preserves its value', function (
    ?string $contentType,
    bool $successful,
): void {
    $headers = $contentType === null ? [] : ['Content-Type: '.$contentType];
    [$buffer, $handle] = preparedCurlResponseBuffer(100, $headers);

    $buffer->writeBody($handle, 'SOAP');
    $result = $buffer->result();

    expect($result->successful)->toBe($successful)
        ->and($result->contentType)->toBe($contentType);
})->with([
    'missing' => [null, false],
    'empty' => ['', false],
    'SOAP XML' => ['application/soap+xml; charset=UTF-8', true],
    'multipart' => ['multipart/related; boundary="safe-boundary"', true],
]);

it('rejects protected response metadata supplied only as an HTTP trailer', function (): void {
    [$buffer, $handle] = preparedCurlResponseBuffer(100, [
        'Transfer-Encoding: chunked',
    ]);

    expect($buffer->writeBody($handle, 'SOAP'))->toBe(4)
        ->and($buffer->writeHeader($handle, "Content-Type: application/soap+xml\r\n"))->toBe(0)
        ->and($buffer->result()->successful)->toBeFalse();
});

it('accepts irrelevant HTTP extension headers with every legal token character', function (
    string $headerName,
): void {
    [$buffer, $handle] = preparedCurlResponseBuffer(100, [
        'Content-Type: application/soap+xml',
        $headerName.': harmless',
    ]);

    expect($buffer->writeBody($handle, 'SOAP'))->toBe(4)
        ->and($buffer->result()->successful)->toBeTrue();
})->with([
    'underscore' => ['X_Test'],
    'punctuation' => ['X!Trace~Value'],
]);
