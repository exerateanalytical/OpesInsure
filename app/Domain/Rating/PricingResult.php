<?php

declare(strict_types=1);

namespace App\Domain\Rating;

/**
 * REQ-RAT-001/004: explainable premium build-up. Every line carries code, kind and amount_minor;
 * totals are integer minor units: base + coverages + loadings − discounts (+ minimum/rounding
 * adjustments) = net premium; + fees + taxes/levies = total.
 */
final readonly class PricingResult
{
    /**
     * @param  list<array<string,mixed>>  $lines
     * @param  list<array{branch_code:string,basis_points:int,amount_minor:int}>  $branchAllocation
     * @param  list<string>  $warnings
     */
    public function __construct(
        public int $baseMinor,
        public int $coveragesMinor,
        public int $loadingsMinor,
        public int $discountsMinor,
        public int $adjustmentsMinor,
        public int $netPremiumMinor,
        public int $feeMinor,
        public int $taxMinor,
        public int $totalMinor,
        public array $lines,
        public array $branchAllocation,
        public string $allocationStatus,
        public array $warnings = [],
    ) {}

    public function toArray(): array
    {
        return [
            'base_minor' => $this->baseMinor, 'coverages_minor' => $this->coveragesMinor, 'loadings_minor' => $this->loadingsMinor,
            'discounts_minor' => $this->discountsMinor, 'adjustments_minor' => $this->adjustmentsMinor, 'net_premium_minor' => $this->netPremiumMinor,
            'fee_minor' => $this->feeMinor, 'tax_minor' => $this->taxMinor, 'total_minor' => $this->totalMinor,
            'lines' => $this->lines, 'branch_allocation' => $this->branchAllocation, 'allocation_status' => $this->allocationStatus,
            'warnings' => $this->warnings,
        ];
    }
}
