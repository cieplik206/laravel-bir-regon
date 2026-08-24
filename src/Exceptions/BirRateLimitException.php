<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Exceptions;

final class BirRateLimitException extends BirException
{
    private function __construct(
        private readonly int $retryAfter,
        private readonly bool $quotaExceeded,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function quotaExceeded(int $retryAfterSeconds): self
    {
        $retryAfterSeconds = max(1, $retryAfterSeconds);

        return new self(
            $retryAfterSeconds,
            true,
            sprintf(
                'The local GUS BIR request limit has been reached. Retry after %d seconds.',
                $retryAfterSeconds,
            ),
        );
    }

    public static function limiterUnavailable(): self
    {
        return new self(
            1,
            false,
            'The local GUS BIR request limiter is unavailable. Retry after 1 second.',
        );
    }

    public function retryAfterSeconds(): int
    {
        return $this->retryAfter;
    }

    public function quotaWasExceeded(): bool
    {
        return $this->quotaExceeded;
    }
}
