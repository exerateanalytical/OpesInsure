<?php

declare(strict_types=1);

namespace App\Application\Catalogue\Sandbox;

use App\Application\Audit\AuditWriter;
use App\Application\Catalogue\Governance\Models\ProductTestCase;
use App\Application\Catalogue\Governance\Models\ProductTestRun;
use App\Application\Catalogue\ProductVersionSnapshot;
use App\Application\DocumentCatalogue\DocumentCatalogueService;
use App\Application\Rating\RatingService;
use App\Application\Rules\RuleEngine;
use App\Application\Temporal\ReferenceInstant;
use App\Models\InsuranceProduct;
use App\Models\TariffVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * REQ-PRD-008 — product test sandbox (PRE §76–78). Runs a product version's test policy pack
 * (or ad-hoc facts) through the one rules path (RuleEngine::eligibility), the one rating path
 * (RatingService::price) and the document requirement matrix (DocumentCatalogueService), with a
 * full trace and no side effects: every evaluation runs inside a database transaction that is
 * always rolled back and no subject is passed to the engines (so no decision log / rating run /
 * quote is written). Only the governance evidence row (product_test_runs) is persisted by runPack().
 *
 * Draft versions have no approved tariff yet: when no tariff resolves on the reference date the
 * version's latest non-rejected tariff is used as a CANDIDATE (flagged in the result).
 */
final class ProductSandbox
{
    public function __construct(
        private readonly RuleEngine $rules,
        private readonly RatingService $rating,
        private readonly DocumentCatalogueService $documents,
        private readonly AuditWriter $audit,
    ) {}

    /** @return array<string,mixed> one evaluation with trace (+ assertions when $expected is given) */
    public function evaluate(InsuranceProduct $v, array $facts, ?string $referenceDate = null, array $expected = []): array
    {
        $result = null;
        DB::beginTransaction();
        try {
            $result = $this->evaluateInside($v, $facts, $referenceDate, $expected);
        } finally {
            DB::rollBack(); // sandbox: nothing any engine might have written survives
        }

        return $result;
    }

    public function addCase(InsuranceProduct $v, array $data, User $actor): ProductTestCase
    {
        if (ProductTestCase::where('insurance_product_id', $v->id)->where('code', $data['code'])->exists()) {
            throw ValidationException::withMessages(['code' => 'A test case with this code already exists for this version.']);
        }
        $case = ProductTestCase::create(['insurance_product_id' => $v->id, 'code' => $data['code'], 'name' => $data['name'], 'facts' => $data['facts'],
            'expected' => $data['expected'] ?? [], 'reference_date' => $data['reference_date'] ?? null, 'created_by' => $actor->id]);
        $this->audit->record('catalogue.test_case.created', 'insurance_product', $v->id, ['code' => $case->code]);

        return $case;
    }

    /** Runs every case of the version's pack; persists only the evidence row. */
    public function runPack(InsuranceProduct $v, User $actor): ProductTestRun
    {
        $cases = ProductTestCase::where('insurance_product_id', $v->id)->orderBy('code')->get();
        if ($cases->isEmpty()) {
            throw ValidationException::withMessages(['cases' => 'This version has no test policy pack; add at least one test case.']);
        }
        $results = $cases->map(fn (ProductTestCase $c) => ['code' => $c->code, 'name' => $c->name]
            + $this->evaluate($v, (array) $c->facts, $c->reference_date?->toDateString(), (array) $c->expected))->all();
        $failed = count(array_filter($results, fn ($r) => ! $r['passed']));

        $run = ProductTestRun::create(['insurance_product_id' => $v->id, 'configuration_hash' => self::configurationHash($v), 'status' => $failed === 0 ? 'PASSED' : 'FAILED',
            'cases_total' => count($results), 'cases_failed' => $failed, 'results' => $results, 'run_by' => $actor->id, 'ran_at' => now()]);
        $this->audit->record('catalogue.test_pack.run', 'insurance_product', $v->id, ['run_id' => $run->id, 'status' => $run->status, 'failed' => $failed]);

        return $run;
    }

    /** Latest run, and whether it still matches the current configuration (a changed configuration invalidates it). */
    public function latestRun(InsuranceProduct $v): ?array
    {
        $run = ProductTestRun::where('insurance_product_id', $v->id)->orderByDesc('ran_at')->first();

        return $run ? ['run' => $run, 'current' => hash_equals($run->configuration_hash, self::configurationHash($v))] : null;
    }

    /** Hash of the configuration content a test result depends on: snapshot content, tariff rules, eligibility rules. */
    public static function configurationHash(InsuranceProduct $v): string
    {
        $v = $v->fresh() ?? $v;

        return hash('sha256', json_encode([
            // Lifecycle side effects (mappings applied at submission, tariff / rule-set status moves) do not invalidate a run; content changes do.
            'snapshot' => collect(app(ProductVersionSnapshot::class)->build($v))->except(['regulatory_mappings', 'tariffs'])->all(),
            'tariffs' => DB::table('tariff_versions')->where('insurance_product_id', $v->id)->orderBy('version')->get(['id', 'rules_hash'])->map(fn ($t) => (array) $t)->all(),
            'eligibility_rules' => $v->eligibility_rules,
            'rule_sets' => DB::table('rule_sets')->where('insurance_product_id', $v->id)->orderBy('code')->orderBy('version')->get(['id', 'content_hash'])->map(fn ($r) => (array) $r)->all(),
        ], JSON_UNESCAPED_SLASHES));
    }

