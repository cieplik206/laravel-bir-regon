<?php

declare(strict_types=1);

use cieplik206\BirRegon\Enums\Environment;
use cieplik206\BirRegon\Enums\SoapFaultCode;
use cieplik206\BirRegon\Protocol\BirOperation;
use cieplik206\BirRegon\Protocol\RawTransportResult;
use cieplik206\BirRegon\Protocol\TransportFailureType;
use cieplik206\BirRegon\RateLimit\UnlimitedBirRequestLimiter;
use cieplik206\BirRegon\Transport\BirHttpSenderInterface;
use cieplik206\BirRegon\Transport\NativeSoapTransport;

it('decodes a plain SOAP body using the HTTP content type returned by the sender', function (): void {
    $body = file_get_contents(__DIR__.'/../Fixtures/Gus/soap/login-success.xml');

    if (! is_string($body)) {
        throw new RuntimeException('Unable to read the plain SOAP fixture.');
    }

    $sender = new class($body, 'application/soap+xml; charset=UTF-8') implements BirHttpSenderInterface
    {
        public function __construct(
            private readonly string $body,
            private readonly string $contentType,
        ) {}

        public function send(
            BirOperation $operation,
            #[SensitiveParameter] string $soapEnvelope,
            #[SensitiveParameter] ?string $sessionId,
        ): RawTransportResult {
            return RawTransportResult::success($this->body, $this->contentType);
        }
    };
    $response = (new NativeSoapTransport(
        apiKey: 'APIKEYSENTINEL123456',
        requestLimiter: new UnlimitedBirRequestLimiter,
        environment: Environment::Sandbox,
        httpSender: $sender,
    ))->call(BirOperation::Login);

    expect($response->successful)->toBeTrue()
        ->and($response->result())->toBe('fixtureSession000001');
});

it('decodes a MIME SOAP body using the multipart HTTP content type returned by the sender', function (): void {
    $entity = file_get_contents(__DIR__.'/../Fixtures/Gus/mime/standard.multipart');

    if (! is_string($entity)) {
        throw new RuntimeException('Unable to read the MIME SOAP fixture.');
    }

    $separator = str_contains($entity, "\r\n\r\n") ? "\r\n\r\n" : "\n\n";
    $headerEnd = strpos($entity, $separator);

    if ($headerEnd === false) {
        throw new RuntimeException('The MIME SOAP fixture has no HTTP header separator.');
    }

    $headerBlock = substr($entity, 0, $headerEnd);
    $body = substr($entity, $headerEnd + strlen($separator));

    if (preg_match('/^Content-Type:\s*(.+)$/mi', $headerBlock, $matches) !== 1) {
        throw new RuntimeException('The MIME SOAP fixture has no Content-Type header.');
    }

    $contentType = trim($matches[1]);
    $sender = new class($body, $contentType) implements BirHttpSenderInterface
    {
        public function __construct(
            private readonly string $body,
            private readonly string $contentType,
        ) {}

        public function send(
            BirOperation $operation,
            #[SensitiveParameter] string $soapEnvelope,
            #[SensitiveParameter] ?string $sessionId,
        ): RawTransportResult {
            return RawTransportResult::success($this->body, $this->contentType);
        }
    };
    $response = (new NativeSoapTransport(
        apiKey: 'APIKEYSENTINEL123456',
        requestLimiter: new UnlimitedBirRequestLimiter,
        environment: Environment::Sandbox,
        httpSender: $sender,
    ))->call(BirOperation::Login);

    expect($response->successful)->toBeTrue()
        ->and($response->result())->toBe('fixtureSession000001');
});

it('decodes top-level XOP SOAP using its declared SOAP media type', function (): void {
    $body = nativeTransportHttpFixture('soap/login-success.xml');
    $response = (new NativeSoapTransport(
        apiKey: 'APIKEYSENTINEL123456',
        requestLimiter: new UnlimitedBirRequestLimiter,
        environment: Environment::Sandbox,
        httpSender: new NativeTransportCompletedExchangeSender(
            $body,
            'application/xop+xml; charset=UTF-8; type="application/soap+xml"',
            200,
        ),
    ))->call(BirOperation::Login);

    expect($response->successful)->toBeTrue()
        ->and($response->result())->toBe('fixtureSession000001');
});

