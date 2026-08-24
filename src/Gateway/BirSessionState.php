<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Gateway;

use cieplik206\BirRegon\Concerns\PreventsSerialization;
use SensitiveParameterValue;

final class BirSessionState
{
    use PreventsSerialization;

    private ?SensitiveParameterValue $sessionId = null;

    public function hasSession(): bool
    {
        $this->ensureNotRestoredFromSerialization();

        return $this->sessionId !== null;
    }

    public function id(): ?string
    {
        $this->ensureNotRestoredFromSerialization();

        $sessionId = $this->sessionId?->getValue();

        return is_string($sessionId) ? $sessionId : null;
    }

    public function start(#[\SensitiveParameter] string $sessionId): void
    {
        $this->ensureNotRestoredFromSerialization();
        $this->sessionId = new SensitiveParameterValue($sessionId);
    }

    public function clear(): void
    {
        $this->ensureNotRestoredFromSerialization();
        $this->sessionId = null;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['sessionId' => $this->sessionId === null ? '[NONE]' : '[REDACTED]'];
    }
}
