<?php

declare(strict_types=1);

namespace cieplik206\BirRegon;

use cieplik206\BirRegon\Data\CompanyData;
use cieplik206\BirRegon\Exceptions\BirException;
use Illuminate\Support\Collection;

class BirBatchSearchBuilder extends BirRequestBuilder
{
    public const TYPE_NIPS = 'NIPS';

    public const TYPE_KRS_NUMBERS = 'KRS_NUMBERS';

    public const TYPE_REGONS_9 = 'REGONS_9';

    public const TYPE_REGONS_14 = 'REGONS_14';

    /**
     * @param  array<int, string>  $identifiers
     */
    public function __construct(
        BirClientInterface $client,
        private readonly array $identifiers,
        private readonly string $identifierType,
    ) {
        parent::__construct($client);
    }

    /** @return Collection<int, CompanyData> */
    public function search(): Collection
    {
        $client = $this->resolveClient();

        $companies = match ($this->identifierType) {
            self::TYPE_NIPS => $client->searchByNips($this->identifiers),
            self::TYPE_KRS_NUMBERS => $client->searchByKrsNumbers($this->identifiers),
            self::TYPE_REGONS_9 => $client->searchByRegons9($this->identifiers),
            self::TYPE_REGONS_14 => $client->searchByRegons14($this->identifiers),
            default => throw new BirException('Unsupported batch search identifier type.'),
        };

        return new Collection($companies);
    }

    /** @return Collection<int, CompanyData> */
    public function get(): Collection
    {
        return $this->search();
    }
}
