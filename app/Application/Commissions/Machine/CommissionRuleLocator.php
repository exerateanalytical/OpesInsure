<?php

declare(strict_types=1);

namespace App\Application\Commissions\Machine;

use App\Models\CommissionRuleVersion;
use App\Models\Policy;
use Carbon\CarbonInterface;

/**
 * REQ-COM-001 — which APPROVED commission_rule_versions row an automatic accrual uses (read-only; rule authoring and tiers are
 * REQ-COM-002). Most specific wins: partner-specific over generic, product-specific over carrier-wide, then the latest version,
 * restricted to the tenant + carrier of the policy and to rules effective on the sale date.
 */
final class CommissionRuleLocator
{
    public function forPolicy(Policy $policy, string $partnerId, CarbonInterface $at): ?CommissionRuleVersion
    {
        $productId = $policy->proposal?->offer?->product_id;
        $day = $at->toDateString();

        return CommissionRuleVersion::where('tenant_id', $policy->tenant_id)->where('carrier_id', $policy->carrier_id)->where('status', 'APPROVED')
            ->where(fn ($q) => $q->whereNull('partner_id')->orWhere('partner_id', $partnerId))
            ->where(fn ($q) => $q->whereNull('product_id')->when($productId, fn ($q) => $q->orWhere('product_id', $productId)))
            ->whereDate('effective_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $day))
            ->orderByRaw('partner_id IS NULL ASC')->orderByRaw('product_id IS NULL ASC')->orderByDesc('version')
            ->first();
    }
}
