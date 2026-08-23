<?php

declare(strict_types=1);

namespace cieplik206\BirRegon;

use cieplik206\BirRegon\Exceptions\BirException;
use DateTimeImmutable;

class BirRegonService
{
    private ?self $sandboxService = null;

    public function __construct(
        private readonly BirClientInterface $client,
        private readonly ?BirClientInterface $sandboxClient = null,
    ) {}

    public function sandbox(): self
    {
        if ($this->sandboxClient === null) {
            throw new BirException('Sandbox client is not configured.');
        }

        if ($this->client === $this->sandboxClient) {
            return $this;
        }

        return $this->sandboxService ??= new self($this->sandboxClient, $this->sandboxClient);
    }

    public function forNip(string $nip): BirSearchBuilder
    {
        return new BirSearchBuilder($this->client, $nip, BirSearchBuilder::TYPE_NIP);
    }

    public function forRegon(string $regon): BirSearchBuilder
    {
        return new BirSearchBuilder($this->client, $regon, BirSearchBuilder::TYPE_REGON);
    }

    public function forKrs(string $krs): BirSearchBuilder
    {
        return new BirSearchBuilder($this->client, $krs, BirSearchBuilder::TYPE_KRS);
    }

    /**
     * @param  array<int, string>  $nips
     */
    public function forNips(array $nips): BirBatchSearchBuilder
    {
        return new BirBatchSearchBuilder($this->client, $nips, BirBatchSearchBuilder::TYPE_NIPS);
    }

    /**
     * @param  array<int, string>  $krsNumbers
     */
    public function forKrsNumbers(array $krsNumbers): BirBatchSearchBuilder
    {
        return new BirBatchSearchBuilder(
            $this->client,
            $krsNumbers,
            BirBatchSearchBuilder::TYPE_KRS_NUMBERS,
        );
    }

    /**
     * @param  array<int, string>  $regons
     */
    public function forRegons9(array $regons): BirBatchSearchBuilder
    {
        return new BirBatchSearchBuilder($this->client, $regons, BirBatchSearchBuilder::TYPE_REGONS_9);
    }

    /**
     * @param  array<int, string>  $regons
     */
    public function forRegons14(array $regons): BirBatchSearchBuilder
    {
        return new BirBatchSearchBuilder($this->client, $regons, BirBatchSearchBuilder::TYPE_REGONS_14);
    }

    public function forDate(DateTimeImmutable $date): BirBulkReportBuilder
    {
        return new BirBulkReportBuilder($this->client, $date);
    }

    public function service(): BirServiceBuilder
    {
        return new BirServiceBuilder($this->client);
    }

    public function diagnostics(): BirDiagnosticsBuilder
    {
        return new BirDiagnosticsBuilder($this->client);
    }
}
