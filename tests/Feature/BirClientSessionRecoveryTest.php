<?php

declare(strict_types=1);

use cieplik206\BirRegon\BirClient;
use cieplik206\BirRegon\Contracts\BirSoapTransportInterface;
use cieplik206\BirRegon\Enums\BulkReportType;
use cieplik206\BirRegon\Enums\ReportType;
use cieplik206\BirRegon\Exceptions\BirAuthenticationException;
use cieplik206\BirRegon\Exceptions\BirNotFoundException;
use cieplik206\BirRegon\Exceptions\BirTransportException;
use cieplik206\BirRegon\Exceptions\BirValidationException;
use cieplik206\BirRegon\Gateway\NativeBirGateway;
use cieplik206\BirRegon\Protocol\BirOperation;
use cieplik206\BirRegon\Protocol\GetValueParameter;
use cieplik206\BirRegon\Protocol\TransportFailureType;
use cieplik206\BirRegon\Protocol\TransportResponse;
use cieplik206\BirRegon\Tests\Support\QueueBirSoapTransport;

it('renews an expired session in a long-lived client and retries the failed search once', function (): void {
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success('A1234567890123456789'),
        TransportResponse::success(clientRecoveryFixture('inner/search-single.xml')),
        TransportResponse::success(''),
        TransportResponse::success('0'),
        TransportResponse::success('7'),
        TransportResponse::success('B1234567890123456789'),
        TransportResponse::success(clientRecoveryFixture('inner/search-single.xml')),
    );
    $client = new BirClient(new NativeBirGateway($transport));

    $initialResult = $client->searchByNip('1111111111');
    $recoveredResult = $client->searchByNip('2222222222');

    expect($initialResult)->toHaveCount(1)
        ->and($initialResult[0]->regon)->toBe('012345678')
        ->and($recoveredResult)->toHaveCount(1)
        ->and($recoveredResult[0]->regon)->toBe('012345678')
        ->and(array_column($transport->calls, 0))->toBe([
            BirOperation::Login,
            BirOperation::Search,
            BirOperation::Search,
            BirOperation::GetValue,
            BirOperation::GetValue,
            BirOperation::Login,
            BirOperation::Search,
        ])
        ->and($transport->calls[3][1])->toBe([
            'parameter' => GetValueParameter::SessionStatus,
        ])
        ->and($transport->calls[4][1])->toBe([
            'parameter' => GetValueParameter::MessageCode,
        ]);
});

it('renews an expired session for an authenticated GetValue operation', function (): void {
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success('A1234567890123456789'),
        TransportResponse::success('23-08-2026'),
        TransportResponse::success(''),
        TransportResponse::success('0'),
        TransportResponse::success('7'),
        TransportResponse::success('B1234567890123456789'),
        TransportResponse::success('23-08-2026'),
    );
    $client = new BirClient(new NativeBirGateway($transport));

    $initialStatus = $client->getDataStatus();
    $recoveredStatus = $client->getDataStatus();

    expect($initialStatus->format('Y-m-d'))->toBe('2026-08-23')
        ->and($recoveredStatus->format('Y-m-d'))->toBe('2026-08-23')
        ->and(array_column($transport->calls, 0))->toBe([
            BirOperation::Login,
            BirOperation::GetValue,
            BirOperation::GetValue,
            BirOperation::GetValue,
            BirOperation::GetValue,
            BirOperation::Login,
            BirOperation::GetValue,
        ])
        ->and($transport->calls[3][1])->toBe([
            'parameter' => GetValueParameter::SessionStatus,
        ])
        ->and($transport->calls[4][1])->toBe([
            'parameter' => GetValueParameter::MessageCode,
        ]);
});

it('renews an expired session when a bulk report silently returns empty data', function (): void {
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success('A1234567890123456789'),
        TransportResponse::success(clientRecoveryFixture('inner/bulk-multiple.xml')),
        TransportResponse::success(''),
        TransportResponse::success('0'),
        TransportResponse::success('7'),
        TransportResponse::success('B1234567890123456789'),
        TransportResponse::success(clientRecoveryFixture('inner/bulk-multiple.xml')),
    );
    $client = new BirClient(new NativeBirGateway($transport));
    $reportType = BulkReportType::NewLegalEntitiesAndNaturalPersons;

    $initialReport = $client->getBulkReport(clientRecoveryReportDate(1), $reportType);
    $recoveredReport = $client->getBulkReport(clientRecoveryReportDate(2), $reportType);

    expect($initialReport->reportData)->toBe(['012345678', '000000001', '987654321'])
        ->and($recoveredReport->reportData)->toBe(['012345678', '000000001', '987654321'])
        ->and(array_column($transport->calls, 0))->toBe([
            BirOperation::Login,
            BirOperation::BulkReport,
            BirOperation::BulkReport,
            BirOperation::GetValue,
            BirOperation::GetValue,
            BirOperation::Login,
            BirOperation::BulkReport,
        ]);
});

