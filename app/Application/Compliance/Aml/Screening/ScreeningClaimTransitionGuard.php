<?php

declare(strict_types=1);

namespace App\Application\Compliance\Aml\Screening;

use App\Domain\Claims\ClaimTransitionGuard;
use App\Models\Claim;
use Illuminate\Support\Facades\DB;

/**
 * Agent E8 — REQ-AML-001 claims payout block: settlement is refused while the claimant or the policyholder has a
 * blocking screening hit (ComplianceGate, stage PAYOUT). Tagged 'claims.transition_guards' by ScreeningServiceProvider.
 */
final class ScreeningClaimTransitionGuard implements ClaimTransitionGuard
{
    public function __construct(private readonly ComplianceGate $gate) {}

    public function events(): array
    {
        return ['request_settlement', 'settle'];
    }

    public function check(Claim $claim, string $event, array $context): ?string
    {
        $holder = $claim->policy_id ? DB::table('policies')->where('id', $claim->policy_id)->value('party_id') : null;

        return $this->gate->blockingReason($claim->tenant_id, [$claim->claimant_party_id, $holder], 'PAYOUT', 'claim', $claim->id);
    }
}
