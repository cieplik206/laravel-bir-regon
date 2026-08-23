<?php

declare(strict_types=1);

use cieplik206\BirRegon\Contracts\BirSoapTransportInterface;
use cieplik206\BirRegon\Enums\BulkReportType;
use cieplik206\BirRegon\Enums\Environment;
use cieplik206\BirRegon\Enums\ReportType;
use cieplik206\BirRegon\Enums\SoapFaultCode;
use cieplik206\BirRegon\Exceptions\BirAuthenticationException;
use cieplik206\BirRegon\Exceptions\BirNotFoundException;
use cieplik206\BirRegon\Exceptions\BirProtocolException;
use cieplik206\BirRegon\Exceptions\BirRateLimitException;
use cieplik206\BirRegon\Exceptions\BirReportException;
use cieplik206\BirRegon\Exceptions\BirSoapFaultException;
use cieplik206\BirRegon\Exceptions\BirTransportException;
use cieplik206\BirRegon\Gateway\BirSessionState;
use cieplik206\BirRegon\Gateway\NativeBirGateway;
use cieplik206\BirRegon\Protocol\BirOperation;
use cieplik206\BirRegon\Protocol\GetValueParameter;
use cieplik206\BirRegon\Protocol\RawTransportResult;
use cieplik206\BirRegon\Protocol\SearchCriteria;
use cieplik206\BirRegon\Protocol\TransportFailureType;
use cieplik206\BirRegon\Protocol\TransportResponse;
use cieplik206\BirRegon\RateLimit\CacheBirRequestLimiter;
use cieplik206\BirRegon\Tests\Support\QueueBirSoapTransport;
use cieplik206\BirRegon\Transport\BirHttpSenderInterface;
use cieplik206\BirRegon\Transport\NativeSoapTransport;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

it('logs in once and reuses the SID for authenticated requests', function (): void {
    $firstSession = 'A1234567890123456789';
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success($firstSession),
        TransportResponse::success(nativeGatewayFixture('inner/search-single.xml')),
        TransportResponse::success(nativeGatewayFixture('inner/search-single.xml')),
    );
    $gateway = new NativeBirGateway($transport);

    $first = $gateway->search(SearchCriteria::nip('0123456789'));
    $second = $gateway->search(SearchCriteria::regon('012345678'));

    expect($first)->toHaveCount(1)
        ->and($second)->toHaveCount(1)
        ->and($first[0]->regon)->toBe('012345678')
        ->and(array_column($transport->calls, 0))->toBe([
            BirOperation::Login,
            BirOperation::Search,
            BirOperation::Search,
        ])
        ->and(array_column($transport->calls, 2))->toBe([
            null,
            $firstSession,
            $firstSession,
        ])
        ->and($transport->authenticationChecks)->toBe(1);
});

it('preserves every search row in source order', function (): void {
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success('A1234567890123456789'),
        TransportResponse::success(nativeGatewayFixture('inner/search-multiple.xml')),
    );
    $gateway = new NativeBirGateway($transport);

    $results = $gateway->search(SearchCriteria::nips([
        '0123456789',
        '9876543210',
    ]));

    expect($results)->toHaveCount(2)
        ->and($results[0]->regon)->toBe('012345678')
        ->and($results[0]->name)->toBe('FIKCYJNA SPÓŁKA TESTOWA')
        ->and($results[1]->regon)->toBe('987654321')
        ->and($results[1]->name)->toBe('FIKCYJNE GOSPODARSTWO TESTOWE')
        ->and($results[1]->activityEndDate)->toBe('2025-12-31');
});

it('reads public GetValue parameters without a key or login', function (): void {
    $transport = (new QueueBirSoapTransport(false))->queue(
        TransportResponse::success('1'),
    );
    $gateway = new NativeBirGateway($transport);

    $status = $gateway->getValue(GetValueParameter::ServiceStatus);

    expect($status)->toBe('1')
        ->and($transport->authenticationChecks)->toBe(0)
        ->and($transport->calls)->toHaveCount(1)
        ->and($transport->calls[0][0])->toBe(BirOperation::GetValue)
        ->and($transport->calls[0][1])->toBe([
            'parameter' => GetValueParameter::ServiceStatus,
        ])
        ->and($transport->calls[0][2])->toBeNull();
});

it('rejects a nil public GetValue result from a custom transport without authentication', function (): void {
    $transport = (new QueueBirSoapTransport(false))->queue(
        TransportResponse::success('', true),
    );
    $gateway = new NativeBirGateway($transport);

    expect(fn () => $gateway->getValue(GetValueParameter::ServiceMessage))
        ->toThrow(BirProtocolException::class, 'GUS BIR returned an invalid GetValue response.')
        ->and($transport->authenticationChecks)->toBe(0)
        ->and(array_column($transport->calls, 0))->toBe([
            BirOperation::GetValue,
        ]);
});

it('logs in before reading an authenticated GetValue parameter', function (): void {
    $session = 'A1234567890123456789';
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success($session),
        TransportResponse::success('23-08-2026'),
    );
    $gateway = new NativeBirGateway($transport);

    $status = $gateway->getValue(GetValueParameter::DataStatus);

    expect($status)->toBe('23-08-2026')
        ->and(array_column($transport->calls, 0))->toBe([
            BirOperation::Login,
            BirOperation::GetValue,
        ])
        ->and($transport->calls[1][1])->toBe([
            'parameter' => GetValueParameter::DataStatus,
        ])
        ->and($transport->calls[1][2])->toBe($session);
});