it('preserves a typed SOAP fault returned with its standard HTTP status', function (
    int $httpStatus,
    SoapFaultCode $faultCode,
): void {
    $body = nativeTransportHttpFixture('soap/fault.xml');

    if ($faultCode === SoapFaultCode::Sender) {
        $body = str_replace('s:Receiver', 's:Sender', $body);
    }

    $response = (new NativeSoapTransport(
        apiKey: 'APIKEYSENTINEL123456',
        requestLimiter: new UnlimitedBirRequestLimiter,
        environment: Environment::Sandbox,
        httpSender: new NativeTransportCompletedExchangeSender(
            $body,
            'application/soap+xml; charset=UTF-8',
            $httpStatus,
        ),
    ))->call(BirOperation::Login);

    expect($response->successful)->toBeFalse()
        ->and($response->failureType)->toBe(TransportFailureType::Protocol)
        ->and($response->soapFaultCode)->toBe($faultCode)
        ->and($response->result())->toBeNull();
})->with([
    'Sender over HTTP 400' => [400, SoapFaultCode::Sender],
    'Receiver over HTTP 500' => [500, SoapFaultCode::Receiver],
]);

it('classifies completed HTTP failures without a SOAP fault as transport failures', function (
    int $httpStatus,
    string $contentType,
    string $body,
): void {
    $response = (new NativeSoapTransport(
        apiKey: 'APIKEYSENTINEL123456',
        requestLimiter: new UnlimitedBirRequestLimiter,
        environment: Environment::Sandbox,
        httpSender: new NativeTransportCompletedExchangeSender(
            $body,
            $contentType,
            $httpStatus,
        ),
    ))->call(BirOperation::Login);

    expect($response->successful)->toBeFalse()
        ->and($response->failureType)->toBe(TransportFailureType::Transport)
        ->and($response->soapFaultCode)->toBeNull();
})->with([
    'HTTP 200 HTML maintenance page' => [
        200,
        'text/html; charset=UTF-8',
        '<html><body>Przerwa techniczna</body></html>',
    ],
    'HTTP 500 HTML' => [500, 'text/html; charset=UTF-8', '<html>failure</html>'],
    'HTTP 500 empty SOAP body' => [500, 'application/soap+xml', ''],
    'HTTP 401 without SOAP' => [401, 'text/plain', 'Unauthorized'],
]);

it('classifies malformed SOAP on a completed HTTP 500 exchange as a protocol failure', function (): void {
    $response = (new NativeSoapTransport(
        apiKey: 'APIKEYSENTINEL123456',
        requestLimiter: new UnlimitedBirRequestLimiter,
        environment: Environment::Sandbox,
        httpSender: new NativeTransportCompletedExchangeSender(
            '<not-soap/>',
            'application/soap+xml',
            500,
        ),
    ))->call(BirOperation::Login);

    expect($response->successful)->toBeFalse()
        ->and($response->failureType)->toBe(TransportFailureType::Protocol)
        ->and($response->soapFaultCode)->toBeNull();
});

it('rejects a successful SOAP operation carried by HTTP 500', function (): void {
    $response = (new NativeSoapTransport(
        apiKey: 'APIKEYSENTINEL123456',
        requestLimiter: new UnlimitedBirRequestLimiter,
        environment: Environment::Sandbox,
        httpSender: new NativeTransportCompletedExchangeSender(
            nativeTransportHttpFixture('soap/login-success.xml'),
            'application/soap+xml',
            500,
        ),
    ))->call(BirOperation::Login);

    expect($response->successful)->toBeFalse()
        ->and($response->failureType)->toBe(TransportFailureType::Protocol)
        ->and($response->result())->toBeNull();
});

function nativeTransportHttpFixture(string $relativePath): string
{
    $body = file_get_contents(__DIR__.'/../Fixtures/Gus/'.$relativePath);

    if (! is_string($body)) {
        throw new RuntimeException('Unable to read the HTTP response fixture.');
    }

    return $body;
}

final readonly class NativeTransportCompletedExchangeSender implements BirHttpSenderInterface
{
    public function __construct(
        private string $body,
        private string $contentType,
        private int $httpStatus,
    ) {}

    public function send(
        BirOperation $operation,
        #[SensitiveParameter] string $soapEnvelope,
        #[SensitiveParameter] ?string $sessionId,
    ): RawTransportResult {
        return RawTransportResult::completed(
            $this->body,
            $this->contentType,
            $this->httpStatus,
        );
    }
}
