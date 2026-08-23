<?php

declare(strict_types=1);

use cieplik206\BirRegon\BirClient;
use cieplik206\BirRegon\BirClientInterface;
use cieplik206\BirRegon\BirRegonService;
use cieplik206\BirRegon\Contracts\BirGatewayInterface;
use cieplik206\BirRegon\Enums\BulkReportType;
use cieplik206\BirRegon\Enums\ReportType;
use cieplik206\BirRegon\Exceptions\BirProtocolException;
use cieplik206\BirRegon\Exceptions\BirTransportException;
use cieplik206\BirRegon\Gateway\NativeBirGateway;
use cieplik206\BirRegon\Protocol\BirOperation;
use cieplik206\BirRegon\Protocol\DiagnosticsSnapshot;
use cieplik206\BirRegon\Protocol\GetValueParameter;
use cieplik206\BirRegon\Protocol\SearchCriteria;
use cieplik206\BirRegon\Protocol\TransportFailureType;
use cieplik206\BirRegon\Protocol\TransportResponse;
use cieplik206\BirRegon\Tests\Support\QueueBirSoapTransport;

it('defines logout as a boolean operation on both public client contracts', function (): void {
    foreach ([BirGatewayInterface::class, BirClientInterface::class] as $contract) {
        $reflection = new ReflectionClass($contract);
        $method = $reflection->getMethod('logout');
        $returnType = $method->getReturnType();

        if (! $returnType instanceof ReflectionNamedType) {
            throw new RuntimeException("{$contract}::logout() must declare a named return type.");
        }

        expect($method->isPublic())->toBeTrue()
            ->and($method->getNumberOfParameters())->toBe(0)
            ->and($returnType->getName())->toBe('bool')
            ->and($returnType->allowsNull())->toBeFalse();
    }
});

it('treats logout without a local session as an idempotent success without transport calls', function (): void {
    $transport = new QueueBirSoapTransport;
    $gateway = new NativeBirGateway($transport);

    expect($gateway->logout())->toBeTrue()
        ->and($gateway->logout())->toBeTrue()
        ->and($transport->calls)->toBe([])
        ->and($transport->sessionIds)->toBe([])
        ->and($transport->authenticationChecks)->toBe(0);
});

it('accepts documented logout scalar variants and always starts the next operation with a new SID', function (
    string $rawLogoutResult,
    bool $expected,
): void {
    $firstSession = 'A1234567890123456789';
    $secondSession = 'B1234567890123456789';
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success($firstSession),
        TransportResponse::success(logoutSearchFixture()),
        TransportResponse::success($rawLogoutResult),
    );
    $gateway = new NativeBirGateway($transport);

    expect($gateway->search(SearchCriteria::nip('0123456789')))->toHaveCount(1)
        ->and($gateway->logout())->toBe($expected);

    $transport->queue(
        TransportResponse::success($secondSession),
        TransportResponse::success(logoutSearchFixture()),
    );

    expect($gateway->search(SearchCriteria::nip('0123456789')))->toHaveCount(1)
        ->and(array_column($transport->calls, 0))->toBe([
            BirOperation::Login,
            BirOperation::Search,
            BirOperation::Logout,
            BirOperation::Login,
            BirOperation::Search,
        ])
        ->and($transport->calls[2])->toBe([BirOperation::Logout, [], $firstSession])
        ->and($transport->calls[3][2])->toBeNull()
        ->and($transport->calls[4][2])->toBe($secondSession)
        ->and($transport->authenticationChecks)->toBe(2);
})->with([
    'word true' => ['true', true],
    'numeric true' => ['1', true],
    'word false' => ['false', false],
    'numeric false' => ['0', false],
]);

it('raises a typed failure for an invalid logout result and still clears the local SID', function (
    Closure $logoutResponse,
    string $expectedException,
): void {
    $firstSession = 'A1234567890123456789';
    $secondSession = 'B1234567890123456789';
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success($firstSession),
        TransportResponse::success(logoutSearchFixture()),
        $logoutResponse(),
    );
    $gateway = new NativeBirGateway($transport);
    $gateway->search(SearchCriteria::nip('0123456789'));
    $caught = null;

    if (! is_a($expectedException, Throwable::class, true)) {
        throw new RuntimeException('The expected logout failure must be a Throwable class.');
    }

    try {
        $gateway->logout();
    } catch (Throwable $exception) {
        $caught = $exception;
    }

    expect($caught)->toBeInstanceOf($expectedException)
        ->and($transport->calls[2])->toBe([BirOperation::Logout, [], $firstSession]);

    $transport->queue(
        TransportResponse::success($secondSession),
        TransportResponse::success(logoutSearchFixture()),
    );

    expect($gateway->search(SearchCriteria::nip('0123456789')))->toHaveCount(1)
        ->and(array_column($transport->calls, 0))->toBe([
            BirOperation::Login,
            BirOperation::Search,
            BirOperation::Logout,
            BirOperation::Login,
            BirOperation::Search,
        ])
        ->and($transport->calls[3][2])->toBeNull()
        ->and($transport->calls[4][2])->toBe($secondSession)
        ->and($transport->authenticationChecks)->toBe(2);
})->with([
    'empty result' => [
        static fn (): TransportResponse => TransportResponse::success(''),
        BirProtocolException::class,
    ],
    'nil result' => [
        static fn (): TransportResponse => TransportResponse::success('', true),
        BirProtocolException::class,
    ],
    'unknown scalar' => [
        static fn (): TransportResponse => TransportResponse::success('success'),
        BirProtocolException::class,
    ],
    'SOAP protocol failure' => [
        static fn (): TransportResponse => TransportResponse::failure(TransportFailureType::Protocol),
        BirProtocolException::class,
    ],
    'transport failure response' => [
        static fn (): TransportResponse => TransportResponse::failure(TransportFailureType::Transport),
        BirTransportException::class,
    ],
    'transport exception' => [
        static fn (): RuntimeException => new RuntimeException('Sensitive upstream transport details.'),
        BirTransportException::class,
    ],
]);

