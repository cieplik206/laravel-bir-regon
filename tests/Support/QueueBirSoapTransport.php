<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Tests\Support;

use cieplik206\BirRegon\Contracts\BirSoapTransportInterface;
use cieplik206\BirRegon\Protocol\BirOperation;
use cieplik206\BirRegon\Protocol\TransportResponse;
use LogicException;
use Throwable;

final class QueueBirSoapTransport implements BirSoapTransportInterface
{
    /** @var list<array{BirOperation, array<string, mixed>, ?string}> */
    public array $calls = [];

    /** @var list<?string> */
    public array $sessionIds = [];

    public int $authenticationChecks = 0;

    /** @var list<TransportResponse|Throwable> */
    private array $queue = [];

    private ?string $sessionId = null;

    public function __construct(private bool $authenticationConfigured = true) {}

    public function setAuthenticationConfigured(bool $configured): self
    {
        $this->authenticationConfigured = $configured;

        return $this;
    }

    public function queue(TransportResponse|Throwable ...$results): self
    {
        array_push($this->queue, ...$results);

        return $this;
    }

    public function isAuthenticationConfigured(): bool
    {
        $this->authenticationChecks++;

        return $this->authenticationConfigured;
    }

    public function useSession(#[\SensitiveParameter] ?string $sessionId): void
    {
        $this->sessionId = $sessionId;
        $this->sessionIds[] = $sessionId;
    }

    public function call(BirOperation $operation, array $parameters = []): TransportResponse
    {
        $this->calls[] = [
            $operation,
            $this->redactSensitiveParameters($parameters),
            $this->sessionId,
        ];

        $result = array_shift($this->queue);

        if ($result === null) {
            throw new LogicException('No queued transport response is available.');
        }

        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return [
            'authenticationConfigured' => $this->authenticationConfigured ? 'yes' : 'no',
            'sessionId' => $this->sessionId === null ? '[NONE]' : '[REDACTED]',
            'queuedResponses' => (string) count($this->queue),
        ];
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function redactSensitiveParameters(array $parameters): array
    {
        foreach ($parameters as $name => $value) {
            if (preg_match('/(?:api.?key|user.?key|klucz|credential|secret|token)/i', $name) === 1) {
                $parameters[$name] = '[REDACTED]';

                continue;
            }

            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $parameters[$name] = $this->redactSensitiveParameters($value);
            }
        }

        return $parameters;
    }
}