    private function evaluateInside(InsuranceProduct $v, array $facts, ?string $referenceDate, array $expected): array
    {
        $at = ReferenceInstant::at($referenceDate ?? $v->effective_from?->toDateString() ?? now()->toDateString());
        $out = ['reference_date' => $at->businessDate(), 'facts' => $facts];

        // Rules (eligibility) — no subject: nothing recorded.
        try {
            $e = DB::transaction(fn () => $this->rules->eligibility($v, $facts, $at->referenceAt->toDateTimeImmutable())); // savepoint: a failing query cannot poison the sandbox transaction
            $out['eligibility'] = ['outcome' => $e['outcome']->value, 'blocking' => $e['result']->blocking, 'reasons' => $e['result']->reasons,
                'explanations' => $e['explanations'], 'trace' => $e['result']->trace, 'resolved_versions' => $e['result']->resolvedVersions];
        } catch (Throwable $ex) {
            $out['eligibility'] = ['outcome' => 'ERROR', 'error' => $ex->getMessage()];
        }

        // Rating — stateless price(); rateQuote() (which persists rating_runs) is never called.
        $tariff = $this->rating->tariffFor($v, $at);
        $source = 'RESOLVED';
        if (! $tariff) {
            $tariff = TariffVersion::where('insurance_product_id', $v->id)->whereNotIn('status', ['REJECTED', 'EXPIRED', 'RETIRED'])->orderByDesc('version')->first();
            $source = 'CANDIDATE';
        }
        if (! $tariff) {
            $out['rating'] = ['status' => 'NO_TARIFF', 'error' => 'No tariff version exists for this product version.'];
        } else {
            try {
                $r = DB::transaction(fn () => $this->rating->price($tariff, $facts, (string) $v->line_code, null, $at));
                $out['rating'] = ['status' => 'PRICED', 'tariff_version_id' => $tariff->id, 'tariff_status' => $tariff->status, 'tariff_source' => $source,
                    'pricing' => $r['pricing']->toArray(), 'trace' => $r['engine_result']->trace, 'resolved_versions' => $r['resolved_versions']];
            } catch (Throwable $ex) {
                $out['rating'] = ['status' => 'FAILED', 'tariff_version_id' => $tariff->id, 'tariff_source' => $source, 'error' => $ex->getMessage()];
            }
        }

        // Documents — the resolved requirement matrix for this version, by stage.
        $rows = $this->documents->requirementsFor($v);
        $out['documents'] = ['mapped' => $rows->isNotEmpty(), 'by_stage' => $rows->groupBy(fn ($r) => $r['stage'] ?? 'ANY')
            ->map(fn ($g) => $g->map(fn ($r) => ['document' => $r['canonical_code'], 'label_en' => $r['label_en'], 'level' => $r['level']])->values()->all())->all()];

        $out['assertions'] = $this->assert($out, $expected);
        $out['passed'] = ! in_array(false, array_column($out['assertions'], 'passed'), true);

        return $out;
    }

    /** @return list<array{check:string,expected:mixed,actual:mixed,passed:bool}> */
    private function assert(array $out, array $expected): array
    {
        $a = [];
        $total = $out['rating']['pricing']['total_minor'] ?? null;
        if (array_key_exists('eligibility', $expected)) {
            $a[] = ['check' => 'eligibility', 'expected' => strtoupper((string) $expected['eligibility']), 'actual' => $out['eligibility']['outcome'] ?? null,
                'passed' => strtoupper((string) $expected['eligibility']) === ($out['eligibility']['outcome'] ?? null)];
        }
        if (array_key_exists('premium_total_minor', $expected)) {
            $a[] = ['check' => 'premium_total_minor', 'expected' => (int) $expected['premium_total_minor'], 'actual' => $total, 'passed' => $total === (int) $expected['premium_total_minor']];
        }
        if (array_key_exists('premium_min_minor', $expected)) {
            $a[] = ['check' => 'premium_min_minor', 'expected' => (int) $expected['premium_min_minor'], 'actual' => $total, 'passed' => $total !== null && $total >= (int) $expected['premium_min_minor']];
        }
        if (array_key_exists('premium_max_minor', $expected)) {
            $a[] = ['check' => 'premium_max_minor', 'expected' => (int) $expected['premium_max_minor'], 'actual' => $total, 'passed' => $total !== null && $total <= (int) $expected['premium_max_minor']];
        }
        if (array_key_exists('rating_fails', $expected)) {
            $fails = ($out['rating']['status'] ?? null) !== 'PRICED';
            $a[] = ['check' => 'rating_fails', 'expected' => (bool) $expected['rating_fails'], 'actual' => $fails, 'passed' => $fails === (bool) $expected['rating_fails']];
        }
        if ($a === []) {
            // No explicit expectation: the case must at least evaluate and price.
            $a[] = ['check' => 'evaluates', 'expected' => true, 'actual' => ($out['eligibility']['outcome'] ?? 'ERROR') !== 'ERROR' && ($out['rating']['status'] ?? null) === 'PRICED',
                'passed' => ($out['eligibility']['outcome'] ?? 'ERROR') !== 'ERROR' && ($out['rating']['status'] ?? null) === 'PRICED'];
        }

        return $a;
    }
}
