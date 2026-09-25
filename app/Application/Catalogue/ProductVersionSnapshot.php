<?php

declare(strict_types=1);

namespace App\Application\Catalogue;

use App\Application\DocumentCatalogue\DocumentCatalogueService;
use App\Models\Catalogue\ExclusionLegalText;
use App\Models\InsuranceProduct;
use App\Models\Regulatory\ProductRegulatoryMapping;
use Illuminate\Support\Facades\DB;

/**
 * REQ-PRD-001 reproducibility — a canonical, hashable picture of everything a
 * version sells: identity, plans, coverages with typed limits/deductibles,
 * exclusions with their legal-text versions, CIMA mappings, approved tariffs and
 * document requirements. Frozen on approval/publication; issued policies can be
 * re-derived from it and drift is detectable.
 */
final class ProductVersionSnapshot
{
    public function __construct(private readonly DocumentCatalogueService $documents) {}

    /** @return array<string,mixed> */
    public function build(InsuranceProduct $v): array
    {
        $v->loadMissing(['carrierProduct.family', 'coverageDefinitions', 'plans.coverages', 'limits.coverage', 'deductibles.coverage']);
        $cp = $v->carrierProduct;
        $on = $v->effective_from?->toDateString();
        $codeOf = fn ($coverage) => $coverage?->code;

        $exclusions = DB::table('product_exclusions as pe')->join('exclusion_definitions as e', 'e.id', '=', 'pe.exclusion_definition_id')
            ->leftJoin('coverage_definitions as c', 'c.id', '=', 'pe.coverage_definition_id')->leftJoin('product_plans as pp', 'pp.id', '=', 'pe.product_plan_id')
            ->where('pe.insurance_product_id', $v->id)
            ->get(['e.id', 'e.code', 'e.kind', 'e.effects', 'pe.level', 'pp.code as plan_code', 'c.code as coverage_code', 'pe.condition'])
            ->map(function ($e) use ($on) {
                $text = $on ? ExclusionLegalText::where('exclusion_definition_id', $e->id)->where('status', 'APPROVED')->whereNull('superseded_at')
                    ->whereDate('effective_from', '<=', $on)->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $on))
                    ->orderByDesc('version')->first() : null;

                return ['code' => $e->code, 'kind' => $e->kind, 'effects' => json_decode((string) $e->effects, true), 'level' => $e->level,
                    'plan' => $e->plan_code, 'coverage' => $e->coverage_code, 'condition' => json_decode((string) $e->condition, true),
                    'legal_text' => $text ? ['id' => $text->id, 'version' => $text->version, 'hash' => $text->text_hash] : null];
            })->sortBy(fn ($e) => $e['code'].'|'.$e['level'].'|'.$e['plan'].'|'.$e['coverage'])->values()->all();

        return self::canonical([
            'version' => ['id' => $v->id, 'code' => $v->code, 'version' => (int) $v->version, 'name' => $v->name, 'line_code' => $v->line_code,
                'effective_from' => $on, 'effective_until' => $v->effective_until?->toDateString(), 'sales_start' => $v->sales_start?->toDateString(),
                'sales_end' => $v->sales_end?->toDateString(), 'new_business_allowed' => (bool) $v->new_business_allowed, 'renewal_allowed' => (bool) $v->renewal_allowed,
                'eligibility_rules' => $v->eligibility_rules ?? [], 'regulatory_reference' => $v->regulatory_reference],
            'carrier_product' => $cp ? ['id' => $cp->id, 'code' => $cp->code, 'name' => $cp->name, 'description' => $cp->description, 'customer_type' => $cp->customer_type,
                'currency' => $cp->currency, 'market' => $cp->market, 'family' => $cp->family?->code, 'class' => $cp->family?->class_code,
                'default_branch_code' => $cp->family?->default_branch_code] : null,
            'coverages' => $v->coverageDefinitions->map(fn ($c) => ['code' => $c->code, 'inclusion' => $c->pivot->inclusion, 'waiting_period_days' => $c->pivot->waiting_period_days,
                'territory' => $c->pivot->territory, 'coverage_period' => $c->pivot->coverage_period, 'display_order' => (int) $c->pivot->display_order])
                ->sortBy('code')->values()->all(),
            'limits' => $v->limits->map(fn ($l) => ['coverage' => $codeOf($l->coverage), 'plan_id' => $l->product_plan_id, 'type' => $l->limit_type,
                'amount_minor' => $l->amount_minor, 'percentage_bp' => $l->percentage_bp, 'currency' => $l->currency])
                ->sortBy(fn ($l) => $l['coverage'].'|'.$l['plan_id'].'|'.$l['type'])->values()->all(),
            'deductibles' => $v->deductibles->map(fn ($d) => ['coverage' => $codeOf($d->coverage), 'plan_id' => $d->product_plan_id, 'type' => $d->deductible_type,
                'amount_minor' => $d->amount_minor, 'percentage_bp' => $d->percentage_bp, 'basis' => $d->percentage_basis, 'days' => $d->days,
                'minimum_minor' => $d->minimum_minor, 'maximum_minor' => $d->maximum_minor, 'currency' => $d->currency])
                ->sortBy(fn ($d) => $d['coverage'].'|'.$d['plan_id'].'|'.$d['type'])->values()->all(),
            'plans' => $v->plans->map(fn ($p) => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'tier' => $p->tier, 'is_default' => $p->is_default,
                'tariff_version_id' => $p->tariff_version_id, 'pricing_reference' => $p->pricing_reference, 'eligibility' => $p->eligibility, 'status' => $p->status,
                'coverages' => $p->coverages->map(fn ($c) => ['code' => $c->code, 'inclusion' => $c->pivot->inclusion])->sortBy('code')->values()->all()])
                ->sortBy('code')->values()->all(),
            'exclusions' => $exclusions,
            'regulatory_mappings' => ProductRegulatoryMapping::where('insurance_product_id', $v->id)->where('status', 'ACTIVE')->get()
                ->map(fn ($m) => ['regime' => $m->regime, 'branch_code' => $m->branch_code, 'relationship_type' => $m->relationship_type])
                ->sortBy(fn ($m) => $m['branch_code'].'|'.$m['relationship_type'])->values()->all(),
            'tariffs' => $v->tariffs()->where('status', 'APPROVED')->orderBy('version')->get(['id', 'version', 'rules_hash'])
                ->map(fn ($t) => ['id' => $t->id, 'version' => (int) $t->version, 'rules_hash' => $t->rules_hash])->all(),
            'document_requirements' => rescue(fn () => $this->documents->requirementsFor($v)->map(fn ($r) => collect((array) $r)->only(['document_type_code', 'code', 'stage', 'requirement', 'level'])->all())->values()->all(), [], false),
        ]);
    }

    public static function hash(array $snapshot): string
    {
        return hash('sha256', json_encode(self::canonical($snapshot), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** Integrity (stored snapshot matches its hash) and drift (live configuration still equals the snapshot). */
    public function verify(InsuranceProduct $v): array
    {
        if ($v->snapshot === null) {
            return ['frozen' => false, 'intact' => null, 'drift' => null];
        }
        $intact = self::hash($v->snapshot) === $v->snapshot_hash;
        $live = self::hash($this->build($v->fresh())) === $v->snapshot_hash;

        return ['frozen' => true, 'intact' => $intact, 'drift' => ! $live, 'hash' => $v->snapshot_hash];
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($v) => self::canonical($v), $value);
    }
}
