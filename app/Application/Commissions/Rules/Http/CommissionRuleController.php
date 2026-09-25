<?php

declare(strict_types=1);

namespace App\Application\Commissions\Rules\Http;

use App\Application\Commissions\Rules\CommissionRuleComponents;
use App\Application\Commissions\Rules\CommissionRuleResolver;
use App\Domain\Tenancy\TenantContext;
use App\Models\CommissionRuleVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Batch 10-2: REQ-COM-002 commission rule resolution preview and rule components. */
final class CommissionRuleController
{
    public function __construct(private TenantContext $tenant, private CommissionRuleResolver $resolver, private CommissionRuleComponents $components) {}

    /** Extra validation for POST financial-distribution/commission-rules. @return array<string, mixed> */
    public static function ruleExtensionRules(): array
    {
        return [
            'agreement_id' => 'nullable|uuid|exists:carrier_broker_agreements,id',
            'line_code' => 'nullable|string|max:32',
            'transaction_type' => ['nullable', 'string', Rule::in([...CommissionRuleComponents::TRANSACTION_TYPES, ...array_map('strtolower', CommissionRuleComponents::TRANSACTION_TYPES)])],
            'calculation_method' => ['nullable', Rule::in(CommissionRuleComponents::METHODS)],
            'volume_period' => ['nullable', Rule::in(CommissionRuleComponents::VOLUME_PERIODS)],
            'tiers' => 'array|max:50',
            'tiers.*.tier_basis' => ['required', Rule::in(['PREMIUM', 'VOLUME'])],
            'tiers.*.threshold_from_minor' => 'required|integer|min:0',
            'tiers.*.threshold_to_minor' => 'nullable|integer|gt:tiers.*.threshold_from_minor',
            'tiers.*.basis_points' => 'required|integer|min:0|max:10000',
            'splits' => 'array|max:20',
            'splits.*.beneficiary_type' => ['required', Rule::in(CommissionRuleComponents::BENEFICIARY_TYPES)],
            'splits.*.beneficiary_id' => 'nullable|uuid',
            'splits.*.share_basis_points' => 'required|integer|min:1|max:10000',
        ];
    }

    public function resolve(Request $r): JsonResponse
    {
        $d = $r->validate([
            'carrier_id' => 'required|uuid', 'agreement_id' => 'nullable|uuid', 'product_or_line' => 'nullable|string|max:64',
            'transaction_type' => ['nullable', 'string', 'max:24'], 'at' => 'nullable|date', 'premium_minor' => 'nullable|integer',
        ]);
        $res = $this->resolver->resolve($d['carrier_id'], $d['agreement_id'] ?? null, $d['product_or_line'] ?? null, $d['transaction_type'] ?? null, $d['at'] ?? null, (int) ($d['premium_minor'] ?? 0), $this->tenant->id());

        return $res === null ? response()->json(['message' => 'No approved commission rule applies.'], 404) : response()->json(['data' => $res->toArray()]);
    }

    public function show(CommissionRuleVersion $rule): JsonResponse
    {
        abort_unless($rule->tenant_id === $this->tenant->id(), 404);

        return response()->json(['data' => [...$rule->toArray(), 'tiers' => $this->components->tiers($rule->id), 'splits' => $this->components->splits($rule->id)]]);
    }
}
