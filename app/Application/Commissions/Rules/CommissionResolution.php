<?php

declare(strict_types=1);

namespace App\Application\Commissions\Rules;

use App\Models\CommissionRuleVersion;

/** Result of CommissionRuleResolver: the rule version, the effective rate, the amount and its split lines. */
final class CommissionResolution
{
    /**
     * @param  list<array{beneficiary_type:string,beneficiary_id:?string,share_basis_points:int,amount_minor:int}>  $splits
     */
    public function __construct(
        public readonly CommissionRuleVersion $rule,
        public readonly int $premiumMinor,
        public readonly int $basisPoints,
        public readonly int $amountMinor,
        public readonly ?int $periodProductionMinor,
        public readonly array $splits,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'rule_version_id' => $this->rule->id, 'version' => $this->rule->version,
            'calculation_method' => $this->rule->calculation_method ?? 'FLAT', 'premium_minor' => $this->premiumMinor,
            'basis_points' => $this->basisPoints, 'amount_minor' => $this->amountMinor,
            'period_production_minor' => $this->periodProductionMinor, 'splits' => $this->splits,
        ];
    }
}
