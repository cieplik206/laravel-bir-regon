<?php

declare(strict_types=1);

namespace cieplik206\BirRegon;

use cieplik206\BirRegon\Concerns\PreventsSerialization;
use cieplik206\BirRegon\Exceptions\BirException;
use DateTimeImmutable;
use SensitiveParameterValue;

class BirRegonService
{
    use PreventsSerialization;

    private bool $isSandbox = false;

    private ?self $sandboxService = null;

    private readonly SensitiveParameterValue $client;

    private readonly SensitiveParameterValue $sandboxClient;

    public function __construct(
        #[\SensitiveParameter] BirClientInterface $client,
        #[\SensitiveParameter] ?BirClientInterface $sandboxClient = null,
    ) {
        if ($sandboxClient !== null && $client === $sandboxClient) {
            throw new \InvalidArgumentException(
                'Production and sandbox BIR clients must be different instances.',
            );
        }

        $this->client = new SensitiveParameterValue($client);
        $this->sandboxClient = new SensitiveParameterValue($sandboxClient);
    }

    public function sandbox(): self
    {
        $this->ensureNotRestoredFromSerialization();

        if ($this->isSandbox) {
            return $this;
        }

        $sandboxClient = $this->sandboxClient();

        if ($sandboxClient === null) {
            throw new BirException('Sandbox client is not configured.');
        }

        if ($this->sandboxService === null) {
            $this->sandboxService = new self($sandboxClient);
            $this->sandboxService->isSandbox = true;
        }

        return $this->sandboxService;
    }

    public function forNip(#[\SensitiveParameter] string $nip): BirSearchBuilder
    {
        return new BirSearchBuilder($this->getClient(), $nip, BirSearchBuilder::TYPE_NIP);
    }

    public function forRegon(#[\SensitiveParameter] string $regon): BirSearchBuilder
    {
        return new BirSearchBuilder($this->getClient(), $regon, BirSearchBuilder::TYPE_REGON);
    }

    public function forKrs(#[\SensitiveParameter] string $krs): BirSearchBuilder
    {
        return new BirSearchBuilder($this->getClient(), $krs, BirSearchBuilder::TYPE_KRS);
    }

    /**
     * @param  array<int, string>  $nips
     */
    public function forNips(#[\SensitiveParameter] array $nips): BirBatchSearchBuilder
    {
        return new BirBatchSearchBuilder($this->getClient(), $nips, BirBatchSearchBuilder::TYPE_NIPS);
    }

    /**
     * @param  array<int, string>  $krsNumbers
     */
    public function forKrsNumbers(#[\SensitiveParameter] array $krsNumbers): BirBatchSearchBuilder
    {
        return new BirBatchSearchBuilder(
            $this->getClient(),
            $krsNumbers,
            BirBatchSearchBuilder::TYPE_KRS_NUMBERS,
        );
    }

    /**
     * @param  array<int, string>  $regons
     */
    public function forRegons9(#[\SensitiveParameter] array $regons): BirBatchSearchBuilder
    {
        return new BirBatchSearchBuilder($this->getClient(), $regons, BirBatchSearchBuilder::TYPE_REGONS_9);
    }

    /**
     * @param  array<int, string>  $regons
     */
    public function forRegons14(#[\SensitiveParameter] array $regons): BirBatchSearchBuilder
    {
        return new BirBatchSearchBuilder($this->getClient(), $regons, BirBatchSearchBuilder::TYPE_REGONS_14);
    }

    public function forDate(DateTimeImmutable $date): BirBulkReportBuilder
    {
        return new BirBulkReportBuilder($this->getClient(), $date);
    }

    public function service(): BirServiceBuilder
    {
        return new BirServiceBuilder($this->getClient());
    }

    public function diagnostics(): BirDiagnosticsBuilder
    {
        return new BirDiagnosticsBuilder($this->getClient());
    }

    public function logout(): bool
    {
        return $this->getClient()->logout();
    }

    private function getClient(): BirClientInterface
    {
        $this->ensureNotRestoredFromSerialization();

        return $this->client();
    }

    private function client(): BirClientInterface
    {
        $client = $this->client->getValue();

        if (! $client instanceof BirClientInterface) {
            throw new \LogicException('The BIR client is unavailable.');
        }

        return $client;
    }

    private function sandboxClient(): ?BirClientInterface
    {
        $sandboxClient = $this->sandboxClient->getValue();

        if ($sandboxClient !== null && ! $sandboxClient instanceof BirClientInterface) {
            throw new \LogicException('The BIR sandbox client is unavailable.');
        }

        return $sandboxClient;
    }
}
