<?php

declare(strict_types=1);

namespace App\Application\Commissions\Rules;

use App\Domain\Tenancy\TenantContext;
use App\Models\CommissionRuleVersion;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REQ-COM-002 — single commission rule resolver.
 *
 * Selects the APPROVED rule version effective at `$at` for a carrier, an
 * agreement (and its broker), a product or line and a transaction type, most
 * specific first (agreement > broker > any; product > line > any; exact
 * transaction type > any; latest effective_from, highest version), then
 * computes the commission and its intermediary split lines.
 *
 * Calculation methods:
 *  - FLAT:   basis_points × premium
 *  - TIERED: rate of the PREMIUM tier containing |premium| (falls back to basis_points)
 *  - VOLUME: rate of the VOLUME tier containing the period production (read-only query)
 *  - HYBRID: basis_points + the VOLUME tier rate as a bonus
 */
final class CommissionRuleResolver
{
    public function __construct(private CommissionRuleComponents $components, private TenantContext $tenant) {}

    public function resolve(string $carrierId, ?string $agreementId, ?string $productOrLine, ?string $transactionType, DateTimeInterface|string|null $at = null, int $premiumMinor = 0, ?string $tenantId = null): ?CommissionResolution
    {
        $at = $at === null ? CarbonImmutable::now() : CarbonImmutable::parse($at);
        $tenantId ??= $this->tenant->id();
        $partnerId = $agreementId === null ? null : DB::table('carrier_broker_agreements')->where('id', $agreementId)->where('carrier_id', $carrierId)->value('partner_id');
        if ($agreementId !== null && $partnerId === null) {
            return null; // agreement belongs to another carrier
        }
        [$productId, $lineCode] = $this->productOrLine($productOrLine);
        $transactionType = $transactionType === null ? null : strtoupper($transactionType);
        $day = $at->toDateString();

        $rule = CommissionRuleVersion::query()
            ->where('tenant_id', $tenantId)->where('carrier_id', $carrierId)->where('status', 'APPROVED')
            ->whereDate('effective_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $day))
            ->where(fn ($q) => $agreementId === null ? $q->whereNull('agreement_id') : $q->whereNull('agreement_id')->orWhere('agreement_id', $agreementId))
            ->where(fn ($q) => $partnerId === null ? $q->whereNull('partner_id') : $q->whereNull('partner_id')->orWhere('partner_id', $partnerId))
            ->where(fn ($q) => $productId === null ? $q->whereNull('product_id') : $q->whereNull('product_id')->orWhere('product_id', $productId))
            ->where(fn ($q) => $lineCode === null ? $q->whereNull('line_code') : $q->whereNull('line_code')->orWhere('line_code', $lineCode))
            ->where(fn ($q) => $transactionType === null ? $q->whereNull('transaction_type') : $q->whereNull('transaction_type')->orWhere('transaction_type', $transactionType))
            ->orderByRaw('(agreement_id IS NOT NULL) DESC, (partner_id IS NOT NULL) DESC, (product_id IS NOT NULL) DESC, (line_code IS NOT NULL) DESC, (transaction_type IS NOT NULL) DESC')
            ->orderByDesc('effective_from')->orderByDesc('version')
            ->first();

        return $rule === null ? null : $this->compute($rule, $premiumMinor, $at, $partnerId);
    }

    /** Computes amount + split lines for a known rule version (used by the accrual path). */
    public function compute(CommissionRuleVersion $rule, int $premiumMinor, DateTimeInterface|string|null $at = null, ?string $partnerId = null): CommissionResolution
    {
        $at = $at === null ? CarbonImmutable::now() : CarbonImmutable::parse($at);
        $method = $rule->calculation_method ?? 'FLAT';
        $tiers = $method === 'FLAT' ? [] : $this->components->tiers($rule->id);
        $production = null;
        $rate = (int) $rule->basis_points;
        if ($method === 'TIERED') {
            $rate = $this->tierRate($tiers, 'PREMIUM', abs($premiumMinor)) ?? $rate;
        } elseif ($method === 'VOLUME' || $method === 'HYBRID') {
            $production = $this->periodProduction($rule, $at, $partnerId ?? $rule->partner_id);
            $tierRate = $this->tierRate($tiers, 'VOLUME', $production);
            $rate = $method === 'VOLUME' ? ($tierRate ?? $rate) : $rate + ($tierRate ?? 0);
        }
        $rate = min($rate, CommissionRuleComponents::FULL_SHARE);
        $amount = intdiv($premiumMinor * $rate, 10000);

        return new CommissionResolution($rule, $premiumMinor, $rate, $amount, $production, $this->splitLines($rule, $amount, $partnerId));
    }

    /** @return list<array{beneficiary_type:string,beneficiary_id:?string,share_basis_points:int,amount_minor:int}> */
    private function splitLines(CommissionRuleVersion $rule, int $amount, ?string $partnerId): array
    {
        $splits = $this->components->splits($rule->id);
        if ($splits === []) {
            return [['beneficiary_type' => 'BROKER', 'beneficiary_id' => $partnerId, 'share_basis_points' => CommissionRuleComponents::FULL_SHARE, 'amount_minor' => $amount]];
        }
        $lines = [];
        $allocated = 0;
        foreach ($splits as $s) {
            $part = intdiv($amount * $s['share_basis_points'], CommissionRuleComponents::FULL_SHARE);
            $allocated += $part;
            $lines[] = [...$s, 'beneficiary_id' => $s['beneficiary_id'] ?? $partnerId, 'amount_minor' => $part];
        }
        $lines[0]['amount_minor'] += $amount - $allocated; // rounding remainder to the first (lead) line

        return $lines;
    }

    /** @param list<array{tier_basis:string,threshold_from_minor:int,threshold_to_minor:?int,basis_points:int}> $tiers */
    private function tierRate(array $tiers, string $basis, int $value): ?int
    {
        foreach ($tiers as $t) {
            if ($t['tier_basis'] === $basis && $value >= $t['threshold_from_minor'] && ($t['threshold_to_minor'] === null || $value < $t['threshold_to_minor'])) {
                return $t['basis_points'];
            }
        }

        return null;
    }

    /** Read-only: premium of the carrier's issued policies in the rule's volume period up to $at (per broker when known). */
    private function periodProduction(CommissionRuleVersion $rule, CarbonImmutable $at, ?string $partnerId): int
    {
        $start = match ($rule->volume_period ?? 'MONTH') {
            'QUARTER' => $at->startOfQuarter(), 'YEAR' => $at->startOfYear(), default => $at->startOfMonth(),
        };

        return (int) DB::table('policies')
            ->where('tenant_id', $rule->tenant_id)->where('carrier_id', $rule->carrier_id)
            ->whereNotNull('issued_at')->where('issued_at', '>=', $start)->where('issued_at', '<=', $at)
            ->when($partnerId !== null, fn ($q) => $q->whereExists(fn ($e) => $e->selectRaw('1')->from('commission_accruals')
                ->whereColumn('commission_accruals.policy_id', 'policies.id')->where('commission_accruals.partner_id', $partnerId)))
            ->sum('premium_minor');
    }

    /** @return array{0:?string,1:?string} [productId, lineCode] */
    private function productOrLine(?string $productOrLine): array
    {
        if ($productOrLine === null || $productOrLine === '') {
            return [null, null];
        }
        if (Str::isUuid($productOrLine)) {
            return [$productOrLine, DB::table('insurance_products')->where('id', $productOrLine)->value('line_code')];
        }

        return [null, strtoupper($productOrLine)];
    }
}
