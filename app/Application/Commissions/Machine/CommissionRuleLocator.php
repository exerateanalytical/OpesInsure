<?php

declare(strict_types=1);

namespace App\Application\Commissions\Machine;

use App\Application\Commissions\Rules\CommissionRuleResolver;
use App\Models\CommissionRuleVersion;
use App\Models\Policy;
use Carbon\CarbonInterface;

/**
 * REQ-COM-001 — which APPROVED commission_rule_versions row an automatic accrual uses. Thin adapter over the single REQ-COM-002
 * selector (Rules\CommissionRuleResolver::resolve, most specific wins) for a policy + attributed partner; no rule selection here.
 */
final class CommissionRuleLocator
{
    public function __construct(private readonly CommissionRuleResolver $resolver) {}

    public function forPolicy(Policy $policy, string $partnerId, CarbonInterface $at): ?CommissionRuleVersion
    {
        return $this->resolver->resolve($policy->carrier_id, null, $policy->proposal?->offer?->product_id, null, $at, (int) $policy->premium_minor, $policy->tenant_id, $partnerId)?->rule;
    }
}
