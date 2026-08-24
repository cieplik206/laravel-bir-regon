<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Protocol;

final readonly class XmlDecodeResult
{
    /**
     * @param  list<array<string, string>>  $records
     */
    private function __construct(
        public bool $successful,
        #[\SensitiveParameter] public array $records,
        public ?BirErrorData $error,
    ) {}

    /** @param list<array<string, string>> $records */
    public static function success(
        #[\SensitiveParameter] array $records,
        ?BirErrorData $error = null,
    ): self {
        return new self(true, $records, $error);
    }

    public static function failure(): self
    {
        return new self(false, [], null);
    }
}
