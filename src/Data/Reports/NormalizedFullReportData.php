<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Data\Reports;

use cieplik206\BirRegon\Enums\EntityType;
use Spatie\LaravelData\Data;

final class NormalizedFullReportData extends Data
{
    /**
     * @param  list<EntityDetailsData>  $localUnits
     * @param  list<PkdActivityData>  $pkdActivities
     * @param  list<PartnerData>  $partners
     */
    public function __construct(
        public ?EntityDetailsData $entity = null,
        public array $localUnits = [],
        public array $pkdActivities = [],
        public array $partners = [],
        public ?EntityType $unitType = null,
    ) {}
}
