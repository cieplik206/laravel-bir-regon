<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Gateway;

final readonly class BirSessionDiagnostics
{
    public function __construct(
        public ?bool $active,
        public ?int $messageCode,
        public bool $messageCodeWasEmpty = false,
    ) {}

    public function indicatesExpiredSession(): bool
    {
        return $this->active === false
            || $this->messageCode === 7
            || $this->messageCodeWasEmpty;
    }

    public function isComplete(): bool
    {
        return $this->active !== null
            && ($this->messageCode !== null || $this->messageCodeWasEmpty);
    }
}