it('rejects a nil authenticated GetValue result from a custom transport without recovery', function (): void {
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success('A1234567890123456789'),
        TransportResponse::success('', true),
    );
    $gateway = new NativeBirGateway($transport);

    expect(fn () => $gateway->getValue(GetValueParameter::Message))
        ->toThrow(BirProtocolException::class, 'GUS BIR returned an invalid GetValue response.')
        ->and(array_column($transport->calls, 0))->toBe([
            BirOperation::Login,
            BirOperation::GetValue,
        ])
        ->and($transport->authenticationChecks)->toBe(1);
});

it('preserves a native nil protocol failure without running session recovery', function (): void {
    $sender = new NativeGatewayQueueHttpSender(
        nativeGatewaySoapResponse(BirOperation::Login, 'A1234567890123456789'),
        nativeGatewaySoapResponse(BirOperation::GetValue, '', ' xsi:nil="true"'),
        nativeGatewaySoapResponse(BirOperation::GetValue, '0'),
        nativeGatewaySoapResponse(BirOperation::GetValue, '7'),
        nativeGatewaySoapResponse(BirOperation::Login, 'B1234567890123456789'),
        nativeGatewaySoapResponse(BirOperation::GetValue, 'recovered'),
    );
    $gateway = new NativeBirGateway(new NativeSoapTransport(
        apiKey: 'APIKEYSENTINEL123456',
        environment: Environment::Sandbox,
        httpSender: $sender,
    ));

    expect(fn () => $gateway->getValue(GetValueParameter::Message))
        ->toThrow(BirProtocolException::class, 'GUS BIR returned an invalid GetValue response.')
        ->and($sender->operations)->toBe([
            BirOperation::Login,
            BirOperation::GetValue,
        ]);
});

it('does not classify nil session diagnostics as an expired session', function (): void {
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success('A1234567890123456789'),
        TransportResponse::failure(TransportFailureType::Protocol, resultWasNil: true),
        TransportResponse::success('fixture message'),
        TransportResponse::success('1'),
    );
    $gateway = new NativeBirGateway($transport);

    expect(fn () => $gateway->diagnostics())
        ->toThrow(BirProtocolException::class, 'GUS BIR returned invalid diagnostics.')
        ->and(array_column($transport->calls, 0))->toBe([
            BirOperation::Login,
            BirOperation::GetValue,
            BirOperation::GetValue,
            BirOperation::GetValue,
        ])
        ->and($transport->authenticationChecks)->toBe(1);
});

it('paces a cold native diagnostics sequence instead of interrupting it', function (): void {
    $now = new DateTimeImmutable('2026-08-23 08:00:00', new DateTimeZone('Europe/Warsaw'));
    $sleeps = [];
    $limiter = new CacheBirRequestLimiter(
        new Repository(new ArrayStore),
        Environment::Production,
        'APIKEYSENTINEL123456',
        'bir-test-cold-diagnostics',
        static function () use (&$now): DateTimeImmutable {
            return $now;
        },
        static function (float $seconds) use (&$now, &$sleeps): void {
            $sleeps[] = $seconds;
            $now = nativeGatewayRateLimitTimeAt((float) $now->format('U.u') + $seconds);
        },
    );
    $sender = new NativeGatewayQueueHttpSender(
        nativeGatewaySoapResponse(BirOperation::Login, 'A1234567890123456789'),
        nativeGatewaySoapResponse(BirOperation::GetValue, '0'),
        nativeGatewaySoapResponse(BirOperation::GetValue, 'No errors'),
        nativeGatewaySoapResponse(BirOperation::GetValue, '1'),
    );
    $gateway = new NativeBirGateway(new NativeSoapTransport(
        apiKey: 'APIKEYSENTINEL123456',
        environment: Environment::Production,
        httpSender: $sender,
        requestLimiter: $limiter,
    ));

    $diagnostics = $gateway->diagnostics();

    expect($diagnostics->messageCode)->toBe(0)
        ->and($diagnostics->message)->toBe('No errors')
        ->and($diagnostics->sessionStatus)->toBe(1)
        ->and($sender->operations)->toBe([
            BirOperation::Login,
            BirOperation::GetValue,
            BirOperation::GetValue,
            BirOperation::GetValue,
        ])
        ->and($sleeps)->toHaveCount(3)
        ->and($sleeps[0])->toBeGreaterThan(0.33)
        ->and($sleeps[0])->toBeLessThan(0.34)
        ->and($sleeps[1])->toBeGreaterThan(0.33)
        ->and($sleeps[1])->toBeLessThan(0.34)
        ->and($sleeps[2])->toBeGreaterThan(0.33)
        ->and($sleeps[2])->toBeLessThan(0.34);
});

it('paces every short debt in a native expired-session recovery sequence', function (): void {
    $now = new DateTimeImmutable('2026-08-23 08:00:00', new DateTimeZone('Europe/Warsaw'));
    $sleeps = [];
    $limiter = new CacheBirRequestLimiter(
        new Repository(new ArrayStore),
        Environment::Production,
        'APIKEYSENTINEL123456',
        'bir-test-native-recovery',
        static function () use (&$now): DateTimeImmutable {
            return $now;
        },
        static function (float $seconds) use (&$now, &$sleeps): void {
            $sleeps[] = $seconds;
            $now = nativeGatewayRateLimitTimeAt((float) $now->format('U.u') + $seconds);
        },
    );
    $searchResult = htmlspecialchars(
        nativeGatewayFixture('inner/search-single.xml'),
        ENT_QUOTES | ENT_XML1,
        'UTF-8',
    );
    $sender = new NativeGatewayQueueHttpSender(
        nativeGatewaySoapResponse(BirOperation::Login, 'A1234567890123456789'),
        nativeGatewaySoapResponse(BirOperation::Search, ''),
        nativeGatewaySoapResponse(BirOperation::GetValue, '0'),
        nativeGatewaySoapResponse(BirOperation::GetValue, '7'),
        nativeGatewaySoapResponse(BirOperation::Login, 'B1234567890123456789'),
        nativeGatewaySoapResponse(BirOperation::Search, $searchResult),
    );
    $gateway = new NativeBirGateway(new NativeSoapTransport(
        apiKey: 'APIKEYSENTINEL123456',
        environment: Environment::Production,
        httpSender: $sender,
        requestLimiter: $limiter,
    ));

    $results = $gateway->search(SearchCriteria::nip('0123456789'));

    expect($results)->toHaveCount(1)
        ->and($results[0]->regon)->toBe('012345678')
        ->and($sender->operations)->toBe([
            BirOperation::Login,
            BirOperation::Search,
            BirOperation::GetValue,
            BirOperation::GetValue,
            BirOperation::Login,
            BirOperation::Search,
        ])
        ->and($sleeps)->toHaveCount(5);
});

