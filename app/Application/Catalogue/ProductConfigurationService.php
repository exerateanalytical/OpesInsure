<?php

declare(strict_types=1);

namespace App\Application\Catalogue;

use App\Application\Audit\AuditWriter;
use App\Models\Catalogue\CoverageDeductible;
use App\Models\Catalogue\CoverageLimit;
use App\Models\Catalogue\ProductPlan;
use App\Models\CoverageDefinition;
use App\Models\ExclusionDefinition;
use App\Models\InsuranceLine;
use App\Models\InsuranceProduct;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-PRD-004/005/006 — configuration of a DRAFT version: plans, coverage terms
 * (inclusion, waiting period, territory, period), typed limits and deductibles,
 * and level-scoped exclusions / extensions. Every write refuses non-draft versions.
 */
final class ProductConfigurationService
{
    public const INCLUSIONS = ['MANDATORY', 'OPTIONAL', 'DEFAULT'];

    public const EXCLUSION_LEVELS = ['PRODUCT', 'PLAN', 'COVERAGE', 'CUSTOMER', 'RISK', 'CLAIM'];

    public function __construct(private readonly AuditWriter $audit) {}

    public function addPlan(InsuranceProduct $v, array $data, User $actor): ProductPlan
    {
        ProductModelService::assertDraft($v);

        return DB::transaction(function () use ($v, $data) {
            if (! empty($data['is_default'])) {
                $v->plans()->update(['is_default' => false]);
            }
            $plan = $v->plans()->create(collect($data)->except('coverages')->all() + ['status' => 'ACTIVE', 'description' => [], 'eligibility' => []]);
            if (isset($data['coverages'])) {
                $this->syncPlanCoverages($plan, $data['coverages']);
            }
            $this->audit->record('catalogue.plan.created', 'product_plan', $plan->id, ['version_id' => $v->id, 'code' => $plan->code]);

            return $plan->load('coverages');
        });
    }

    /** @param list<array{coverage_definition_id:string,inclusion?:string}> $coverages */
    public function syncPlanCoverages(ProductPlan $plan, array $coverages): ProductPlan
    {
        $v = $plan->version;
        ProductModelService::assertDraft($v);
        $onVersion = DB::table('product_coverages')->where('insurance_product_id', $v->id)->pluck('inclusion', 'coverage_definition_id');
        $sync = [];
        foreach (array_values($coverages) as $i => $c) {
            $id = $c['coverage_definition_id'];
            if (! $onVersion->has($id)) {
                throw ValidationException::withMessages(['coverages' => 'Plan coverages must be coverages of the product version.']);
            }
            $sync[$id] = ['inclusion' => $c['inclusion'] ?? $onVersion[$id], 'display_order' => $i];
        }
        $missing = $onVersion->filter(fn ($inc) => $inc === 'MANDATORY')->keys()->diff(array_keys($sync));
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages(['coverages' => 'Every plan must include the version\'s mandatory coverages.']);
        }
        $plan->coverages()->sync($sync);

