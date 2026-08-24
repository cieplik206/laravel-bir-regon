<?php

declare(strict_types=1);

use cieplik206\BirRegon\Contracts\BirRequestLimiterInterface;
use cieplik206\BirRegon\Enums\Environment;
use cieplik206\BirRegon\Protocol\BirOperation;
use cieplik206\BirRegon\Protocol\RawTransportResult;
use cieplik206\BirRegon\RateLimit\UnlimitedBirRequestLimiter;
use cieplik206\BirRegon\Transport\BirHttpSenderInterface;
use cieplik206\BirRegon\Transport\NativeSoapTransport;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

function nativeTransportTraceContains(mixed $value, string $needle): bool
{
    if (is_string($value)) {
        return str_contains($value, $needle);
    }

    if ($value instanceof SensitiveParameterValue) {
        return false;
    }

    if (is_array($value)) {
        foreach ($value as $item) {
            if (nativeTransportTraceContains($item, $needle)) {
                return true;
            }
        }
    }

    return false;
}

it('does not expose request credentials to a global error handler backtrace', function (): void {
    $apiKey = 'APIKEYSENTINEL123456';
    $sessionId = 'SIDSESSION1234567890';
    $observed = [
        'apiKeyExposed' => false,
        'sessionIdExposed' => false,
        'requestXmlExposed' => false,
        'handlerInvocations' => 0,
        'senderCalls' => 0,
    ];
    $sender = new class($observed) implements BirHttpSenderInterface
    {
        /**
         * @param array{
         *     apiKeyExposed: bool,
         *     sessionIdExposed: bool,
         *     requestXmlExposed: bool,
         *     handlerInvocations: int,
         *     senderCalls: int
         * } $observed
         */
        public function __construct(public array &$observed) {}

        public function send(
            BirOperation $operation,
            #[SensitiveParameter] string $soapEnvelope,
            #[SensitiveParameter] ?string $sessionId,
        ): RawTransportResult {
            $this->observed['senderCalls']++;
            trigger_error('Deterministic fake HTTP sender warning.', E_USER_WARNING);

            return RawTransportResult::failure();
        }
    };
    $handlerInstalled = false;

    try {
        set_error_handler(static function () use (&$observed, $apiKey, $sessionId): bool {
            $trace = debug_backtrace();
            $observed['apiKeyExposed'] = $observed['apiKeyExposed']
                || nativeTransportTraceContains($trace, $apiKey);
            $observed['sessionIdExposed'] = $observed['sessionIdExposed']
                || nativeTransportTraceContains($trace, $sessionId);
            $observed['requestXmlExposed'] = $observed['requestXmlExposed']
                || nativeTransportTraceContains($trace, '<soap:Envelope');
            $observed['handlerInvocations']++;

            return true;
        });
        $handlerInstalled = true;

        $transport = new NativeSoapTransport(
            apiKey: $apiKey,
            requestLimiter: new UnlimitedBirRequestLimiter,
            environment: Environment::Sandbox,
            httpSender: $sender,
        );
        $login = $transport->call(BirOperation::Login);
        $transport->useSession($sessionId);
        $logout = $transport->call(BirOperation::Logout);
    } finally {
        if ($handlerInstalled) {
            restore_error_handler();
        }
    }

    expect($login->successful)->toBeFalse()
        ->and($logout->successful)->toBeFalse()
        ->and($observed['senderCalls'])->toBe(2)
        ->and($observed['handlerInvocations'])->toBe(2)
        ->and([
            'apiKeyExposed' => $observed['apiKeyExposed'],
            'sessionIdExposed' => $observed['sessionIdExposed'],
            'requestXmlExposed' => $observed['requestXmlExposed'],
        ])->toBe([
            'apiKeyExposed' => false,
            'sessionIdExposed' => false,
            'requestXmlExposed' => false,
        ]);
});

it('does not expose credentials stored by custom sender or limiter implementations', function (): void {
    $senderSecret = 'CUSTOM-SENDER-SECRET-SENTINEL';
    $limiterSecret = 'CUSTOM-LIMITER-SECRET-SENTINEL';
    $transport = new NativeSoapTransport(
        apiKey: 'APIKEYSENTINEL123456',
        environment: Environment::Sandbox,
        httpSender: new NativeTransportExporterSender($senderSecret),
        requestLimiter: new NativeTransportExporterLimiter($limiterSecret),
    );

    $exported = var_export($transport, true);
    $dumped = (new CliDumper)->dump((new VarCloner)->cloneVar($transport), true);

    foreach ([$exported, $dumped] as $rendered) {
        expect($rendered)
            ->not->toContain($senderSecret)
            ->not->toContain($limiterSecret);
    }
});

final readonly class NativeTransportExporterSender implements BirHttpSenderInterface
{
    public function __construct(public string $secret) {}

    public function send(
        BirOperation $operation,
        #[SensitiveParameter] string $soapEnvelope,
        #[SensitiveParameter] ?string $sessionId,
    ): RawTransportResult {
        return RawTransportResult::failure();
    }
}

final readonly class NativeTransportExporterLimiter implements BirRequestLimiterInterface
{
    public function __construct(public string $secret) {}

    public function acquire(
        BirOperation $operation,
        #[SensitiveParameter] array $parameters = [],
    ): void {}
}
