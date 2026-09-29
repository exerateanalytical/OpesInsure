<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\PartnerWorkspace;

use App\Application\Documents\Letterhead\LetterheadResolver;
use App\Models\Claim;
use App\Models\Policy;
use App\Models\Quote;
use App\Models\TenantCustomer;

/** JSON shapes shared by the Wave 16 partner workspace endpoints. */
final class PartnerWorkspaceShapes
{
    public static function quote(Quote $q, string $tenantId): array
    {
        $best = $q->offers->sortBy(fn ($o) => $o->comparison_rank ?? PHP_INT_MAX)->first();

        return [
            // R4: one query for every quote row of the list (RequestMemo::rowValue), not one per row.
            'id' => $q->id, 'customer_id' => \App\Application\Identity\Rbac\RequestMemo::rowValue('quote-customer:'.$tenantId, 'quotes', (string) $q->id,
                fn (array $ids) => \Illuminate\Support\Facades\DB::table('tenant_customers')->join('quotes', 'quotes.party_id', '=', 'tenant_customers.party_id')
                    ->where('tenant_customers.tenant_id', $tenantId)->whereIn('quotes.id', $ids)
                    ->get(['tenant_customers.id as cid', 'quotes.id as qid'])->pluck('cid', 'qid')->map(fn ($v) => (string) $v)->all()),
            'customer_name' => $q->party?->display_name ?? 'Client', 'line_code' => $q->line_code, 'status' => $q->status, 'channel' => $q->channel,
            'offers' => $q->offers->count(), 'best_premium_minor' => $best ? (int) $best->total_minor : null, 'currency' => $q->currency,
            'expires_at' => $q->expires_at?->toIso8601String(), 'created_at' => $q->created_at?->toIso8601String(),
            // Assisted sale (agent-sold): the agent can prompt the client for the premium (POST /mobile/agent/sales/{id}/payment-request).
            'assisted' => ! empty(($q->comparison_context ?? [])['agent_user_id']), 'payment_status' => ($q->comparison_context ?? [])['payment_status'] ?? null,
        ];
    }

    public static function policy(Policy $p): array
    {
        return [
            'id' => $p->id, 'policy_number' => $p->policy_number, 'customer_name' => $p->party?->display_name ?? 'Client',
            'carrier_id' => $p->carrier_id, 'carrier_name' => $p->carrier?->party?->display_name ?? 'Insurer', 'carrier_short_name' => \App\Application\Directory\InsurerShortNames::shortOf($p->carrier),
            'line_code' => $p->terms_snapshot['line_code'] ?? null, 'premium_minor' => (int) $p->premium_minor, 'currency' => $p->currency, 'status' => $p->status,
            'coverage_starts_at' => $p->coverage_starts_at?->toIso8601String(), 'coverage_ends_at' => $p->coverage_ends_at?->toIso8601String(), 'issued_at' => $p->issued_at?->toIso8601String(),
        ];
    }

    public static function claim(Claim $c): array
    {
        return [
            'id' => $c->id, 'claim_number' => $c->claim_number, 'policy_id' => $c->policy_id, 'policy_number' => $c->policy?->policy_number,
            'customer_name' => $c->claimant?->display_name ?? $c->policy?->party?->display_name ?? 'Claimant', 'status' => $c->status, 'priority' => $c->priority,
            'carrier_name' => $c->policy?->carrier?->party?->display_name, 'carrier_short_name' => \App\Application\Directory\InsurerShortNames::shortOf($c->policy?->carrier), 'carrier_logo_url' => LetterheadResolver::carrierLogoUrl($c->policy?->carrier_id),
            'estimated_loss_minor' => $c->estimated_loss_minor !== null ? (int) $c->estimated_loss_minor : null,
            'approved_amount_minor' => $c->approved_amount_minor !== null ? (int) $c->approved_amount_minor : null, 'currency' => $c->currency,
            'loss_occurred_at' => $c->loss_occurred_at?->toIso8601String(), 'submitted_at' => $c->submitted_at?->toIso8601String(),
        ];
    }

    public static function proposal(\App\Models\Proposal $p, string $tenantId): array
    {
        $o = $p->offer;

        return [
            'id' => $p->id, 'proposal_number' => $p->proposal_number, 'customer_id' => TenantCustomer::where(['tenant_id' => $tenantId, 'party_id' => $p->party_id])->value('id'),
            'customer_name' => $p->party?->display_name ?? 'Client', 'status' => $p->status, 'line_code' => $o?->quote?->line_code,
            'carrier_name' => $o?->carrier?->party?->display_name, 'carrier_short_name' => \App\Application\Directory\InsurerShortNames::shortOf($o?->carrier), 'carrier_logo_url' => LetterheadResolver::carrierLogoUrl($o?->carrier_id), 'policy_id' => $p->issuedPolicyId(),
            'total_minor' => $o ? (int) $o->total_minor : null, 'currency' => $o?->currency ?? 'XAF',
            'submitted_at' => $p->submitted_at?->toIso8601String(), 'decided_at' => $p->decided_at?->toIso8601String(), 'created_at' => $p->created_at?->toIso8601String(),
        ];
    }

    public static function money(int $minor): string
    {
        return number_format($minor / 100, 0, '.', ' ').' FCFA';
    }
}
