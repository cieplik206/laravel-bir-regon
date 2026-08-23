<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Facades;

use cieplik206\BirRegon\BirBatchSearchBuilder;
use cieplik206\BirRegon\BirBulkReportBuilder;
use cieplik206\BirRegon\BirDiagnosticsBuilder;
use cieplik206\BirRegon\BirRegonService;
use cieplik206\BirRegon\BirSearchBuilder;
use cieplik206\BirRegon\BirServiceBuilder;
use DateTimeImmutable;
use Illuminate\Support\Facades\Facade;

/**
 * @method static BirRegonService sandbox()
 * @method static BirSearchBuilder forNip(string $nip)
 * @method static BirSearchBuilder forRegon(string $regon)
 * @method static BirSearchBuilder forKrs(string $krs)
 * @method static BirBatchSearchBuilder forNips(array<int, string> $nips)
 * @method static BirBatchSearchBuilder forKrsNumbers(array<int, string> $krsNumbers)
 * @method static BirBatchSearchBuilder forRegons9(array<int, string> $regons)
 * @method static BirBatchSearchBuilder forRegons14(array<int, string> $regons)
 * @method static BirBulkReportBuilder forDate(DateTimeImmutable $date)
 * @method static BirServiceBuilder service()
 * @method static BirDiagnosticsBuilder diagnostics()
 * @method static bool logout()
 *
 * @see BirRegonService
 */
class BirRegon extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BirRegonService::class;
    }
}
