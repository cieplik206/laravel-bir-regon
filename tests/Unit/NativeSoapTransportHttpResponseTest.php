<?php

declare(strict_types=1);

use cieplik206\BirRegon\Enums\Environment;
use cieplik206\BirRegon\Protocol\BirOperation;
use cieplik206\BirRegon\Protocol\RawTransportResult;
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
        environment: Environment::Sandbox,
        httpSender: $sender,
    ))->call(BirOperation::Login);

    expect($response->successful)->toBeTrue()
        ->and($response->result())->toBe('fixture-session-0001');
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
        environment: Environment::Sandbox,
        httpSender: $sender,
    ))->call(BirOperation::Login);

    expect($response->successful)->toBeTrue()
        ->and($response->result())->toBe('fixture-session-0001');
});