it('does not renew an active session for a genuinely empty bulk report', function (): void {
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success('A1234567890123456789'),
        TransportResponse::success(''),
        TransportResponse::success('1'),
        TransportResponse::success('0'),
    );
    $client = new BirClient(new NativeBirGateway($transport));

    $report = $client->getBulkReport(
        clientRecoveryReportDate(1),
        BulkReportType::NewLegalEntitiesAndNaturalPersons,
    );

    expect($report->reportData)->toBe([])
        ->and(array_column($transport->calls, 0))->toBe([
            BirOperation::Login,
            BirOperation::BulkReport,
            BirOperation::GetValue,
            BirOperation::GetValue,
        ]);
});

it('renews a session that expires between a search and a full report', function (): void {
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success('A1234567890123456789'),
        TransportResponse::success(clientRecoveryFixture('inner/search-single.xml')),
        TransportResponse::success(''),
        TransportResponse::success('0'),
        TransportResponse::success('7'),
        TransportResponse::success('B1234567890123456789'),
        TransportResponse::success(clientRecoveryFixture('inner/full-single.xml')),
    );
    $client = new BirClient(new NativeBirGateway($transport));

    $report = $client->getFullReportByNip('1111111111', ReportType::Organization);

    expect($report->basicData->regon)->toBe('012345678')
        ->and($report->reportData)->toHaveCount(1)
        ->and($report->reportData[0]['praw_regon9'])->toBe('012345678')
        ->and(array_column($transport->calls, 0))->toBe([
            BirOperation::Login,
            BirOperation::Search,
            BirOperation::FullReport,
            BirOperation::GetValue,
            BirOperation::GetValue,
            BirOperation::Login,
            BirOperation::FullReport,
        ]);
});

it('retries an expired session only once when the replacement session also expires', function (): void {
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success('A1234567890123456789'),
        TransportResponse::success(clientRecoveryFixture('inner/search-single.xml')),
        TransportResponse::success(''),
        TransportResponse::success('0'),
        TransportResponse::success('7'),
        TransportResponse::success('B1234567890123456789'),
        TransportResponse::success(''),
        TransportResponse::success('0'),
        TransportResponse::success('7'),
    );
    $client = new BirClient(new NativeBirGateway($transport));

    $client->searchByNip('1111111111');

    expect(fn () => $client->searchByNip('2222222222'))
        ->toThrow(BirAuthenticationException::class, 'GUS BIR session could not be renewed.')
        ->and(array_column($transport->calls, 0))->toBe([
            BirOperation::Login,
            BirOperation::Search,
            BirOperation::Search,
            BirOperation::GetValue,
            BirOperation::GetValue,
            BirOperation::Login,
            BirOperation::Search,
            BirOperation::GetValue,
            BirOperation::GetValue,
        ])
        ->and($transport->authenticationChecks)->toBe(2);
});

it('does not retry or renew an active session after an ordinary transport failure', function (): void {
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success('A1234567890123456789'),
        TransportResponse::success(clientRecoveryFixture('inner/search-single.xml')),
        TransportResponse::failure(TransportFailureType::Transport),
        TransportResponse::success(clientRecoveryFixture('inner/search-single.xml')),
    );
    $client = new BirClient(new NativeBirGateway($transport));

    $client->searchByNip('1111111111');

    expect(fn () => $client->searchByNip('2222222222'))
        ->toThrow(BirTransportException::class, 'Unable to communicate with the GUS BIR service.');

    $resultAfterFailure = $client->searchByNip('3333333333');

    expect($resultAfterFailure)->toHaveCount(1)
        ->and($resultAfterFailure[0]->regon)->toBe('012345678')
        ->and(array_column($transport->calls, 0))->toBe([
            BirOperation::Login,
            BirOperation::Search,
            BirOperation::Search,
            BirOperation::Search,
        ])
        ->and($transport->authenticationChecks)->toBe(1);
});

