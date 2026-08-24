<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Gateway;

use cieplik206\BirRegon\Concerns\PreventsSerialization;
use cieplik206\BirRegon\Contracts\BirEnvironmentAwareTransportInterface;
use cieplik206\BirRegon\Contracts\BirGatewayInterface;
use cieplik206\BirRegon\Contracts\BirRateLimitScopeInterface;
use cieplik206\BirRegon\Contracts\BirSoapTransportInterface;
use cieplik206\BirRegon\Enums\BulkReportType;
use cieplik206\BirRegon\Enums\ReportType;
use cieplik206\BirRegon\Exceptions\BirAuthenticationException;
use cieplik206\BirRegon\Exceptions\BirNotFoundException;
use cieplik206\BirRegon\Exceptions\BirProtocolException;
use cieplik206\BirRegon\Exceptions\BirRateLimitException;
use cieplik206\BirRegon\Exceptions\BirReportException;
use cieplik206\BirRegon\Exceptions\BirSoapFaultException;
use cieplik206\BirRegon\Exceptions\BirTransportException;
use cieplik206\BirRegon\Protocol\BirErrorData;
use cieplik206\BirRegon\Protocol\BirOperation;
use cieplik206\BirRegon\Protocol\DiagnosticsSnapshot;
use cieplik206\BirRegon\Protocol\GetValueParameter;
use cieplik206\BirRegon\Protocol\SearchCriteria;
use cieplik206\BirRegon\Protocol\SearchResult;
use cieplik206\BirRegon\Protocol\TransportFailureType;
use cieplik206\BirRegon\Protocol\TransportResponse;
use cieplik206\BirRegon\Protocol\XmlDecodeResult;
use cieplik206\BirRegon\Protocol\XmlRecordsDecoder;
use DateTimeImmutable;
use SensitiveParameterValue;
use Throwable;

final class NativeBirGateway implements BirGatewayInterface
{
    use PreventsSerialization;

    private readonly SensitiveParameterValue $transport;

    public function __construct(
        #[\SensitiveParameter] BirSoapTransportInterface $transport,
        #[\SensitiveParameter] private readonly XmlRecordsDecoder $recordsDecoder = new XmlRecordsDecoder,
        #[\SensitiveParameter] private readonly BirSessionState $session = new BirSessionState,
    ) {
        $this->transport = new SensitiveParameterValue($transport);
    }

