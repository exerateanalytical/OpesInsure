<?php

declare(strict_types=1);

namespace App\Application\Regulatory;

use App\Models\InsuranceProduct;
use App\Models\Regulatory\LegacyProductAuthorization;
use App\Models\Regulatory\ProductRegulatoryMapping;
use App\Models\Regulatory\RegulatoryBranch;
use App\Models\Regulatory\RegulatoryRegime;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Publication guard (CIMA Regulatory Dictionary v1): a product can only be
 * submitted/published/resumed when it has at least one PRIMARY CIMA branch
 * mapping and its insurer holds an ACTIVE, effective authorization covering
 * every PRIMARY and COMPLEMENTARY branch. ACCESSORY branches need no
 * authorization, except that branches 14/15 (accessory_allowed = false) can
 * never be accessory (Article 328-1). Class default mappings are applied
 * automatically first, so onboarding only needs the insurer authorization.
 *
 * Owner decisions 2026-09-25: item 7 — unknown authorization is BLOCK_NEW_PRODUCT_PUBLICATION, never a warning.
 * Item 8 — a product already live is LEGACY_ACTIVE_AUTHORIZATION_PENDING_VERIFICATION: its versions may keep the
 * branches (and family) it was already selling, but never a new branch, an expansion into another branch, a new
 * product family, or a regulatory claim.
 */
final class CimaPublicationGuard
{
    public function __construct(private readonly CimaProductMappingService $mappings, private readonly CimaAuthorizationService $authorizations) {}

    public function assertPublishable(InsuranceProduct $product): void
    {
        $violations = $this->violations($product);
        if ($violations !== []) {
            throw ValidationException::withMessages(['cima' => $violations]);
        }
    }

    /**
     * Carrier resume (RETIRED → ACTIVE) of a product that was already on sale
     * before the CIMA dictionary went live is grandfathered: it is never
     * unpublished or blocked from resuming. Everything else is checked.
     */
    public function assertResumable(InsuranceProduct $product): void
    {
        if ($this->isGrandfathered($product)) {
            return;
        }
        $this->assertPublishable($product);
    }

    public function isGrandfathered(InsuranceProduct $product): bool
    {
        // Only products that already went on sale (ACTIVE, or paused as RETIRED) can be grandfathered.
        if (! in_array($product->status, ['ACTIVE', 'RETIRED'], true)) {
            return false;
        }
        $onSaleSince = $product->published_at ?? $product->created_at;
        $liveSince = RegulatoryRegime::where('code', 'CIMA')->where('is_seeded', true)->min('created_at');

        return $liveSince === null || ($onSaleSince !== null && $onSaleSince->lt($liveSince));
    }

    /** @return list<string> human, actionable reasons; empty when publishable */
    public function violations(InsuranceProduct $product, bool $applyDefaults = true): array
    {
        if ($applyDefaults) {
            $this->mappings->applyClassDefaults($product);
        }
        $mappings = $this->activeMappings($product);
        $name = $product->name ?: $product->code;
        if ($mappings->where('relationship_type', 'PRIMARY')->isEmpty()) {
            return ["Publication blocked: product {$name} has no PRIMARY CIMA branch mapping. Map its class to a CIMA branch in Product admin (CIMA Regulatory Dictionary → Product mapping)."];
        }

        $branches = RegulatoryBranch::current()->whereIn('code', $mappings->pluck('branch_code'))->get()->keyBy('code');
        $primaryAllowsComplementary = $mappings->where('relationship_type', 'PRIMARY')->contains(fn ($m) => (bool) ($branches[$m->branch_code]->complementary_covers_allowed ?? false));
        $out = [];
        foreach ($mappings->sortBy(fn ($m) => $branches[$m->branch_code]->number ?? 99) as $m) {
            $b = $branches[$m->branch_code] ?? null;
            if (! $b || $b->reserved) {
                $out[] = "Publication blocked: {$m->branch_code} is not an active, non-reserved CIMA branch.";

                continue;
            }
            $title = "CIMA branch {$b->number} — {$b->label_fr}";
            if ($m->relationship_type === 'ACCESSORY') {
                if (! $b->accessory_allowed) {
                    $out[] = "Publication blocked: {$title} can never be covered as an accessory risk (Article 328-1). Map it as PRIMARY and record the insurer's authorization.";
                }

                continue;
            }
            if ($m->relationship_type === 'COMPLEMENTARY' && ! $primaryAllowsComplementary) {
                $out[] = "Publication blocked: {$title} is mapped as COMPLEMENTARY but the product has no PRIMARY life branch that allows complementary covers (branches 20/21).";
            }
            if (! $this->authorizations->isAuthorized($product->carrier_id, $b->code)) {
                $out[] = $this->unauthorizedReason($product, $b->code, $title, (int) $b->number);
            }
        }

        return array_values(array_unique(array_filter($out)));
    }

