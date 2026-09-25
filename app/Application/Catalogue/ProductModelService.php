<?php

declare(strict_types=1);

namespace App\Application\Catalogue;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Regulatory\CimaProductMappingService;
use App\Application\Regulatory\CimaPublicationGuard;
use App\Models\Regulatory\ProductRegulatoryMapping;
use App\Models\Regulatory\RegulatoryBranch;
use App\Application\Temporal\ReferenceInstant;
use App\Application\Temporal\VersionResolver;
use App\Models\Catalogue\CarrierProduct;
use App\Models\Catalogue\ProductFamily;
use App\Models\InsuranceProduct;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-PRD-001/002/003 — product hierarchy and version lifecycle.
 * Branch → class → family → carrier product → version (insurance_products).
 * Publication itself stays in CatalogueService::publish (gates + supersession).
 */
final class ProductModelService
{
    public function __construct(
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
        private readonly ProductVersionSnapshot $snapshots,
        private readonly VersionResolver $versions,
    ) {}

    public function createFamily(array $data, User $actor): ProductFamily
    {
        $family = ProductFamily::create($data + ['status' => 'ACTIVE', 'description' => []]);
        $this->audit->record('catalogue.family.created', 'product_family', $family->id, ['code' => $family->code, 'class_code' => $family->class_code]);

        return $family;
    }

    public function createCarrierProduct(array $data, User $actor): CarrierProduct
    {
        if (! empty($data['product_family_id']) && ! empty($data['line_code'])) {
            $family = ProductFamily::findOrFail($data['product_family_id']);
            if ($family->line_code !== null && $family->line_code !== $data['line_code']) {
                throw ValidationException::withMessages(['line_code' => 'The carrier product line must match its family line ('.$family->line_code.').']);
            }
        }
        $product = CarrierProduct::create($data + ['status' => 'ACTIVE', 'currency' => 'XAF', 'description' => [], 'created_by' => $actor->id]);
        $this->audit->record('catalogue.carrier_product.created', 'carrier_product', $product->id, ['code' => $product->code, 'carrier_id' => $product->carrier_id]);

        return $product;
    }

    public function updateCarrierProduct(CarrierProduct $product, array $data, User $actor): CarrierProduct
    {
        $old = $product->only(array_keys($data));
        $product->update($data);
        $this->audit->recordChange('catalogue.carrier_product.updated', 'carrier_product', $product->id, $old, $product->only(array_keys($data)), 'Carrier product attributes updated');

        return $product->refresh();
    }

    /**
     * New DRAFT version. Versions are never edited live: changes to a submitted or
     * published version are made on a new version cloned from it (base_version_id).
     */
    public function newVersion(CarrierProduct $product, array $data, User $actor): InsuranceProduct
    {
        return DB::transaction(function () use ($product, $data, $actor) {
            $latest = InsuranceProduct::where(['carrier_id' => $product->carrier_id, 'code' => $product->code])->lockForUpdate()->orderByDesc('version')->first();
            if ($latest && $latest->status === 'DRAFT') {
                throw ValidationException::withMessages(['version' => 'A draft version ('.$latest->version.') already exists for this product.']);
            }
            $base = isset($data['base_version_id']) ? InsuranceProduct::where('carrier_product_id', $product->id)->findOrFail($data['base_version_id']) : $latest;
            $version = InsuranceProduct::create([
                'carrier_id' => $product->carrier_id, 'carrier_product_id' => $product->id, 'line_code' => $product->line_code, 'code' => $product->code,
                'name' => $data['name'] ?? $base?->name ?? ($product->name['en'] ?? $product->code), 'version' => ($latest?->version ?? 0) + 1,
                'effective_from' => $data['effective_from'], 'effective_until' => $data['effective_until'] ?? null, 'status' => 'DRAFT', 'coverages' => [],
                'eligibility_rules' => $data['eligibility_rules'] ?? $base?->eligibility_rules ?? [], 'regulatory_reference' => $data['regulatory_reference'] ?? $base?->regulatory_reference,
                'sales_start' => $data['sales_start'] ?? null, 'sales_end' => $data['sales_end'] ?? null,
                'new_business_allowed' => $data['new_business_allowed'] ?? true, 'renewal_allowed' => $data['renewal_allowed'] ?? true,
                'base_version_id' => $base?->id, 'created_by' => $actor->id,
            ]);
            if ($base) {
                $this->cloneConfiguration($base, $version);
            }
            $this->audit->record('catalogue.version.created', 'insurance_product', $version->id, ['version' => $version->version, 'base_version_id' => $base?->id]);
            $this->outbox->record('catalogue.product_version.created', 'insurance_product', $version->id, ['product_id' => $version->id, 'carrier_product_id' => $product->id, 'version' => $version->version]);

            return $version->refresh();
        });
    }