it('paces consecutive native reports after a cold login', function (): void {
    $now = new DateTimeImmutable('2026-08-23 08:00:00', new DateTimeZone('Europe/Warsaw'));
    $sleeps = [];
    $limiter = new CacheBirRequestLimiter(
        new Repository(new ArrayStore),
        Environment::Production,
        'APIKEYSENTINEL123456',
        'bir-test-consecutive-reports',
        static function () use (&$now): DateTimeImmutable {
            return $now;
        },
        static function (float $seconds) use (&$now, &$sleeps): void {
            $sleeps[] = $seconds;
            $now = nativeGatewayRateLimitTimeAt((float) $now->format('U.u') + $seconds);
        },
    );
    $report = htmlspecialchars(
        nativeGatewayFixture('inner/full-single.xml'),
        ENT_QUOTES | ENT_XML1,
        'UTF-8',
    );
    $sender = new NativeGatewayQueueHttpSender(
        nativeGatewaySoapResponse(BirOperation::Login, 'A1234567890123456789'),
        nativeGatewaySoapResponse(BirOperation::FullReport, $report),
        nativeGatewaySoapResponse(BirOperation::FullReport, $report),
        nativeGatewaySoapResponse(BirOperation::FullReport, $report),
    );
    $gateway = new NativeBirGateway(new NativeSoapTransport(
        apiKey: 'APIKEYSENTINEL123456',
        environment: Environment::Production,
        httpSender: $sender,
        requestLimiter: $limiter,
    ));

    $first = $gateway->fullReport('012345678', ReportType::Organization);
    $second = $gateway->fullReport('012345678', ReportType::Organization);
    $third = $gateway->fullReport('012345678', ReportType::Organization);

    expect($first)->toHaveCount(1)
        ->and($second)->toHaveCount(1)
        ->and($third)->toHaveCount(1)
        ->and($sender->operations)->toBe([
            BirOperation::Login,
            BirOperation::FullReport,
            BirOperation::FullReport,
            BirOperation::FullReport,
        ])
        ->and($sleeps)->toHaveCount(3);
});

it('finishes diagnostics after an empty weighted batch in the same native scope', function (): void {
    $now = new DateTimeImmutable('2026-08-23 08:00:00', new DateTimeZone('Europe/Warsaw'));
    $sleeps = [];
    $limiter = new CacheBirRequestLimiter(
        new Repository(new ArrayStore),
        Environment::Production,
        'APIKEYSENTINEL123456',
        'bir-test-empty-weighted-batch',
        static function () use (&$now): DateTimeImmutable {
            return $now;
        },
        static function (float $seconds) use (&$now, &$sleeps): void {
            $sleeps[] = $seconds;
            $now = nativeGatewayRateLimitTimeAt((float) $now->format('U.u') + $seconds);
        },
    );
    $sender = new NativeGatewayQueueHttpSender(
        nativeGatewaySoapResponse(BirOperation::Login, 'A1234567890123456789'),
        nativeGatewaySoapResponse(BirOperation::Search, ''),
        nativeGatewaySoapResponse(BirOperation::GetValue, '1'),
        nativeGatewaySoapResponse(BirOperation::GetValue, '0'),
    );
    $gateway = new NativeBirGateway(new NativeSoapTransport(
        apiKey: 'APIKEYSENTINEL123456',
        environment: Environment::Production,
        httpSender: $sender,
        requestLimiter: $limiter,
    ));

    $results = $gateway->search(nativeGatewayBatchCriteria());

    expect($results)->toBe([])
        ->and($sender->operations)->toBe([
            BirOperation::Login,
            BirOperation::Search,
            BirOperation::GetValue,
            BirOperation::GetValue,
        ])
        ->and($sleeps)->toHaveCount(3)
        ->and($sleeps[1])->toBeGreaterThan(6.66)
        ->and($sleeps[1])->toBeLessThan(6.68);
});