    public function search(#[\SensitiveParameter] SearchCriteria $criteria): array
    {
        $decoded = $this->callForRecords(
            BirOperation::Search,
            ['criteria' => $criteria],
        );

        if ($decoded->error !== null) {
            if ($decoded->error->code === 4) {
                return [];
            }

            throw new BirProtocolException(
                'GUS BIR rejected the search request (code '.$decoded->error->code.').',
            );
        }

        $results = [];

        foreach ($decoded->records as $record) {
            $result = SearchResult::tryFromRecord($record);

            if ($result === null) {
                throw new BirProtocolException('GUS BIR returned an invalid search record.');
            }

            $results[] = $result;
        }

        return $results;
    }

    public function fullReport(
        #[\SensitiveParameter] string $regon,
        ReportType $reportType,
    ): array {
        $decoded = $this->callForRecords(BirOperation::FullReport, [
            'regon' => $regon,
            'reportType' => $reportType,
        ]);

        if ($decoded->error !== null) {
            if ($decoded->error->code < 1) {
                throw new BirProtocolException(
                    'GUS BIR returned an invalid report error response.',
                );
            }

            if ($decoded->error->code === 4) {
                throw new BirNotFoundException('REGON');
            }

            throw new BirReportException(
                $decoded->error->code,
                'GUS BIR rejected the full report (code '.$decoded->error->code.').',
            );
        }

        return $decoded->records;
    }

    public function bulkReport(DateTimeImmutable $date, BulkReportType $reportType): array
    {
        $decoded = $this->callForRecords(BirOperation::BulkReport, [
            'date' => $date->format('Y-m-d'),
            'reportType' => $reportType,
        ]);

        if ($decoded->error !== null) {
            if ($decoded->error->code < 1) {
                throw new BirProtocolException(
                    'GUS BIR returned an invalid report error response.',
                );
            }

            if ($decoded->error->code === 4) {
                return [];
            }

            throw new BirReportException(
                $decoded->error->code,
                'GUS BIR rejected the bulk report (code '.$decoded->error->code.').',
            );
        }

        $regons = [];
        $expectedRegonLength = match ($reportType) {
            BulkReportType::NewLegalEntitiesAndNaturalPersons,
            BulkReportType::UpdatedLegalEntitiesAndNaturalPersons,
            BulkReportType::DeletedLegalEntitiesAndNaturalPersons => 9,
            BulkReportType::NewLocalUnits,
            BulkReportType::UpdatedLocalUnits,
            BulkReportType::DeletedLocalUnits => 14,
        };

        foreach ($decoded->records as $record) {
            $regon = $record['regon'] ?? $record['Regon'] ?? null;

            if (
                ! is_string($regon)
                || preg_match('/^\d{'.$expectedRegonLength.'}$/D', $regon) !== 1
            ) {
                throw new BirProtocolException('GUS BIR returned an invalid bulk report record.');
            }

            $regons[] = $regon;
        }

        return $regons;
    }

    public function getValue(GetValueParameter $parameter): string
    {
        $this->ensureNotRestoredFromSerialization();

        if (! $parameter->requiresSession()) {
            return $this->callUnauthenticated(BirOperation::GetValue, ['parameter' => $parameter]);
        }

        return $this->callAuthenticatedScalar(BirOperation::GetValue, ['parameter' => $parameter]);
    }

    public function diagnostics(): DiagnosticsSnapshot
    {
        $this->ensureNotRestoredFromSerialization();

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->ensureSession();
            $sessionId = $this->session->id();
            $messageCodeResponse = $this->callTransportUsingSession(BirOperation::GetValue, [
                'parameter' => GetValueParameter::MessageCode,
            ], $sessionId);
            $messageResponse = $this->callTransportUsingSession(BirOperation::GetValue, [
                'parameter' => GetValueParameter::Message,
            ], $sessionId);
            $statusResponse = $this->callTransportUsingSession(BirOperation::GetValue, [
                'parameter' => GetValueParameter::SessionStatus,
            ], $sessionId);

            if ($messageResponse->resultWasNil) {
                throw new BirProtocolException('GUS BIR returned invalid diagnostics.');
            }

            $sessionDiagnostics = $this->sessionDiagnosticsFromResponses(
                $statusResponse,
                $messageCodeResponse,
            );

            if ($this->recoverSessionIfExpired($sessionDiagnostics, $attempt)) {
                continue;
            }

            foreach ([$messageCodeResponse, $messageResponse, $statusResponse] as $response) {
                if (! $response->successful) {
                    $this->throwTransportFailure($response);
                }

                if ($response->resultWasNil) {
                    throw new BirProtocolException('GUS BIR returned invalid diagnostics.');
                }
            }

            $rawMessageCode = trim($messageCodeResponse->result() ?? '');
            $message = $messageResponse->result() ?? '';
            $rawSessionStatus = trim($statusResponse->result() ?? '');

            if (
                preg_match('/^\d+$/D', $rawMessageCode) !== 1
                || preg_match('/^[01]$/D', $rawSessionStatus) !== 1
            ) {
                throw new BirProtocolException('GUS BIR returned invalid diagnostics.');
            }

            return new DiagnosticsSnapshot(
                messageCode: (int) $rawMessageCode,
                message: $message,
                sessionStatus: (int) $rawSessionStatus,
            );
        }

        throw new BirAuthenticationException('GUS BIR session could not be renewed.');
    }

    public function logout(): bool
    {
        $this->ensureNotRestoredFromSerialization();

        if (! $this->session->hasSession()) {
            return true;
        }

        $sessionId = $this->session->id();

        try {
            $response = $this->callTransportUsingSession(BirOperation::Logout, [], $sessionId);

            if ($response->resultWasNil) {
                throw new BirProtocolException('GUS BIR returned an invalid logout response.');
            }

            if (! $response->successful) {
                $this->throwTransportFailure($response);
            }

            return match (trim($response->result() ?? '')) {
                'true', '1' => true,
                'false', '0' => false,
                default => throw new BirProtocolException(
                    'GUS BIR returned an invalid logout response.',
                ),
            };
        } finally {
            $this->resetSession();
        }
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return [
            'transport' => '[HIDDEN]',
            'sessionId' => '[REDACTED]',
        ];
    }

    /** @param array<string, mixed> $parameters */
    private function callForRecords(
        BirOperation $operation,
        #[\SensitiveParameter] array $parameters,
    ): XmlDecodeResult {
        $this->ensureNotRestoredFromSerialization();

        $transport = $this->transport();
        $scopedTransport = $transport instanceof BirRateLimitScopeInterface
            ? $transport
            : null;
        $scopedTransport?->beginRateLimitScope();

        try {
            return $this->callForRecordsWithinScope($operation, $parameters);
        } finally {
            $scopedTransport?->endRateLimitScope();
        }
    }

