<?php

declare(strict_types=1);

namespace App\Application\Fraud;

use App\Models\Claim;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * REQ-FRD-001 — the fact set CLAIM-scope fraud rules are evaluated against. Every fact is derived from stored
 * data (no external scoring); a fact that cannot be derived is null and never fires a rule.
 */
final class ClaimFraudFacts
{
    public const FACTS = [
        'estimated_loss_minor', 'days_inception_to_loss', 'days_loss_to_expiry', 'days_loss_to_report',
        'loss_to_premium_ratio', 'prior_claims_365d', 'open_claims_on_policy', 'priority',
    ];

    /** @return array<string,mixed> */
    public function for(Claim $claim): array
    {
        $policy = DB::table('policies')->where('id', $claim->policy_id)->first(['coverage_starts_at', 'coverage_ends_at', 'premium_minor']);
        $loss = $claim->loss_occurred_at ? CarbonImmutable::parse($claim->loss_occurred_at) : null;
        $reported = $claim->submitted_at ? CarbonImmutable::parse($claim->submitted_at) : null;
        $starts = $policy?->coverage_starts_at ? CarbonImmutable::parse($policy->coverage_starts_at) : null;
        $ends = $policy?->coverage_ends_at ? CarbonImmutable::parse($policy->coverage_ends_at) : null;
        $estimate = $claim->estimated_loss_minor !== null ? (int) $claim->estimated_loss_minor : null;
        $premium = (int) ($policy->premium_minor ?? 0);

        $prior = null;
        if ($claim->claimant_party_id && $loss) {
            $prior = DB::table('claims')->where('claimant_party_id', $claim->claimant_party_id)->where('id', '<>', $claim->id)
                ->whereBetween('loss_occurred_at', [$loss->subDays(365), $loss])->count();
        }

        return [
            'estimated_loss_minor' => $estimate,
            'days_inception_to_loss' => $loss && $starts ? (int) floor($starts->diffInDays($loss, false)) : null,
            'days_loss_to_expiry' => $loss && $ends ? (int) floor($loss->diffInDays($ends, false)) : null,
            'days_loss_to_report' => $loss && $reported ? (int) floor($loss->diffInDays($reported, false)) : null,
            'loss_to_premium_ratio' => $estimate !== null && $premium > 0 ? round($estimate / $premium, 4) : null,
            'prior_claims_365d' => $prior,
            'open_claims_on_policy' => DB::table('claims')->where('policy_id', $claim->policy_id)->where('id', '<>', $claim->id)->whereNull('closed_at')->count(),
            'priority' => $claim->priority,
        ];
    }
}