it('renews and retries an expired weighted batch within one native scope', function (): void {
    $now = new DateTimeImmutable('2026-08-23 08:00:00', new DateTimeZone('Europe/Warsaw'));
    $sleeps = [];
    $limiter = new CacheBirRequestLimiter(
        new Repository(new ArrayStore),
        Environment::Production,
        'APIKEYSENTINEL123456',
        'bir-test-expired-weighted-batch',
        static function () use (&$now): DateTimeImmutable {
            return $now;
        },
        static function (float $seconds) use (&$now, &$sleeps): void {
            $sleeps[] = $seconds;
            $now = nativeGatewayRateLimitTimeAt((float) $now->format('U.u') + $seconds);
        },
    );
    $searchResult = htmlspecialchars(
        nativeGatewayFixture('inner/search-single.xml'),
        ENT_QUOTES | ENT_XML1,
        'UTF-8',
    );
    $sender = new NativeGatewayQueueHttpSender(
        nativeGatewaySoapResponse(BirOperation::Login, 'A1234567890123456789'),
        nativeGatewaySoapResponse(BirOperation::Search, ''),
        nativeGatewaySoapResponse(BirOperation::GetValue, '0'),
        nativeGatewaySoapResponse(BirOperation::GetValue, '7'),
        nativeGatewaySoapResponse(BirOperation::Login, 'B1234567890123456789'),
        nativeGatewaySoapResponse(BirOperation::Search, $searchResult),
    );
    $gateway = new NativeBirGateway(new NativeSoapTransport(
        apiKey: 'APIKEYSENTINEL123456',
        environment: Environment::Production,
        httpSender: $sender,
        requestLimiter: $limiter,
    ));

    $results = $gateway->search(nativeGatewayBatchCriteria());

    expect($results)->toHaveCount(1)
        ->and($results[0]->regon)->toBe('012345678')
        ->and($sender->operations)->toBe([
            BirOperation::Login,
            BirOperation::Search,
            BirOperation::GetValue,
            BirOperation::GetValue,
            BirOperation::Login,
            BirOperation::Search,
        ])
        ->and($sleeps)->toHaveCount(5)
        ->and($sleeps[1])->toBeGreaterThan(6.66)
        ->and($sleeps[1])->toBeLessThan(6.68);

    try {
        $gateway->getValue(GetValueParameter::ServiceStatus);
        throw new RuntimeException('A new operation slept through weighted batch debt.');
    } catch (BirRateLimitException $exception) {
        expect($exception->quotaWasExceeded())->toBeTrue()
            ->and($exception->retryAfterSeconds())->toBe(7)
            ->and($sender->operations)->toHaveCount(6);
    }
});

it('does not renew from nil recovery diagnostics', function (string $nilField): void {
    $statusResponse = $nilField === 'session status'
        ? TransportResponse::failure(TransportFailureType::Protocol, resultWasNil: true)
        : TransportResponse::success('0');
    $messageCodeResponse = $nilField === 'message code'
        ? TransportResponse::failure(TransportFailureType::Protocol, resultWasNil: true)
        : TransportResponse::success('7');
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success('A1234567890123456789'),
        TransportResponse::success(''),
        $statusResponse,
        $messageCodeResponse,
    );
    $gateway = new NativeBirGateway($transport);

    expect(fn () => $gateway->search(SearchCriteria::nip('0123456789')))
        ->toThrow(BirProtocolException::class, 'GUS BIR returned invalid diagnostics.')
        ->and(array_column($transport->calls, 0))->toBe([
            BirOperation::Login,
            BirOperation::Search,
            BirOperation::GetValue,
            BirOperation::GetValue,
        ])
        ->and($transport->authenticationChecks)->toBe(1);
})->with([
    'nil session status' => ['session status'],
    'nil message code' => ['message code'],
]);

it('returns a genuinely empty authenticated message without renewing an active session', function (): void {
    $session = 'A1234567890123456789';
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success($session),
        TransportResponse::success(''),
        TransportResponse::success('1'),
        TransportResponse::success('0'),
    );
    $gateway = new NativeBirGateway($transport);

    expect($gateway->getValue(GetValueParameter::Message))->toBe('')
        ->and(array_column($transport->calls, 0))->toBe([
            BirOperation::Login,
            BirOperation::GetValue,
            BirOperation::GetValue,
            BirOperation::GetValue,
        ])
        ->and($transport->calls[1][1])->toBe([
            'parameter' => GetValueParameter::Message,
        ])
        ->and($transport->calls[2][1])->toBe([
            'parameter' => GetValueParameter::SessionStatus,
        ])
        ->and($transport->calls[3][1])->toBe([
            'parameter' => GetValueParameter::MessageCode,
        ])
        ->and(array_column($transport->calls, 2))->toBe([
            null,
            $session,
            $session,
            $session,
        ])
        ->and($transport->authenticationChecks)->toBe(1);
});

it('fails for a missing key before making a transport call', function (): void {
    $transport = new QueueBirSoapTransport(false);
    $gateway = new NativeBirGateway($transport);

    expect(fn () => $gateway->search(SearchCriteria::nip('0123456789')))
        ->toThrow(
            BirAuthenticationException::class,
            'BIR API key is not configured. Set BIR_API_KEY in your .env file.',
        )
        ->and($transport->authenticationChecks)->toBe(1)
        ->and($transport->calls)->toBe([])
        ->and($transport->sessionIds)->toBe([]);
});

it('renews an inactive session once after an empty outer result', function (): void {
    $firstSession = 'A1234567890123456789';
    $secondSession = 'B1234567890123456789';
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success($firstSession),
        TransportResponse::success(''),
        TransportResponse::success('0'),
        TransportResponse::success('7'),
        TransportResponse::success($secondSession),
        TransportResponse::success(nativeGatewayFixture('inner/search-single.xml')),
    );
    $gateway = new NativeBirGateway($transport);

    $results = $gateway->search(SearchCriteria::nip('0123456789'));

    expect($results)->toHaveCount(1)
        ->and($results[0]->regon)->toBe('012345678')
        ->and(array_column($transport->calls, 0))->toBe([
            BirOperation::Login,
            BirOperation::Search,
            BirOperation::GetValue,
            BirOperation::GetValue,
            BirOperation::Login,
            BirOperation::Search,
        ])
        ->and($transport->calls[2][1])->toBe([
            'parameter' => GetValueParameter::SessionStatus,
        ])
        ->and($transport->calls[3][1])->toBe([
            'parameter' => GetValueParameter::MessageCode,
        ])
        ->and($transport->calls[5][2])->toBe($secondSession);
});