    public const AUTHORIZED = 'AUTHORIZED';

    public const BLOCKED = 'BLOCK_NEW_PRODUCT_PUBLICATION';

    /** Item 7/8 status of the product's insurer authorization: AUTHORIZED | LEGACY_ACTIVE_AUTHORIZATION_PENDING_VERIFICATION | BLOCK_NEW_PRODUCT_PUBLICATION. */
    public function authorizationStatus(InsuranceProduct $product): string
    {
        $unauthorized = $this->activeMappings($product)->whereIn('relationship_type', ['PRIMARY', 'COMPLEMENTARY'])->pluck('branch_code')->unique()
            ->reject(fn ($code) => $this->authorizations->isAuthorized($product->carrier_id, $code));
        if ($unauthorized->isEmpty()) {
            return self::AUTHORIZED;
        }
        $legacy = $this->legacyRecord($product);

        return $legacy && $unauthorized->diff($legacy->branch_codes)->isEmpty() ? LegacyProductAuthorization::STATUS : self::BLOCKED;
    }

    /** Item 8: legacy products can never back a regulatory claim (compliance attestations, regulatory exports). */
    public function regulatoryClaimsAllowed(InsuranceProduct $product): bool
    {
        return $this->authorizationStatus($product) === self::AUTHORIZED;
    }

    /** The open legacy record covering this product (itself, or an earlier live version with the same carrier and code). */
    public function legacyRecord(InsuranceProduct $product): ?LegacyProductAuthorization
    {
        return LegacyProductAuthorization::where('status', LegacyProductAuthorization::STATUS)
            ->where(fn ($q) => $q->where('insurance_product_id', $product->id)->orWhere(fn ($w) => $w->where('carrier_id', $product->carrier_id)->where('product_code', $product->code)))
            ->orderBy('recorded_at')->first();
    }

    private function unauthorizedReason(InsuranceProduct $product, string $branchCode, string $title, int $number): ?string
    {
        $legacy = $this->legacyRecord($product);
        if ($legacy === null) {
            return "Publication blocked (BLOCK_NEW_PRODUCT_PUBLICATION): insurer not authorized for {$title}. Record the insurer's CIMA authorization for branch {$number} in Insurer setup.";
        }
        if (! in_array($branchCode, (array) $legacy->branch_codes, true)) {
            return "Publication blocked: {$title} is a new branch for a product whose authorization is LEGACY_ACTIVE_AUTHORIZATION_PENDING_VERIFICATION; record a verified CIMA authorization for branch {$number} first.";
        }
        $family = $product->carrierProduct?->product_family_id;
        if ($legacy->product_family_id !== null && $family !== null && $family !== $legacy->product_family_id) {
            return "Publication blocked: a new product family is not permitted while the authorization is LEGACY_ACTIVE_AUTHORIZATION_PENDING_VERIFICATION.";
        }

        return null;
    }

    /** @return Collection<int, ProductRegulatoryMapping> */
    public function activeMappings(InsuranceProduct $product): Collection
    {
        $on = now()->toDateString();

        return ProductRegulatoryMapping::where('insurance_product_id', $product->id)->where('status', 'ACTIVE')
            ->where(fn ($w) => $w->whereNull('effective_until')->orWhereDate('effective_until', '>=', $on))->get();
    }
}
