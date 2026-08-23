<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Protocol;

use cieplik206\BirRegon\Enums\SoapFaultCode;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Throwable;

final readonly class SoapResponseDecoder
{
    private const SOAP_NAMESPACE = 'http://www.w3.org/2003/05/soap-envelope';

    private const WSA_NAMESPACE = 'http://www.w3.org/2005/08/addressing';

    private const XOP_NAMESPACE = 'http://www.w3.org/2004/08/xop/include';

    private const XSI_NAMESPACE = 'http://www.w3.org/2001/XMLSchema-instance';

    public function __construct(private int $maxResponseBytes = 10_000_000) {}

    public function decode(
        #[\SensitiveParameter] string $response,
        BirOperation $operation,
        ?string $httpContentType = null,
        ?int $httpStatus = null,
    ): TransportResponse {
        try {
            if ($response === '' || strlen($response) > $this->maxResponseBytes) {
                return TransportResponse::failure(TransportFailureType::Protocol);
            }

            $soapXml = $this->extractSoapXml($response, $httpContentType);

            if (
                $soapXml === null
                || ! $this->isUtf8ByteSequence($soapXml)
                || stripos($soapXml, '<!DOCTYPE') !== false
            ) {
                return TransportResponse::failure(TransportFailureType::Protocol);
            }

            $document = $this->loadXml($soapXml);

            if (
                $document === null
                || $document->doctype !== null
                || ! $this->hasSupportedEncoding($document)
                || ! $document->documentElement instanceof DOMElement
            ) {
                return TransportResponse::failure(TransportFailureType::Protocol);
            }

            $root = $document->documentElement;

            if ($root->localName !== 'Envelope' || $root->namespaceURI !== self::SOAP_NAMESPACE) {
                return TransportResponse::failure(TransportFailureType::Protocol);
            }

            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('soap', self::SOAP_NAMESPACE);
            $xpath->registerNamespace('wsa', self::WSA_NAMESPACE);
            $xpath->registerNamespace('xop', self::XOP_NAMESPACE);

            $xopIncludes = $xpath->query('//xop:Include');

            if ($xopIncludes === false || $xopIncludes->length !== 0) {
                return TransportResponse::failure(TransportFailureType::Protocol);
            }

            $bodies = $xpath->query('/soap:Envelope/soap:Body');

            if ($bodies === false || $bodies->length !== 1) {
                return TransportResponse::failure(TransportFailureType::Protocol);
            }

            $body = $bodies->item(0);

            if (! $body instanceof DOMElement || count($this->elementChildren($body)) !== 1) {
                return TransportResponse::failure(TransportFailureType::Protocol);
            }

            $faults = $xpath->query('/soap:Envelope/soap:Body/soap:Fault');

            if ($faults === false || $faults->length > 1) {
                return TransportResponse::failure(TransportFailureType::Protocol);
            }

            if ($faults->length === 1) {
                $fault = $faults->item(0);
                $faultCode = $fault instanceof DOMElement
                    ? $this->soapFaultCode($xpath, $fault)
                    : null;

                if (
                    $faultCode === null
                    || (
                        $httpStatus !== null
                        && $faultCode->expectedHttpStatus() !== $httpStatus
                    )
                ) {
                    return TransportResponse::failure(TransportFailureType::Protocol);
                }

                return TransportResponse::failure(
                    TransportFailureType::Protocol,
                    soapFaultCode: $faultCode,
                );
            }

            if ($httpStatus !== null && $httpStatus !== 200) {
                return TransportResponse::failure(TransportFailureType::Protocol);
            }

            if (! $this->hasExpectedResponseAction($xpath, $operation)) {
                return TransportResponse::failure(TransportFailureType::Protocol);
            }

            $responseNodes = $xpath->query(
                '/soap:Envelope/soap:Body/*[local-name()="'.$operation->value.'Response" and namespace-uri()="'.$operation->namespace().'"]',
            );

            if ($responseNodes === false || $responseNodes->length !== 1) {
                return TransportResponse::failure(TransportFailureType::Protocol);
            }

            $responseNode = $responseNodes->item(0);

            if (
                ! $responseNode instanceof DOMElement
                || count($this->elementChildren($responseNode)) !== 1
            ) {
                return TransportResponse::failure(TransportFailureType::Protocol);
            }

            $resultNodes = $xpath->query(
                './*[local-name()="'.$operation->resultElement().'" and namespace-uri()="'.$operation->namespace().'"]',
                $responseNode,
            );

            if ($resultNodes === false || $resultNodes->length !== 1) {
                return TransportResponse::failure(TransportFailureType::Protocol);
            }

            $result = $resultNodes->item(0);

            if (! $result instanceof DOMElement || $this->elementChildren($result) !== []) {
                return TransportResponse::failure(TransportFailureType::Protocol);
            }

            $nil = $this->nilValue($result);

            if ($nil === null) {
                return TransportResponse::failure(TransportFailureType::Protocol);
            }

            if ($nil && ! $this->operationAllowsNilResult($operation)) {
                return TransportResponse::failure(
                    TransportFailureType::Protocol,
                    resultWasNil: true,
                );
            }

            if ($nil && $result->childNodes->length !== 0) {
                return TransportResponse::failure(TransportFailureType::Protocol);
            }

            return TransportResponse::success($nil ? '' : $result->textContent, $nil);
        } catch (Throwable) {
            return TransportResponse::failure(TransportFailureType::Protocol);
        }
    }

    public function supportsHttpContentType(?string $httpContentType): bool
    {
        if ($httpContentType === null || $httpContentType === '') {
            return false;
        }

        $contentType = $this->parseContentType($httpContentType);

        if ($contentType === null) {
            return false;
        }

        return match ($contentType['mediaType']) {
            'application/soap+xml', 'multipart/related' => true,
            'application/xop+xml' => strtolower(
                $contentType['parameters']['type'] ?? '',
            ) === 'application/soap+xml',
            default => false,
        };
    }

    private function extractSoapXml(
        #[\SensitiveParameter] string $response,
        ?string $httpContentType,
    ): ?string {
        if ($httpContentType !== null) {
            $contentType = $this->parseContentType($httpContentType);

            if ($contentType === null) {
                return null;
            }

            if ($contentType['mediaType'] === 'application/soap+xml') {
                return $this->plainXmlCandidate($response);
            }

            if (
                $contentType['mediaType'] === 'application/xop+xml'
                && strtolower($contentType['parameters']['type'] ?? '') === 'application/soap+xml'
            ) {
                return $this->plainXmlCandidate($response);
            }

            if ($contentType['mediaType'] !== 'multipart/related') {
                return null;
            }

            return $this->extractMultipartSoap($response, $contentType['parameters']);
        }

        $plainXml = $this->plainXmlCandidate($response);

        if ($plainXml !== null) {
            return $plainXml;
        }

        $trimmed = ltrim($response, "\r\n\t ");
        $parameters = [];
        $multipartBody = $trimmed;

        if (! str_starts_with($multipartBody, '--')) {
            $entity = $this->splitMimeEntity($multipartBody);

            if ($entity === null) {
                return null;
            }

            $headers = $this->parseMimeHeaders($entity['headers']);
            $contentType = $headers === null
                ? null
                : $this->parseContentType($headers['content-type'] ?? '');

            if ($contentType === null || $contentType['mediaType'] !== 'multipart/related') {
                return null;
            }

            $parameters = $contentType['parameters'];
            $multipartBody = $entity['body'];
        }

        if (! isset($parameters['boundary'])) {
            $boundary = $this->inferBoundary($multipartBody);

            if ($boundary === null) {
                return null;
            }

            $parameters['boundary'] = $boundary;
        }

        return $this->extractMultipartSoap($multipartBody, $parameters);
    }

    private function plainXmlCandidate(#[\SensitiveParameter] string $response): ?string
    {
        if (str_starts_with($response, "\xEF\xBB\xBF")) {
            $response = substr($response, 3);
        }

        $trimmed = ltrim($response, "\r\n\t ");

        return str_starts_with($trimmed, '<') ? $trimmed : null;
    }

    /** @param array<string, string> $parameters */
    private function extractMultipartSoap(
        #[\SensitiveParameter] string $body,
        array $parameters,
    ): ?string {
        $boundary = $parameters['boundary'] ?? null;

        if ($boundary === null || ! $this->isValidBoundary($boundary)) {
            return null;
        }

        $relatedType = isset($parameters['type'])
            ? strtolower($parameters['type'])
            : null;

        if (
            $relatedType !== null
            && ! in_array($relatedType, ['application/soap+xml', 'application/xop+xml'], true)
        ) {
            return null;
        }

        $rawParts = $this->splitMultipartBody($body, $boundary);

        if ($rawParts === null || $rawParts === []) {
            return null;
        }

        $parts = [];

        foreach ($rawParts as $rawPart) {
            $entity = $this->splitMimeEntity($rawPart);

            if ($entity === null) {
                return null;
            }

            $headers = $this->parseMimeHeaders($entity['headers']);

            if ($headers === null) {
                return null;
            }

            $contentType = $this->parseContentType($headers['content-type'] ?? '');

            if ($contentType === null) {
                return null;
            }

            $isSoap = $contentType['mediaType'] === 'application/soap+xml'
                || (
                    $contentType['mediaType'] === 'application/xop+xml'
                    && strtolower($contentType['parameters']['type'] ?? '') === 'application/soap+xml'
                );
            $contentId = isset($headers['content-id'])
                ? $this->normalizeContentId($headers['content-id'])
                : null;

            if (isset($headers['content-id']) && $contentId === null) {
                return null;
            }

            $parts[] = [
                'contentId' => $contentId,
                'encoding' => strtolower(trim($headers['content-transfer-encoding'] ?? '8bit')),
                'isSoap' => $isSoap,
                'payload' => $entity['body'],
            ];
        }

        if (count(array_filter($parts, static fn (array $part): bool => $part['isSoap'])) !== 1) {
            return null;
        }

        $rootIndex = 0;

        if (isset($parameters['start'])) {
            $start = $this->normalizeContentId($parameters['start']);

            if ($start === null) {
                return null;
            }

            $matches = [];

            foreach ($parts as $index => $part) {
                if ($part['contentId'] === $start) {
                    $matches[] = $index;
                }
            }

            if (count($matches) !== 1) {
                return null;
            }

            $rootIndex = $matches[0];
        }

        $root = $parts[$rootIndex] ?? null;

        if (! is_array($root) || $root['isSoap'] !== true) {
            return null;
        }

        $payload = rtrim((string) $root['payload'], "\r\n");

        if ($root['encoding'] === 'base64') {
            $decoded = base64_decode($payload, true);

            if (! is_string($decoded)) {
                return null;
            }

            $payload = $decoded;
        } elseif (! in_array($root['encoding'], ['8bit', 'binary'], true)) {
            return null;
        }

        return $payload;
    }

    /** @return list<string>|null */
    private function splitMultipartBody(
        #[\SensitiveParameter] string $body,
        string $boundary,
    ): ?array {
        return $this->splitStrictMultipartBody($body, $boundary)
            ?? $this->splitHistoricallyIndentedSinglePart($body, $boundary);
    }

    /** @return list<string>|null */
    private function splitStrictMultipartBody(
        #[\SensitiveParameter] string $body,
        string $boundary,
    ): ?array {
        $pattern = '/^--'.preg_quote($boundary, '/').'(?<closing>--)?[ \t]*\r?$/m';
        $count = preg_match_all($pattern, $body, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        if (! is_int($count) || $count < 2) {
            return null;
        }

        $lastIndex = $count - 1;

        foreach ($matches as $index => $match) {
            $closing = ($match['closing'][1] ?? -1) !== -1;

            if (($index === $lastIndex) !== $closing) {
                return null;
            }
        }

        $parts = [];

        for ($index = 0; $index < $lastIndex; $index++) {
            $delimiter = $matches[$index][0];
            $nextDelimiter = $matches[$index + 1][0];
            $start = $delimiter[1] + strlen($delimiter[0]);

            if (($body[$start] ?? '') === "\n") {
                $start++;
            }

            $length = $nextDelimiter[1] - $start;

            if ($length < 0) {
                return null;
            }

            $part = substr($body, $start, $length);
            $part = preg_replace('/\r?\n\z/D', '', $part);

            if (! is_string($part) || $part === '') {
                return null;
            }

            $parts[] = $part;
        }

        return $parts;
    }

    /** @return list<string>|null */
    private function splitHistoricallyIndentedSinglePart(
        #[\SensitiveParameter] string $body,
        string $boundary,
    ): ?array {
        $quotedBoundary = preg_quote($boundary, '/');
        $openingPattern = '/^--'.$quotedBoundary.'[ \t]*\r?$/m';
        $closingPattern = '/^(?<indent>[ \t]+)--'.$quotedBoundary.'--[ \t]*\r?$/m';
        $allBoundaryPattern = '/^[ \t]*--'.$quotedBoundary.'(?:--)?[ \t]*\r?$/m';

        if (
            preg_match($openingPattern, $body, $opening, PREG_OFFSET_CAPTURE) !== 1
            || preg_match($closingPattern, $body, $closing, PREG_OFFSET_CAPTURE) !== 1
            || preg_match_all($allBoundaryPattern, $body) !== 2
        ) {
            return null;
        }

        $openingLine = $opening[0][0] ?? null;
        $openingOffset = $opening[0][1] ?? null;
        $closingLine = $closing[0][0] ?? null;
        $closingOffset = $closing[0][1] ?? null;
        $indent = $closing['indent'][0] ?? null;

        if (
            ! is_string($openingLine)
            || ! is_int($openingOffset)
            || ! is_string($closingLine)
            || ! is_int($closingOffset)
            || ! is_string($indent)
            || strlen($indent) > 32
            || $openingOffset >= $closingOffset
            || trim(substr($body, 0, $openingOffset), "\r\n\t ") !== ''
            || trim(substr($body, $closingOffset + strlen($closingLine)), "\r\n\t ") !== ''
        ) {
            return null;
        }

        $partStart = $openingOffset + strlen($openingLine);

        if (($body[$partStart] ?? '') === "\n") {
            $partStart++;
        }

        $part = substr($body, $partStart, $closingOffset - $partStart);
        $part = preg_replace('/\r?\n\z/D', '', $part);

        if (! is_string($part) || $part === '') {
            return null;
        }

        $entity = $this->splitMimeEntity($part);

        if ($entity === null) {
            return null;
        }

        $headers = $this->dedentHistoricalMimeHeaders($entity['headers'], $indent);

        if ($headers === null) {
            return null;
        }

        return [$headers."\r\n\r\n".$entity['body']];
    }

    private function dedentHistoricalMimeHeaders(string $rawHeaders, string $indent): ?string
    {
        $dedented = [];

        foreach (preg_split('/\r?\n/', $rawHeaders) ?: [] as $line) {
            if (! str_starts_with($line, $indent)) {
                return null;
            }

            $line = substr($line, strlen($indent));

            if ($line === '' || preg_match('/^[ \t]/', $line) === 1) {
                return null;
            }

            $dedented[] = $line;
        }

        return $dedented === [] ? null : implode("\r\n", $dedented);
    }

    private function inferBoundary(#[\SensitiveParameter] string $body): ?string
    {
        if (preg_match('/\A--([^\r\n]+)\r?\n/', $body, $matches) !== 1) {
            return null;
        }

        $boundary = rtrim($matches[1], " \t");

        return $this->isValidBoundary($boundary) ? $boundary : null;
    }

    private function isValidBoundary(string $boundary): bool
    {
        return preg_match(
            "~\A[-A-Za-z0-9'()+_,./:=? ]{0,69}[-A-Za-z0-9'()+_,./:=?]\z~D",
            $boundary,
        ) === 1;
    }

    /** @return array{headers: string, body: string}|null */
    private function splitMimeEntity(#[\SensitiveParameter] string $entity): ?array
    {
        $separatorLength = 4;
        $separatorPosition = strpos($entity, "\r\n\r\n");

        if ($separatorPosition === false) {
            $separatorLength = 2;
            $separatorPosition = strpos($entity, "\n\n");
        }

        if ($separatorPosition === false) {
            return null;
        }

        return [
            'headers' => substr($entity, 0, $separatorPosition),
            'body' => substr($entity, $separatorPosition + $separatorLength),
        ];
    }

    /** @return array<string, string>|null */
    private function parseMimeHeaders(string $rawHeaders): ?array
    {
        if (preg_match('/\r(?!\n)|\x00/', $rawHeaders) === 1) {
            return null;
        }

        $headers = [];

        foreach (preg_split('/\r?\n/', $rawHeaders) ?: [] as $line) {
            if ($line === '' || preg_match('/^[ \t]/', $line) === 1) {
                return null;
            }

            $separator = strpos($line, ':');

            if ($separator === false) {
                return null;
            }

            $name = strtolower(trim(substr($line, 0, $separator)));
            $value = trim(substr($line, $separator + 1));

            if (
                preg_match('/\A[!#$%&\'*+\-.^_`|~0-9A-Za-z]+\z/D', $name) !== 1
                || $value === ''
                || array_key_exists($name, $headers)
            ) {
                return null;
            }

            $headers[$name] = $value;
        }

        return $headers;
    }

    /** @return array{mediaType: string, parameters: array<string, string>}|null */
    private function parseContentType(string $contentType): ?array
    {
        $length = strlen($contentType);
        $position = 0;

        while ($position < $length && ctype_space($contentType[$position])) {
            $position++;
        }

        $semicolon = strpos($contentType, ';', $position);
        $mediaEnd = $semicolon === false ? $length : $semicolon;
        $mediaType = strtolower(trim(substr($contentType, $position, $mediaEnd - $position)));

        if (preg_match('/\A[A-Za-z0-9!#$%&\'*+.^_`|~-]+\/[A-Za-z0-9!#$%&\'*+.^_`|~-]+\z/D', $mediaType) !== 1) {
            return null;
        }

        $position = $mediaEnd;
        $parameters = [];

        while ($position < $length) {
            if ($contentType[$position] !== ';') {
                return null;
            }

            $position++;

            while ($position < $length && ctype_space($contentType[$position])) {
                $position++;
            }

            if (preg_match('/\G([!#$%&\'*+\-.^_`|~0-9A-Za-z]+)/', $contentType, $nameMatch, 0, $position) !== 1) {
                return null;
            }

            $name = strtolower($nameMatch[1]);
            $position += strlen($nameMatch[0]);

            while ($position < $length && ctype_space($contentType[$position])) {
                $position++;
            }

            if (($contentType[$position] ?? '') !== '=') {
                return null;
            }

            $position++;

            while ($position < $length && ctype_space($contentType[$position])) {
                $position++;
            }

            if (($contentType[$position] ?? '') === '"') {
                $position++;
                $value = '';
                $closed = false;

                while ($position < $length) {
                    $character = $contentType[$position++];

                    if ($character === '"') {
                        $closed = true;

                        break;
                    }

                    if ($character === '\\') {
                        if ($position >= $length) {
                            return null;
                        }

                        $character = $contentType[$position++];
                    }

                    if (ord($character) < 32 || ord($character) === 127) {
                        return null;
                    }

                    $value .= $character;
                }

                if (! $closed) {
                    return null;
                }
            } else {
                $valueStart = $position;

                while ($position < $length && $contentType[$position] !== ';') {
                    $position++;
                }

                $value = trim(substr($contentType, $valueStart, $position - $valueStart));

                if ($value === '' || preg_match('/[\s\x00-\x1F\x7F]/', $value) === 1) {
                    return null;
                }
            }

            while ($position < $length && ctype_space($contentType[$position])) {
                $position++;
            }

            if (array_key_exists($name, $parameters)) {
                return null;
            }

            $parameters[$name] = $value;
        }

        return ['mediaType' => $mediaType, 'parameters' => $parameters];
    }

    private function normalizeContentId(string $contentId): ?string
    {
        $normalized = trim($contentId);

        if (str_starts_with($normalized, '<') || str_ends_with($normalized, '>')) {
            if (! str_starts_with($normalized, '<') || ! str_ends_with($normalized, '>')) {
                return null;
            }

            $normalized = substr($normalized, 1, -1);
        }

        return $normalized !== '' && preg_match('/[\s<>\x00-\x1F\x7F]/', $normalized) !== 1
            ? $normalized
            : null;
    }

    private function loadXml(#[\SensitiveParameter] string $xml): ?DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $document = new DOMDocument;
            $document->resolveExternals = false;
            $document->substituteEntities = false;

            return $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT) ? $document : null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function hasSupportedEncoding(DOMDocument $document): bool
    {
        $encoding = $document->encoding;

        return $encoding === null || strtoupper(str_replace('_', '-', $encoding)) === 'UTF-8';
    }

    private function isUtf8ByteSequence(#[\SensitiveParameter] string $xml): bool
    {
        return ! str_contains($xml, "\0") && preg_match('//u', $xml) === 1;
    }

    /** @return list<DOMElement> */
    private function elementChildren(DOMElement $element): array
    {
        $children = [];

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $children[] = $child;
            }
        }

        return $children;
    }

    private function nilValue(DOMElement $result): ?bool
    {
        if (! $result->hasAttributeNS(self::XSI_NAMESPACE, 'nil')) {
            return false;
        }

        return match ($result->getAttributeNS(self::XSI_NAMESPACE, 'nil')) {
            'true', '1' => true,
            'false', '0' => false,
            default => null,
        };
    }

    private function hasExpectedResponseAction(DOMXPath $xpath, BirOperation $operation): bool
    {
        $actionNodes = $xpath->query('/soap:Envelope/soap:Header/wsa:Action');

        if ($actionNodes === false || $actionNodes->length !== 1) {
            return false;
        }

        $actionNode = $actionNodes->item(0);

        return $actionNode instanceof DOMElement
            && $this->elementChildren($actionNode) === []
            && trim($actionNode->textContent) === $operation->responseAction();
    }

    private function soapFaultCode(DOMXPath $xpath, DOMElement $fault): ?SoapFaultCode
    {
        $codeNodes = $xpath->query('./soap:Code/soap:Value', $fault);

        if ($codeNodes === false || $codeNodes->length !== 1) {
            return null;
        }

        $codeNode = $codeNodes->item(0);

        if (! $codeNode instanceof DOMElement || $this->elementChildren($codeNode) !== []) {
            return null;
        }

        $value = trim($codeNode->textContent);

        if (preg_match('/\A(?:(?<prefix>[A-Za-z_][A-Za-z0-9_.-]*):)?(?<name>[A-Za-z_][A-Za-z0-9_.-]*)\z/D', $value, $matches) !== 1) {
            return null;
        }

        $prefix = $matches['prefix'];
        $namespace = $codeNode->lookupNamespaceURI($prefix === '' ? null : $prefix);

        if ($namespace !== self::SOAP_NAMESPACE) {
            return null;
        }

        return SoapFaultCode::tryFrom($matches['name']);
    }

    private function operationAllowsNilResult(BirOperation $operation): bool
    {
        return match ($operation) {
            BirOperation::Login,
            BirOperation::Search,
            BirOperation::FullReport,
            BirOperation::BulkReport => true,
            BirOperation::GetValue,
            BirOperation::Logout => false,
        };
    }
}
