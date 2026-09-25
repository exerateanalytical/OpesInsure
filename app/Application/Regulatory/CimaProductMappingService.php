<?php

declare(strict_types=1);

namespace App\Application\Regulatory;

use App\Application\Audit\AuditWriter;
use App\Models\InsuranceProduct;
use App\Models\Regulatory\ProductRegulatoryMapping;
use App\Models\Regulatory\RegulatoryBranch;
use App\Models\Regulatory\RegulatoryClassDefault;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Product → CIMA branch mappings (PRIMARY / ACCESSORY / COMPLEMENTARY).
 * Background default: a product with no mapping gets its normalized class's
 * default mapping (regulatory_class_defaults) automatically. Product admins
 * override with maker-checker: an ADMIN mapping is created PENDING_APPROVAL
 * and a different user approves it; approval supersedes the class defaults.
 * Owner decisions 2026-09-25 items 1-6: mappings are coverage-level (coverage_code; null = whole product), and a
 * COMPLEMENTARY life cover carries its own premium flag, dates and conditions.
 */
final class CimaProductMappingService
{
    public const TYPES = ['PRIMARY', 'ACCESSORY', 'COMPLEMENTARY'];

    public function __construct(private readonly AuditWriter $audit) {}

    /** Applies the class defaults when the product has no mapping at all. Returns rows created. */
    public function applyClassDefaults(InsuranceProduct $product): int
    {
        if (ProductRegulatoryMapping::where('insurance_product_id', $product->id)->exists()) {
            return 0;
        }
        $on = now()->toDateString();
        $defaults = RegulatoryClassDefault::where('line_code', $product->line_code)->where('status', 'ACTIVE')
            ->whereDate('effective_from', '<=', $on)->where(fn ($w) => $w->whereNull('effective_until')->orWhereDate('effective_until', '>=', $on))->get();
        if ($defaults->isEmpty()) {
            return 0;
        }
        $coverages = $product->coverageDefinitions()->pluck('code')->all();
        $created = 0;
        foreach ($defaults as $d) {
            if ($d->requires_coverage_code !== null && ! in_array($d->requires_coverage_code, $coverages, true)) {
                continue;
            }
            $branch = RegulatoryBranch::current()->where('code', $d->branch_code)->first();
            ProductRegulatoryMapping::create([
                'insurance_product_id' => $product->id, 'product_version' => (int) $product->version, 'regime' => $d->regime,
                'branch_code' => $d->branch_code, 'relationship_type' => $d->relationship_type, 'is_primary' => $d->relationship_type === 'PRIMARY',
                'effective_from' => $product->effective_from?->toDateString() ?? $on, 'legal_reference' => $branch?->legal_reference,
                'status' => 'ACTIVE', 'source' => 'CLASS_DEFAULT', 'notes' => 'Automatic default for class '.$product->line_code,
                // Owner decision item 1: coverage-level rules map PRODUCT -> COVERAGE -> CIMA BRANCH.
                'coverage_code' => $d->mapping_level === 'COVERAGE' ? $d->requires_coverage_code : null,
                'separate_premium' => (bool) $d->separate_premium,
            ]);
            $created++;
        }
        if ($created > 0) {
            $this->audit->record('regulatory.mapping.defaults_applied', 'insurance_product', $product->id, ['line_code' => $product->line_code, 'count' => $created]);
        }

        return $created;
    }

    /**
     * PRODUCT -> COVERAGE -> CIMA BRANCH tree of the active mappings (owner decision item 1, many-to-many).
     *
     * @return array{product: list<array<string, mixed>>, coverages: array<string, list<array<string, mixed>>>}
     */
    public function coverageTree(InsuranceProduct $product): array
    {
        $rows = $this->guard()->activeMappings($product)->map(fn (ProductRegulatoryMapping $m) => [
            'branch_code' => $m->branch_code, 'relationship_type' => $m->relationship_type, 'separate_premium' => (bool) $m->separate_premium,
            'effective_from' => $m->effective_from?->toDateString(), 'effective_until' => $m->effective_until?->toDateString(),
            'conditions' => $m->conditions, 'branch_allocation_status' => $m->branch_allocation_status, 'coverage_code' => $m->coverage_code,
        ]);

        return [
            'product' => $rows->whereNull('coverage_code')->values()->all(),
            'coverages' => $rows->whereNotNull('coverage_code')->groupBy('coverage_code')->map->values()->map->all()->all(),
        ];
    }

    private function guard(): CimaPublicationGuard
    {
        return app(CimaPublicationGuard::class);
    }