    /** @param array<string, mixed> $parameters */
    private function callForRecordsWithinScope(
        BirOperation $operation,
        #[\SensitiveParameter] array $parameters,
    ): XmlDecodeResult {

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->ensureSession();
            $response = $this->callTransport($operation, $parameters);

            if (! $response->successful) {
                $this->throwTransportFailure($response);
            }

            $result = $response->result() ?? '';
            $decoded = $this->recordsDecoder->decode($result);

            if (! $decoded->successful) {
                throw new BirProtocolException('GUS BIR returned malformed report XML.');
            }

            if ($decoded->error?->code === 7) {
                $this->resetExpiredSessionForRetry($attempt);

                continue;
            }

            if ($result === '') {
                $diagnostics = $this->diagnoseSession();

                if ($this->recoverSessionIfExpired($diagnostics, $attempt)) {
                    continue;
                }

                if (! $diagnostics->isComplete()) {
                    throw new BirProtocolException(
                        'GUS BIR returned incomplete diagnostics for an empty response.',
                    );
                }

                if ($diagnostics->messageCode !== 0) {
                    return XmlDecodeResult::success([], new BirErrorData(
                        $diagnostics->messageCode,
                        'GUS BIR rejected the request.',
                    ));
                }
            }

            return $decoded;
        }