    /** REVIEW → APPROVED (maker-checker); the snapshot is frozen here. */
    public function approve(InsuranceProduct $v, User $checker, string $reason): InsuranceProduct
    {
        $this->assertTransition($v, ProductVersionStatus::APPROVED);
        if ($v->created_by === $checker->id) {
            throw ValidationException::withMessages(['actor' => __('wave2.maker_checker')]);
        }
        $this->applyFamilyDefaultMapping($v);
        app(CimaProductMappingService::class)->applyClassDefaults($v);

        return DB::transaction(function () use ($v, $checker, $reason) {
            $snapshot = $this->snapshots->build($v);
            $v->update(['status' => 'APPROVED', 'approved_by' => $checker->id, 'approved_at' => now(), 'snapshot' => $snapshot, 'snapshot_hash' => ProductVersionSnapshot::hash($snapshot)]);
            $this->history($v, 'IN_REVIEW', 'APPROVED', 'APPROVED', $reason, $checker);
            $this->outbox->record('catalogue.product_version.approved', 'insurance_product', $v->id, ['product_id' => $v->id, 'snapshot_hash' => $snapshot ? ProductVersionSnapshot::hash($snapshot) : null]);
            $this->audit->record('catalogue.version.approved', 'insurance_product', $v->id, ['version' => $v->version, 'snapshot_hash' => $v->snapshot_hash], $reason);

            return $v->refresh();
        });
    }

    public function suspend(InsuranceProduct $v, User $actor, string $reason): InsuranceProduct
    {
        $this->assertTransition($v, ProductVersionStatus::SUSPENDED);

        $v = $this->move($v, 'SUSPENDED', 'SUSPENDED', $reason, $actor, ['suspended_at' => now(), 'suspension_reason' => $reason]);
        $this->outbox->record('catalogue.product_version.suspended', 'insurance_product', $v->id, ['product_id' => $v->id, 'reason' => $reason]);

        return $v;
    }

    public function reinstate(InsuranceProduct $v, User $actor, string $reason): InsuranceProduct
    {
        $this->assertTransition($v, ProductVersionStatus::PUBLISHED);
        if ($v->status !== 'SUSPENDED') {
            throw ValidationException::withMessages(['status' => 'Only a suspended version can be reinstated.']);
        }
        if (InsuranceProduct::where(['carrier_id' => $v->carrier_id, 'code' => $v->code, 'status' => 'ACTIVE'])->exists()) {
            throw ValidationException::withMessages(['status' => 'Another version of this product is already published.']);
        }
        app(CimaPublicationGuard::class)->assertPublishable($v);

        $v = $this->move($v, 'ACTIVE', 'REINSTATED', $reason, $actor, ['suspended_at' => null, 'suspension_reason' => null]);
        $this->outbox->record('catalogue.product_version.reinstated', 'insurance_product', $v->id, ['product_id' => $v->id]);

        return $v;
    }

    public function retire(InsuranceProduct $v, User $actor, string $reason): InsuranceProduct
    {
        $this->assertTransition($v, ProductVersionStatus::RETIRED);

        $v = $this->move($v, 'RETIRED', 'RETIRED', $reason, $actor, ['retired_at' => now()]);
        $this->outbox->record('catalogue.product_version.retired', 'insurance_product', $v->id, ['product_id' => $v->id]);

        return $v;
    }

    /** Effective-dated resolution through the Temporal engine (never "latest"). */
    public function resolveAt(string $carrierId, string $code, string $at, string $timezone = 'Africa/Douala'): InsuranceProduct
    {
        $resolved = $this->versions->resolve('insurance_product', ['carrier_id' => $carrierId, 'code' => $code], ReferenceInstant::at($at, $timezone));

        return InsuranceProduct::findOrFail($resolved->id);
    }