it('renews an expired session from GUS error code 7 without diagnostic requests', function (): void {
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success('A1234567890123456789'),
        TransportResponse::success(nativeGatewayErrorXml(7)),
        TransportResponse::success('B1234567890123456789'),
        TransportResponse::success(nativeGatewayFixture('inner/search-single.xml')),
    );
    $gateway = new NativeBirGateway($transport);

    $results = $gateway->search(SearchCriteria::nip('0123456789'));

    expect($results)->toHaveCount(1)
        ->and(array_column($transport->calls, 0))->toBe([
            BirOperation::Login,
            BirOperation::Search,
            BirOperation::Login,
            BirOperation::Search,
        ]);
});

it('limits explicit GUS error code 7 recovery to one retry without diagnostics', function (): void {
    $firstSession = 'A1234567890123456789';
    $secondSession = 'B1234567890123456789';
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success($firstSession),
        TransportResponse::success(nativeGatewayErrorXml(7)),
        TransportResponse::success($secondSession),
        TransportResponse::success(nativeGatewayErrorXml(7)),
    );
    $gateway = new NativeBirGateway($transport);

    expect(fn () => $gateway->search(SearchCriteria::nip('0123456789')))
        ->toThrow(BirAuthenticationException::class, 'GUS BIR session could not be renewed.')
        ->and(array_column($transport->calls, 0))->toBe([
            BirOperation::Login,
            BirOperation::Search,
            BirOperation::Login,
            BirOperation::Search,
        ])
        ->and(array_column($transport->calls, 2))->toBe([
            null,
            $firstSession,
            null,
            $secondSession,
        ])
        ->and($transport->authenticationChecks)->toBe(2);
});

it('renews after a successful empty message code even when the session status is active', function (): void {
    $firstSession = 'A1234567890123456789';
    $secondSession = 'B1234567890123456789';
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success($firstSession),
        TransportResponse::success(''),
        TransportResponse::success('1'),
        TransportResponse::success(''),
        TransportResponse::success($secondSession),
        TransportResponse::success(nativeGatewayFixture('inner/search-single.xml')),
    );
    $gateway = new NativeBirGateway($transport);

    $results = $gateway->search(SearchCriteria::nip('0123456789'));

    expect($results)->toHaveCount(1)
        ->and($results[0]->regon)->toBe('012345678')
        ->and(array_column($transport->calls, 0))->toBe([
            BirOperation::Login,
            BirOperation::Search,
            BirOperation::GetValue,
            BirOperation::GetValue,
            BirOperation::Login,
            BirOperation::Search,
        ])
        ->and($transport->calls[3][1])->toBe([
            'parameter' => GetValueParameter::MessageCode,
        ])
        ->and($transport->calls[5][2])->toBe($secondSession)
        ->and($transport->authenticationChecks)->toBe(2);
});

it('keeps an active session and returns genuinely empty data without retrying', function (): void {
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success('A1234567890123456789'),
        TransportResponse::success(''),
        TransportResponse::success('1'),
        TransportResponse::success('0'),
    );
    $gateway = new NativeBirGateway($transport);

    $results = $gateway->search(SearchCriteria::nip('0123456789'));

    expect($results)->toBe([])
        ->and(array_column($transport->calls, 0))->toBe([
            BirOperation::Login,
            BirOperation::Search,
            BirOperation::GetValue,
            BirOperation::GetValue,
        ])
        ->and($transport->authenticationChecks)->toBe(1);
});

it('uses one SID for the complete diagnostics snapshot', function (): void {
    $session = new BirSessionState;
    $transport = new DiagnosticsSessionSwitchingTransport($session);
    $gateway = new NativeBirGateway($transport, session: $session);

    expect($gateway->search(SearchCriteria::nip('0123456789')))->toBe([])
        ->and(array_column($transport->calls, 2))->toBe([
            null,
            DiagnosticsSessionSwitchingTransport::INITIAL_SESSION,
            DiagnosticsSessionSwitchingTransport::INITIAL_SESSION,
            DiagnosticsSessionSwitchingTransport::INITIAL_SESSION,
        ]);
});

it('maps diagnostics code 4 from an empty outer result to an empty search result', function (): void {
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success('A1234567890123456789'),
        TransportResponse::success(''),
        TransportResponse::success('1'),
        TransportResponse::success('4'),
    );
    $gateway = new NativeBirGateway($transport);

    expect($gateway->search(SearchCriteria::nip('0000000000')))->toBe([])
        ->and(array_column($transport->calls, 0))->toBe([
            BirOperation::Login,
            BirOperation::Search,
            BirOperation::GetValue,
            BirOperation::GetValue,
        ]);
});

it('does not renew an active session after an ordinary transport failure', function (): void {
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success('A1234567890123456789'),
        TransportResponse::failure(TransportFailureType::Transport),
        TransportResponse::success('1'),
        TransportResponse::success('0'),
    );
    $gateway = new NativeBirGateway($transport);

    expect(fn () => $gateway->search(SearchCriteria::nip('0123456789')))
        ->toThrow(BirTransportException::class, 'Unable to communicate with the GUS BIR service.')
        ->and(array_column($transport->calls, 0))->toBe([
            BirOperation::Login,
            BirOperation::Search,
        ]);
});

it('does not renew an active session after an ordinary protocol failure', function (): void {
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success('A1234567890123456789'),
        TransportResponse::failure(TransportFailureType::Protocol),
        TransportResponse::success('1'),
        TransportResponse::success('0'),
    );
    $gateway = new NativeBirGateway($transport);

    expect(fn () => $gateway->search(SearchCriteria::nip('0123456789')))
        ->toThrow(BirProtocolException::class, 'GUS BIR returned an invalid SOAP response.')
        ->and(array_column($transport->calls, 0))->toBe([
            BirOperation::Login,
            BirOperation::Search,
        ]);
});

