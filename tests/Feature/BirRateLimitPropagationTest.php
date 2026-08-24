<?php

declare(strict_types=1);

use cieplik206\BirRegon\Contracts\BirSoapTransportInterface;
use cieplik206\BirRegon\Exceptions\BirRateLimitException;
use cieplik206\BirRegon\Gateway\NativeBirGateway;
use cieplik206\BirRegon\Protocol\BirOperation;
use cieplik206\BirRegon\Protocol\SearchCriteria;
use cieplik206\BirRegon\Protocol\TransportResponse;

it('propagates rate limiting without issuing session diagnostics', function (): void {
    $transport = new RateLimitPropagationTransport;
    $gateway = new NativeBirGateway($transport);

    try {
        $gateway->search(SearchCriteria::krs('0000123456'));
        throw new RuntimeException('The gateway swallowed the rate-limit exception.');
    } catch (BirRateLimitException $exception) {
        expect($exception->retryAfterSeconds())->toBe(4)
            ->and($transport->calls)->toBe([
                BirOperation::Login,
                BirOperation::Search,
            ]);
    }
});

final class RateLimitPropagationTransport implements BirSoapTransportInterface
{
    /** @var list<BirOperation> */
    public array $calls = [];

    public function isAuthenticationConfigured(): bool
    {
        return true;
    }

    public function useSession(#[SensitiveParameter] ?string $sessionId): void {}

    public function call(BirOperation $operation, array $parameters = []): TransportResponse
    {
        $this->calls[] = $operation;

        if ($operation === BirOperation::Login) {
            return TransportResponse::success('abcdefghijklmnopqrst');
        }

        throw BirRateLimitException::quotaExceeded(4);
    }
}
