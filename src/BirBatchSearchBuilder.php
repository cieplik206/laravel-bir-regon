<?php

declare(strict_types=1);

namespace cieplik206\BirRegon;

use cieplik206\BirRegon\Data\CompanyData;
use cieplik206\BirRegon\Exceptions\BirException;
use Illuminate\Support\Collection;
use SensitiveParameterValue;

class BirBatchSearchBuilder extends BirRequestBuilder
{
    public const TYPE_NIPS = 'NIPS';

    public const TYPE_KRS_NUMBERS = 'KRS_NUMBERS';

    public const TYPE_REGONS_9 = 'REGONS_9';

    public const TYPE_REGONS_14 = 'REGONS_14';

    private readonly SensitiveParameterValue $identifiers;

    /**
     * @param  array<int, string>  $identifiers
     */
    public function __construct(
        #[\SensitiveParameter] BirClientInterface $client,
        #[\SensitiveParameter] array $identifiers,
        private readonly string $identifierType,
    ) {
        parent::__construct($client);

        $this->identifiers = new SensitiveParameterValue($identifiers);
    }

    /** @return Collection<int, CompanyData> */
    public function search(): Collection
    {
        $client = $this->getClient();

        $companies = match ($this->identifierType) {
            self::TYPE_NIPS => $client->searchByNips($this->identifiers()),
            self::TYPE_KRS_NUMBERS => $client->searchByKrsNumbers($this->identifiers()),
            self::TYPE_REGONS_9 => $client->searchByRegons9($this->identifiers()),
            self::TYPE_REGONS_14 => $client->searchByRegons14($this->identifiers()),
            default => throw new BirException('Unsupported batch search identifier type.'),
        };

        return new Collection($companies);
    }

    /** @return Collection<int, CompanyData> */
    public function get(): Collection
    {
        return $this->search();
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        if ($this->wasRestoredFromSerialization()) {
            return [
                'client' => '[UNAVAILABLE]',
                'identifiers' => '[UNAVAILABLE]',
                'identifierType' => '[UNAVAILABLE]',
            ];
        }

        return [
            'client' => '[HIDDEN]',
            'identifiers' => '[REDACTED]',
            'identifierType' => $this->identifierType,
        ];
    }

    /** @return array<int, string> */
    private function identifiers(): array
    {
        $identifiers = $this->identifiers->getValue();

        if (! is_array($identifiers)) {
            throw new \LogicException('The BIR search identifiers are unavailable.');
        }

        return $identifiers;
    }
}