it('delegates logout through BirClient without changing the gateway result', function (): void {
    $gateway = new LogoutRecordingGateway(false);
    $client = new BirClient($gateway);

    expect($client->logout())->toBeFalse()
        ->and($gateway->calls)->toBe([['logout']]);
});

it('logs out sandbox and production sessions independently through BirRegonService', function (): void {
    $productionSession = 'PRODUCTIONSESSION001';
    $replacementSandboxSession = 'SANDBOXSESSION000002';
    $sandboxSession = 'SANDBOXSESSION000001';
    $searchResult = logoutSearchFixture();
    $productionTransport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success($productionSession),
        TransportResponse::success($searchResult),
        TransportResponse::success($searchResult),
        TransportResponse::success('1'),
    );
    $sandboxTransport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success($sandboxSession),
        TransportResponse::success($searchResult),
        TransportResponse::success('true'),
        TransportResponse::success($replacementSandboxSession),
        TransportResponse::success($searchResult),
        TransportResponse::success($searchResult),
    );
    $service = new BirRegonService(
        new BirClient(new NativeBirGateway($productionTransport)),
        new BirClient(new NativeBirGateway($sandboxTransport)),
    );

    $service->forNip('1111111111')->get();
    $service->sandbox()->forNip('2222222222')->get();

    expect($service->sandbox()->logout())->toBeTrue();

    $service->forNip('3333333333')->get();
    $service->sandbox()->forNip('4444444444')->get();

    expect($service->logout())->toBeTrue();

    $service->sandbox()->forNip('5555555555')->get();

    expect(array_column($productionTransport->calls, 0))->toBe([
        BirOperation::Login,
        BirOperation::Search,
        BirOperation::Search,
        BirOperation::Logout,
    ])->and(array_column($productionTransport->calls, 2))->toBe([
        null,
        $productionSession,
        $productionSession,
        $productionSession,
    ])->and($productionTransport->calls[3])->toBe([
        BirOperation::Logout,
        [],
        $productionSession,
    ])->and(array_column($sandboxTransport->calls, 0))->toBe([
        BirOperation::Login,
        BirOperation::Search,
        BirOperation::Logout,
        BirOperation::Login,
        BirOperation::Search,
        BirOperation::Search,
    ])->and($sandboxTransport->calls[2])->toBe([
        BirOperation::Logout,
        [],
        $sandboxSession,
    ])->and($sandboxTransport->calls[3][2])->toBeNull()
        ->and($sandboxTransport->calls[4][2])->toBe($replacementSandboxSession)
        ->and($sandboxTransport->calls[5][2])->toBe($replacementSandboxSession)
        ->and($productionTransport->sessionIds)->not->toContain($sandboxSession)
        ->and($productionTransport->sessionIds)->not->toContain($replacementSandboxSession)
        ->and($sandboxTransport->sessionIds)->not->toContain($productionSession);
});

function logoutSearchFixture(): string
{
    $contents = file_get_contents(__DIR__.'/../Fixtures/Gus/inner/search-single.xml');

    if (! is_string($contents)) {
        throw new RuntimeException('The logout search fixture could not be read.');
    }

    return $contents;
}

final class LogoutRecordingGateway implements BirGatewayInterface
{
    /** @var list<array<int, mixed>> */
    public array $calls = [];

    public function __construct(private readonly bool $logoutResult) {}

    public function search(SearchCriteria $criteria): array
    {
        throw new RuntimeException('Unexpected search call.');
    }

    public function fullReport(string $regon, ReportType $reportType): array
    {
        throw new RuntimeException('Unexpected full report call.');
    }

    public function bulkReport(DateTimeImmutable $date, BulkReportType $reportType): array
    {
        throw new RuntimeException('Unexpected bulk report call.');
    }

    public function getValue(GetValueParameter $parameter): string
    {
        throw new RuntimeException('Unexpected GetValue call.');
    }

    public function diagnostics(): DiagnosticsSnapshot
    {
        throw new RuntimeException('Unexpected diagnostics call.');
    }

    public function logout(): bool
    {
        $this->calls[] = ['logout'];

        return $this->logoutResult;
    }
}
