<?php

declare(strict_types=1);

namespace App\Application\Attribution;

use App\Models\Policy;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * REQ-CRM-003 — commission-ready attribution of a policy to an intermediary.
 *
 * No per-policy attribution table (that would duplicate customer_attributions):
 * the producing intermediary of a policy is the partner that held the
 * customer's origin lock when the policy was issued, reconstructed from
 * attribution_events. A later transfer/reassignment therefore moves future
 * servicing but never rewrites who produced an already-issued policy — which
 * is what commission accrual needs (commission_accruals.partner_id).
 */
final class PolicyAttributionResolver
{
    /** @return array{partner_id: ?string, origin_type: ?string, attribution_id: ?string, as_of: string, current_partner_id: ?string} */
    public function forPolicy(Policy $policy): array
    {
        $at = Carbon::parse($policy->issued_at ?? $policy->created_at);
        $resolved = $this->partnerAt($policy->party_id, $at);

        return $resolved + ['as_of' => $at->toIso8601String()];
    }

    /** @return array{partner_id: ?string, origin_type: ?string, attribution_id: ?string, current_partner_id: ?string} */
    public function partnerAt(string $partyId, CarbonInterface $at): array
    {
        $a = DB::table('customer_attributions')->where('party_id', $partyId)->where('status', 'ACTIVE')->first();
        if (! $a || Carbon::parse($a->effective_from)->gt($at)) {
            return ['partner_id' => null, 'origin_type' => null, 'attribution_id' => $a->id ?? null, 'current_partner_id' => $a->partner_id ?? null];
        }
        // First ownership change after $at tells us who held it at $at.
        $change = DB::table('attribution_events')->where('attribution_id', $a->id)->whereIn('type', ['TRANSFERRED', 'REASSIGNED'])
            ->where('occurred_at', '>', $at)->orderBy('occurred_at')->first();
        $partnerId = $change ? $change->from_partner_id : $a->partner_id;
        $origin = $partnerId ? DB::table('partners')->where('id', $partnerId)->value('type') : $a->origin_type;

        return ['partner_id' => $partnerId, 'origin_type' => $partnerId ? ($origin === 'AGENT' ? 'AGENT' : 'BROKER') : $a->origin_type, 'attribution_id' => $a->id, 'current_partner_id' => $a->partner_id];
    }
}