    /** Maker step: proposes an override mapping (PENDING_APPROVAL). */
    public function propose(InsuranceProduct $product, array $data, ?User $actor): ProductRegulatoryMapping
    {
        $type = strtoupper((string) ($data['relationship_type'] ?? ''));
        if (! in_array($type, self::TYPES, true)) {
            throw ValidationException::withMessages(['relationship_type' => ['Relationship type must be PRIMARY, ACCESSORY or COMPLEMENTARY.']]);
        }
        $branch = RegulatoryBranch::current()->where('code', $data['branch_code'] ?? null)->first();
        if (! $branch) {
            throw ValidationException::withMessages(['branch_code' => ['Unknown or inactive CIMA branch.']]);
        }
        if ($branch->reserved) {
            throw ValidationException::withMessages(['branch_code' => ["CIMA branch {$branch->number} is reserved and cannot be mapped."]]);
        }
        if ($type === 'ACCESSORY' && ! $branch->accessory_allowed) {
            throw ValidationException::withMessages(['relationship_type' => ["CIMA branch {$branch->number} — {$branch->label_fr} can never be covered as an accessory risk (Article 328-1)."]]);
        }
        $coverage = filled($data['coverage_code'] ?? null) ? strtoupper((string) $data['coverage_code']) : null;
        if ($coverage !== null && ! $product->coverageDefinitions()->where('code', $coverage)->exists()) {
            throw ValidationException::withMessages(['coverage_code' => ["Coverage {$coverage} is not part of this product."]]);
        }
        // Owner decision item 8: a LEGACY_ACTIVE_AUTHORIZATION_PENDING_VERIFICATION product can never gain a new unauthorized branch.
        $legacy = $this->guard()->legacyRecord($product);
        if ($legacy && $type !== 'ACCESSORY' && ! in_array($branch->code, (array) $legacy->branch_codes, true)
            && ! app(CimaAuthorizationService::class)->isAuthorized((string) $product->carrier_id, $branch->code)) {
            throw ValidationException::withMessages(['branch_code' => ["CIMA branch {$branch->number} would be a new branch; LEGACY_ACTIVE_AUTHORIZATION_PENDING_VERIFICATION does not permit it until a verified authorization is recorded."]]);
        }
        if (blank($data['effective_from'] ?? null)) {
            throw ValidationException::withMessages(['effective_from' => ['An effective date is required.']]);
        }

        $mapping = ProductRegulatoryMapping::create([
            'insurance_product_id' => $product->id, 'product_version' => (int) $product->version, 'regime' => 'CIMA',
            'branch_code' => $branch->code, 'relationship_type' => $type, 'is_primary' => $type === 'PRIMARY',
            'effective_from' => $data['effective_from'], 'effective_until' => $data['effective_until'] ?? null,
            'legal_reference' => $data['legal_reference'] ?? $branch->legal_reference, 'status' => 'PENDING_APPROVAL', 'source' => 'ADMIN',
            'notes' => $data['notes'] ?? null, 'created_by' => $actor?->id,
            'coverage_code' => $coverage, 'separate_premium' => (bool) ($data['separate_premium'] ?? $type === 'COMPLEMENTARY'),
            'conditions' => $data['conditions'] ?? null,
        ]);
        $this->audit->record('regulatory.mapping.proposed', 'product_regulatory_mapping', $mapping->id, ['product_id' => $product->id, 'branch' => $branch->code, 'type' => $type]);

        return $mapping;
    }

    /** Checker step: a different user activates the mapping; the automatic class defaults are superseded. */
    public function approve(ProductRegulatoryMapping $mapping, User $actor): ProductRegulatoryMapping
    {
        if ($mapping->status !== 'PENDING_APPROVAL') {
            throw ValidationException::withMessages(['status' => ['Only a pending mapping can be approved.']]);
        }
        if ($mapping->created_by !== null && $mapping->created_by === $actor->id) {
            throw ValidationException::withMessages(['actor' => ['Maker-checker: the person who proposed this mapping cannot approve it.']]);
        }

        return DB::transaction(function () use ($mapping, $actor) {
            ProductRegulatoryMapping::where('insurance_product_id', $mapping->insurance_product_id)->where('source', 'CLASS_DEFAULT')->where('status', 'ACTIVE')
                ->get()->each(fn (ProductRegulatoryMapping $m) => $m->update(['status' => 'SUPERSEDED', 'effective_until' => now()->toDateString()]));
            $mapping->update(['status' => 'ACTIVE', 'approved_by' => $actor->id, 'approved_at' => now()]);
            $this->audit->record('regulatory.mapping.approved', 'product_regulatory_mapping', $mapping->id, ['branch' => $mapping->branch_code]);

            return $mapping->refresh();
        });
    }

    public function reject(ProductRegulatoryMapping $mapping, User $actor, string $reason): ProductRegulatoryMapping
    {
        if ($mapping->status !== 'PENDING_APPROVAL') {
            throw ValidationException::withMessages(['status' => ['Only a pending mapping can be rejected.']]);
        }
        $mapping->update(['status' => 'REJECTED', 'approved_by' => $actor->id, 'approved_at' => now()]);
        $this->audit->record('regulatory.mapping.rejected', 'product_regulatory_mapping', $mapping->id, [], $reason);

        return $mapping->refresh();
    }

    /** Ends an active mapping (never deletes it). */
    public function retire(ProductRegulatoryMapping $mapping, User $actor, string $reason): ProductRegulatoryMapping
    {
        $mapping->update(['status' => 'SUPERSEDED', 'effective_until' => now()->toDateString()]);
        $this->audit->record('regulatory.mapping.retired', 'product_regulatory_mapping', $mapping->id, [], $reason);

        return $mapping->refresh();
    }
}
