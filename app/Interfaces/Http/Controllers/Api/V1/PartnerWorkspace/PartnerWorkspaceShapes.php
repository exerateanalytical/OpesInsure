<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\PartnerWorkspace;

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
            'id' => $q->id, 'customer_id' => TenantCustomer::where(['tenant_id' => $tenantId, 'party_id' => $q->party_id])->value('id'),
            'customer_name' => $q->party?->display_name ?? 'Client', 'line_code' => $q->line_code, 'status' => $q->status, 'channel' => $q->channel,
            'offers' => $q->offers->count(), 'best_premium_minor' => $best ? (int) $best->total_minor : null, 'currency' => $q->currency,
            'expires_at' => $q->expires_at?->toIso8601String(), 'created_at' => $q->created_at?->toIso8601String(),
        ];
    }

    public static function policy(Policy $p): array
    {
        return [
            'id' => $p->id, 'policy_number' => $p->policy_number, 'customer_name' => $p->party?->display_name ?? 'Client',
            'carrier_id' => $p->carrier_id, 'carrier_name' => $p->carrier?->party?->display_name ?? 'Insurer',
            'line_code' => $p->terms_snapshot['line_code'] ?? null, 'premium_minor' => (int) $p->premium_minor, 'currency' => $p->currency, 'status' => $p->status,
            'coverage_starts_at' => $p->coverage_starts_at?->toIso8601String(), 'coverage_ends_at' => $p->coverage_ends_at?->toIso8601String(), 'issued_at' => $p->issued_at?->toIso8601String(),
        ];
    }

    public static function claim(Claim $c): array
    {
        return [
            'id' => $c->id, 'claim_number' => $c->claim_number, 'policy_id' => $c->policy_id, 'policy_number' => $c->policy?->policy_number,
            'customer_name' => $c->claimant?->display_name ?? $c->policy?->party?->display_name ?? 'Claimant', 'status' => $c->status, 'priority' => $c->priority,
            'estimated_loss_minor' => $c->estimated_loss_minor !== null ? (int) $c->estimated_loss_minor : null,
            'approved_amount_minor' => $c->approved_amount_minor !== null ? (int) $c->approved_amount_minor : null, 'currency' => $c->currency,
            'loss_occurred_at' => $c->loss_occurred_at?->toIso8601String(), 'submitted_at' => $c->submitted_at?->toIso8601String(),
        ];
    }

    public static function money(int $minor): string
    {
        return number_format($minor / 100, 0, '.', ' ').' FCFA';
    }
}