it('does not diagnose the session after a scalar transport failure', function (): void {
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success('A1234567890123456789'),
        TransportResponse::failure(TransportFailureType::Transport),
    );
    $gateway = new NativeBirGateway($transport);

    expect(fn () => $gateway->getValue(GetValueParameter::Message))
        ->toThrow(BirTransportException::class, 'Unable to communicate with the GUS BIR service.')
        ->and(array_column($transport->calls, 0))->toBe([
            BirOperation::Login,
            BirOperation::GetValue,
        ]);
});

it('exposes a typed SOAP fault without running session diagnostics', function (): void {
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success('A1234567890123456789'),
        TransportResponse::failure(
            TransportFailureType::Protocol,
            soapFaultCode: SoapFaultCode::Sender,
        ),
    );
    $gateway = new NativeBirGateway($transport);
    $exception = null;

    try {
        $gateway->search(SearchCriteria::nip('0123456789'));
    } catch (BirSoapFaultException $caught) {
        $exception = $caught;
    }

    expect($exception)->toBeInstanceOf(BirSoapFaultException::class)
        ->and($exception->faultCode)->toBe(SoapFaultCode::Sender)
        ->and(array_column($transport->calls, 0))->toBe([
            BirOperation::Login,
            BirOperation::Search,
        ]);
});

it('limits renewal to one attempt when the replacement request fails', function (): void {
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success('A1234567890123456789'),
        TransportResponse::success(''),
        TransportResponse::success('0'),
        TransportResponse::success('7'),
        TransportResponse::success('B1234567890123456789'),
        TransportResponse::success(''),
        TransportResponse::success('0'),
        TransportResponse::success('7'),
    );
    $gateway = new NativeBirGateway($transport);

    expect(fn () => $gateway->search(SearchCriteria::nip('0123456789')))
        ->toThrow(BirAuthenticationException::class, 'GUS BIR session could not be renewed.')
        ->and(array_column($transport->calls, 0))->toBe([
            BirOperation::Login,
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

it('clears the replacement SID after the only renewal attempt also expires', function (): void {
    $firstSession = 'A1234567890123456789';
    $failedReplacementSession = 'B1234567890123456789';
    $nextSession = 'C1234567890123456789';
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success($firstSession),
        TransportResponse::success(''),
        TransportResponse::success('0'),
        TransportResponse::success('7'),
        TransportResponse::success($failedReplacementSession),
        TransportResponse::success(''),
        TransportResponse::success('0'),
        TransportResponse::success('7'),
    );
    $gateway = new NativeBirGateway($transport);

    expect(fn () => $gateway->search(SearchCriteria::nip('0123456789')))
        ->toThrow(BirAuthenticationException::class, 'GUS BIR session could not be renewed.');

    $transport->queue(
        TransportResponse::success($nextSession),
        TransportResponse::success(nativeGatewayFixture('inner/search-single.xml')),
    );

    $results = $gateway->search(SearchCriteria::nip('0123456789'));

    expect($results)->toHaveCount(1)
        ->and($results[0]->regon)->toBe('012345678')
        ->and(array_column($transport->calls, 0))->toBe([
            BirOperation::Login,
            BirOperation::Search,
            BirOperation::GetValue,
            BirOperation::GetValue,
            BirOperation::Login,
            BirOperation::Search,
            BirOperation::GetValue,
            BirOperation::GetValue,
            BirOperation::Login,
            BirOperation::Search,
        ])
        ->and($transport->calls[8][2])->toBeNull()
        ->and($transport->calls[9][2])->toBe($nextSession)
        ->and($transport->authenticationChecks)->toBe(3);
});

it('clears the replacement SID after an authenticated scalar expires twice', function (): void {
    $firstSession = 'A1234567890123456789';
    $failedReplacementSession = 'B1234567890123456789';
    $nextSession = 'C1234567890123456789';
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success($firstSession),
        TransportResponse::success(''),
        TransportResponse::success('0'),
        TransportResponse::success('7'),
        TransportResponse::success($failedReplacementSession),
        TransportResponse::success(''),
        TransportResponse::success('0'),
        TransportResponse::success('7'),
    );
    $gateway = new NativeBirGateway($transport);

    expect(fn () => $gateway->getValue(GetValueParameter::DataStatus))
        ->toThrow(BirAuthenticationException::class, 'GUS BIR session could not be renewed.');

    $transport->queue(
        TransportResponse::success($nextSession),
        TransportResponse::success('23-08-2026'),
    );

    expect($gateway->getValue(GetValueParameter::DataStatus))->toBe('23-08-2026')
        ->and(array_column($transport->calls, 0))->toBe([
            BirOperation::Login,
            BirOperation::GetValue,
            BirOperation::GetValue,
            BirOperation::GetValue,
            BirOperation::Login,
            BirOperation::GetValue,
            BirOperation::GetValue,
            BirOperation::GetValue,
            BirOperation::Login,
            BirOperation::GetValue,
        ])
        ->and($transport->calls[8][2])->toBeNull()
        ->and($transport->calls[9][2])->toBe($nextSession)
        ->and($transport->authenticationChecks)->toBe(3);
});

it('rejects an invalid login result as an authentication failure', function (): void {
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success('not-a-valid-sid'),
    );
    $gateway = new NativeBirGateway($transport);

    expect(fn () => $gateway->search(SearchCriteria::nip('0123456789')))
        ->toThrow(BirAuthenticationException::class, 'Invalid API key')
        ->and(array_column($transport->calls, 0))->toBe([BirOperation::Login]);
});