    /**
     * CIMA mapping through the Regulatory module: when a version has no mapping at
     * all, its family's default CIMA branch becomes the automatic PRIMARY mapping
     * (same standing as a class default); line class defaults still apply after.
     */
    public function applyFamilyDefaultMapping(InsuranceProduct $v): bool
    {
        $branch = $v->carrierProduct?->family?->default_branch_code;
        if ($branch === null || ProductRegulatoryMapping::where('insurance_product_id', $v->id)->exists()) {
            return false;
        }
        $b = RegulatoryBranch::current()->where('code', $branch)->first();
        if (! $b) {
            return false;
        }
        ProductRegulatoryMapping::create([
            'insurance_product_id' => $v->id, 'product_version' => (int) $v->version, 'regime' => $b->regime, 'branch_code' => $branch,
            'relationship_type' => 'PRIMARY', 'is_primary' => true, 'effective_from' => $v->effective_from?->toDateString() ?? now()->toDateString(),
            'legal_reference' => $b->legal_reference, 'status' => 'ACTIVE', 'source' => 'CLASS_DEFAULT',
            'notes' => 'Automatic default for product family '.$v->carrierProduct->family->code,
        ]);
        $this->audit->record('regulatory.mapping.family_default_applied', 'insurance_product', $v->id, ['branch_code' => $branch]);

        return true;
    }

    public static function specStatus(InsuranceProduct $v): string
    {
        return ProductVersionStatus::fromStorage((string) $v->status);
    }

    public static function assertDraft(InsuranceProduct $v): void
    {
        if ($v->status !== 'DRAFT') {
            throw ValidationException::withMessages(['status' => 'Version '.$v->version.' is '.self::specStatus($v).'; versions are never edited live — create a new version.']);
        }
    }

    private function assertTransition(InsuranceProduct $v, string $to): void
    {
        $from = self::specStatus($v);
        if (! ProductVersionStatus::canTransition($from, $to)) {
            throw ValidationException::withMessages(['status' => "Transition {$from} → {$to} is not allowed."]);
        }
    }

    private function move(InsuranceProduct $v, string $to, string $reasonCode, string $notes, User $actor, array $extra): InsuranceProduct
    {
        return DB::transaction(function () use ($v, $to, $reasonCode, $notes, $actor, $extra) {
            $from = $v->status;
            $v->update(['status' => $to] + $extra);
            $this->history($v, $from, $to, $reasonCode, $notes, $actor);
            $this->audit->record('catalogue.version.'.strtolower($reasonCode), 'insurance_product', $v->id, ['from' => $from, 'to' => $to], $notes);

            return $v->refresh();
        });
    }

    private function history(InsuranceProduct $v, ?string $from, string $to, string $reason, string $notes, User $actor): void
    {
        DB::table('product_status_history')->insert(['id' => (string) Str::uuid(), 'insurance_product_id' => $v->id, 'from_status' => $from, 'to_status' => $to,
            'reason_code' => $reason, 'notes' => $notes, 'actor_id' => $actor->id, 'occurred_at' => now()]);
    }

    private function cloneConfiguration(InsuranceProduct $from, InsuranceProduct $to): void
    {
        $now = now();
        foreach (DB::table('product_coverages')->where('insurance_product_id', $from->id)->get() as $row) {
            DB::table('product_coverages')->insert(['insurance_product_id' => $to->id] + collect((array) $row)->except('insurance_product_id')->all());
        }
        $planMap = [];
        foreach ($from->plans()->with('coverages')->get() as $plan) {
            $copy = $to->plans()->create($plan->only(['code', 'name', 'description', 'tier', 'tariff_version_id', 'pricing_reference', 'eligibility', 'is_default', 'display_order', 'status']));
            $copy->coverages()->sync($plan->coverages->mapWithKeys(fn ($c) => [$c->id => ['inclusion' => $c->pivot->inclusion, 'display_order' => $c->pivot->display_order]])->all());
            $planMap[$plan->id] = $copy->id;
        }
        foreach (['coverage_limits', 'coverage_deductibles'] as $table) {
            foreach (DB::table($table)->where('insurance_product_id', $from->id)->get() as $row) {
                $r = (array) $row;
                DB::table($table)->insert(['id' => (string) Str::uuid(), 'insurance_product_id' => $to->id,
                    'product_plan_id' => $r['product_plan_id'] ? $planMap[$r['product_plan_id']] ?? null : null, 'created_at' => $now, 'updated_at' => $now]
                    + collect($r)->except(['id', 'insurance_product_id', 'product_plan_id', 'created_at', 'updated_at'])->all());
            }
        }
        foreach (DB::table('product_exclusions')->where('insurance_product_id', $from->id)->get() as $row) {
            $r = (array) $row;
            DB::table('product_exclusions')->insert(['id' => (string) Str::uuid(), 'insurance_product_id' => $to->id,
                'product_plan_id' => $r['product_plan_id'] ? $planMap[$r['product_plan_id']] ?? null : null]
                + collect($r)->except(['id', 'insurance_product_id', 'product_plan_id'])->all());
        }
    }
}