it('maps a GUS not-found record without querying the session status', function (): void {
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success('A1234567890123456789'),
        TransportResponse::success(clientRecoveryFixture('inner/search-error-4.xml')),
    );
    $client = new BirClient(new NativeBirGateway($transport));

    expect(fn () => $client->searchByNip('0000000000'))
        ->toThrow(
            BirNotFoundException::class,
            'Nie znaleziono podmiotu dla identyfikatora typu NIP.',
        )
        ->and(array_column($transport->calls, 0))->toBe([
            BirOperation::Login,
            BirOperation::Search,
        ]);
});

it('performs no transport calls after local batch validation fails', function (): void {
    $transport = new QueueBirSoapTransport;
    $client = new BirClient(new NativeBirGateway($transport));

    expect(fn () => $client->searchByNips(array_fill(0, 21, '1111111111')))
        ->toThrow(
            BirValidationException::class,
            'Too many identifiers. Maximum allowed is 20.',
        )
        ->and($transport->authenticationChecks)->toBe(0)
        ->and($transport->calls)->toBe([])
        ->and($transport->sessionIds)->toBe([]);
});

it('restarts the complete public diagnostics snapshot when its session expires mid-read', function (): void {
    $firstSession = 'A1234567890123456789';
    $secondSession = 'B1234567890123456789';
    $transport = new class($firstSession, $secondSession) implements BirSoapTransportInterface
    {
        /** @var list<string|null> */
        public array $getValueSessionIds = [];

        private bool $firstSessionExpired = false;

        private int $loginCount = 0;

        private ?string $sessionId = null;

        public function __construct(
            private readonly string $firstSession,
            private readonly string $secondSession,
        ) {}

        public function isAuthenticationConfigured(): bool
        {
            return true;
        }

        public function useSession(?string $sessionId): void
        {
            $this->sessionId = $sessionId;
        }

        public function call(
            BirOperation $operation,
            #[SensitiveParameter] array $parameters = [],
        ): TransportResponse {
            if ($operation === BirOperation::Login) {
                $this->loginCount++;

                return TransportResponse::success(
                    $this->loginCount === 1 ? $this->firstSession : $this->secondSession,
                );
            }

            if ($operation !== BirOperation::GetValue) {
                return TransportResponse::failure(TransportFailureType::Protocol);
            }

            $this->getValueSessionIds[] = $this->sessionId;
            $parameter = $parameters['parameter'] ?? null;

            if (! $parameter instanceof GetValueParameter) {
                return TransportResponse::failure(TransportFailureType::Protocol);
            }

            if ($this->sessionId === $this->firstSession) {
                return match ($parameter) {
                    GetValueParameter::MessageCode => TransportResponse::success(
                        $this->firstSessionExpired ? '7' : '4',
                    ),
                    GetValueParameter::Message => $this->expireFirstSession(),
                    GetValueParameter::SessionStatus => TransportResponse::success(
                        $this->firstSessionExpired ? '0' : '1',
                    ),
                    default => TransportResponse::failure(TransportFailureType::Protocol),
                };
            }

            return match ($parameter) {
                GetValueParameter::MessageCode => TransportResponse::success('0'),
                GetValueParameter::Message => TransportResponse::success('replacement session message'),
                GetValueParameter::SessionStatus => TransportResponse::success('1'),
                default => TransportResponse::failure(TransportFailureType::Protocol),
            };
        }

        private function expireFirstSession(): TransportResponse
        {
            $this->firstSessionExpired = true;

            return TransportResponse::success('');
        }
    };
    $client = new BirClient(new NativeBirGateway($transport));

    $diagnostics = $client->getDiagnostics();

    expect($diagnostics->messageCode)->toBe(0)
        ->and($diagnostics->message)->toBe('replacement session message')
        ->and($diagnostics->sessionStatus)->toBe(1)
        ->and($transport->getValueSessionIds)->toBe([
            $firstSession,
            $firstSession,
            $firstSession,
            $secondSession,
            $secondSession,
            $secondSession,
        ]);
});

function clientRecoveryFixture(string $relativePath): string
{
    $contents = file_get_contents(__DIR__.'/../Fixtures/Gus/'.$relativePath);

    if ($contents === false) {
        throw new RuntimeException("Unable to read GUS fixture: {$relativePath}");
    }

    return $contents;
}

function clientRecoveryReportDate(int $daysAgo): DateTimeImmutable
{
    return new DateTimeImmutable(
        "-{$daysAgo} days",
        new DateTimeZone('Europe/Warsaw'),
    );
}