it('maps search error code 4 to an empty result', function (): void {
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success('A1234567890123456789'),
        TransportResponse::success(nativeGatewayFixture('inner/search-error-4.xml')),
    );
    $gateway = new NativeBirGateway($transport);

    expect($gateway->search(SearchCriteria::nip('0000000000')))->toBe([])
        ->and(array_column($transport->calls, 0))->toBe([
            BirOperation::Login,
            BirOperation::Search,
        ]);
});

it('rejects malformed inner report XML', function (): void {
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success('A1234567890123456789'),
        TransportResponse::success('<root><dane>'),
    );
    $gateway = new NativeBirGateway($transport);

    expect(fn () => $gateway->search(SearchCriteria::nip('0123456789')))
        ->toThrow(BirProtocolException::class, 'GUS BIR returned malformed report XML.');
});

it('keeps the default inner report limit aligned with the documented manual transport graph', function (): void {
    $largeValue = str_repeat('A', 5_100_000);
    $xml = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<root><dane><LargeField>'.$largeValue.'</LargeField></dane></root>';
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success('A1234567890123456789'),
        TransportResponse::success($xml),
    );
    $gateway = new NativeBirGateway($transport);

    expect(strlen($xml))->toBeGreaterThan(5_000_000)
        ->toBeLessThan(10_000_000);

    $rows = $gateway->fullReport('012345678', ReportType::Organization);

    expect($rows)->toHaveCount(1)
        ->and(strlen($rows[0]['LargeField']))->toBe(5_100_000);
});

it('maps full report error code 4 to not found', function (): void {
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success('A1234567890123456789'),
        TransportResponse::success(nativeGatewayFixture('inner/search-error-4.xml')),
    );
    $gateway = new NativeBirGateway($transport);

    expect(fn () => $gateway->fullReport('012345678', ReportType::Organization))
        ->toThrow(BirNotFoundException::class, 'Nie znaleziono firmy dla REGON: 012345678');
});

it('preserves the GUS code for a rejected full report', function (): void {
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success('A1234567890123456789'),
        TransportResponse::success(nativeGatewayFixture('inner/full-error-11.xml')),
    );
    $gateway = new NativeBirGateway($transport);

    $exception = null;

    try {
        $gateway->fullReport('012345678', ReportType::NaturalPersonActivity);
    } catch (BirReportException $caught) {
        $exception = $caught;
    }

    if (! $exception instanceof BirReportException) {
        throw new RuntimeException('Expected the full report to be rejected.');
    }

    expect($exception->gusCode)->toBe(11)
        ->and($exception->getMessage())->toBe('GUS BIR rejected the full report (code 11).');
});

it('parses REGON values from a bulk report', function (): void {
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success('A1234567890123456789'),
        TransportResponse::success(nativeGatewayFixture('inner/bulk-multiple.xml')),
    );
    $gateway = new NativeBirGateway($transport);

    $result = $gateway->bulkReport(
        new DateTimeImmutable('2026-08-22'),
        BulkReportType::NewLegalEntitiesAndNaturalPersons,
    );

    expect($result)->toBe(['012345678', '000000001', '987654321']);
});

it('maps bulk report error code 4 to an empty result', function (): void {
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success('A1234567890123456789'),
        TransportResponse::success(nativeGatewayErrorXml(4)),
    );
    $gateway = new NativeBirGateway($transport);

    expect($gateway->bulkReport(
        new DateTimeImmutable('2026-08-22'),
        BulkReportType::NewLegalEntitiesAndNaturalPersons,
    ))->toBe([]);
});

it('preserves the GUS code for a rejected bulk report', function (): void {
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success('A1234567890123456789'),
        TransportResponse::success(nativeGatewayFixture('inner/bulk-error-101.xml')),
    );
    $gateway = new NativeBirGateway($transport);

    $exception = null;

    try {
        $gateway->bulkReport(
            new DateTimeImmutable('2026-08-22'),
            BulkReportType::NewLegalEntitiesAndNaturalPersons,
        );
    } catch (BirReportException $caught) {
        $exception = $caught;
    }

    if (! $exception instanceof BirReportException) {
        throw new RuntimeException('Expected the bulk report to be rejected.');
    }

    expect($exception->gusCode)->toBe(101)
        ->and($exception->getMessage())->toBe('GUS BIR rejected the bulk report (code 101).');
});

it('classifies an invalid embedded report error as a protocol failure', function (
    string $operation,
    string $rawCode,
): void {
    $xml = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<root><dane><ErrorCode>'.$rawCode.'</ErrorCode>'
        .'<ErrorMessageEn>Malformed fixture error.</ErrorMessageEn></dane></root>';
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success('A1234567890123456789'),
        TransportResponse::success($xml),
    );
    $gateway = new NativeBirGateway($transport);
    $request = $operation === 'full'
        ? fn (): array => $gateway->fullReport('012345678', ReportType::Organization)
        : fn (): array => $gateway->bulkReport(
            new DateTimeImmutable('2026-08-22'),
            BulkReportType::NewLegalEntitiesAndNaturalPersons,
        );

    expect($request)->toThrow(
        BirProtocolException::class,
        'GUS BIR returned an invalid report error response.',
    );
})->with([
    'full zero' => ['full', '0'],
    'full non-numeric' => ['full', 'invalid'],
    'bulk zero' => ['bulk', '0'],
    'bulk non-numeric' => ['bulk', 'invalid'],
]);

it('rejects an invalid REGON in a bulk report record', function (): void {
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success('A1234567890123456789'),
        TransportResponse::success('<root><dane><regon>123</regon></dane></root>'),
    );
    $gateway = new NativeBirGateway($transport);

    expect(fn () => $gateway->bulkReport(
        new DateTimeImmutable('2026-08-22'),
        BulkReportType::NewLegalEntitiesAndNaturalPersons,
    ))->toThrow(
        BirProtocolException::class,
        'GUS BIR returned an invalid bulk report record.',
    );
});