        throw new BirAuthenticationException('GUS BIR session could not be renewed.');
    }

    /** @param array<string, mixed> $parameters */
    private function callAuthenticatedScalar(
        BirOperation $operation,
        #[\SensitiveParameter] array $parameters,
    ): string {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->ensureSession();
            $response = $this->callTransport($operation, $parameters);
            $this->assertScalarResultWasNotNil($response, $operation);

            if (! $response->successful) {
                $this->throwTransportFailure($response);
            }

            $result = $response->result() ?? '';

            if ($result === '') {
                $diagnostics = $this->diagnoseSession();

                if ($this->recoverSessionIfExpired($diagnostics, $attempt)) {
                    continue;
                }

                if (! $diagnostics->isComplete() || $diagnostics->messageCode !== 0) {
                    throw new BirProtocolException(
                        'GUS BIR returned invalid diagnostics for an empty response.',
                    );
                }
            }

            return $result;
        }

        throw new BirAuthenticationException('GUS BIR session could not be renewed.');
    }

    /** @param array<string, mixed> $parameters */
    private function callUnauthenticated(
        BirOperation $operation,
        #[\SensitiveParameter] array $parameters,
    ): string {
        try {
            $transport = $this->transport();
            $transport->useSession(null);
            $response = $transport->call($operation, $parameters);
        } catch (BirRateLimitException $exception) {
            throw $exception;
        } catch (Throwable) {
            $response = TransportResponse::failure(TransportFailureType::Transport);
        }

        $this->assertScalarResultWasNotNil($response, $operation);

        if (! $response->successful) {
            $this->throwTransportFailure($response);
        }

        return $response->result() ?? '';
    }

    private function ensureSession(): void
    {
        if ($this->session->hasSession()) {
            return;
        }

        try {
            $transport = $this->transport();
            $authenticationConfigured = $transport->isAuthenticationConfigured();
        } catch (Throwable) {
            throw new BirTransportException('Unable to configure GUS BIR authentication.');
        }

        if (! $authenticationConfigured) {
            $environmentVariable = null;

            if ($transport instanceof BirEnvironmentAwareTransportInterface) {
                try {
                    $environmentVariable = $transport->environment()->apiKeyEnvironmentVariable();
                } catch (Throwable) {
                    // Environment metadata is optional; keep the safe neutral hint.
                }
            }

            throw new BirAuthenticationException(
                $environmentVariable === null
                    ? 'BIR API key is not configured for the selected environment. Configure BIR_API_KEY for production or BIR_SANDBOX_API_KEY for sandbox.'
                    : 'BIR API key is not configured. Set '.$environmentVariable.' in your .env file.',
            );
        }

        try {
            $transport = $this->transport();
            $transport->useSession(null);
            $response = $transport->call(BirOperation::Login);
        } catch (BirRateLimitException $exception) {
            throw $exception;
        } catch (Throwable) {
            $response = TransportResponse::failure(TransportFailureType::Transport);
        }

        if (! $response->successful) {
            $this->throwTransportFailure($response);
        }

        $sessionId = $response->result() ?? '';

        if ($sessionId === '') {
            throw new BirAuthenticationException('Invalid API key');
        }

        if (preg_match('/^[A-Za-z0-9]{20}$/D', $sessionId) !== 1) {
            throw new BirProtocolException('GUS BIR returned an invalid session identifier.');
        }

        $this->session->start($sessionId);

        try {
            $this->transport()->useSession($sessionId);
        } catch (Throwable) {
            $this->session->clear();

            throw new BirTransportException('Unable to configure the GUS BIR session.');
        }
    }

    /** @param array<string, mixed> $parameters */
    private function callTransport(
        BirOperation $operation,
        #[\SensitiveParameter] array $parameters,
    ): TransportResponse {
        return $this->callTransportUsingSession(
            $operation,
            $parameters,
            $this->session->id(),
        );
    }

    /** @param array<string, mixed> $parameters */
    private function callTransportUsingSession(
        BirOperation $operation,
        #[\SensitiveParameter] array $parameters,
        #[\SensitiveParameter] ?string $sessionId,
    ): TransportResponse {
        try {
            $transport = $this->transport();
            $transport->useSession($sessionId);

            return $transport->call($operation, $parameters);
        } catch (BirRateLimitException $exception) {
            throw $exception;
        } catch (Throwable) {
            return TransportResponse::failure(TransportFailureType::Transport);
        }
    }

    private function diagnoseSession(): BirSessionDiagnostics
    {
        if (! $this->session->hasSession()) {
            return new BirSessionDiagnostics(false, null);
        }

        $sessionId = $this->session->id();

        $statusResponse = $this->callTransportUsingSession(BirOperation::GetValue, [
            'parameter' => GetValueParameter::SessionStatus,
        ], $sessionId);
        $messageCodeResponse = $this->callTransportUsingSession(BirOperation::GetValue, [
            'parameter' => GetValueParameter::MessageCode,
        ], $sessionId);

        return $this->sessionDiagnosticsFromResponses($statusResponse, $messageCodeResponse);
    }

    private function sessionDiagnosticsFromResponses(
        TransportResponse $statusResponse,
        TransportResponse $messageCodeResponse,
    ): BirSessionDiagnostics {
        if (
            $statusResponse->resultWasNil
            || $messageCodeResponse->resultWasNil
        ) {
            throw new BirProtocolException('GUS BIR returned invalid diagnostics.');
        }

        foreach ([$statusResponse, $messageCodeResponse] as $response) {
            if (! $response->successful) {
                $this->throwTransportFailure($response);
            }
        }

        $status = trim($statusResponse->result() ?? '');
        $rawMessageCode = trim($messageCodeResponse->result() ?? '');

        $active = match ($status) {
            '', '0' => false,
            '1' => true,
            default => null,
        };
        $messageCode = preg_match('/^\d+$/D', $rawMessageCode) === 1
            ? (int) $rawMessageCode
            : null;

        return new BirSessionDiagnostics(
            $active,
            $messageCode,
            $messageCodeResponse->successful && $rawMessageCode === '',
        );
    }

    private function assertScalarResultWasNotNil(
        TransportResponse $response,
        BirOperation $operation,
    ): void {
        if ($response->resultWasNil) {
            throw new BirProtocolException(sprintf(
                'GUS BIR returned an invalid %s response.',
                $operation->name,
            ));
        }
    }

    private function recoverSessionIfExpired(
        BirSessionDiagnostics $diagnostics,
        int $attempt,
    ): bool {
        if (! $diagnostics->indicatesExpiredSession()) {
            return false;
        }

        $this->resetExpiredSessionForRetry($attempt);

        return true;
    }

    private function resetExpiredSessionForRetry(int $attempt): void
    {
        if ($attempt !== 0) {
            $this->resetSession();

            throw new BirAuthenticationException('GUS BIR session could not be renewed.');
        }

        $this->resetSession();
    }

    private function resetSession(): void
    {
        $this->session->clear();

        try {
            $this->transport()->useSession(null);
        } catch (Throwable) {
            // A transport will be reconfigured before the next operation.
        }
    }

    private function throwTransportFailure(TransportResponse $response): never
    {
        if ($response->soapFaultCode !== null) {
            throw new BirSoapFaultException($response->soapFaultCode);
        }

        if ($response->failureType === TransportFailureType::Transport) {
            throw new BirTransportException('Unable to communicate with the GUS BIR service.');
        }

        throw new BirProtocolException('GUS BIR returned an invalid SOAP response.');
    }

    private function transport(): BirSoapTransportInterface
    {
        $transport = $this->transport->getValue();

        if (! $transport instanceof BirSoapTransportInterface) {
            throw new \LogicException('The BIR SOAP transport is unavailable.');
        }

        return $transport;
    }
}
