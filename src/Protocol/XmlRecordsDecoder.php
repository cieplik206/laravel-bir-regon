<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Protocol;

use DOMDocument;
use DOMElement;
use Throwable;

final readonly class XmlRecordsDecoder
{
    public function __construct(private int $maxResponseBytes = 10_000_000) {}

    public function decode(#[\SensitiveParameter] string $xml): XmlDecodeResult
    {
        try {
            if ($xml === '') {
                return XmlDecodeResult::success([]);
            }

            if (
                strlen($xml) > $this->maxResponseBytes
                || ! $this->isUtf8ByteSequence($xml)
                || stripos($xml, '<!DOCTYPE') !== false
            ) {
                return XmlDecodeResult::failure();
            }

            $document = $this->loadXml($xml);

            if (
                $document === null
                || $document->doctype !== null
                || ! $this->hasSupportedEncoding($document)
                || ! $document->documentElement instanceof DOMElement
                || $document->documentElement->localName !== 'root'
                || $document->documentElement->namespaceURI !== null
            ) {
                return XmlDecodeResult::failure();
            }

            $records = [];
            $error = null;

            foreach ($document->documentElement->childNodes as $recordNode) {
                if (! $recordNode instanceof DOMElement) {
                    continue;
                }

                if ($recordNode->localName !== 'dane' || $recordNode->namespaceURI !== null) {
                    return XmlDecodeResult::failure();
                }

                $record = [];

                foreach ($recordNode->childNodes as $fieldNode) {
                    if (! $fieldNode instanceof DOMElement) {
                        continue;
                    }

                    if ($fieldNode->namespaceURI !== null || $this->hasElementChild($fieldNode)) {
                        return XmlDecodeResult::failure();
                    }

                    $field = $fieldNode->localName;

                    if (array_key_exists($field, $record)) {
                        return XmlDecodeResult::failure();
                    }

                    $record[$field] = $fieldNode->textContent;
                }

                $recordError = $this->errorFromRecord($record);

                if ($recordError !== null) {
                    $error = $recordError;
                } else {
                    $records[] = $record;
                }
            }

            return $error === null
                ? XmlDecodeResult::success($records)
                : XmlDecodeResult::success([], $error);
        } catch (Throwable) {
            return XmlDecodeResult::failure();
        }
    }

    /** @param array<string, string> $record */
    private function errorFromRecord(array $record): ?BirErrorData
    {
        $rawCode = $record['ErrorCode'] ?? '';

        if ($rawCode === '') {
            return null;
        }

        if (! ctype_digit($rawCode) || (int) $rawCode === 0) {
            return new BirErrorData(-1, 'GUS BIR returned an invalid error response.');
        }

        $message = $record['ErrorMessageEn']
            ?? $record['DatabaseSearchResultEn']
            ?? $record['ErrorMessagePl']
            ?? $record['DatabaseSearchResultPl']
            ?? 'GUS BIR rejected the request.';

        return new BirErrorData((int) $rawCode, $message);
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

    private function hasElementChild(DOMElement $element): bool
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                return true;
            }
        }

        return false;
    }
}
