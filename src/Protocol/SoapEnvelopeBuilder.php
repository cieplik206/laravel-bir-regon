<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Protocol;

use cieplik206\BirRegon\Concerns\PreventsSerialization;
use cieplik206\BirRegon\Enums\BulkReportType;
use cieplik206\BirRegon\Enums\ReportType;
use DOMDocument;
use DOMElement;
use SensitiveParameterValue;
use Throwable;

final class SoapEnvelopeBuilder
{
    use PreventsSerialization;

    private const DATA_NAMESPACE = 'http://CIS/BIR/PUBL/2014/07/DataContract';

    private const SOAP_NAMESPACE = 'http://www.w3.org/2003/05/soap-envelope';

    private const WSA_NAMESPACE = 'http://www.w3.org/2005/08/addressing';

    private const XSI_NAMESPACE = 'http://www.w3.org/2001/XMLSchema-instance';

    private readonly SensitiveParameterValue $apiKey;

    private ?SensitiveParameterValue $sessionId = null;

    public function __construct(#[\SensitiveParameter] string $apiKey)
    {
        $this->apiKey = new SensitiveParameterValue($apiKey);
    }

    public function useSession(#[\SensitiveParameter] ?string $sessionId): void
    {
        $this->ensureNotRestoredFromSerialization();
        $this->sessionId = $sessionId === null ? null : new SensitiveParameterValue($sessionId);
    }

    /** @param array<string, mixed> $parameters */
    public function build(
        BirOperation $operation,
        #[\SensitiveParameter] array $parameters,
        string $endpoint,
    ): ?string {
        $this->ensureNotRestoredFromSerialization();

        try {
            $document = new DOMDocument('1.0', 'UTF-8');
            $document->formatOutput = false;

            $envelope = $document->createElementNS(self::SOAP_NAMESPACE, 'soap:Envelope');
            $envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:wsa', self::WSA_NAMESPACE);
            $envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ns', $operation->namespace());
            $envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:dat', self::DATA_NAMESPACE);
            $envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', self::XSI_NAMESPACE);
            $document->appendChild($envelope);

            $header = $document->createElementNS(self::SOAP_NAMESPACE, 'soap:Header');
            $envelope->appendChild($header);
            $this->appendAddressingHeader($document, $header, 'Action', $operation->action());
            $this->appendAddressingHeader($document, $header, 'To', $endpoint);

            $body = $document->createElementNS(self::SOAP_NAMESPACE, 'soap:Body');
            $envelope->appendChild($body);
            $request = $document->createElementNS($operation->namespace(), 'ns:'.$operation->value);
            $body->appendChild($request);
            $this->appendOperationParameters($document, $request, $operation, $parameters);

            $xml = $document->saveXML();

            return is_string($xml) ? $xml : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return [
            'apiKey' => '[REDACTED]',
            'sessionId' => $this->sessionId === null ? '[NONE]' : '[REDACTED]',
        ];
    }

    private function appendAddressingHeader(
        DOMDocument $document,
        DOMElement $header,
        string $name,
        string $value,
    ): void {
        $element = $document->createElementNS(self::WSA_NAMESPACE, 'wsa:'.$name);
        $element->setAttributeNS(self::SOAP_NAMESPACE, 'soap:mustUnderstand', '1');
        $element->appendChild($document->createTextNode($value));
        $header->appendChild($element);
    }

    /** @param array<string, mixed> $parameters */
    private function appendOperationParameters(
        DOMDocument $document,
        DOMElement $request,
        BirOperation $operation,
        #[\SensitiveParameter] array $parameters,
    ): void {
        match ($operation) {
            BirOperation::Login => $this->appendTextParameter(
                $document,
                $request,
                'pKluczUzytkownika',
                $this->apiKey(),
            ),
            BirOperation::Logout => $this->appendTextParameter(
                $document,
                $request,
                'pIdentyfikatorSesji',
                $this->sessionId() ?? '',
            ),
            BirOperation::Search => $this->appendSearchCriteria(
                $document,
                $request,
                $parameters['criteria'] ?? null,
            ),
            BirOperation::FullReport => $this->appendFullReportParameters(
                $document,
                $request,
                $parameters,
            ),
            BirOperation::BulkReport => $this->appendBulkReportParameters(
                $document,
                $request,
                $parameters,
            ),
            BirOperation::GetValue => $this->appendGetValueParameter(
                $document,
                $request,
                $parameters['parameter'] ?? null,
            ),
        };
    }

    private function appendSearchCriteria(
        DOMDocument $document,
        DOMElement $request,
        #[\SensitiveParameter] mixed $criteria,
    ): void {
        if (! $criteria instanceof SearchCriteria) {
            throw new \InvalidArgumentException('Search criteria are required.');
        }

        $container = $document->createElementNS(
            BirOperation::Search->namespace(),
            'ns:pParametryWyszukiwania',
        );
        $request->appendChild($container);

        foreach (SearchCriteria::WSDL_FIELD_ORDER as $field) {
            $element = $document->createElementNS(self::DATA_NAMESPACE, 'dat:'.$field);

            if ($field === $criteria->field) {
                $element->appendChild($document->createTextNode($criteria->value));
            } else {
                $element->setAttributeNS(self::XSI_NAMESPACE, 'xsi:nil', 'true');
            }

            $container->appendChild($element);
        }
    }

    /** @param array<string, mixed> $parameters */
    private function appendFullReportParameters(
        DOMDocument $document,
        DOMElement $request,
        #[\SensitiveParameter] array $parameters,
    ): void {
        $regon = $parameters['regon'] ?? null;
        $reportType = $parameters['reportType'] ?? null;

        if (! is_string($regon) || ! $reportType instanceof ReportType) {
            throw new \InvalidArgumentException('Full report parameters are invalid.');
        }

        $this->appendTextParameter($document, $request, 'pRegon', $regon);
        $this->appendTextParameter($document, $request, 'pNazwaRaportu', $reportType->value);
    }

    /** @param array<string, mixed> $parameters */
    private function appendBulkReportParameters(
        DOMDocument $document,
        DOMElement $request,
        #[\SensitiveParameter] array $parameters,
    ): void {
        $date = $parameters['date'] ?? null;
        $reportType = $parameters['reportType'] ?? null;

        if (! is_string($date) || ! $reportType instanceof BulkReportType) {
            throw new \InvalidArgumentException('Bulk report parameters are invalid.');
        }

        $this->appendTextParameter($document, $request, 'pDataRaportu', $date);
        $this->appendTextParameter($document, $request, 'pNazwaRaportu', $reportType->value);
    }

    private function appendGetValueParameter(
        DOMDocument $document,
        DOMElement $request,
        mixed $parameter,
    ): void {
        if (! $parameter instanceof GetValueParameter) {
            throw new \InvalidArgumentException('GetValue parameter is invalid.');
        }

        $this->appendTextParameter($document, $request, 'pNazwaParametru', $parameter->value);
    }

    private function appendTextParameter(
        DOMDocument $document,
        DOMElement $request,
        string $name,
        #[\SensitiveParameter] string $value,
    ): void {
        $element = $document->createElementNS($request->namespaceURI, 'ns:'.$name);
        $element->appendChild($document->createTextNode($value));
        $request->appendChild($element);
    }

    private function apiKey(): string
    {
        $apiKey = $this->apiKey->getValue();

        return is_string($apiKey) ? $apiKey : '';
    }

    private function sessionId(): ?string
    {
        $sessionId = $this->sessionId?->getValue();

        return is_string($sessionId) ? $sessionId : null;
    }
}
