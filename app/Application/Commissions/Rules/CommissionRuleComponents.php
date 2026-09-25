<?php

declare(strict_types=1);

namespace App\Application\Commissions\Rules;

use App\Models\CommissionRuleVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-COM-002 — tiers and intermediary splits of a commission rule version
 * (PRE §57–60, SCF §25, §38). Components are written with the DRAFT rule and
 * checked again at approval: an approved rule with splits always totals 100 %.
 */
final class CommissionRuleComponents
{
    public const TRANSACTION_TYPES = ['NEW_BUSINESS', 'RENEWAL', 'ENDORSEMENT', 'CANCELLATION', 'REINSTATEMENT', 'ADJUSTMENT'];

    public const METHODS = ['FLAT', 'TIERED', 'VOLUME', 'HYBRID'];

    public const VOLUME_PERIODS = ['MONTH', 'QUARTER', 'YEAR'];

    public const BENEFICIARY_TYPES = ['BROKER', 'BRANCH', 'AGENT'];

    public const FULL_SHARE = 10000;

    /**
     * @param  list<array<string, mixed>>  $tiers
     * @param  list<array<string, mixed>>  $splits
     */
    public function store(CommissionRuleVersion $rule, array $tiers, array $splits): void
    {
        $this->assertSplitsTotal($splits);
        foreach ($tiers as $tier) {
            DB::table('commission_rule_tiers')->insert([
                'id' => (string) Str::uuid(), 'commission_rule_version_id' => $rule->id,
                'tier_basis' => strtoupper((string) $tier['tier_basis']), 'threshold_from_minor' => (int) $tier['threshold_from_minor'],
                'threshold_to_minor' => isset($tier['threshold_to_minor']) ? (int) $tier['threshold_to_minor'] : null,
                'basis_points' => (int) $tier['basis_points'], 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach (array_values($splits) as $i => $split) {
            DB::table('commission_rule_splits')->insert([
                'id' => (string) Str::uuid(), 'commission_rule_version_id' => $rule->id,
                'beneficiary_type' => strtoupper((string) $split['beneficiary_type']), 'beneficiary_id' => $split['beneficiary_id'] ?? null,
                'share_basis_points' => (int) $split['share_basis_points'], 'position' => $i, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** Approval gate: splits total 100 % and the calculation method has the tiers it needs. */
    public function assertApprovable(CommissionRuleVersion $rule): void
    {
        $this->assertSplitsTotal($this->splits($rule->id));
        $method = $rule->calculation_method ?? 'FLAT';
        $bases = array_unique(array_map(fn (array $t) => $t['tier_basis'], $this->tiers($rule->id)));
        $needed = ['TIERED' => 'PREMIUM', 'VOLUME' => 'VOLUME', 'HYBRID' => 'VOLUME'][$method] ?? null;
        if ($needed !== null && ! in_array($needed, $bases, true)) {
            throw ValidationException::withMessages(['tiers' => __('validation.required', ['attribute' => 'tiers'])]);
        }
    }

    /** @param  list<array<string, mixed>>  $splits */
    public function assertSplitsTotal(array $splits): void
    {
        if ($splits === []) {
            return;
        }
        $total = array_sum(array_map(fn ($s) => (int) $s['share_basis_points'], $splits));
        if ($total !== self::FULL_SHARE) {
            throw ValidationException::withMessages(['splits' => 'Commission split shares must total 100% (10000 basis points), got '.$total.'.']);
        }
    }

    /** @return list<array{tier_basis:string,threshold_from_minor:int,threshold_to_minor:?int,basis_points:int}> */
    public function tiers(string $ruleId): array
    {
        return DB::table('commission_rule_tiers')->where('commission_rule_version_id', $ruleId)->orderBy('threshold_from_minor')
            ->get(['tier_basis', 'threshold_from_minor', 'threshold_to_minor', 'basis_points'])
            ->map(fn ($t) => ['tier_basis' => $t->tier_basis, 'threshold_from_minor' => (int) $t->threshold_from_minor, 'threshold_to_minor' => $t->threshold_to_minor === null ? null : (int) $t->threshold_to_minor, 'basis_points' => (int) $t->basis_points])->all();
    }

    /** @return list<array{beneficiary_type:string,beneficiary_id:?string,share_basis_points:int}> */
    public function splits(string $ruleId): array
    {
        return DB::table('commission_rule_splits')->where('commission_rule_version_id', $ruleId)->orderBy('position')
            ->get(['beneficiary_type', 'beneficiary_id', 'share_basis_points'])
            ->map(fn ($s) => ['beneficiary_type' => $s->beneficiary_type, 'beneficiary_id' => $s->beneficiary_id, 'share_basis_points' => (int) $s->share_basis_points])->all();
    }
}
