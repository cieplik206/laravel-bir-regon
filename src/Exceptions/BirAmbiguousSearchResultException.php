<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Exceptions;

final class BirAmbiguousSearchResultException extends BirException
{
    public readonly string $identifierType;

    public function __construct(
        #[\SensitiveParameter] string $identifierType,
        public readonly int $resultCount,
    ) {
        $this->identifierType = in_array($identifierType, ['NIP', 'REGON', 'KRS'], true)
            ? $identifierType
            : 'UNKNOWN';

        parent::__construct(sprintf(
            'GUS BIR returned %d search results for the %s identifier. Use get() or search() to retrieve every result.',
            $resultCount,
            $this->identifierType,
        ));
    }

    /** @return array{identifierType: string, resultCount: int} */
    public function __serialize(): array
    {
        return [
            'identifierType' => $this->identifierType,
            'resultCount' => $this->resultCount,
        ];
    }

    /** @param array{identifierType?: mixed, resultCount?: mixed} $data */
    public function __unserialize(#[\SensitiveParameter] array $data): void
    {
        $identifierType = is_string($data['identifierType'] ?? null)
            ? $data['identifierType']
            : 'UNKNOWN';
        $resultCount = is_int($data['resultCount'] ?? null)
            ? $data['resultCount']
            : 0;

        $this->__construct($identifierType, $resultCount);

        // PHP creates a new trace containing the unserialize() argument. The
        // original trace is intentionally not serialized, so this synthetic
        // trace has no diagnostic value and could retain a crafted payload.
        (new \ReflectionProperty(\Exception::class, 'trace'))->setValue($this, []);
    }
}
