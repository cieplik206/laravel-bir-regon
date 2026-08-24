<?php

declare(strict_types=1);

use cieplik206\BirRegon\Protocol\BirOperation;
use cieplik206\BirRegon\Protocol\RawTransportResult;
use cieplik206\BirRegon\Protocol\SoapResponseDecoder;
use cieplik206\BirRegon\Protocol\TransportResponse;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

it('keeps a decoded login SID out of dumps and serialized state', function (): void {
    $fixture = file_get_contents(__DIR__.'/../Fixtures/Gus/soap/login-success.xml');

    if (! is_string($fixture)) {
        throw new RuntimeException('Unable to read the login SOAP fixture.');
    }

    $sessionId = 'fixtureSession000001';
    $response = (new SoapResponseDecoder)->decode($fixture, BirOperation::Login);
    $dumped = '';

    (new CliDumper)->dump(
        (new VarCloner)->cloneVar($response),
        static function (string $line) use (&$dumped): void {
            $dumped .= $line;
        },
    );

    expect($response->successful)->toBeTrue()
        ->and($response->result())->toBe($sessionId);

    $serialized = serialize($response);
    $restored = unserialize($serialized);

    if (! $restored instanceof TransportResponse) {
        throw new LogicException('Expected a serialized transport response tombstone.');
    }

    foreach ([
        print_r($response, true),
        var_export($response, true),
        $dumped,
        $serialized,
    ] as $rendered) {
        expect(str_contains($rendered, $sessionId))->toBeFalse();
    }

    expect(fn (): ?string => $restored->result())
        ->toThrow(LogicException::class, sprintf(
            'Serialization of %s is not supported.',
            TransportResponse::class,
        ));
});

it('keeps a restored transport response tombstone safe to inspect', function (): void {
    $secret = 'fixtureSession000001';
    $restored = unserialize(serialize(TransportResponse::success($secret)));

    if (! $restored instanceof TransportResponse) {
        throw new LogicException('Expected a serialized transport response tombstone.');
    }

    expect($restored->__debugInfo())->toBe([
        'result' => '[UNAVAILABLE]',
        'successful' => '[UNAVAILABLE]',
        'failureType' => '[UNAVAILABLE]',
        'resultWasNil' => '[UNAVAILABLE]',
        'soapFaultCode' => '[UNAVAILABLE]',
    ])
        ->and(print_r($restored, true))->not->toContain($secret)
        ->toContain('[UNAVAILABLE]');
});

it('keeps a restored raw transport tombstone safe to inspect', function (): void {
    $secret = '<secret-response-body />';
    $restored = unserialize(serialize(RawTransportResult::success(
        $secret,
        'application/soap+xml',
    )));

    if (! $restored instanceof RawTransportResult) {
        throw new LogicException('Expected a serialized raw transport tombstone.');
    }

    expect($restored->__debugInfo())->toBe([
        'body' => '[UNAVAILABLE]',
        'contentType' => '[UNAVAILABLE]',
        'exchangeCompleted' => '[UNAVAILABLE]',
        'httpStatus' => '[UNAVAILABLE]',
        'successful' => '[UNAVAILABLE]',
    ])
        ->and(print_r($restored, true))->not->toContain($secret)
        ->toContain('[UNAVAILABLE]');
});
