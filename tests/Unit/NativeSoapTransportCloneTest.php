<?php

declare(strict_types=1);

use cieplik206\BirRegon\Enums\Environment;
use cieplik206\BirRegon\Protocol\BirOperation;
use cieplik206\BirRegon\Protocol\RawTransportResult;
use cieplik206\BirRegon\RateLimit\UnlimitedBirRequestLimiter;
use cieplik206\BirRegon\Transport\BirHttpSenderInterface;
use cieplik206\BirRegon\Transport\NativeSoapTransport;

it('keeps SOAP body and header session state isolated between transport clones', function (): void {
    $requests = [];
    $sender = new class($requests) implements BirHttpSenderInterface
    {
        /** @param list<array{body: string, sessionId: ?string}> $requests */
        public function __construct(public array &$requests) {}

        public function send(
            BirOperation $operation,
            #[SensitiveParameter] string $soapEnvelope,
            #[SensitiveParameter] ?string $sessionId,
        ): RawTransportResult {
            unset($operation);
            $this->requests[] = [
                'body' => $soapEnvelope,
                'sessionId' => $sessionId,
            ];

            return RawTransportResult::failure();
        }
    };
    $firstSession = 'AAAAAAAAAAAAAAAAAAAA';
    $secondSession = 'BBBBBBBBBBBBBBBBBBBB';
    $first = new NativeSoapTransport(
        apiKey: 'APIKEYSENTINEL123456',
        requestLimiter: new UnlimitedBirRequestLimiter,
        environment: Environment::Sandbox,
        httpSender: $sender,
    );
    $first->useSession($firstSession);

    $second = clone $first;
    $second->useSession($secondSession);

    $first->call(BirOperation::Logout);
    $second->call(BirOperation::Logout);

    expect($requests)->toHaveCount(2)
        ->and($requests[0]['sessionId'])->toBe($firstSession)
        ->and($requests[0]['body'])->toContain($firstSession)
        ->and($requests[0]['body'])->not->toContain($secondSession)
        ->and($requests[1]['sessionId'])->toBe($secondSession)
        ->and($requests[1]['body'])->toContain($secondSession)
        ->and($requests[1]['body'])->not->toContain($firstSession);
});