it('rejects a bulk REGON whose length does not match the report family', function (
    BulkReportType $reportType,
    string $regon,
): void {
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success('A1234567890123456789'),
        TransportResponse::success('<root><dane><regon>'.$regon.'</regon></dane></root>'),
    );
    $gateway = new NativeBirGateway($transport);

    expect(fn () => $gateway->bulkReport(
        new DateTimeImmutable('2026-08-22'),
        $reportType,
    ))->toThrow(
        BirProtocolException::class,
        'GUS BIR returned an invalid bulk report record.',
    );
})->with([
    '14 digits in an entity report' => [
        BulkReportType::NewLegalEntitiesAndNaturalPersons,
        '01234567800001',
    ],
    '9 digits in a local-unit report' => [
        BulkReportType::NewLocalUnits,
        '012345678',
    ],
]);

it('accepts a 14-digit REGON in a local-unit bulk report', function (): void {
    $transport = (new QueueBirSoapTransport)->queue(
        TransportResponse::success('A1234567890123456789'),
        TransportResponse::success('<root><dane><regon>01234567800001</regon></dane></root>'),
    );
    $gateway = new NativeBirGateway($transport);

    expect($gateway->bulkReport(
        new DateTimeImmutable('2026-08-22'),
        BulkReportType::NewLocalUnits,
    ))->toBe(['01234567800001']);
});

function nativeGatewayFixture(string $relativePath): string
{
    $contents = file_get_contents(__DIR__.'/../Fixtures/Gus/'.$relativePath);

    if ($contents === false) {
        throw new RuntimeException("Unable to read GUS fixture: {$relativePath}");
    }

    return $contents;
}

function nativeGatewayErrorXml(int $code): string
{
    return "<?xml version=\"1.0\" encoding=\"UTF-8\"?><root><dane><ErrorCode>{$code}</ErrorCode><ErrorMessageEn>Fixture error.</ErrorMessageEn></dane></root>";
}

function nativeGatewayBatchCriteria(): SearchCriteria
{
    $identifiers = array_map(
        static fn (int $value): string => str_pad((string) $value, 10, '0', STR_PAD_LEFT),
        range(1, SearchCriteria::MAX_BATCH_SIZE),
    );

    return SearchCriteria::krsNumbers($identifiers);
}

function nativeGatewayRateLimitTimeAt(float $timestamp): DateTimeImmutable
{
    $time = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $timestamp));

    if (! $time instanceof DateTimeImmutable) {
        throw new RuntimeException('Unable to advance the native gateway test clock.');
    }

    return $time->setTimezone(new DateTimeZone('Europe/Warsaw'));
}

function nativeGatewaySoapResponse(
    BirOperation $operation,
    string $result,
    string $resultAttributes = '',
): string {
    $namespace = $operation->namespace();
    $response = $operation->value.'Response';
    $resultElement = $operation->resultElement();
    $responseAction = $operation->responseAction();

    return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"
            xmlns:a="http://www.w3.org/2005/08/addressing"
            xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
          <s:Header>
            <a:Action s:mustUnderstand="1">{$responseAction}</a:Action>
          </s:Header>
          <s:Body>
            <{$response} xmlns="{$namespace}">
              <{$resultElement}{$resultAttributes}>{$result}</{$resultElement}>
            </{$response}>
          </s:Body>
        </s:Envelope>
        XML;
}

final class NativeGatewayQueueHttpSender implements BirHttpSenderInterface
{
    /** @var list<BirOperation> */
    public array $operations = [];

    /** @var list<string> */
    private array $responses;

    public function __construct(string ...$responses)
    {
        $this->responses = array_values($responses);
    }

    public function send(
        BirOperation $operation,
        #[SensitiveParameter] string $soapEnvelope,
        #[SensitiveParameter] ?string $sessionId,
    ): RawTransportResult {
        $this->operations[] = $operation;
        $response = array_shift($this->responses);

        if (! is_string($response)) {
            throw new LogicException('No queued HTTP response is available.');
        }

        return RawTransportResult::success($response, 'application/soap+xml');
    }
}

final class DiagnosticsSessionSwitchingTransport implements BirSoapTransportInterface
{
    public const string INITIAL_SESSION = 'A1234567890123456789';

    private const string REPLACEMENT_SESSION = 'B1234567890123456789';

    /** @var list<array{BirOperation, array<string, mixed>, ?string}> */
    public array $calls = [];

    private ?string $sessionId = null;

    public function __construct(private readonly BirSessionState $session) {}

    public function isAuthenticationConfigured(): bool
    {
        return true;
    }

    public function useSession(#[SensitiveParameter] ?string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    public function call(BirOperation $operation, array $parameters = []): TransportResponse
    {
        $this->calls[] = [$operation, $parameters, $this->sessionId];

        if ($operation === BirOperation::Login) {
            return TransportResponse::success(self::INITIAL_SESSION);
        }

        if ($operation === BirOperation::Search) {
            return TransportResponse::success('');
        }

        $parameter = $parameters['parameter'] ?? null;

        if ($operation === BirOperation::GetValue && $parameter === GetValueParameter::SessionStatus) {
            $this->session->start(self::REPLACEMENT_SESSION);

            return TransportResponse::success('1');
        }

        if ($operation === BirOperation::GetValue && $parameter === GetValueParameter::MessageCode) {
            return TransportResponse::success('0');
        }

        throw new LogicException('Unexpected operation in diagnostics session-switch fixture.');
    }
}