        return $plan->load('coverages');
    }

    public function configureCoverage(InsuranceProduct $v, CoverageDefinition $coverage, array $terms, User $actor): array
    {
        ProductModelService::assertDraft($v);
        $q = DB::table('product_coverages')->where(['insurance_product_id' => $v->id, 'coverage_definition_id' => $coverage->id]);
        if (! $q->exists()) {
            $this->assertSameLine($v, $coverage->insurance_line_id, 'coverage_definition_id');
            DB::table('product_coverages')->insert(['insurance_product_id' => $v->id, 'coverage_definition_id' => $coverage->id, 'configuration' => '{}',
                'display_order' => (int) DB::table('product_coverages')->where('insurance_product_id', $v->id)->max('display_order') + 1]);
        }
        if (isset($terms['inclusion'])) {
            if ($coverage->mandatory && $terms['inclusion'] !== 'MANDATORY') {
                throw ValidationException::withMessages(['inclusion' => 'This coverage is mandatory for its line.']);
            }
            $terms['is_optional'] = $terms['inclusion'] === 'OPTIONAL';
        }
        $q->update(collect($terms)->only(['inclusion', 'is_optional', 'waiting_period_days', 'territory', 'coverage_period', 'display_order'])->all());
        $this->audit->record('catalogue.coverage.configured', 'insurance_product', $v->id, ['coverage' => $coverage->code] + $terms);

        return (array) $q->first();
    }

    public function addLimit(InsuranceProduct $v, array $data, User $actor): CoverageLimit
    {
        ProductModelService::assertDraft($v);
        $this->assertOnVersion($v, $data['coverage_definition_id'], $data['product_plan_id'] ?? null);
        $type = $data['limit_type'];
        $pct = in_array($type, ['PERCENT_OF_SUM_INSURED', 'PERCENT_OF_LOSS'], true);
        if ($type === 'UNLIMITED') {
            $data['amount_minor'] = $data['percentage_bp'] = null;
        } elseif ($pct && ! isset($data['percentage_bp'])) {
            throw ValidationException::withMessages(['percentage_bp' => 'Percentage limits need percentage_bp.']);
        } elseif (! $pct && ! isset($data['amount_minor'])) {
            throw ValidationException::withMessages(['amount_minor' => 'This limit type needs an amount.']);
        }
        $limit = CoverageLimit::create(['insurance_product_id' => $v->id] + $data);
        $this->audit->record('catalogue.limit.created', 'coverage_limit', $limit->id, ['version_id' => $v->id, 'type' => $type]);

        return $limit;
    }

    public function addDeductible(InsuranceProduct $v, array $data, User $actor): CoverageDeductible
    {
        ProductModelService::assertDraft($v);
        $this->assertOnVersion($v, $data['coverage_definition_id'], $data['product_plan_id'] ?? null);
        $need = ['FIXED' => ['amount_minor'], 'PERCENTAGE' => ['percentage_bp'], 'DAYS' => ['days'], 'COMBINED' => ['percentage_bp'],
            'MINIMUM' => ['minimum_minor'], 'MAXIMUM' => ['maximum_minor']][$data['deductible_type']];
        foreach ($need as $field) {
            if (! isset($data[$field])) {
                throw ValidationException::withMessages([$field => "{$data['deductible_type']} deductibles need {$field}."]);
            }
        }
        if ($data['deductible_type'] === 'COMBINED' && ! isset($data['minimum_minor']) && ! isset($data['maximum_minor'])) {
            throw ValidationException::withMessages(['minimum_minor' => 'COMBINED deductibles need a minimum and/or maximum.']);
        }
        $deductible = CoverageDeductible::create(['insurance_product_id' => $v->id] + $data);
        $this->audit->record('catalogue.deductible.created', 'coverage_deductible', $deductible->id, ['version_id' => $v->id, 'type' => $deductible->deductible_type]);

        return $deductible;
    }

    public function removeTerm(CoverageLimit|CoverageDeductible $term, User $actor): void
    {
        ProductModelService::assertDraft(InsuranceProduct::findOrFail($term->insurance_product_id));
        $term->delete();
        $this->audit->record('catalogue.term.removed', $term->getTable(), $term->id, ['version_id' => $term->insurance_product_id]);
    }

    public function attachExclusion(InsuranceProduct $v, ExclusionDefinition $exclusion, array $data, User $actor): array
    {
        ProductModelService::assertDraft($v);
        $this->assertSameLine($v, $exclusion->insurance_line_id, 'exclusion_definition_id');
        $level = $data['level'] ?? 'PRODUCT';
        $plan = $data['product_plan_id'] ?? null;
        $coverage = $data['coverage_definition_id'] ?? null;
        if ($level === 'PLAN' && ! $plan) {
            throw ValidationException::withMessages(['product_plan_id' => 'PLAN-level exclusions need a plan.']);
        }
        if ($level === 'COVERAGE' && ! $coverage) {
            throw ValidationException::withMessages(['coverage_definition_id' => 'COVERAGE-level exclusions need a coverage.']);
        }
        if ($plan || $coverage) {
            $this->assertOnVersion($v, $coverage, $plan);
        }
        $row = ['id' => (string) Str::uuid(), 'insurance_product_id' => $v->id, 'exclusion_definition_id' => $exclusion->id, 'level' => $level,
            'product_plan_id' => $plan, 'coverage_definition_id' => $coverage, 'condition' => json_encode($data['condition'] ?? (object) []),
            'configuration' => json_encode($data['configuration'] ?? (object) [])];
        DB::table('product_exclusions')->insert($row);
        $this->audit->record('catalogue.exclusion.attached', 'insurance_product', $v->id, ['exclusion' => $exclusion->code, 'level' => $level, 'kind' => $exclusion->kind]);

        return $row;
    }

    private function assertOnVersion(InsuranceProduct $v, ?string $coverageId, ?string $planId): void
    {
        if ($coverageId && ! DB::table('product_coverages')->where(['insurance_product_id' => $v->id, 'coverage_definition_id' => $coverageId])->exists()) {
            throw ValidationException::withMessages(['coverage_definition_id' => 'The coverage is not part of this product version.']);
        }
        if ($planId && ! ProductPlan::where(['id' => $planId, 'insurance_product_id' => $v->id])->exists()) {
            throw ValidationException::withMessages(['product_plan_id' => 'The plan does not belong to this product version.']);
        }
    }

    private function assertSameLine(InsuranceProduct $v, string $lineId, string $field): void
    {
        if (InsuranceLine::where('code', $v->line_code)->value('id') !== $lineId) {
            throw ValidationException::withMessages([$field => __('wave2.coverage_line_mismatch')]);
        }
    }
}
